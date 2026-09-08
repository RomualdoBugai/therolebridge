<?php

declare(strict_types=1);

/**
 * brevo_schedule_campaigns_bugai_db.php
 *
 * Lê o schedule da tabela esp_schedule e cria campanhas na Brevo.
 *
 * Uso:
 *   php brevo_schedule_campaigns_bugai_db.php [daysAhead] [espId]
 *
 * Ex:
 *   php brevo_schedule_campaigns_bugai_db.php 1 1   # amanhã, esp_id=1
 */

require_once __DIR__ . '/../includes/config.php';

/**
 * Pega as configs básicas do ESP (Brevo/Bugai) na tabela esp.
 */
function getEspConfigFromDb(int $espId, int $maxRetries = 1): array
{
    $sql = "
        SELECT id, name, api_key, domain_name, domain, smtp_user
        FROM esp
        WHERE id = :id
        LIMIT 1
    ";

    $row = pdoFetchOne($sql, [':id' => $espId], $maxRetries);

    if (!$row) {
        throw new RuntimeException("ESP com id={$espId} não encontrado na tabela esp.");
    }

    return [
        'id'          => (int) $row['id'],
        'espName'     => $row['name'],
        'apiKey'      => $row['api_key'],
        'domainName'  => $row['domain_name'],
        'domain'      => $row['domain'],
        'senderEmail' => $row['smtp_user'],          // contact@therolebridge.com
        'timezone'    => 'America/New_York',         // fixa aqui; se quiser, adiciona coluna depois
    ];
}

/**
 * Pega o schedule de um dia específico (weekday) para um esp_id.
 */
function getScheduleForDay(int $espId, string $weekday, int $maxRetries = 1): array
{
    $sql = "
        SELECT 
            id,
            esp_id,
            weekday,
            slot_index,
            time,
            template_remote_id,
            template_label_id,
            name_pattern,
            segment_ids_json,
            exclusion_segment_ids_json,
            list_ids_json,
            is_active
        FROM esp_schedule
        WHERE esp_id = :esp_id
          AND weekday = :weekday
          AND is_active = 1
        ORDER BY slot_index ASC
    ";

    $rows = pdoFetchAll($sql, [
        ':esp_id'  => $espId,
        ':weekday' => $weekday,
    ], $maxRetries);

    return $rows ?: [];
}

// ==========================
// HTTP / BREVO HELPERS
// ==========================

function brevoRequest(string $method, string $path, string $apiKey, ?array $body = null, array $query = []): ?array
{
    $baseUrl = 'https://api.brevo.com/v3/';
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $headers = [
        'api-key: ' . $apiKey,
        'Accept: application/json',
    ];

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

function brevoGetTemplate(string $apiKey, int $templateRemoteId): ?array
{
    return brevoRequest('GET', 'smtp/templates/' . $templateRemoteId, $apiKey);
}

/**
 * Versão simples: pega as últimas 50 campanhas classic (qualquer status)
 * e verifica se já existe uma com o MESMO name.
 */
function brevoCampaignExistsByName(string $apiKey, string $name): bool
{
    $result = brevoRequest('GET', 'emailCampaigns', $apiKey, null, [
        'type'              => 'classic',
        'limit'             => 50,
        'offset'            => 0,
        'sort'              => 'desc',
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

// ==========================
// HELPERS DE NOME / JSON
// ==========================

/**
 * Substitui os placeholders do name_pattern:
 * - DATEUPDATE -> m/d/y
 * - TEMPLATEID -> id do template
 * - LIVE       -> slot_index + 1
 */
function buildCampaignNameFromPattern(string $pattern, int $templateLabelId, int $slotIndex, DateTimeImmutable $date): string
{
    $name = $pattern;
    $name = str_replace('DATEUPDATE', $date->format('m/d/y'), $name);
    $name = str_replace('TEMPLATEID', (string)$templateLabelId, $name);
    $name = str_replace('LIVE', (string)($slotIndex + 1), $name);

    return $name;
}

/**
 * Decodifica JSON de ids de segmento/lista.
 */
function jsonIdsToArray(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    // garante que são ints
    return array_values(array_map('intval', $data));
}

// ==========================
// MAIN
// ==========================

function main($daysAhead = 1, $espId = 1): void
{
    $esp = getEspConfigFromDb($espId);

    if (empty($esp['apiKey'])) {
        throw new RuntimeException("ESP id={$espId} está sem api_key na tabela esp.");
    }

    $tz = new DateTimeZone($esp['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $targetDate = $now->modify(($daysAhead >= 0 ? '+' : '') . $daysAhead . ' days');
    $weekday = $targetDate->format('l'); // Monday, ...

    echo "ESP: {$esp['espName']} (id={$espId})\n";
    echo "Target date: " . $targetDate->format('Y-m-d') . " ({$weekday}), daysAhead={$daysAhead}\n";

    // agora usa a versão nova sem $pdo:
    $scheduleRows = getScheduleForDay($espId, $weekday);

    if (empty($scheduleRows)) {
        echo "Nenhum schedule ativo para {$weekday} (esp_id={$espId}). Nada a fazer.\n";
        return;
    }

    foreach ($scheduleRows as $row) {
        $slotIndex  = (int)$row['slot_index'];
        $templateRemoteId = (int)$row['template_remote_id'];
        $templateLabelId = (int)$row['template_label_id'];
        $timeStr    = $row['time']; // HH:MM:SS

        echo "---- Slot {$slotIndex} ({$timeStr}) templateRemoteId={$templateRemoteId} ----\n";

        // horário local do envio
        [$h, $m, $s] = explode(':', $timeStr);
        $sendDateTime = $targetDate->setTime((int)$h, (int)$m, (int)$s);
        $scheduledAt  = $sendDateTime->format('c'); // ISO 8601 com offset

        // pega template na Brevo
        try {
            $tpl = brevoGetTemplate($esp['apiKey'], $templateRemoteId);
        } catch (Throwable $e) {
            echo "ERRO ao buscar template {$templateRemoteId}: {$e->getMessage()}\n";
            continue;
        }

        if (empty($tpl) || empty($tpl['isActive'])) {
            echo "Template {$templateRemoteId} não encontrado ou inativo – pulando.\n";
            continue;
        }

        $subject = $tpl['subject'] ?? null;
        if (!$subject) {
            echo "Template {$templateRemoteId} sem subject – pulando.\n";
            continue;
        }

        // sender: prioriza o do template
        $senderName  = $tpl['sender']['name']  ?? $esp['domainName'] ?? 'The Role Bridge';
        $senderEmail = $tpl['sender']['email'] ?? $esp['senderEmail'];

        // monta nome da campanha a partir do pattern do banco
        $namePattern  = $row['name_pattern'];
        $campaignName = buildCampaignNameFromPattern($namePattern, $templateLabelId, $slotIndex, $targetDate);

        // evita duplicar campanha com mesmo name
        try {
            if (brevoCampaignExistsByName($esp['apiKey'], $campaignName)) {
                echo "❌ Campanha '{$campaignName}' já existe – pulando.\n";
                continue;
            }
        } catch (Throwable $e) {
            echo "ERRO ao verificar campanhas existentes: {$e->getMessage()}\n";
            continue;
        }

        // recipients
        $segmentIds          = jsonIdsToArray($row['segment_ids_json']);
        $exclusionSegmentIds = jsonIdsToArray($row['exclusion_segment_ids_json']);
        $listIds             = jsonIdsToArray($row['list_ids_json'] ?? null);

        $recipients = [];
        if (!empty($listIds)) {
            $recipients['listIds'] = $listIds;
        }
        if (!empty($segmentIds)) {
            $recipients['segmentIds'] = $segmentIds;
        }
        if (!empty($exclusionSegmentIds)) {
            $recipients['exclusionSegmentIds'] = $exclusionSegmentIds;
        }

        $payload = [
            'name'        => $campaignName,
            'sender'      => [
                'email' => $senderEmail,
                'name'  => $senderName,
            ],
            'subject'     => $subject,
            'templateId'  => $templateRemoteId,
            'scheduledAt' => $scheduledAt,
        ];

        // replyTo – só se for e-mail válido
        if (!empty($tpl['replyTo']) && filter_var($tpl['replyTo'], FILTER_VALIDATE_EMAIL)) {
            $payload['replyTo'] = $tpl['replyTo'];
        }

        if (!empty($recipients)) {
            $payload['recipients'] = $recipients;
        }

        // previewText: usa template name se tiver e não contiver "NOPREVIEW"
        if (!empty($tpl['name']) && strpos($tpl['name'], 'NOPREVIEW') === false) {
            $payload['previewText'] = $tpl['name'];
        }

        // cria campanha
        try {
            $resp = brevoRequest('POST', 'emailCampaigns', $esp['apiKey'], $payload);
        } catch (Throwable $e) {
            echo "ERRO ao criar campanha '{$campaignName}': {$e->getMessage()}\n";
            continue;
        }

        $id = $resp['id'] ?? null;
        if ($id) {
            echo "✅ Campanha criada [ID {$id}] '{$campaignName}' – template {$templateRemoteId} – scheduledAt {$scheduledAt}\n";
        } else {
            echo "⚠️ Campanha '{$campaignName}' criada, mas resposta sem ID.\n";
        }

        sleep(1); // rate limit básico
    }

    echo "Fim.\n";
}

// EXEC
if (php_sapi_name() === 'cli') {

    $arg = $argv[1] ?? null;
    $arrayDays = $arg !== null ? [(int) $arg] : [1];

    $esps = pdoFetchAll(
        "SELECT id FROM esp WHERE platform = 1 AND sync_campaign = 1 ORDER BY id ASC"
    );

    if (empty($esps)) {
        echo "Nenhum ESP com platform=1 e sync_campaign=1 encontrado.\n";
    } else {
        foreach ($esps as $esp) {
            foreach ($arrayDays as $day) {
                echo "\n=== ESP id={$esp['id']} — Dia +{$day} ===\n";
                main($day, (int) $esp['id']);
            }
        }
    }
} else {
    echo "Rode esse script via CLI.\n";
}
