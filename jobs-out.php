<?php

// Valida se é bot antes de qualquer coisa (proteção básica)
require_once __DIR__ . '/includes/bot_check_v2.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/provider_jobs.php';

// -----------------------------------------------------
// Salvar log de clique (AGORA com utm_id + provider)
// -----------------------------------------------------

$jobClickId = (int) ($_GET['job_click_id'] ?? 0);

if ($jobClickId === 0) {
    // Preenche UTM se estiver tudo vazio (pra não ficar registro “morto”)
    if ($utmSource === '' && $utmMedium === '' && $utmCampaign === '') {
        $utmSource   = 'direct';
        $utmMedium   = 'site';
        $utmCampaign = 'job_out_fallback';
    }

    // LOCALIZAÇÃO: vem em $_GET['location']
    $rawLocation = $_GET['location'] ?? '';

    // Só sobrescreve se city/state/zip estiverem vazios
    if ($city === '' && $state === '' && $zip === '') {
        [$city, $state, $zip] = parseLocationString($rawLocation);
    }
}

$jobClickOutId = 0;

// Resolve provider/job rotation for repeated real clicks.
// Rule:
// - no recent click: keep original job_url from the front
// - repeated click: use provider with fewer recent clicks
// - if tied: keep current provider and rotate to the next job
$rotationResult = resolveProviderClickRotationTarget(
    (string) $provider,
    $email,
    $keyword,
    $ipAddress,
    $location,
    $state,
    $city,
    $_GET['job_url'] ?? ('job-grid.php' . $linkCompleteHref)
);

$provider  = $rotationResult['provider'];
$targetUrl = $rotationResult['target_url'];

// Salva o clickout
try {
    // When rotation picks a different job, use its ID/price; fall back to GET param for first click.
    $rotatedJobId  = $rotationResult['provider_job_id'] ?? null;
    $providerJobId = $rotatedJobId !== null
        ? trim((string) $rotatedJobId)
        : trim((string) ($_GET['job_id'] ?? ''));

    $rotatedJobPrice  = $rotationResult['provider_job_price'] ?? null;
    $providerJobPrice = $rotatedJobPrice !== null
        ? (int) $rotatedJobPrice
        : (int) ($_GET['job_price'] ?? 0);

    $sql = "
        INSERT INTO job_clicks_out (
            job_click_id,
            provider,
            provider_job_id,
            provider_job_price,
            click_source,
            utm_source,
            utm_medium,
            utm_campaign,
            utm_id,
            email,
            keyword,
            city,
            state,
            zip,
            user_agent,
            ip_address,
            created_at
        ) VALUES (
            :job_click_id,
            :provider,
            :provider_job_id,
            :provider_job_price,
            :click_source,
            :utm_source,
            :utm_medium,
            :utm_campaign,
            :utm_id,
            :email,
            :keyword,
            :city,
            :state,
            :zip,
            :user_agent,
            :ip_address,
            :created_at
        )
    ";

    $params = [
        ':job_click_id'      => $jobClickId,
        ':provider'          => $provider,
        ':provider_job_id'    => $providerJobId !== '' ? $providerJobId : null,
        ':provider_job_price' => $providerJobPrice ?: null,
        ':click_source'      => $clickSource,
        ':utm_source'        => $utmSource,
        ':utm_medium'        => $utmMedium,
        ':utm_campaign'      => $utmCampaign,
        ':utm_id'            => $utmId,
        ':email'             => $email,
        ':keyword'           => $keyword,
        ':city'              => $city,
        ':state'             => $state,
        ':zip'               => $zip,
        ':user_agent'        => $userAgent,
        ':ip_address'        => $ipAddress,
        ':created_at'        => date('Y-m-d H:i:s'),
    ];

    // ID do INSERT em job_clicks_out -> vamos mandar no t1
    $jobClickOutId = pdoRunWithReconnect(function (PDO $pdo) use ($sql, $params): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            return (int) $pdo->lastInsertId();
        }

        return 0;
    }, 1);
} catch (Throwable $e) {
    // Não quebra a experiência do usuário se der erro no log
    error_log('Error inserting job_clicks_out: ' . $e->getMessage());
}

// Appends the provider-specific sub-ID to the outbound URL (t1 for Talroo, source_id for Jooble fallback)
if (!empty($targetUrl) && filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    $targetUrl = appendProviderSubId($provider, $targetUrl, $jobClickOutId, $utmSource);
}

// Redireciona pro Provider ou, se não tiver URL válida, cai no fallback
if (!empty($targetUrl)) {
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

// Fallback hard caso tudo dê errado
header('Location: job-grid.php' . $linkCompleteHref, true, 302);
exit;
