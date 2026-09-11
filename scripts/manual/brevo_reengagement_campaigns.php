<?php

declare(strict_types=1);

/**
 * brevo_reengagement_campaigns.php
 *
 * Cria campanhas de Reengagement na Brevo.
 * Nome: {SIGLA} {m/d/y} #000 {TIME} - Reengagement
 *
 * Uso: php scripts/manual/brevo_reengagement_campaigns.php
 */

if (PHP_SAPI !== 'cli') {
    echo "Execute este script pela linha de comando (CLI).\n";
    exit;
}

require_once __DIR__ . '/../../includes/config.php';

// ==========================
// CONFIGURAÇÃO — edite aqui
// ==========================

/** IDs dos ESPs a processar */
$espIds = [6];

/** Dias à frente (0 = hoje, 1 = amanhã, …) */
$arrayDays = [1];

/** Horários de envio local (America/New_York). Cria uma campanha por horário. */
$sendTimes = ['10:00', '16:00'];

// ==========================
// BREVO HELPERS
// ==========================

function brevoRequest(string $method, string $path, string $apiKey, ?array $body = null, array $query = []): ?array
{
    $url = 'https://api.brevo.com/v3/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $headers = ['api-key: ' . $apiKey, 'Accept: application/json'];

    if ($body !== null) {
        $json = json_encode($body);
        if ($json === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException("Brevo API error {$httpCode}: " . substr($response, 0, 500));
    }

    if ($response === '' || $response === 'null') {
        return null;
    }

    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('json_decode failed: ' . json_last_error_msg());
    }

    return $decoded;
}

function brevoGetTemplate(string $apiKey, int $remoteId): ?array
{
    return brevoRequest('GET', 'smtp/templates/' . $remoteId, $apiKey);
}

/**
 * Busca segmentos na Brevo e retorna o ID do primeiro cujo nome contenha $nameContains.
 */
function brevoFindSegmentByName(string $apiKey, string $nameContains): ?int
{
    $limit  = 50;
    $offset = 0;

    while (true) {
        $result   = brevoRequest('GET', 'contacts/segments', $apiKey, null, [
            'limit'  => $limit,
            'offset' => $offset,
        ]);

        $segments = $result['segments'] ?? [];

        if (empty($segments)) {
            break;
        }

        foreach ($segments as $seg) {
            $segName = $seg['segmentName'] ?? '';
            if ($segName !== '' && str_contains($segName, $nameContains)) {
                return (int) $seg['id'];
            }
        }

        if (count($segments) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return null;
}

function brevoCampaignExistsByName(string $apiKey, string $name): bool
{
    $result = brevoRequest('GET', 'emailCampaigns', $apiKey, null, [
        'type'               => 'classic',
        'limit'              => 50,
        'offset'             => 0,
        'sort'               => 'desc',
        'excludeHtmlContent' => true,
    ]);

    if (empty($result['campaigns']) || !is_array($result['campaigns'])) {
        return false;
    }

    foreach ($result['campaigns'] as $campaign) {
        if (!empty($campaign['name']) && $campaign['name'] === $name) {
            return true;
        }
    }

    return false;
}

/** Converte "10:00" → "10AM", "16:00" → "4PM" */
function timeToLabel(string $time): string
{
    [$h] = explode(':', $time);
    $hour = (int) $h;
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $display = $hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour);
    return $display . $suffix;
}

function buildCampaignName(string $espSigla, string $timeLabel, DateTimeImmutable $date): string
{
    return $espSigla . ' ' . $date->format('m/d/y') . ' #000 ' . $timeLabel . ' - Reengagement';
}

// ==========================
// MAIN
// ==========================

function main(int $daysAhead, array $sendTimes, int $espId): void
{
    // ESP config diretamente do banco
    $esp = pdoFetchOne(
        "SELECT id, name, api_key, domain_name, smtp_user FROM esp WHERE id = :id LIMIT 1",
        [':id' => $espId]
    );

    if (!$esp || empty($esp['api_key'])) {
        echo "ESP id={$espId} não encontrado ou sem api_key — pulando.\n";
        return;
    }

    $apiKey      = (string) $esp['api_key'];
    $espName     = (string) $esp['name'];
    $senderName  = (string) $esp['domain_name'];
    $senderEmail = (string) $esp['smtp_user'];

    // Sigla do ESP a partir do name (ex.: "Brevo-PHG" -> "PHG")
    $espParts = explode('-', $espName);
    $espSigla = strtoupper(trim((string) end($espParts)));
    if ($espSigla === '') {
        $espSigla = strtoupper(trim($espName));
    }

    $tz         = new DateTimeZone('America/New_York');
    $now        = new DateTimeImmutable('now', $tz);
    $targetDate = $now->modify(($daysAhead >= 0 ? '+' : '') . $daysAhead . ' days');
    $weekday    = strtolower($targetDate->format('l'));

    echo "\n=== ESP: {$espName} (id={$espId}) | {$targetDate->format('Y-m-d')} ===\n";

    // Top N templates por CTR médio (últimos 60 dias), um por horário
    $needed = count($sendTimes);
    $ranked = pdoFetchAll(
        "SELECT
             es.template_remote_id,
             es.template_label_id,
             COALESCE(
                 AVG(
                     CASE WHEN c.global_delivered > 0
                     THEN (COALESCE(c.global_unique_clicks, 0) / c.global_delivered) * 100
                     ELSE NULL END
                 ), 0
             ) AS avg_ctr,
             COUNT(c.id) AS campaign_count
         FROM esp_schedule es
         LEFT JOIN email_campaigns c
             ON c.esp_id = es.esp_id
             AND (
                 CASE WHEN c.name LIKE '%#%'
                 THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.name, '#', -1), ' ', 1) AS UNSIGNED)
                 ELSE NULL END
             ) = es.template_label_id
             AND c.scheduled_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
         WHERE es.esp_id = :esp_id
           AND es.weekday = :weekday
           AND es.is_active = 1
         GROUP BY es.template_remote_id, es.template_label_id
         ORDER BY avg_ctr DESC, campaign_count DESC
         LIMIT {$needed}",
        [':esp_id' => $espId, ':weekday' => $weekday]
    );

    if (empty($ranked)) {
        echo "Nenhum schedule ativo para {$weekday} (esp_id={$espId}) — abortando.\n";
        return;
    }

    echo count($ranked) . " template(s) rankeados por CTR disponíveis.\n";

    // Segmentos buscados uma única vez
    try {
        $segmentId = brevoFindSegmentByName($apiKey, 'Reengagement');
    } catch (Throwable $e) {
        echo "ERRO ao buscar segmento Reengagement: {$e->getMessage()}\n";
        return;
    }

    if (!$segmentId) {
        echo "Segmento com 'Reengagement' não encontrado — abortando.\n";
        return;
    }

    try {
        $exclusionSegmentId = brevoFindSegmentByName($apiKey, '5N/30C/30O');
    } catch (Throwable $e) {
        echo "ERRO ao buscar segmento de exclusão: {$e->getMessage()}\n";
        return;
    }

    if (!$exclusionSegmentId) {
        echo "Segmento de exclusão com '5N/30C/30O' não encontrado — abortando.\n";
        return;
    }

    echo "Segmento: id={$segmentId} | Exclusão: id={$exclusionSegmentId}\n";

    // Um template diferente por horário (slot 1 = top 1, slot 2 = top 2, …)
    foreach ($sendTimes as $idx => $sendTime) {
        $row = $ranked[$idx] ?? null;

        if (!$row) {
            echo "\n-- Slot " . ($idx + 1) . " ({$sendTime}) sem template disponível — pulando.\n";
            continue;
        }

        $remoteId  = (int) $row['template_remote_id'];
        $labelId   = (int) $row['template_label_id'];
        $avgCtr    = round((float) $row['avg_ctr'], 2);
        $count     = (int) $row['campaign_count'];
        $timeLabel = timeToLabel($sendTime);

        echo "\n-- Slot " . ($idx + 1) . " | {$timeLabel} | template remote_id={$remoteId} label={$labelId} avg_ctr={$avgCtr}% ({$count} campanhas) --\n";

        try {
            $tpl = brevoGetTemplate($apiKey, $remoteId);
        } catch (Throwable $e) {
            echo "ERRO ao buscar template {$remoteId}: {$e->getMessage()}\n";
            continue;
        }

        if (empty($tpl) || empty($tpl['isActive'])) {
            echo "Template {$remoteId} não encontrado ou inativo — pulando.\n";
            continue;
        }

        $subject     = (string) ($tpl['subject'] ?? '');
        $tplName     = (string) ($tpl['name'] ?? '');
        $replyTo     = (string) ($tpl['replyTo'] ?? '');
        $tplSendName = (string) ($tpl['sender']['name']  ?? '');
        $tplSendMail = (string) ($tpl['sender']['email'] ?? '');

        if ($subject === '') {
            echo "Template {$remoteId} sem subject — pulando.\n";
            continue;
        }

        $campaignName = buildCampaignName($espSigla, $timeLabel, $targetDate);

        try {
            if (brevoCampaignExistsByName($apiKey, $campaignName)) {
                echo "❌ '{$campaignName}' já existe — pulando.\n";
                continue;
            }
        } catch (Throwable $e) {
            echo "ERRO ao verificar campanhas existentes: {$e->getMessage()}\n";
            continue;
        }

        [$h, $m]      = explode(':', $sendTime);
        $sendDateTime = $targetDate->setTime((int) $h, (int) $m, 0);
        $scheduledAt  = $sendDateTime->format('c');

        $payload = [
            'name'        => $campaignName,
            'sender'      => [
                'email' => $tplSendMail !== '' ? $tplSendMail : $senderEmail,
                'name'  => $tplSendName !== '' ? $tplSendName : $senderName,
            ],
            'subject'     => $subject,
            'templateId'  => $remoteId,
            'scheduledAt' => $scheduledAt,
            'recipients'  => [
                'segmentIds'          => [$segmentId],
                'exclusionSegmentIds' => [$exclusionSegmentId],
            ],
        ];

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['replyTo'] = $replyTo;
        }

        if ($tplName !== '' && !str_contains($tplName, 'NOPREVIEW')) {
            $payload['previewText'] = $tplName;
        }

        try {
            $resp = brevoRequest('POST', 'emailCampaigns', $apiKey, $payload);
        } catch (Throwable $e) {
            echo "ERRO ao criar '{$campaignName}': {$e->getMessage()}\n";
            continue;
        }

        $id = $resp['id'] ?? null;
        if ($id) {
            echo "✅ Criada [ID {$id}] '{$campaignName}' scheduledAt={$scheduledAt}\n";
        } else {
            echo "⚠️ '{$campaignName}' criada mas resposta sem ID.\n";
        }

        sleep(1);
    }
}

// ==========================
// EXEC
// ==========================

foreach ($espIds as $espId) {
    foreach ($arrayDays as $day) {
        main($day, $sendTimes, $espId);
    }
}

echo "\nFim.\n";
