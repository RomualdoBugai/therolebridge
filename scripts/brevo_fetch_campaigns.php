<?php

/**
 * Sincroniza campanhas de email do ESP
 * - Busca campanhas enviadas via /v3/emailCampaigns
 * - Filtra por status=sent e intervalo de datas
 * - Salva/atualiza na tabela email_campaigns
 *
 * Pode rodar via CLI ou dashboard se JLD_ALLOW_WEB_TRIGGER === true
 */

// =========================
// INCLUDES
// =========================
require_once __DIR__ . '/../includes/config.php';

/* =========================
 * FUNÇÃO CHAMADA ESP
 * ========================= */
function callEsp(string $url, string $apiKey): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "api-key: {$apiKey}",
            "accept: application/json",
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Erro cURL ao chamar ESP: {$error}");
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = isset($data['message']) ? (string) $data['message'] : 'Erro desconhecido';
        throw new RuntimeException("Erro ESP HTTP {$httpCode}: {$msg} | Resposta: " . $response);
    }

    if (!is_array($data)) {
        throw new RuntimeException("Resposta inválida da API ESP: {$response}");
    }

    return $data;
}

/* =========================
 * FUNÇÃO UPSERT CAMPANHA
 * ========================= */
function upsertCampaign(int $espId, array $campaign): void
{
    $campaignId = $campaign['id'] ?? null;
    if ($campaignId === null) {
        return;
    }

    $name        = $campaign['name'] ?? null;
    $subject     = $campaign['subject'] ?? null;

    $senderEmail = $campaign['sender']['email'] ?? null;
    $senderName  = $campaign['sender']['name'] ?? null;

    $createdAt   = $campaign['createdAt'] ?? null;
    $modifiedAt  = $campaign['modifiedAt'] ?? null;
    $scheduledAt = $campaign['scheduledAt'] ?? null;

    $global = $campaign['statistics']['globalStats'] ?? [];

    $globalSent               = $global['sent'] ?? null;
    $globalDelivered          = $global['delivered'] ?? null;
    $globalHardBounces        = $global['hardBounces'] ?? null;
    $globalSoftBounces        = $global['softBounces'] ?? null;
    $globalViewed             = $global['viewed'] ?? null;
    $globalUniqueViews        = $global['uniqueViews'] ?? null;
    $globalTrackableViews     = $global['trackableViews'] ?? null;
    $globalOpensRate          = $global['opensRate'] ?? null;
    $globalClickers           = $global['clickers'] ?? null;
    $globalUniqueClicks       = $global['uniqueClicks'] ?? null;
    $globalUnsubscriptions    = $global['unsubscriptions'] ?? null;
    $globalComplaints         = $global['complaints'] ?? null;
    $globalAppleMppOpens      = $global['appleMppOpens'] ?? null;
    $globalEstimatedViews     = $global['estimatedViews'] ?? null;
    $globalTrackableViewsRate = $global['trackableViewsRate'] ?? null;

    $createdAtDb   = $createdAt ? date('Y-m-d H:i:s', strtotime((string) $createdAt)) : null;
    $modifiedAtDb  = $modifiedAt ? date('Y-m-d H:i:s', strtotime((string) $modifiedAt)) : null;
    $scheduledAtDb = $scheduledAt ? date('Y-m-d H:i:s', strtotime((string) $scheduledAt)) : null;

    $params = [
        ':esp_id'                       => $espId,
        ':esp_campaign_id'              => $campaignId,
        ':name'                         => $name,
        ':subject'                      => $subject,
        ':sender_email'                 => $senderEmail,
        ':sender_name'                  => $senderName,
        ':created_at_esp'               => $createdAtDb,
        ':modified_at_esp'              => $modifiedAtDb,
        ':scheduled_at'                 => $scheduledAtDb,
        ':global_sent'                  => $globalSent,
        ':global_delivered'             => $globalDelivered,
        ':global_hard_bounces'          => $globalHardBounces,
        ':global_soft_bounces'          => $globalSoftBounces,
        ':global_viewed'                => $globalViewed,
        ':global_unique_views'          => $globalUniqueViews,
        ':global_trackable_views'       => $globalTrackableViews,
        ':global_opens_rate'            => $globalOpensRate,
        ':global_clickers'              => $globalClickers,
        ':global_unique_clicks'         => $globalUniqueClicks,
        ':global_unsubscriptions'       => $globalUnsubscriptions,
        ':global_complaints'            => $globalComplaints,
        ':global_apple_mpp_opens'       => $globalAppleMppOpens,
        ':global_estimated_views'       => $globalEstimatedViews,
        ':global_trackable_views_rate'  => $globalTrackableViewsRate,
        ':updated_at_local'             => date('Y-m-d H:i:s'),
    ];

    $rowsAffected = pdoExecute(
        "
        UPDATE email_campaigns
        SET
            name                         = :name,
            subject                      = :subject,
            sender_email                 = :sender_email,
            sender_name                  = :sender_name,
            created_at_esp               = :created_at_esp,
            modified_at_esp              = :modified_at_esp,
            scheduled_at                 = :scheduled_at,
            global_sent                  = :global_sent,
            global_delivered             = :global_delivered,
            global_hard_bounces          = :global_hard_bounces,
            global_soft_bounces          = :global_soft_bounces,
            global_viewed                = :global_viewed,
            global_unique_views          = :global_unique_views,
            global_trackable_views       = :global_trackable_views,
            global_opens_rate            = :global_opens_rate,
            global_clickers              = :global_clickers,
            global_unique_clicks         = :global_unique_clicks,
            global_unsubscriptions       = :global_unsubscriptions,
            global_complaints            = :global_complaints,
            global_apple_mpp_opens       = :global_apple_mpp_opens,
            global_estimated_views       = :global_estimated_views,
            global_trackable_views_rate  = :global_trackable_views_rate,
            updated_at_local             = :updated_at_local
        WHERE esp_id = :esp_id
          AND esp_campaign_id = :esp_campaign_id
        ",
        $params
    );

    if ($rowsAffected > 0) {
        return;
    }

    pdoInsertGetId(
        "
        INSERT INTO email_campaigns (
            esp_id,
            esp_campaign_id,
            name,
            subject,
            sender_email,
            sender_name,
            created_at_esp,
            modified_at_esp,
            scheduled_at,
            global_sent,
            global_delivered,
            global_hard_bounces,
            global_soft_bounces,
            global_viewed,
            global_unique_views,
            global_trackable_views,
            global_opens_rate,
            global_clickers,
            global_unique_clicks,
            global_unsubscriptions,
            global_complaints,
            global_apple_mpp_opens,
            global_estimated_views,
            global_trackable_views_rate,
            created_at_local,
            updated_at_local
        ) VALUES (
            :esp_id,
            :esp_campaign_id,
            :name,
            :subject,
            :sender_email,
            :sender_name,
            :created_at_esp,
            :modified_at_esp,
            :scheduled_at,
            :global_sent,
            :global_delivered,
            :global_hard_bounces,
            :global_soft_bounces,
            :global_viewed,
            :global_unique_views,
            :global_trackable_views,
            :global_opens_rate,
            :global_clickers,
            :global_unique_clicks,
            :global_unsubscriptions,
            :global_complaints,
            :global_apple_mpp_opens,
            :global_estimated_views,
            :global_trackable_views_rate,
            :created_at_local,
            :updated_at_local
        )
        ",
        $params + [
            ':created_at_local' => date('Y-m-d H:i:s'),
        ]
    );
}

/* =======================================================
 * BUSCAR CONTAS BREVO ATIVAS
 * ======================================================= */
function getBrevoAccounts(): array
{
    return pdoFetchAll(
        "
        SELECT id, name, api_key, sync_campaign
        FROM esp
        WHERE sync_campaign = 1
          AND api_key IS NOT NULL
        "
    );
}

/* =======================================================
 * SINCRONIZAR UMA CONTA ESPECÍFICA
 * ======================================================= */
function syncEspAccount(int $espId, string $apiKey): void
{
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $endDate = $nowUtc->modify('-60 minutes')->format('Y-m-d\TH:i:s') . '.000Z';

    $daysBack = 10;

    if (PHP_SAPI === 'cli' && isset($GLOBALS['argv'][1]) && is_numeric($GLOBALS['argv'][1])) {
        $daysBack = (int) $GLOBALS['argv'][1];
    } elseif (isset($GLOBALS['BREVO_DAYS_BACK']) && is_numeric($GLOBALS['BREVO_DAYS_BACK'])) {
        $daysBack = (int) $GLOBALS['BREVO_DAYS_BACK'];
    }

    if ($daysBack <= 0) {
        $daysBack = 10;
    }

    $startUtc = clone $nowUtc;
    $startUtc->modify('-' . $daysBack . ' days');
    $startDate = $startUtc->format('Y-m-d\TH:i:s.000\Z');

    $baseUrl = 'https://api.brevo.com/v3/emailCampaigns';

    $limit = 100;
    $offset = 0;
    $totalFetched = 0;

    echo "Sincronizando campanhas enviadas entre {$startDate} e {$endDate}...\n";

    while (true) {
        $query = http_build_query([
            'status'             => 'sent',
            'statistics'         => 'globalStats',
            'startDate'          => $startDate,
            'endDate'            => $endDate,
            'limit'              => $limit,
            'offset'             => $offset,
            'sort'               => 'asc',
            'excludeHtmlContent' => 'true',
        ]);

        $url = "{$baseUrl}?{$query}";
        $data = callEsp($url, $apiKey);

        $campaigns = $data['campaigns'] ?? [];
        $count = (int) ($data['count'] ?? 0);

        if (empty($campaigns) || !is_array($campaigns)) {
            break;
        }

        foreach ($campaigns as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }

            upsertCampaign($espId, $campaign);
            $totalFetched++;
        }

        $offset += $limit;

        if ($offset >= $count) {
            break;
        }
    }

    echo "  - Conta {$espId}: campanhas processadas: {$totalFetched}\n";
}

function startSyncCampaignsFromEsps(): void
{
    try {
        $accounts = getBrevoAccounts();

        if (empty($accounts)) {
            echo "Nenhuma conta Brevo ativa encontrada na tabela esp.\n";
            return;
        }

        foreach ($accounts as $acc) {
            $id = (int) $acc['id'];
            $name = (string) ($acc['name'] ?? '');
            $apiKey = (string) ($acc['api_key'] ?? '');

            echo "Sincronizando conta [{$id}] {$name}...\n";

            try {
                syncEspAccount($id, $apiKey);
            } catch (Throwable $e) {
                echo "  ERRO ao sincronizar conta [{$id}] {$name}: " . $e->getMessage() . "\n";
            }

            echo PHP_EOL;
        }

        echo "Sincronização concluída para todas as contas.\n";
    } catch (Throwable $e) {
        echo "Erro geral na sincronização: " . $e->getMessage() . "\n";
        throw $e;
    }
}

// =========================
// EXECUÇÃO
// =========================
if (PHP_SAPI === 'cli' || (defined('JLD_ALLOW_WEB_TRIGGER') && JLD_ALLOW_WEB_TRIGGER === true)) {
    echo "Iniciando sincronização ESP -> email_campaigns\n";
    startSyncCampaignsFromEsps();
} else {
    echo "Execute este script pela linha de comando (CLI)\n";
}
