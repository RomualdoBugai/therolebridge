<?php

declare(strict_types=1);

/**
 * brevo_sync_templates.php
 *
 * Sincroniza templates SMTP da Brevo para a tabela email_templates.
 *
 * Uso:
 *   php brevo_sync_templates.php [espId]
 *
 * Exemplo:
 *   php brevo_sync_templates.php 1
 */

require_once __DIR__ . '/../includes/config.php';

/**
 * Pega dados do ESP na tabela esp usando PDO helpers.
 */
function getEspConfigFromDb(int $espId): array
{
    $sql = "
        SELECT id, name, api_key, domain_name, domain, smtp_user
        FROM esp
        WHERE id = :id
        LIMIT 1
    ";

    $row = pdoFetchOne($sql, [':id' => $espId]);

    if (!$row) {
        throw new RuntimeException("ESP com id={$espId} não encontrado na tabela esp.");
    }

    if (empty($row['api_key'])) {
        throw new RuntimeException("ESP id={$espId} está sem api_key preenchido.");
    }

    return [
        'id'          => (int)$row['id'],
        'espName'     => $row['name'],
        'apiKey'      => $row['api_key'],
        'domainName'  => $row['domain_name'],
        'domain'      => $row['domain'],
        'senderEmail' => $row['smtp_user'],
    ];
}

/**
 * HTTP genérico pra Brevo.
 */
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

/**
 * Lista templates da Brevo (paginado).
 * Retorna array de IDs [22, 4, 5, ...].
 */
function listBrevoTemplateIds(string $apiKey): array
{
    $allIds = [];
    $limit  = 100;   // safe
    $offset = 0;

    while (true) {
        $result = brevoRequest('GET', 'smtp/templates', $apiKey, null, [
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => 'asc',
        ]);

        if (empty($result['templates']) || !is_array($result['templates'])) {
            break;
        }

        foreach ($result['templates'] as $tpl) {
            if (isset($tpl['id'])) {
                $allIds[] = (int)$tpl['id'];
            }
        }

        if (count($result['templates']) < $limit) {
            break;
        }

        $offset += $limit;
        usleep(200_000); // 200ms
    }

    $allIds = array_values(array_unique($allIds));

    return $allIds;
}

/**
 * Detalhe de template: GET /smtp/templates/{id}
 */
function brevoGetTemplate(string $apiKey, int $templateId): ?array
{
    return brevoRequest('GET', 'smtp/templates/' . $templateId, $apiKey);
}

/**
 * Limpa HTML da Brevo, removendo lixo do builder.
 *
 * - Remove classes nl2go-*, gmail-fix, gmx-killpill
 * - Remove atributos: emogrify, data-btn, condition, yahoo, type="absoluteLink"
 * - Remove comentários condicionais do Outlook: <!--[if mso]> ... <![endif]-->
 * - Remove tags VML/Office: <v:...>, <o:...>
 * - Remove <style> muito específico do builder (nl2go-, .r0-, .r1-, etc.)
 */
function cleanBrevoHtml(string $html): string
{
    // 1) Remove comentários condicionais do Outlook
    $html = preg_replace(
        '#<!--\s*\[if\s+.*?<!\s*\[endif\]\s*-->#is',
        '',
        $html
    ) ?? $html;

    // 2) Remove tags VML/Office completas (v:, o:)
    // Blocos com conteúdo
    $html = preg_replace(
        '#<\s*(v|o):[a-z0-9:_\-]+[^>]*>.*?<\s*/\s*\1:[a-z0-9:_\-]+\s*>#is',
        '',
        $html
    ) ?? $html;

    // Tags auto-fechadas
    $html = preg_replace(
        '#<\s*(v|o):[a-z0-9:_\-]+[^>]*/\s*>#is',
        '',
        $html
    ) ?? $html;

    // 3) Limpa class= removendo nl2go-*, gmail-fix, gmx-killpill
    $html = preg_replace_callback(
        '/\sclass=("|\')(.*?)\1/si',
        static function (array $m): string {
            $quote   = $m[1];
            $classes = preg_split('/\s+/', trim($m[2])) ?: [];
            $keep    = [];

            foreach ($classes as $c) {
                if ($c === '') {
                    continue;
                }

                $lc = strtolower($c);

                // joga fora
                if (
                    str_starts_with($lc, 'nl2go-') ||
                    $lc === 'gmail-fix' ||
                    $lc === 'gmx-killpill'
                ) {
                    continue;
                }

                $keep[] = $c;
            }

            if (empty($keep)) {
                // remove atributo class inteiro
                return '';
            }

            return ' class=' . $quote . implode(' ', $keep) . $quote;
        },
        $html
    ) ?? $html;

    // 4) Remove atributos lixo
    // emogrify="no"
    $html = preg_replace('/\semogrify=("|\')[^"\']*\1/si', '', $html) ?? $html;
    // data-btn="1" etc
    $html = preg_replace('/\sdata-btn=("|\')[^"\']*\1/si', '', $html) ?? $html;
    // condition="{{ update_profile }}"
    $html = preg_replace('/\scondition=("|\')[^"\']*\1/si', '', $html) ?? $html;
    // yahoo="fix"
    $html = preg_replace('/\syahoo=("|\')[^"\']*\1/si', '', $html) ?? $html;
    // type="absoluteLink" (mas mantém type de outros valores)
    $html = preg_replace('/\stype=("|\')absoluteLink\1/si', '', $html) ?? $html;

    // 5) Remove <style> muito específico do builder
    // (qualquer style que contenha nl2go- ou .r0- / .r1- / .r2- etc.)
    $html = preg_replace(
        '#<style\b[^>]*>.*?(nl2go-|\.r0-|\.r1-|\.r2-).*?</style>#is',
        '',
        $html
    ) ?? $html;

    // Trim básico
    return trim($html);
}

/**
 * Salva/atualiza template na tabela email_templates.
 * Sem ON DUPLICATE KEY – faz SELECT antes – usando pdoFetchOne/pdoExecute.
 */
function upsertTemplate(int $espId, array $tpl): void
{
    $remoteId    = (int)($tpl['id'] ?? 0);
    $name        = (string)($tpl['name'] ?? '');
    $subject     = isset($tpl['subject']) ? (string)$tpl['subject'] : null;
    $isActive    = !empty($tpl['isActive']) ? 1 : 0;
    $senderName  = $tpl['sender']['name']  ?? null;
    $senderEmail = $tpl['sender']['email'] ?? null;
    $replyTo     = $tpl['replyTo'] ?? null;

    if ($remoteId <= 0 || $name === '') {
        // Template zoado, ignora
        return;
    }

    // Limpa htmlContent se existir
    if (!empty($tpl['htmlContent']) && is_string($tpl['htmlContent'])) {
        $tpl['htmlContent'] = cleanBrevoHtml($tpl['htmlContent']);
    }

    $rawJson = json_encode($tpl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawJson === false) {
        throw new RuntimeException('json_encode template failed: ' . json_last_error_msg());
    }

    // 1) Verifica se já existe (esp_id + remote_id)
    $sqlSelect = "
        SELECT id
        FROM email_templates
        WHERE esp_id = :esp_id
          AND remote_id = :remote_id
        LIMIT 1
    ";

    $existing = pdoFetchOne($sqlSelect, [
        ':esp_id'    => $espId,
        ':remote_id' => $remoteId,
    ]);

    $existingId = $existing ? (int)$existing['id'] : 0;

    if ($existingId > 0) {
        // 2) UPDATE se já existe
        $sqlUpdate = "
            UPDATE email_templates
            SET
                name         = :name,
                subject      = :subject,
                is_active    = :is_active,
                sender_name  = :sender_name,
                sender_email = :sender_email,
                reply_to     = :reply_to,
                raw_json     = :raw_json,
                updated_at   = NOW()
            WHERE id = :id
        ";

        pdoExecute($sqlUpdate, [
            ':name'         => $name,
            ':subject'      => $subject,
            ':is_active'    => $isActive,
            ':sender_name'  => $senderName,
            ':sender_email' => $senderEmail,
            ':reply_to'     => $replyTo,
            ':raw_json'     => $rawJson,
            ':id'           => $existingId,
        ]);
    } else {
        // 3) INSERT se não existe
        $sqlInsert = "
            INSERT INTO email_templates (
                esp_id,
                remote_id,
                name,
                subject,
                is_active,
                sender_name,
                sender_email,
                reply_to,
                raw_json,
                created_at,
                updated_at
            )
            VALUES (
                :esp_id,
                :remote_id,
                :name,
                :subject,
                :is_active,
                :sender_name,
                :sender_email,
                :reply_to,
                :raw_json,
                NOW(),
                NOW()
            )
        ";

        pdoExecute($sqlInsert, [
            ':esp_id'       => $espId,
            ':remote_id'    => $remoteId,
            ':name'         => $name,
            ':subject'      => $subject,
            ':is_active'    => $isActive,
            ':sender_name'  => $senderName,
            ':sender_email' => $senderEmail,
            ':reply_to'     => $replyTo,
            ':raw_json'     => $rawJson,
        ]);
    }
}

// ==========================
// MAIN
// ==========================

function main(): void
{
    $espId = 1;
    if (isset($GLOBALS['argv'][1]) && is_numeric($GLOBALS['argv'][1])) {
        $espId = (int)$GLOBALS['argv'][1];
    }

    $esp = getEspConfigFromDb($espId);

    echo "Sincronizando templates para ESP {$esp['espName']} (id={$espId})...\n";

    $templateIds = listBrevoTemplateIds($esp['apiKey']);

    if (empty($templateIds)) {
        echo "Nenhum template encontrado na Brevo.\n";
        return;
    }

    echo "Encontrados " . count($templateIds) . " templates. Buscando detalhes...\n";

    $countOk  = 0;
    $countErr = 0;

    foreach ($templateIds as $tplId) {
        echo "  - Template ID {$tplId}... ";

        try {
            $tpl = brevoGetTemplate($esp['apiKey'], $tplId);
            if (empty($tpl)) {
                echo "vazio/ignorado.\n";
                $countErr++;
                continue;
            }

            upsertTemplate($espId, $tpl);
            echo "OK\n";
            $countOk++;
        } catch (Throwable $e) {
            echo "ERRO: {$e->getMessage()}\n";
            $countErr++;
        }

        usleep(200_000); // 200ms
    }

    echo "Fim. Templates salvos: {$countOk}, erros: {$countErr}.\n";
}

// EXEC
if (PHP_SAPI === 'cli') {
    main();
} else {
    echo "Rode esse script via CLI.\n";
}
