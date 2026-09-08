<?php

// Valida se é bot antes de qualquer coisa (proteção básica)
require_once __DIR__ . '/includes/bot_check_v2.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/provider_jobs.php';

$openGridAfterJobClick = random_int(1, 100) <= 10;

if (trim((string) $keyword) === '' && trim((string) $location) === '') {
    $debugPayload = [
        'reason' => 'missing_keyword_and_location',

        'request' => [
            'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
            'host'         => $_SERVER['HTTP_HOST'] ?? '',
            'uri'          => $_SERVER['REQUEST_URI'] ?? '',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'referer'      => $_SERVER['HTTP_REFERER'] ?? '',
            'scheme'       => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http',
        ],

        'get' => $_GET,

        'post_keys' => array_keys($_POST ?? []),

        'tracking' => [
            'provider'     => $provider ?? '',
            'utm_source'   => $utmSource ?? '',
            'utm_medium'   => $utmMedium ?? '',
            'utm_campaign' => $utmCampaign ?? '',
            'utm_id'       => $utmId ?? '',
            'email'        => $email ?? '',
            'keyword'      => $keyword ?? '',
            'location'     => $location ?? '',
            'city'         => $city ?? '',
            'state'        => $state ?? '',
            'zip'          => $zip ?? '',
        ],

        'client' => [
            'ip_address'      => $ipAddress ?? '',
            'user_agent'      => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'accept'          => $_SERVER['HTTP_ACCEPT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            'cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            'x_real_ip'       => $_SERVER['HTTP_X_REAL_IP'] ?? '',
        ],

        'cookies' => [
            'available_keys' => array_keys($_COOKIE ?? []),
        ],

        'time' => [
            'server_time' => date('Y-m-d H:i:s'),
            'timezone'    => date_default_timezone_get(),
        ],
    ];

    insertJobClickSuspicious(
        $provider,
        $utmSource,
        $utmMedium,
        $utmCampaign,
        $utmId,
        $email,
        $keyword,
        $city,
        $state,
        $zip,
        $userAgent,
        $ipAddress
    );

    error_log('Suspicious missing keyword/location: ' . json_encode($debugPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    header('Location: job-grid.php', true, 302);
    exit;
}

// -----------------------------------------------------
// Salvar log de clique (AGORA com utm_id + provider)
// -----------------------------------------------------

$jobClickId = 0;
try {
    $sql = "
        INSERT INTO job_clicks (
            provider,
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
            :provider,
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
        // usa o slug do provider (coerente com a tabela provider_jobs)
        ':provider'     => $provider,
        ':utm_source'   => $utmSource,
        ':utm_medium'   => $utmMedium,
        ':utm_campaign' => $utmCampaign,
        ':utm_id'       => $utmId ?: '',
        ':email'        => $email ?: '',
        ':keyword'      => $keyword,
        ':city'         => $city ?: '',
        ':state'        => $state ?: '',
        ':zip'          => $zip ?: '',
        ':user_agent'   => $userAgent,
        ':ip_address'   => $ipAddress ?: '',
        ':created_at'   => date('Y-m-d H:i:s'),
    ];

    $jobClickId = pdoRunWithReconnect(function (PDO $pdo) use ($sql, $params): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            return (int) $pdo->lastInsertId();
        }

        return 0;
    }, 1); // 1 retry tá bom aqui
} catch (Throwable $e) {
    // Não quebra a experiência do usuário se der erro no log
    error_log('Error inserting job_clicks: ' . $e->getMessage());
}

// -----------------------------------------------------
// Busca uma vaga disponível (via provider/unified API)
// -----------------------------------------------------
$job = null;
$initialProvider = strtolower(trim((string) $provider));

/**
 * 1) First try the provider that already came in the request.
 */
if ($initialProvider !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $initialProvider)) {
    $response = fetchUnifiedJobs(
        $initialProvider,
        $email,
        $keyword,
        $location,
        $state,
        $city,
        0,
        2
    );

    $jobs  = $response['jobs']  ?? [];
    $error = $response['error'] ?? false;

    if (!$error && !empty($jobs)) {
        foreach ($jobs as $candidateJob) {
            if (
                !empty($candidateJob['url']) &&
                filter_var($candidateJob['url'], FILTER_VALIDATE_URL)
            ) {
                $job = $candidateJob;
                $provider = $initialProvider;
                break;
            }
        }
    }
}

/**
 * 2) If no job was found, try fallback providers.
 * Remove the first attempted provider to avoid duplicate calls.
 */
if ($job === null) {
    $providersToTry = getActiveJobProviderSlugs($initialProvider);

    foreach ($providersToTry as $tryProvider) {
        $tryProvider = strtolower(trim((string) $tryProvider));

        if ($tryProvider === '') {
            continue;
        }

        // Avoid trying the same provider twice
        if ($tryProvider === $initialProvider) {
            continue;
        }

        $response = fetchUnifiedJobs(
            $tryProvider,
            $email,
            $keyword,
            $location,
            $state,
            $city,
            0,
            2
        );

        $jobs  = $response['jobs']  ?? [];
        $error = $response['error'] ?? false;

        if ($error || empty($jobs)) {
            continue;
        }

        foreach ($jobs as $candidateJob) {
            if (
                !empty($candidateJob['url']) &&
                filter_var($candidateJob['url'], FILTER_VALIDATE_URL)
            ) {
                $job = $candidateJob;
                $provider = $tryProvider;
                break 2;
            }
        }
    }
}

$providerConfig = getJobProviderConfig($provider);
$jobPartnerName = $providerConfig['name'] ?? 'our job partner';

// URL do grid mantendo os filtros (default_get já monta $linkCompleteHref começando com '?')
$linkCompleteHref = updateQueryStringParam(
    $linkCompleteHref,
    'job_click_id',
    $jobClickId
);

$linkCompleteHref = updateQueryStringParam(
    $linkCompleteHref,
    'provider',
    $provider
);

$gridUrl = 'job-grid.php' . $linkCompleteHref;

// Decide destino final
$jobUrl        = null;
$isGridFallback = false;

if ($job && !empty($job['url']) && filter_var($job['url'], FILTER_VALIDATE_URL)) {
    // Monta URL de clique para tracking antes de redirecionar
    $jobUrl = 'jobs-out.php' . $linkCompleteHref . '&' . http_build_query([
        'click_source' => 'job_page',
        'job_url'      => $job['url'],
        'job_id'       => $job['external_id'] ?? '',
        'job_price'    => $job['score'] ?? '',
    ]);
} else {
    // Já tentou todos os providers ativos. Se não achou, manda para o grid.
    $jobUrl = $gridUrl;
    $isGridFallback = true;
}

// ----------------------------
// Random badge rotation (stable via cookie)
// ----------------------------

// weights (sum doesn't need to be 100, but keep it sane)
$badgeWeights = [
    'none'   => 45,
    'hiring' => 35,
    'new'    => 20,
];

// cookie config
$cookieName = 'jld_badge_variant';
$cookieTtl = 60 * 60 * 8; // 8 horas

// read existing choice
$badgeVariant = $_COOKIE[$cookieName] ?? null;

$valid = array_key_exists($badgeVariant, $badgeWeights);
if (!$valid) {
    // pick weighted random
    $total = array_sum($badgeWeights);
    $r = random_int(1, max(1, $total));
    $acc = 0;

    foreach ($badgeWeights as $k => $w) {
        $acc += $w;
        if ($r <= $acc) {
            $badgeVariant = $k;
            break;
        }
    }

    // store cookie (Lax avoids cross-site issues)
    setcookie($cookieName, $badgeVariant, [
        'expires'  => time() + $cookieTtl,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,       // needs to be readable only by PHP; JS doesn't need it
        'samesite' => 'Lax',
    ]);
}

$keywordTrim = trim((string) $keyword);
$cityTrim    = trim((string) $city);
$stateTrim   = trim((string) $state);

// Headline
if ($keywordTrim && $cityTrim && $stateTrim) {
    $headline = sprintf(
        'New %s jobs near %s, %s',
        ucfirst($keywordTrim),
        $cityTrim,
        $stateTrim
    );
} elseif ($keywordTrim && ($cityTrim || $stateTrim)) {
    $loc       = $cityTrim ?: $stateTrim;
    $headline  = sprintf('New %s jobs near %s', ucfirst($keywordTrim), $loc);
} elseif ($keywordTrim) {
    $headline = sprintf('New %s job opportunities', ucfirst($keywordTrim));
} else {
    $headline = 'New job opportunities near you';
}
?>
<!DOCTYPE html>
<html lang="en" class="light scroll-smooth" dir="ltr">

<?php include __DIR__ . '/partials/head-minimal.php'; ?>

<body class="font-nunito text-base text-slate-900 dark:text-white dark:bg-slate-900">


    <!-- Bloco principal: CTA para ver vagas -->
    <section class="relative md:py-24 py-16">
        <div class="container relative">

            <!-- "Lista" de vagas: aqui é um cartão único levando pro job grid / job partner -->
            <div class="max-w-xl mx-auto text-center mb-4">
                <h2 class="text-lg font-semibold">
                    <?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Click below to see job leads from our partner:
                    <strong><?php echo htmlspecialchars($jobPartnerName, ENT_QUOTES); ?></strong>.
                </p>
            </div>
            <div class="max-w-xl mx-auto">
                <article class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-gray-800 rounded-2xl px-5 py-4 shadow-sm dark:shadow-gray-800">
                    <div class="text-left">
                        <?php if ($badgeVariant === 'hiring'): ?>
                            <span class="hiring-badge mb-3">
                                <span class="icon-wrap" aria-hidden="true">
                                    <i class="ri-arrow-right-up-line"></i>
                                </span>
                                Hiring Now
                            </span>
                        <?php elseif ($badgeVariant === 'new'): ?>
                            <span class="newpost-badge mb-3">
                                <span class="newpost-dot" aria-hidden="true"></span>
                                New Post
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($job['salary_text'])): ?>
                            <span class="salary-badge mb-3">
                                <span class="salary-icon" aria-hidden="true">
                                    <i class="ri-money-dollar-circle-line"></i>
                                </span>
                                <?= $job['salary_text'] ?>
                            </span>
                        <?php endif; ?>
                        <h2 class="text-base font-bold">
                            <?php echo htmlspecialchars($job['title'] ?? 'Matching job opportunity', ENT_QUOTES); ?>
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            <?php if ($locationLabel): ?>
                                <span class="text-slate-400">•</span>
                                <span><?php echo htmlspecialchars($locationLabel, ENT_QUOTES); ?></span>
                            <?php endif; ?>
                        </p>

                        <p class="mt-3 text-xs leading-relaxed text-slate-500 dark:text-slate-300">
                            <?php if ($isGridFallback): ?>
                                On the next page, you’ll see a <?= $siteTitle ?> job grid with curated leads from multiple providers.<br>
                                When you click on a job, we’ll open the full job details on the employer or job board website, where you can apply directly.
                            <?php else: ?>
                                By clicking the button below, you'll see all the details for your matching jobs on the next page.
                            <?php endif; ?>
                        </p>

                        <div class="mt-4 flex justify-center">
                            <a
                                href="<?php echo htmlspecialchars($jobUrl, ENT_QUOTES); ?>"
                                class="inline-flex items-center justify-center px-4 py-2 text-xl font-semibold rounded-lg bg-primary hover:bg-primary-700 text-white transition"
                                rel="noopener"
                                data-grid-fallback="<?php echo $isGridFallback ? '1' : '0'; ?>"
                                onclick="openJobsAndRedirect(this); return false;">
                                <span class="btn-text">Continue to job</span>
                                <i class="ri-arrow-right-up-line ms-1 text-[11px]"></i>
                            </a>
                        </div>
                    </div>
                </article>
            </div>

        </div><!--end container-->
    </section><!--end section-->
</body>
<script>
    const OPEN_GRID_AFTER_JOB_CLICK = <?= $openGridAfterJobClick ? 'true' : 'false'; ?>;
    const GRID_URL = <?= json_encode($gridUrl); ?>;

    function disableJobButton(el) {
        if (el.dataset.clicked === '1') {
            return false;
        }

        el.dataset.clicked = '1';

        el.classList.add('opacity-60', 'cursor-not-allowed', 'pointer-events-none');
        el.setAttribute('aria-disabled', 'true');

        const text = el.querySelector('.btn-text');
        if (text) {
            text.textContent = 'Loading...';
        }

        return true;
    }

    function enableJobButton(el) {
        el.dataset.clicked = '0';

        el.classList.remove('opacity-60', 'cursor-not-allowed', 'pointer-events-none');
        el.removeAttribute('aria-disabled');

        const text = el.querySelector('.btn-text');
        if (text) {
            text.textContent = 'Continue to job';
        }
    }

    window.addEventListener('pageshow', function() {
        document.querySelectorAll('[data-clicked="1"]').forEach(function(el) {
            enableJobButton(el);
        });
    });

    function openJobsAndRedirect(el) {
        if (!disableJobButton(el)) {
            return;
        }

        const url = el.href;
        const isGridFallback = el.getAttribute('data-grid-fallback') === '1';

        if (!OPEN_GRID_AFTER_JOB_CLICK) {
            // New behavior: do not open the grid. Send the user directly to the tracked job link.
            window.location.href = url;
            return;
        }

        if (isGridFallback) {
            // No valid job link was found, so the grid is the fallback only when enabled.
            window.location.href = GRID_URL;
            return;
        }

        // Precisa acontecer imediatamente dentro do clique
        const jobWindow = window.open('about:blank', '_blank');

        if (!jobWindow) {
            // Popup bloqueado: abre a vaga na aba atual para não perder o clique
            window.location.href = url;
            return;
        }

        // Impede acesso da nova página à página original
        jobWindow.opener = null;

        // Envia a nova aba para a vaga
        jobWindow.location.href = url;

        // Aba original vai para o grid
        window.location.href = GRID_URL;
    }
</script>

</html>