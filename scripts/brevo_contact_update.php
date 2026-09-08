<?php

/**
 * Syncs geo-enriched location data and job keyword to Brevo for leads that were already synced.
 *
 * Logic:
 *   - Lead synced to Brevo: record_sync.synced_at is set
 *   - Lead updated later (geo or job_keyword): record_leads.updated_at > record_sync.synced_at
 *   - This script finds those leads and pushes CITY/STATE/ZIP/JOB_KEYWORD to Brevo via PUT /contacts
 *   - Updates record_sync.synced_at so the lead isn't processed again
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

const CONTACT_UPDATE_BATCH_SIZE = 20;

function fetchUpdatedLeadsForBrevo(int $espId, int $limit, int $offset): array
{
    return pdoFetchAll(
        "
        SELECT
            rl.id,
            rl.email,
            rl.city,
            rl.state,
            rl.zip,
            rl.job_keyword,
            rs.synced_at
        FROM record_leads rl
        INNER JOIN record_sync rs
            ON rs.record_lead_id = rl.id
           AND rs.esp_id = :esp_id
        WHERE rl.updated_at > rs.synced_at
          AND rl.is_valid = 1
          AND (
              rl.city != ''
              OR rl.state != ''
              OR rl.zip != ''
              OR (rl.job_keyword IS NOT NULL AND rl.job_keyword != '')
          )
        ORDER BY rl.updated_at ASC
        LIMIT :limit OFFSET :offset
        ",
        [
            ':esp_id' => $espId,
            ':limit'  => $limit,
            ':offset' => $offset,
        ]
    );
}

function markContactSyncedForEsp(int $recordLeadId, int $espId): void
{
    pdoExecute(
        "
        UPDATE record_sync
        SET synced_at = NOW()
        WHERE record_lead_id = :record_lead_id
          AND esp_id = :esp_id
        ",
        [
            ':record_lead_id' => $recordLeadId,
            ':esp_id'         => $espId,
        ]
    );
}

function runContactUpdateForEsp(int $espId, string $espName, string $apiKey): void
{
    echo "-------------------------------------------\n";
    echo "ESP [{$espId}] {$espName} — contact update (geo + job keyword)\n";

    $totalOk   = 0;
    $totalFail = 0;
    $offset    = 0;

    while (true) {
        $leads = fetchUpdatedLeadsForBrevo($espId, CONTACT_UPDATE_BATCH_SIZE, $offset);

        if (empty($leads)) {
            break;
        }

        echo "Lote OFFSET {$offset} (" . count($leads) . " leads)\n";

        foreach ($leads as $lead) {
            $recordLeadId = (int) ($lead['id'] ?? 0);
            $email        = trim((string) ($lead['email']       ?? ''));
            $city         = trim((string) ($lead['city']        ?? ''));
            $state        = trim((string) ($lead['state']       ?? ''));
            $zip          = trim((string) ($lead['zip']         ?? ''));
            $jobKeyword   = trim((string) ($lead['job_keyword'] ?? ''));

            if ($recordLeadId <= 0 || $email === '') {
                continue;
            }

            $ok = brevoUpdateContact($apiKey, $email, $city, $state, $zip, $jobKeyword);

            if ($ok) {
                markContactSyncedForEsp($recordLeadId, $espId);
                echo "[OK] {$email} | {$city}, {$state} {$zip} | job: {$jobKeyword}\n";
                $totalOk++;
            } else {
                echo "[FAIL] {$email}\n";
                $totalFail++;
            }
        }

        $offset += CONTACT_UPDATE_BATCH_SIZE;
    }

    echo "Concluído — OK: {$totalOk} | Falhas: {$totalFail}\n\n";
}

/**
 * Calls Brevo API to update CITY/STATE/ZIP/JOB_KEYWORD on an existing contact.
 */
function brevoUpdateContact(string $apiKey, string $email, string $city, string $state, string $zip, string $jobKeyword): bool
{
    $attributes = array_filter([
        'CITY'        => $city       ?: null,
        'STATE'       => $state      ?: null,
        'ZIP'         => $zip        ?: null,
        'JOB_KEYWORD' => $jobKeyword ?: null,
    ]);

    if (empty($attributes)) {
        return false;
    }

    $url = 'https://api.brevo.com/v3/contacts/' . urlencode($email);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['attributes' => $attributes]),
        CURLOPT_TIMEOUT    => 10,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError !== '') {
        error_log("brevoUpdateContact curl error | email={$email} | {$curlError}");
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("brevoUpdateContact HTTP {$httpCode} | email={$email} | body=" . substr((string) $response, 0, 300));
        return false;
    }

    return true;
}

function syncUpdatedLeadsToBrevo(): void
{
    $esps = pdoFetchAll(
        "
        SELECT id, name, api_key
        FROM esp
        WHERE sync_records = 1
          AND api_key IS NOT NULL
          AND platform = 1
        ORDER BY id ASC
        "
    );

    if (empty($esps)) {
        echo "Nenhum ESP ativo configurado.\n";
        return;
    }

    foreach ($esps as $esp) {
        runContactUpdateForEsp(
            (int)    $esp['id'],
            (string) $esp['name'],
            (string) $esp['api_key']
        );
    }

    echo "Contact update concluído para todos os ESPs.\n";
}

// =========================
// EXECUÇÃO (CLI)
// =========================

if (PHP_SAPI === 'cli') {
    echo "Iniciando contact update -> Brevo\n\n";
    syncUpdatedLeadsToBrevo();
} else {
    echo "Execute via CLI: php brevo_contact_update.php\n";
}
