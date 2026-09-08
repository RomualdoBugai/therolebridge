<?php
// email_clicks_funnel.php
// Dashboard: Clicks funnel (ESP campaign clicks -> link clicks -> button clicks) + monetização
// Tabelas: email_campaigns, job_clicks, job_clicks_out, job_clicks_suspicious, earnings_daily, provider_jobs

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

$pdo = getPdoConnection();

function ratePercent(int $num, int $den): float
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(($num * 100.0) / $den, 2);
}

function suspiciousBotRegex(): string
{
    return 'bot|crawler|spider|preview|fetch|scanner|curl|wget|python|headless|phantom|selenium|httpclient|monitor|validator|google-read-aloud|googlebot|google|bingbot|bing|yahoo|facebookexternalhit|facebot|facebook|meta|twitterbot|twitter|linkedin|slack|discord|telegram|whatsapp|bytespider|petalbot|semrush|ahrefs|mj12|dotbot|blexbot|okhttp';
}

function suspiciousProbablyRightSql(string $alias = 's'): string
{
    $ua = "LOWER(COALESCE($alias.user_agent, ''))";
    $email = "COALESCE(NULLIF($alias.email, ''), '')";
    $keyword = "COALESCE(NULLIF($alias.keyword, ''), '')";
    $city = "COALESCE(NULLIF($alias.city, ''), '')";
    $state = "COALESCE(NULLIF($alias.state, ''), '')";
    $zip = "COALESCE(NULLIF($alias.zip, ''), '')";
    $utmSource = "COALESCE(NULLIF($alias.utm_source, ''), '')";
    $utmCampaign = "COALESCE(NULLIF($alias.utm_campaign, ''), '')";

    return "(
        $ua REGEXP '" . suspiciousBotRegex() . "'
        OR $ua = ''
        OR ($email = '' AND $keyword = '' AND $city = '' AND $state = '' AND $zip = '')
        OR ($utmSource = '' AND $utmCampaign = '' AND $email = '')
        OR $ua LIKE '%facebookexternalhit%'
        OR $ua LIKE '%facebot%'
        OR $ua LIKE '%twitterbot%'
        OR $ua LIKE '%headlesschrome%'
        OR $ua LIKE '%google-read-aloud%'
    )";
}

function initDailyRow(): array
{
    return [
        'esp_clicks'              => 0,
        'link_clicks'             => 0,
        'button_clicks'           => 0,
        'blocked_clicks'          => 0,
        'blocked_probably_right'  => 0,
        'blocked_needs_review'    => 0,
        'provider_clicks'         => 0,
        'earnings_cents'          => 0,
        'expired_clicks'          => 0,
    ];
}

function suspiciousVerdictLabel(
    int $clicks,
    int $uniqueEmails,
    int $uniqueIps,
    int $probablyRightHits,
    int $missingTrackingHits
): string {
    if ($probablyRightHits > 0) {
        return 'Probably correct - bot/preview/scan';
    }

    if ($missingTrackingHits > 0) {
        return 'Probably correct - missing tracking';
    }

    if ($clicks >= 3) {
        return 'Probably correct - repeated clicks';
    }

    if ($clicks >= 2 && ($uniqueEmails <= 1 || $uniqueIps <= 1)) {
        return 'Probably correct - repeated same user/IP';
    }

    return 'Review - normal UA, single/low volume';
}

function suspiciousVerdictClass(string $verdict): string
{
    return str_starts_with($verdict, 'Probably correct') ? 'ok' : 'review';
}


// =====================================
// DAILY ESP CLICKS (email_campaigns)
// =====================================
$sqlEspDaily = "
    SELECT
        DATE(COALESCE(c.scheduled_at, c.created_at_esp, c.created_at_local)) AS day,
        SUM(COALESCE(c.global_unique_clicks, 0))                              AS esp_unique_clicks
    FROM email_campaigns c
    WHERE COALESCE(c.scheduled_at, c.created_at_esp, c.created_at_local)
          BETWEEN :startDateTime AND :endDateTime
    GROUP BY DATE(COALESCE(c.scheduled_at, c.created_at_esp, c.created_at_local))
    ORDER BY day ASC
";
$stmt = $pdo->prepare($sqlEspDaily);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$espDailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// DAILY JOB CLICKS (job_clicks)
// =====================================
$sqlJobClicksDaily = "
    SELECT
        DATE(j.created_at) AS day,
        COUNT(*)           AS link_clicks
    FROM job_clicks j
    WHERE j.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY DATE(j.created_at)
    ORDER BY day ASC
";
$stmt = $pdo->prepare($sqlJobClicksDaily);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$jobClicksDailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// DAILY BLOCKED/SUSPICIOUS CLICKS (job_clicks_suspicious)
// =====================================
$suspiciousProbablyRightCondition = suspiciousProbablyRightSql('s');
$sqlSuspiciousDaily = "
    SELECT
        DATE(s.created_at) AS day,
        COUNT(*) AS blocked_clicks,
        SUM(CASE
            WHEN $suspiciousProbablyRightCondition
            THEN 1 ELSE 0
        END) AS blocked_probably_right,
        SUM(CASE
            WHEN $suspiciousProbablyRightCondition
            THEN 0 ELSE 1
        END) AS blocked_needs_review
    FROM job_clicks_suspicious s
    WHERE s.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY DATE(s.created_at)
    ORDER BY day ASC
";
$stmt = $pdo->prepare($sqlSuspiciousDaily);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$suspiciousDailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// DAILY BUTTON CLICKS (job_clicks_out)
// =====================================
$sqlJobClicksOutDaily = "
    SELECT
        DATE(o.created_at) AS day,
        COUNT(*)           AS button_clicks
    FROM job_clicks_out o
    WHERE o.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY DATE(o.created_at)
    ORDER BY day ASC
";
$stmt = $pdo->prepare($sqlJobClicksOutDaily);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$jobClicksOutDailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// DAILY PROVIDER REVENUE (earnings_daily)
// =====================================
$sqlEarningsDaily = "
    SELECT
        report_date AS day,
        SUM(clicks)         AS provider_clicks,
        SUM(earnings_cents) AS earnings_cents,
        SUM(expired_clicks) AS expired_clicks
    FROM earnings_daily
    WHERE report_date BETWEEN :startDate AND :endDate
    GROUP BY report_date
    ORDER BY day ASC
";
$stmt = $pdo->prepare($sqlEarningsDaily);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$earningsDailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// HOURLY CLICKS (job_clicks + job_clicks_out)
// =====================================
$sqlHourlyClicks = "
    SELECT HOUR(j.created_at) AS hour, COUNT(*) AS link_clicks
    FROM job_clicks j
    WHERE j.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY HOUR(j.created_at)
    ORDER BY hour ASC
";
$stmt = $pdo->prepare($sqlHourlyClicks);
$stmt->execute([':startDateTime' => $startDateTime, ':endDateTime' => $endDateTime]);
$hourlyClicksRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlHourlyClicksOut = "
    SELECT HOUR(o.created_at) AS hour, COUNT(*) AS button_clicks
    FROM job_clicks_out o
    WHERE o.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY HOUR(o.created_at)
    ORDER BY hour ASC
";
$stmt = $pdo->prepare($sqlHourlyClicksOut);
$stmt->execute([':startDateTime' => $startDateTime, ':endDateTime' => $endDateTime]);
$hourlyClicksOutRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hourlyMap = array_fill(0, 24, ['link_clicks' => 0, 'button_clicks' => 0]);
foreach ($hourlyClicksRows as $row) {
    $hourlyMap[(int)$row['hour']]['link_clicks'] = (int)$row['link_clicks'];
}
foreach ($hourlyClicksOutRows as $row) {
    $hourlyMap[(int)$row['hour']]['button_clicks'] = (int)$row['button_clicks'];
}

$hourlyLabels      = [];
$hourlyLinkSeries  = [];
$hourlyOutSeries   = [];
for ($h = 0; $h < 24; $h++) {
    $hourlyLabels[]     = sprintf('%02d:00', $h);
    $hourlyLinkSeries[] = $hourlyMap[$h]['link_clicks'];
    $hourlyOutSeries[]  = $hourlyMap[$h]['button_clicks'];
}

$jsHourlyLabels     = json_encode($hourlyLabels);
$jsHourlyLinkSeries = json_encode($hourlyLinkSeries);
$jsHourlyOutSeries  = json_encode($hourlyOutSeries);

// =====================================
// MERGE DAILY SERIES (Funnel by day + revenue)
// =====================================
$dailyMap = []; // day => daily funnel metrics

foreach ($espDailyRows as $row) {
    $day = $row['day'];
    if (!isset($dailyMap[$day])) {
        $dailyMap[$day] = initDailyRow();
    }
    $dailyMap[$day]['esp_clicks'] = (int)$row['esp_unique_clicks'];
}

foreach ($jobClicksDailyRows as $row) {
    $day = $row['day'];
    if (!isset($dailyMap[$day])) {
        $dailyMap[$day] = initDailyRow();
    }
    $dailyMap[$day]['link_clicks'] = (int)$row['link_clicks'];
}

foreach ($suspiciousDailyRows as $row) {
    $day = $row['day'];
    if (!isset($dailyMap[$day])) {
        $dailyMap[$day] = initDailyRow();
    }
    $dailyMap[$day]['blocked_clicks']         = (int)$row['blocked_clicks'];
    $dailyMap[$day]['blocked_probably_right'] = (int)$row['blocked_probably_right'];
    $dailyMap[$day]['blocked_needs_review']   = (int)$row['blocked_needs_review'];
}

foreach ($jobClicksOutDailyRows as $row) {
    $day = $row['day'];
    if (!isset($dailyMap[$day])) {
        $dailyMap[$day] = initDailyRow();
    }
    $dailyMap[$day]['button_clicks'] = (int)$row['button_clicks'];
}

// earnings_daily
foreach ($earningsDailyRows as $row) {
    $day = $row['day'];
    if (!isset($dailyMap[$day])) {
        $dailyMap[$day] = initDailyRow();
    }
    $dailyMap[$day]['provider_clicks'] = (int)$row['provider_clicks'];
    $dailyMap[$day]['earnings_cents']  = (int)$row['earnings_cents'];
    $dailyMap[$day]['expired_clicks']  = (int)$row['expired_clicks'];
}

// Ordena por data
ksort($dailyMap);

// Totais do período
$totalEspClicks        = 0;
$totalJobClicks        = 0;
$totalJobClicksOut     = 0;
$totalBlockedClicks    = 0;
$totalBlockedRight     = 0;
$totalBlockedReview    = 0;
$totalProviderClicks   = 0;
$totalEarningsCents    = 0;
$totalExpiredClicks    = 0;

// Arrays para Chart.js
$labelsDays            = [];
$espClicksSeries       = [];
$linkClicksSeries      = [];
$outClicksSeries       = [];
$blockedClicksSeries   = [];
$convEspToLinkSeries   = []; // job_clicks / esp_clicks
$convLinkToOutSeries   = []; // job_clicks_out / job_clicks
$providerClicksSeries  = []; // NOVO: provider clicks (earnings_daily)
$revenueUsdSeries      = []; // NOVO: revenue em USD por dia

foreach ($dailyMap as $day => $vals) {
    $esp        = (int)$vals['esp_clicks'];
    $jc         = (int)$vals['link_clicks'];
    $jco        = (int)$vals['button_clicks'];
    $blocked   = (int)$vals['blocked_clicks'];
    $blockedOk = (int)$vals['blocked_probably_right'];
    $blockedRv = (int)$vals['blocked_needs_review'];
    $provClicks = (int)$vals['provider_clicks'];
    $earnCents  = (int)$vals['earnings_cents'];
    $expClicks  = (int)$vals['expired_clicks'];

    $earnUsdRow = $earnCents / 100.0;

    $labelsDays[]            = $day;
    $espClicksSeries[]       = $esp;
    $linkClicksSeries[]      = $jc;
    $outClicksSeries[]       = $jco;
    $blockedClicksSeries[]   = $blocked;
    $convEspToLinkSeries[]   = ratePercent($jc, $esp);
    $convLinkToOutSeries[]   = ratePercent($jco, $jc);
    $providerClicksSeries[]  = $provClicks;
    $revenueUsdSeries[]      = $earnUsdRow;

    $totalEspClicks        += $esp;
    $totalJobClicks        += $jc;
    $totalJobClicksOut     += $jco;
    $totalBlockedClicks    += $blocked;
    $totalBlockedRight     += $blockedOk;
    $totalBlockedReview    += $blockedRv;
    $totalProviderClicks   += $provClicks;
    $totalEarningsCents    += $earnCents;
    $totalExpiredClicks    += $expClicks;
}

// KPIs de conversão no período
$periodConvEspToLink = ratePercent($totalJobClicks, $totalEspClicks);
$periodConvLinkToOut = ratePercent($totalJobClicksOut, $totalJobClicks);
$periodBlockedRate   = ratePercent($totalBlockedClicks, $totalJobClicks + $totalBlockedClicks);
$periodBlockedOkRate = ratePercent($totalBlockedRight, $totalBlockedClicks);

// KPIs de receita
$periodEarningsUsd = $totalEarningsCents / 100.0;
$epcProvider       = $totalProviderClicks > 0
    ? round($periodEarningsUsd / $totalProviderClicks, 4)
    : 0.0;
$epcButton         = $totalJobClicksOut > 0
    ? round($periodEarningsUsd / $totalJobClicksOut, 4)
    : 0.0;

// =====================================
// DETALHE POR DAY / ESP / PROVIDER (job_clicks + job_clicks_out)
// =====================================
$sqlDetail = "
    SELECT
        DATE(j.created_at)                                                   AS day,
        j.utm_source                                                         AS esp_source,
        j.provider                                                           AS provider_slug,
        COALESCE(pj.slug, j.provider)                                        AS provider_name,
        COUNT(*)                                                             AS link_clicks,
        COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN 1 ELSE 0 END), 0)       AS button_clicks
    FROM job_clicks j
    LEFT JOIN job_clicks_out o
        ON o.job_click_id = j.id
    LEFT JOIN provider_jobs pj
        -- FORÇA MESMA COLLATION NOS DOIS LADOS DA COMPARAÇÃO
        ON pj.slug COLLATE utf8mb4_unicode_ci = j.provider COLLATE utf8mb4_unicode_ci
    WHERE j.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY
        DATE(j.created_at),
        j.utm_source,
        j.provider,
        provider_name
    ORDER BY
        day DESC,
        esp_source,
        provider_name
";
$stmt = $pdo->prepare($sqlDetail);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$detailRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// DETALHE DOS BLOQUEADOS AGRUPADOS
// =====================================
$sqlSuspiciousDetail = "
    SELECT
        DATE(s.created_at) AS day,
        COALESCE(NULLIF(s.utm_source, ''), 'n/a') AS esp_source,
        COALESCE(NULLIF(s.provider, ''), 'n/a') AS provider_slug,
        COALESCE(pj.slug, s.provider, 'n/a') AS provider_name,
        COALESCE(NULLIF(s.keyword, ''), 'n/a') AS keyword,
        COALESCE(NULLIF(s.city, ''), 'n/a') AS city,
        COALESCE(NULLIF(s.state, ''), 'n/a') AS state,
        COUNT(*) AS blocked_clicks,
        COUNT(DISTINCT NULLIF(s.email, '')) AS unique_emails,
        COUNT(DISTINCT NULLIF(s.ip_address, '')) AS unique_ips,
        SUM(CASE
            WHEN $suspiciousProbablyRightCondition
            THEN 1 ELSE 0
        END) AS probably_right_hits,
        SUM(CASE
            WHEN COALESCE(NULLIF(s.email, ''), '') = ''
             AND COALESCE(NULLIF(s.keyword, ''), '') = ''
             AND COALESCE(NULLIF(s.city, ''), '') = ''
             AND COALESCE(NULLIF(s.state, ''), '') = ''
             AND COALESCE(NULLIF(s.zip, ''), '') = ''
            THEN 1 ELSE 0
        END) AS missing_tracking_hits,
        MIN(s.created_at) AS first_click_at,
        MAX(s.created_at) AS last_click_at
    FROM job_clicks_suspicious s
    LEFT JOIN provider_jobs pj
        ON pj.slug COLLATE utf8mb4_unicode_ci = s.provider COLLATE utf8mb4_unicode_ci
    WHERE s.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY
        DATE(s.created_at),
        COALESCE(NULLIF(s.utm_source, ''), 'n/a'),
        COALESCE(NULLIF(s.provider, ''), 'n/a'),
        provider_name,
        COALESCE(NULLIF(s.keyword, ''), 'n/a'),
        COALESCE(NULLIF(s.city, ''), 'n/a'),
        COALESCE(NULLIF(s.state, ''), 'n/a')
    ORDER BY
        blocked_clicks DESC,
        day DESC,
        esp_source,
        provider_name
    LIMIT 300
";
$stmt = $pdo->prepare($sqlSuspiciousDetail);
$stmt->execute([
    ':startDateTime' => $startDateTime,
    ':endDateTime'   => $endDateTime,
]);
$suspiciousDetailRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mapa simples para encaixar blocked clicks no detalhe por Day / ESP / Provider.
$blockedByDayEspProvider = [];
foreach ($suspiciousDetailRows as $row) {
    $key = $row['day'] . '|' . $row['esp_source'] . '|' . $row['provider_slug'];
    if (!isset($blockedByDayEspProvider[$key])) {
        $blockedByDayEspProvider[$key] = 0;
    }
    $blockedByDayEspProvider[$key] += (int)$row['blocked_clicks'];
}


// =====================================
// JSON para JS
// =====================================
$jsLabelsDays            = json_encode($labelsDays);
$jsEspClicksSeries       = json_encode($espClicksSeries);
$jsLinkClicksSeries      = json_encode($linkClicksSeries);
$jsOutClicksSeries       = json_encode($outClicksSeries);
$jsBlockedClicksSeries   = json_encode($blockedClicksSeries);
$jsConvEspToLinkSeries   = json_encode($convEspToLinkSeries);
$jsConvLinkToOutSeries   = json_encode($convLinkToOutSeries);
$jsProviderClicksSeries  = json_encode($providerClicksSeries);
$jsRevenueUsdSeries      = json_encode($revenueUsdSeries);

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <style>
        .pill-ok,
        .pill-review {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .pill-ok {
            background: #dcfce7;
            color: #166534;
        }
        .pill-review {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <!-- Main KPIs -->
        <div class="cards">
            <div class="card">
                <div class="card-title">ESP unique clicks (period)</div>
                <div class="card-value"><?= number_format($totalEspClicks) ?></div>
                <div class="card-helper">
                    Sum of <code>global_unique_clicks</code> from <code>email_campaigns</code> in last <?= $days ?> day(s)
                </div>
            </div>
            <div class="card">
                <div class="card-title">Link clicks (period)</div>
                <div class="card-value"><?= number_format($totalJobClicks) ?></div>
                <div class="card-helper">
                    Total rows in <code>job_clicks</code> in last <?= $days ?> day(s)
                </div>
            </div>
            <div class="card">
                <div class="card-title">Blocked clicks (period)</div>
                <div class="card-value"><?= number_format($totalBlockedClicks) ?></div>
                <div class="card-helper">
                    Rows in <code>job_clicks_suspicious</code>. Block rate: <?= $periodBlockedRate ?>%
                </div>
            </div>
            <div class="card">
                <div class="card-title">Blocked probably correct</div>
                <div class="card-value"><?= $periodBlockedOkRate ?>%</div>
                <div class="card-helper">
                    <?= number_format($totalBlockedRight) ?> likely correct / <?= number_format($totalBlockedReview) ?> real review
                </div>
            </div>
            <div class="card">
                <div class="card-title">Button clicks (period)</div>
                <div class="card-value"><?= number_format($totalJobClicksOut) ?></div>
                <div class="card-helper">
                    Total rows in <code>job_clicks_out</code> in last <?= $days ?> day(s)
                </div>
            </div>
            <div class="card">
                <div class="card-title">Conv. ESP → Link</div>
                <div class="card-value"><?= $periodConvEspToLink ?>%</div>
                <div class="card-helper">
                    link_clicks / ESP unique clicks (period)
                </div>
            </div>
            <div class="card">
                <div class="card-title">Conv. Link → Button</div>
                <div class="card-value"><?= $periodConvLinkToOut ?>%</div>
                <div class="card-helper">
                    button_clicks / link_clicks (period)
                </div>
            </div>

            <!-- KPIs de revenue -->
            <div class="card">
                <div class="card-title">Monetized clicks (provider reports)</div>
                <div class="card-value"><?= number_format($totalProviderClicks) ?></div>
                <div class="card-helper">
                    Sum of <code>clicks</code> from <code>earnings_daily</code> in last <?= $days ?> day(s)
                </div>
            </div>
            <div class="card">
                <div class="card-title">Revenue (USD, period)</div>
                <div class="card-value">$<?= number_format($periodEarningsUsd, 2) ?></div>
                <div class="card-helper">
                    Sum of <code>earnings_cents</code> / 100 from <code>earnings_daily</code> in last <?= $days ?> day(s)
                </div>
            </div>
            <div class="card">
                <div class="card-title">EPC (provider clicks)</div>
                <div class="card-value">$<?= number_format($epcProvider, 4) ?></div>
                <div class="card-helper">
                    Revenue / monetized clicks (from <code>earnings_daily</code>)
                </div>
            </div>
            <?php /* se quiser, usa isso também:
        <div class="card">
            <div class="card-title">Earnings per button click</div>
            <div class="card-value">$<?= number_format($epcButton, 4) ?></div>
            <div class="card-helper">
                Revenue / button_clicks (job_clicks_out)
            </div>
        </div>
        */ ?>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- Chart 1: Volume por dia -->
            <div class="chart-card">
                <div class="chart-title">Daily funnel volume</div>
                <div class="chart-helper">
                    ESP unique clicks vs link clicks vs blocked clicks vs button clicks.
                </div>
                <div class="chart-container">
                    <canvas id="funnelVolumeChart"></canvas>
                </div>
            </div>

            <!-- Chart 2: Taxas de conversão por dia -->
            <div class="chart-card">
                <div class="chart-title">Daily funnel conversion</div>
                <div class="chart-helper">
                    ESP → Link (% link_clicks / ESP clicks) e
                    Link → Button (% button_clicks / link_clicks).
                </div>
                <div class="chart-container">
                    <canvas id="funnelConversionChart"></canvas>
                </div>
            </div>

            <!-- Chart 3: Evolução da monetização -->
            <div class="chart-card">
                <div class="chart-title">Daily monetization (provider reports)</div>
                <div class="chart-helper">
                    Receita diária em USD vs provider clicks (earnings_daily).
                </div>
                <div class="chart-container">
                    <canvas id="monetizationChart"></canvas>
                </div>
            </div>

            <!-- Chart 4: Clicks por hora -->
            <div class="chart-card">
                <div class="chart-title">Clicks by hour of day</div>
                <div class="chart-helper">
                    Link clicks (job_clicks) e button clicks (job_clicks_out) agrupados por hora — período selecionado.
                </div>
                <div class="chart-container">
                    <canvas id="hourlyClicksChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Daily table (aggregate) -->
        <div class="table-wrapper">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Daily funnel breakdown (aggregated)</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Um resumo por dia, somando todos os ESPs e providers.<br>
                <strong>ESP clicks</strong> vêm do Brevo (tabela <code>email_campaigns</code>),
                demais colunas vêm de <code>job_clicks</code>, <code>job_clicks_out</code> e <code>earnings_daily</code>.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th class="text-right">ESP clicks</th>
                        <th class="text-right">Link clicks</th>
                        <th class="text-right">Button clicks</th>
                        <th class="text-right">Blocked clicks</th>
                        <th class="text-right">Blocked OK?</th>
                        <th class="text-right">Provider clicks (report)</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">Expired clicks</th>
                        <th class="text-right">Conv ESP → Link %</th>
                        <th class="text-right">Conv Link → Button %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($dailyMap, true) as $day => $vals): ?>
                        <?php
                        $esp        = (int)$vals['esp_clicks'];
                        $jc         = (int)$vals['link_clicks'];
                        $jco        = (int)$vals['button_clicks'];
                        $blocked   = (int)$vals['blocked_clicks'];
                        $blockedOk = (int)$vals['blocked_probably_right'];
                        $blockedRv = (int)$vals['blocked_needs_review'];
                        $blockedOkRate = ratePercent($blockedOk, $blocked);
                        $provClicks = (int)$vals['provider_clicks'];
                        $earnCents  = (int)$vals['earnings_cents'];
                        $expClicks  = (int)$vals['expired_clicks'];

                        $earnUsdRow = $earnCents / 100.0;

                        $conv1 = ratePercent($jc, $esp);
                        $conv2 = ratePercent($jco, $jc);

                        $isToday = ($day === $today);
                        ?>
                        <tr>
                            <td>
                                <span class="badge-date"><?= htmlspecialchars($day) ?></span>
                                <?php if ($isToday): ?>
                                    <span class="pill-today">Today</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= number_format($esp) ?></td>
                            <td class="text-right"><?= number_format($jc) ?></td>
                            <td class="text-right"><?= number_format($jco) ?></td>
                            <td class="text-right"><?= number_format($blocked) ?></td>
                            <td class="text-right">
                                <?= $blocked > 0 ? ($blockedOkRate . '% OK / ' . number_format($blockedRv) . ' review') : 'n/a' ?>
                            </td>
                            <td class="text-right"><?= number_format($provClicks) ?></td>
                            <td class="text-right">$<?= number_format($earnUsdRow, 2) ?></td>
                            <td class="text-right"><?= number_format($expClicks) ?></td>
                            <td class="text-right"><?= $conv1 ?>%</td>
                            <td class="text-right"><?= $conv2 ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Detail table: Day / ESP / Provider -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Daily detail by ESP & provider</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Agrupado por <strong>day / ESP (utm_source) / provider</strong>.<br>
                Provider name vem de <code>provider_jobs.name</code> quando existe, senão usa o <code>job_clicks.provider</code>.
                Inclui bloqueados agregados de <code>job_clicks_suspicious</code>. Receita ainda está agregada no topo via <code>earnings_daily</code>.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>ESP source</th>
                        <th>Provider</th>
                        <th class="text-right">Link clicks</th>
                        <th class="text-right">Button clicks</th>
                        <th class="text-right">Blocked clicks</th>
                        <th class="text-right">Conv Link → Button %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailRows as $row): ?>
                        <?php
                        $day         = $row['day'];
                        $espSource   = $row['esp_source'] ?: 'n/a';
                        $provName    = $row['provider_name'] ?: $row['provider_slug'];
                        $linkClicks  = (int)$row['link_clicks'];
                        $btnClicks   = (int)$row['button_clicks'];
                        $blockedKey  = $day . '|' . $espSource . '|' . ($row['provider_slug'] ?: 'n/a');
                        $blockedDet  = $blockedByDayEspProvider[$blockedKey] ?? 0;
                        $convDetail  = ratePercent($btnClicks, $linkClicks);
                        ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($day) ?></span></td>
                            <td><?= htmlspecialchars($espSource) ?></td>
                            <td><?= htmlspecialchars($provName) ?></td>
                            <td class="text-right"><?= number_format($linkClicks) ?></td>
                            <td class="text-right"><?= number_format($btnClicks) ?></td>
                            <td class="text-right"><?= number_format($blockedDet) ?></td>
                            <td class="text-right"><?= $convDetail ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Suspicious/blocked grouped table -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Blocked clicks grouped</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Agrupado por <strong>day / ESP / provider / keyword / city / state</strong>.<br>
                A coluna <strong>Block verdict</strong> é heurística: bot/preview/social scanner, UA vazio, tracking vazio ou repetição = bloqueio provavelmente correto; review fica só para caso com UA normal e dados suficientes.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>ESP source</th>
                        <th>Provider</th>
                        <th>Keyword</th>
                        <th>Location</th>
                        <th class="text-right">Blocked clicks</th>
                        <th class="text-right">Unique emails</th>
                        <th class="text-right">Unique IPs</th>
                        <th class="text-right">Probably right hits</th>
                        <th>Block verdict</th>
                        <th>First / Last</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suspiciousDetailRows as $row): ?>
                        <?php
                        $blockedClicks = (int)$row['blocked_clicks'];
                        $uniqueEmails  = (int)$row['unique_emails'];
                        $uniqueIps     = (int)$row['unique_ips'];
                        $probablyRightHits = (int)$row['probably_right_hits'];
                        $missingTrackingHits = (int)$row['missing_tracking_hits'];
                        $verdict       = suspiciousVerdictLabel($blockedClicks, $uniqueEmails, $uniqueIps, $probablyRightHits, $missingTrackingHits);
                        $verdictClass  = suspiciousVerdictClass($verdict);
                        $location      = trim(($row['city'] ?: 'n/a') . ', ' . ($row['state'] ?: 'n/a'), ' ,');
                        ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($row['day']) ?></span></td>
                            <td><?= htmlspecialchars($row['esp_source']) ?></td>
                            <td><?= htmlspecialchars($row['provider_name'] ?: $row['provider_slug']) ?></td>
                            <td><?= htmlspecialchars($row['keyword']) ?></td>
                            <td><?= htmlspecialchars($location ?: 'n/a') ?></td>
                            <td class="text-right"><?= number_format($blockedClicks) ?></td>
                            <td class="text-right"><?= number_format($uniqueEmails) ?></td>
                            <td class="text-right"><?= number_format($uniqueIps) ?></td>
                            <td class="text-right"><?= number_format($probablyRightHits) ?></td>
                            <td>
                                <span class="pill-<?= htmlspecialchars($verdictClass) ?>"><?= htmlspecialchars($verdict) ?></span>
                            </td>
                            <td style="font-size: 0.75rem; color: #6b7280;">
                                <?= htmlspecialchars($row['first_click_at']) ?><br>
                                <?= htmlspecialchars($row['last_click_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        const labelsDays = <?= $jsLabelsDays ?>;
        const espClicksSeries = <?= $jsEspClicksSeries ?>;
        const linkClicksSeries = <?= $jsLinkClicksSeries ?>;
        const outClicksSeries = <?= $jsOutClicksSeries ?>;
        const blockedClicksSeries = <?= $jsBlockedClicksSeries ?>;
        const convEspToLinkSeries = <?= $jsConvEspToLinkSeries ?>;
        const convLinkToOutSeries = <?= $jsConvLinkToOutSeries ?>;
        const providerClicksSeries = <?= $jsProviderClicksSeries ?>;
        const revenueUsdSeries = <?= $jsRevenueUsdSeries ?>;
        const hourlyLabels = <?= $jsHourlyLabels ?>;
        const hourlyLinkSeries = <?= $jsHourlyLinkSeries ?>;
        const hourlyOutSeries = <?= $jsHourlyOutSeries ?>;

        // Chart 1: Daily funnel volume
        const ctxFunnelVol = document.getElementById('funnelVolumeChart').getContext('2d');

        new Chart(ctxFunnelVol, {
            type: 'bar',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'ESP unique clicks',
                        data: espClicksSeries
                    },
                    {
                        label: 'Link clicks (job_clicks)',
                        data: linkClicksSeries
                    },
                    {
                        label: 'Blocked clicks (job_clicks_suspicious)',
                        data: blockedClicksSeries
                    },
                    {
                        label: 'Button clicks (job_clicks_out)',
                        data: outClicksSeries
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => Number(value).toLocaleString()
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxRotation: 45,
                            minRotation: 0,
                            callback: function(value, index) {
                                let rawLabel = '';

                                if (typeof this.getLabelForValue === 'function') {
                                    rawLabel = this.getLabelForValue(value);
                                }

                                if (!rawLabel && labelsDays[index]) {
                                    rawLabel = labelsDays[index];
                                }

                                if (!rawLabel && labelsDays[value]) {
                                    rawLabel = labelsDays[value];
                                }

                                if (!rawLabel) {
                                    rawLabel = String(value);
                                }

                                const date = new Date(rawLabel + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return rawLabel;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                const month = date.toLocaleDateString('en-US', {
                                    month: 'short'
                                });

                                const day = date.getDate();

                                return weekday + ' ' + month + ' ' + day;
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: items => {
                                if (!items || !items.length) {
                                    return '';
                                }

                                const label = items[0].label || '';
                                const date = new Date(label + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return label;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                return label + ' (' + weekday + ')';
                            },
                            label: ctx => {
                                return ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Chart 2: Daily funnel conversion
        const ctxFunnelConv = document.getElementById('funnelConversionChart').getContext('2d');

        new Chart(ctxFunnelConv, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'Conv ESP → Link %',
                        data: convEspToLinkSeries,
                        tension: 0.3
                    },
                    {
                        label: 'Conv Link → Button %',
                        data: convLinkToOutSeries,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => Number(value).toFixed(2) + '%'
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxRotation: 45,
                            minRotation: 0,
                            callback: function(value, index) {
                                let rawLabel = '';

                                if (typeof this.getLabelForValue === 'function') {
                                    rawLabel = this.getLabelForValue(value);
                                }

                                if (!rawLabel && labelsDays[index]) {
                                    rawLabel = labelsDays[index];
                                }

                                if (!rawLabel && labelsDays[value]) {
                                    rawLabel = labelsDays[value];
                                }

                                if (!rawLabel) {
                                    rawLabel = String(value);
                                }

                                const date = new Date(rawLabel + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return rawLabel;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                const month = date.toLocaleDateString('en-US', {
                                    month: 'short'
                                });

                                const day = date.getDate();

                                return weekday + ' ' + month + ' ' + day;
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: items => {
                                if (!items || !items.length) {
                                    return '';
                                }

                                const label = items[0].label || '';
                                const date = new Date(label + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return label;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                return label + ' (' + weekday + ')';
                            },
                            label: ctx => {
                                return ctx.dataset.label + ': ' + Number(ctx.raw).toFixed(2) + '%';
                            }
                        }
                    }
                }
            }
        });

        // Chart 3: Daily monetization
        const ctxMonetization = document.getElementById('monetizationChart').getContext('2d');

        new Chart(ctxMonetization, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'Revenue (USD)',
                        data: revenueUsdSeries,
                        yAxisID: 'y',
                        tension: 0.3
                    },
                    {
                        label: 'Provider clicks (report)',
                        data: providerClicksSeries,
                        yAxisID: 'y1',
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        ticks: {
                            callback: value => '$' + Number(value).toFixed(2)
                        }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            callback: value => Number(value).toLocaleString()
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxRotation: 45,
                            minRotation: 0,
                            callback: function(value, index) {
                                let rawLabel = '';

                                if (typeof this.getLabelForValue === 'function') {
                                    rawLabel = this.getLabelForValue(value);
                                }

                                if (!rawLabel && labelsDays[index]) {
                                    rawLabel = labelsDays[index];
                                }

                                if (!rawLabel && labelsDays[value]) {
                                    rawLabel = labelsDays[value];
                                }

                                if (!rawLabel) {
                                    rawLabel = String(value);
                                }

                                const date = new Date(rawLabel + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return rawLabel;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                const month = date.toLocaleDateString('en-US', {
                                    month: 'short'
                                });

                                const day = date.getDate();

                                return weekday + ' ' + month + ' ' + day;
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: items => {
                                if (!items || !items.length) {
                                    return '';
                                }

                                const label = items[0].label || '';
                                const date = new Date(label + 'T00:00:00');

                                if (isNaN(date.getTime())) {
                                    return label;
                                }

                                const weekday = date.toLocaleDateString('en-US', {
                                    weekday: 'short'
                                });

                                return label + ' (' + weekday + ')';
                            },
                            label: ctx => {
                                if (ctx.dataset.label.includes('Revenue')) {
                                    return ctx.dataset.label + ': $' + Number(ctx.raw).toFixed(2);
                                }

                                return ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        // Chart 4: Clicks by hour of day
        const ctxHourly = document.getElementById('hourlyClicksChart').getContext('2d');

        new Chart(ctxHourly, {
            type: 'line',
            data: {
                labels: hourlyLabels,
                datasets: [{
                        label: 'Link clicks (job_clicks)',
                        data: hourlyLinkSeries,
                        tension: 0.3
                    },
                    {
                        label: 'Button clicks (job_clicks_out)',
                        data: hourlyOutSeries,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => Number(value).toLocaleString()
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString()
                        }
                    }
                }
            }
        });
    </script>

    <script src="assets/dashboard.js"></script>
</body>

</html>