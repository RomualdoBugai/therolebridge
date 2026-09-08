<?php
// =========================
// INCLUDES
// =========================
require_once __DIR__ . '/../includes/config.php';

// =========================
// CONFIGURAÇÕES
// =========================

const BATCH_SIZE = 10;
const BREVO_BASE_URL = 'https://api.brevo.com/v3';

// =========================
// FUNÇÕES
// =========================

function fetchRecordLeadsBatchForEsp(int $limit = BATCH_SIZE, int $offset = 0): array
{
    $sql = "
        SELECT
            rl.id,
            rl.provider_data_id,
            rl.email,
            rl.first_name,
            rl.last_name,
            rl.job_keyword,
            rl.city,
            rl.state,
            rl.zip,
            rl.created_at,
            rl.updated_at
        FROM record_leads rl
        LEFT JOIN record_sync rs
            ON rs.record_lead_id = rl.id
        WHERE rs.record_lead_id IS NULL
          AND rl.is_valid = 1
        ORDER BY
            rl.provider_data_id = 2 DESC,
            rl.provider_data_id = 3 DESC,
            rl.id ASC
        LIMIT :limit OFFSET :offset
    ";

    return pdoRunWithReconnect(function (PDO $pdo) use ($sql, $limit, $offset) {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });
}

function getEspsForLeadSync(): array
{
    return pdoFetchAll(
        "
        SELECT
            id,
            name,
            api_key,
            sync_records,
            sync_list_id,
            sync_records_limit
        FROM esp
        WHERE sync_records = 1
          AND api_key IS NOT NULL
          AND sync_list_id IS NOT NULL
          AND platform = 1
        ORDER BY id ASC
        "
    );
}

function getTodaySyncedCount(int $espId): int
{
    $row = pdoFetchOne(
        "
        SELECT COUNT(*) AS total
        FROM record_sync
        WHERE esp_id = :esp_id
          AND DATE(synced_at) = :today
        ",
        [
            ':esp_id' => $espId,
            ':today'  => date('Y-m-d'),
        ]
    );

    return (int)($row['total'] ?? 0);
}

function mapLeadToBrevoPayload(array $lead, int $listId): array
{
    $attributes = [
        'FIRSTNAME'   => $lead['first_name'] ?? null,
        'LASTNAME'    => $lead['last_name'] ?? null,
        'CITY'        => $lead['city'] ?? null,
        'STATE'       => $lead['state'] ?? null,
        'ZIP'         => $lead['zip'] ?? null,
        'JOB_KEYWORD' => $lead['job_keyword'] ?? null,
    ];

    $attributes = array_filter(
        $attributes,
        static fn($value) => !is_null($value)
    );

    return [
        'updateEnabled' => true,
        'email'         => $lead['email'],
        'attributes'    => $attributes,
        'listIds'       => [(int)$listId],
    ];
}

function brevoPost(string $endpoint, string $apiKey, array $payload): array
{
    $url = rtrim(BREVO_BASE_URL, '/') . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    $decoded = null;
    if ($responseBody !== false && $responseBody !== '') {
        $decoded = json_decode($responseBody, true);
    }

    return [
        'status' => $httpCode,
        'body'   => $decoded,
        'error'  => $curlError ?: null,
    ];
}

function getAllowedStatesForEspBrevo(): array
{
    static $allowedStatesForEsp = null;

    if ($allowedStatesForEsp !== null) {
        return $allowedStatesForEsp;
    }

    $allowedStatesForEsp = [];

    $row = pdoFetchOne(
        "
        SELECT allow_esp_json
        FROM record_config
        ORDER BY id DESC
        LIMIT 1
        "
    );

    if ($row && !empty($row['allow_esp_json'])) {
        $decoded = json_decode((string)$row['allow_esp_json'], true);

        if (is_array($decoded)) {
            $allowedStatesForEsp = array_values(array_unique(array_map(
                static fn($s): string => strtoupper(trim((string)$s)),
                $decoded
            )));
        }
    }

    return $allowedStatesForEsp;
}

function wasRecordSyncedToEsp(int $recordLeadId, int $espId): bool
{
    $row = pdoFetchOne(
        "
        SELECT 1
        FROM record_sync
        WHERE record_lead_id = :record_lead_id
          AND esp_id = :esp_id
        LIMIT 1
        ",
        [
            ':record_lead_id' => $recordLeadId,
            ':esp_id'         => $espId,
        ]
    );

    return $row !== null;
}

function registerRecordSync(int $recordLeadId, int $espId): void
{
    pdoExecute(
        "
        INSERT IGNORE INTO record_sync (record_lead_id, esp_id, synced_at)
        VALUES (:record_lead_id, :esp_id, :synced_at)
        ",
        [
            ':record_lead_id' => $recordLeadId,
            ':esp_id'         => $espId,
            ':synced_at'      => date('Y-m-d H:i:s'),
        ]
    );
}

function syncLeadToEsp(array $lead, int $espId, string $apiKey, int $listId): bool
{
    $recordLeadId = (int)($lead['id'] ?? 0);
    $email = trim((string)($lead['email'] ?? ''));

    if ($recordLeadId <= 0) {
        echo "[SKIP] Lead sem id válido.\n";
        return false;
    }

    if ($email === '') {
        echo "[SKIP] Lead ID {$recordLeadId} sem email.\n";
        return false;
    }

    $allowedStatesForEsp = getAllowedStatesForEspBrevo();
    $state = strtoupper(trim((string)($lead['state'] ?? '')));
    $providerDataId = (int)($lead['provider_data_id'] ?? 0);

    if ($providerDataId !== 2 && $providerDataId !== 3) {
        if ($state === '' || !in_array($state, $allowedStatesForEsp, true)) {
            echo "[SKIP] Lead ID {$recordLeadId} bloqueado por estado não aceito (allow_esp_json).\n";
            return false;
        }
    }

    if (wasRecordSyncedToEsp($recordLeadId, $espId)) {
        echo "[SKIP] Lead ID {$recordLeadId} ({$email}) já sincronizado com ESP {$espId}.\n";
        return false;
    }

    $payload = mapLeadToBrevoPayload($lead, $listId);
    $result = brevoPost('/contacts', $apiKey, $payload);

    $status = (int)$result['status'];
    $body = $result['body'];
    $error = $result['error'];

    if ($error !== null) {
        echo "[ERRO CURL] {$email} | {$error}\n";
        return false;
    }

    if ($status >= 200 && $status < 300) {
        registerRecordSync($recordLeadId, $espId);
        echo "[OK] {$email} (ESP {$espId}, HTTP {$status})\n";
        return true;
    }

    $bodyJson = $body ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'NULL';
    echo "[ERRO API] {$email} | ESP {$espId} | HTTP {$status} | BODY: {$bodyJson}\n";

    return false;
}

function syncAllRecordLeadsToBrevo(): void
{
    $esps = getEspsForLeadSync();

    if (empty($esps)) {
        echo "Nenhum ESP ativo (sync_records = 1) configurado na tabela esp.\n";
        return;
    }

    foreach ($esps as $esp) {
        $espId = (int)$esp['id'];
        $name = (string)($esp['name'] ?? '');
        $apiKey = (string)($esp['api_key'] ?? '');
        $listId = (int)($esp['sync_list_id'] ?? 0);
        $recordsLimit = (int)($esp['sync_records_limit'] ?? 0);

        echo "-------------------------------------------\n";
        echo "Iniciando sincronização record_leads -> ESP [{$espId}] {$name} (lista ID {$listId})\n";

        $alreadyToday = getTodaySyncedCount($espId);

        if ($recordsLimit > 0 && $alreadyToday >= $recordsLimit) {
            echo "Limite diário de {$recordsLimit} registros já foi atingido hoje para o ESP {$espId}.\n";
            continue;
        }

        $remainingLimit = $recordsLimit > 0
            ? ($recordsLimit - $alreadyToday)
            : 0;

        $totalOk = 0;
        $totalFail = 0;
        $offset = 0;

        while (true) {
            $batchLimit = $remainingLimit > 0
                ? min(BATCH_SIZE, max(0, $remainingLimit - $totalOk))
                : BATCH_SIZE;

            if ($batchLimit <= 0) {
                echo "Limite diário de {$recordsLimit} registros atingido para o ESP {$espId}.\n";
                break;
            }

            $leads = fetchRecordLeadsBatchForEsp($batchLimit, $offset);

            if (empty($leads)) {
                echo "=== [ESP {$espId}] Não há mais records ===\n";
                break;
            }

            echo "=== [ESP {$espId}] Processando lote OFFSET {$offset} (" . count($leads) . " registros) ===\n";

            foreach ($leads as $lead) {
                if ($remainingLimit > 0 && $totalOk >= $remainingLimit) {
                    echo "Limite diário de {$recordsLimit} registros atingido para o ESP {$espId}.\n";
                    break 2;
                }

                $ok = syncLeadToEsp($lead, $espId, $apiKey, $listId);

                if ($ok) {
                    $totalOk++;
                } else {
                    $totalFail++;
                }
            }

            $offset += $batchLimit;
        }

        echo "=============================\n";
        echo "Sincronização concluída para ESP {$espId}.\n";
        echo "Sucesso nesta execução: {$totalOk}\n";
        echo "Falhas nesta execução:  {$totalFail}\n\n";
    }

    echo "Sincronização concluída para todos os ESPs ativos.\n";
}

// =========================
// EXECUÇÃO (CLI)
// =========================

if (PHP_SAPI === 'cli') {
    echo "Iniciando sincronização record_leads -> Brevo\n";
    syncAllRecordLeadsToBrevo();
} else {
    echo "Execute este script pela linha de comando (CLI): php sync_record_leads_to_brevo.php\n";
}
