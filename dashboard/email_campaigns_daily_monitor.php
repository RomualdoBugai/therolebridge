<?php
// email_campaigns_daily_monitor.php

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


/**
 * Daily statistics for the last N days (email metrics)
 */
function fetchDailyAggregated(PDO $pdo, string $startDate, string $endDate): array
{
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    $sql = "
        SELECT
            DATE(COALESCE(scheduled_at, created_at_esp))  AS day,
            COALESCE(SUM(global_sent), 0)                 AS sent,
            COALESCE(SUM(global_delivered), 0)            AS delivered,
            COALESCE(SUM(global_hard_bounces), 0)         AS hard_bounces,
            COALESCE(SUM(global_soft_bounces), 0)         AS soft_bounces,
            COALESCE(SUM(global_unique_views), 0)         AS unique_opens,
            COALESCE(SUM(global_unique_clicks), 0)        AS unique_clicks,
            COALESCE(SUM(global_unsubscriptions), 0)      AS unsubs,
            COALESCE(SUM(global_complaints), 0)           AS complaints,
            COALESCE(SUM(global_apple_mpp_opens), 0)      AS apple_mpp
        FROM email_campaigns
        WHERE COALESCE(scheduled_at, created_at_esp)
            BETWEEN :startDateTime AND :endDateTime
        GROUP BY DATE(COALESCE(scheduled_at, created_at_esp))
        ORDER BY day ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDateTime' => $startDateTime,
        ':endDateTime'   => $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Daily revenue + monetized clicks (earnings_daily)
 * Mesmo padrão da email_revenue_dashboard.php
 */
function fetchDailyRevenue(PDO $pdo, string $startDate, string $endDate): array
{
    $sql = "
        SELECT
            report_date AS day,
            SUM(clicks)         AS day_clicks,
            SUM(earnings_cents) AS day_earnings_cents
        FROM earnings_daily
        WHERE report_date BETWEEN :startDate AND :endDate
        GROUP BY report_date
        ORDER BY report_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDate' => $startDate,
        ':endDate'   => $endDate,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Provider distribution from first click (job_clicks).
 * This shows the provider originally requested by jobs.php/email click.
 */
function fetchDailyJobClicksByProvider(PDO $pdo, string $startDate, string $endDate): array
{
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    $sql = "
        SELECT
            x.click_date,
            x.provider,
            x.total_clicks,
            ROUND((x.total_clicks * 100.0) / NULLIF(t.day_total, 0), 2) AS provider_percent
        FROM (
            SELECT
                DATE(created_at) AS click_date,
                COALESCE(NULLIF(provider, ''), 'unknown') AS provider,
                COUNT(*) AS total_clicks
            FROM job_clicks
            WHERE created_at BETWEEN :startDateTimeProvider AND :endDateTimeProvider
            GROUP BY DATE(created_at), COALESCE(NULLIF(provider, ''), 'unknown')
        ) x
        JOIN (
            SELECT
                DATE(created_at) AS click_date,
                COUNT(*) AS day_total
            FROM job_clicks
            WHERE created_at BETWEEN :startDateTimeTotal AND :endDateTimeTotal
            GROUP BY DATE(created_at)
        ) t ON t.click_date = x.click_date
        ORDER BY x.click_date DESC, x.total_clicks DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDateTimeProvider' => $startDateTime,
        ':endDateTimeProvider'   => $endDateTime,
        ':startDateTimeTotal'    => $startDateTime,
        ':endDateTimeTotal'      => $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Provider distribution from outbound click (job_clicks_out).
 * This shows the final provider actually used after fallback.
 */
function fetchDailyJobClicksOutByProvider(PDO $pdo, string $startDate, string $endDate): array
{
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    $sql = "
        SELECT
            x.click_date,
            x.provider,
            x.total_clicks_out,
            ROUND((x.total_clicks_out * 100.0) / NULLIF(t.day_total, 0), 2) AS provider_percent
        FROM (
            SELECT
                DATE(created_at) AS click_date,
                COALESCE(NULLIF(provider, ''), 'unknown') AS provider,
                COUNT(*) AS total_clicks_out
            FROM job_clicks_out
            WHERE created_at BETWEEN :startDateTimeProvider AND :endDateTimeProvider
            GROUP BY DATE(created_at), COALESCE(NULLIF(provider, ''), 'unknown')
        ) x
        JOIN (
            SELECT
                DATE(created_at) AS click_date,
                COUNT(*) AS day_total
            FROM job_clicks_out
            WHERE created_at BETWEEN :startDateTimeTotal AND :endDateTimeTotal
            GROUP BY DATE(created_at)
        ) t ON t.click_date = x.click_date
        ORDER BY x.click_date DESC, x.total_clicks_out DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDateTimeProvider' => $startDateTime,
        ':endDateTimeProvider'   => $endDateTime,
        ':startDateTimeTotal'    => $startDateTime,
        ':endDateTimeTotal'      => $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fallback provider changes: provider requested in job_clicks vs provider actually used in job_clicks_out.
 */
function fetchProviderSwitches(PDO $pdo, string $startDate, string $endDate, int $limit = 200): array
{
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    $sql = "
        SELECT
            jc.id AS job_click_id,
            jco.id AS job_click_out_id,
            jc.provider AS provider_requested,
            jco.provider AS provider_used,
            jc.email,
            jc.utm_source,
            jc.utm_medium,
            jc.utm_campaign,
            jc.utm_id,
            jc.created_at AS click_created_at,
            jco.created_at AS out_created_at
        FROM job_clicks_out jco
        JOIN job_clicks jc
            ON jc.id = jco.job_click_id
        WHERE jco.job_click_id IS NOT NULL
          AND jc.provider IS NOT NULL
          AND jco.provider IS NOT NULL
          AND jc.provider <> jco.provider
          AND jco.created_at BETWEEN :startDateTime AND :endDateTime
        ORDER BY jco.created_at DESC
        LIMIT " . (int)$limit . "
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDateTime' => $startDateTime,
        ':endDateTime'   => $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Daily fallback summary.
 */
function fetchProviderSwitchSummary(PDO $pdo, string $startDate, string $endDate): array
{
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    $sql = "
        SELECT
            DATE(jco.created_at) AS click_date,
            COUNT(*) AS switched_clicks
        FROM job_clicks_out jco
        JOIN job_clicks jc
            ON jc.id = jco.job_click_id
        WHERE jco.job_click_id IS NOT NULL
          AND jc.provider IS NOT NULL
          AND jco.provider IS NOT NULL
          AND jc.provider <> jco.provider
          AND jco.created_at BETWEEN :startDateTime AND :endDateTime
        GROUP BY DATE(jco.created_at)
        ORDER BY click_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':startDateTime' => $startDateTime,
        ':endDateTime'   => $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Carrega dados
$dailyRows              = fetchDailyAggregated($pdo, $startDate, $endDate);
$revenueRows            = fetchDailyRevenue($pdo, $startDate, $endDate);
$jobClickProviderRows   = fetchDailyJobClicksByProvider($pdo, $startDate, $endDate);
$jobOutProviderRows     = fetchDailyJobClicksOutByProvider($pdo, $startDate, $endDate);
$providerSwitchRows     = fetchProviderSwitches($pdo, $startDate, $endDate, 200);
$providerSwitchSummary  = fetchProviderSwitchSummary($pdo, $startDate, $endDate);

// Mapas por dia (revenue + monetized clicks)
$revenueByDay         = [];
$monetizedClicksByDay = [];
$totalRevenueUsd      = 0.0;
$totalMonetizedClicks = 0;

foreach ($revenueRows as $r) {
    $day       = $r['day'];
    $dayClicks = (int)$r['day_clicks'];
    $dayCents  = (int)$r['day_earnings_cents'];

    $dayUsd = $dayCents / 100.0;

    $revenueByDay[$day]         = $dayUsd;
    $monetizedClicksByDay[$day] = $dayClicks;

    $totalRevenueUsd      += $dayUsd;
    $totalMonetizedClicks += $dayClicks;
}

// Provider / fallback aggregates
$totalJobClicks = 0;
foreach ($jobClickProviderRows as $r) {
    $totalJobClicks += (int)$r['total_clicks'];
}

$totalJobClicksOut = 0;
foreach ($jobOutProviderRows as $r) {
    $totalJobClicksOut += (int)$r['total_clicks_out'];
}

$totalProviderSwitches = 0;
$providerSwitchesByDay = [];
foreach ($providerSwitchSummary as $r) {
    $day = $r['click_date'];
    $switched = (int)$r['switched_clicks'];
    $providerSwitchesByDay[$day] = $switched;
    $totalProviderSwitches += $switched;
}

$providerSwitchRate = ratePercent($totalProviderSwitches, $totalJobClicksOut);

// Global aggregates (email metrics)
$totalSent = $totalDelivered = $totalUniqueOpens = $totalUniqueClicks = 0;
$totalBounces = $totalUnsubs = $totalComplaints = $totalAppleMPP = 0;

$daysWithData = count($dailyRows);

$labelsDays         = [];
$sentSeries         = [];
$delivSeries        = [];
$uniqueClicksSeries = [];
$uniqueOpensSeries  = [];
$orSeries           = [];
$ctrSeries          = [];
$ctorSeries         = [];
$bouncesSeries      = [];

$today = date('Y-m-d');
$todayStats = [
    'sent'          => 0,
    'delivered'     => 0,
    'unique_opens'  => 0,
    'unique_clicks' => 0,
    'bounces'       => 0,
    'unsubs'        => 0,
];

foreach ($dailyRows as $row) {
    $day          = $row['day'];
    $sent         = (int)$row['sent'];
    $delivered    = (int)$row['delivered'];
    $hard         = (int)$row['hard_bounces'];
    $soft         = (int)$row['soft_bounces'];
    $bounces      = $hard + $soft;
    $uniqueOpens  = (int)$row['unique_opens'];
    $uniqueClicks = (int)$row['unique_clicks'];
    $unsubs       = (int)$row['unsubs'];
    $complaints   = (int)$row['complaints'];
    $appleMPP     = (int)$row['apple_mpp'];

    $or   = ratePercent($uniqueOpens,  $delivered);
    $ctr  = ratePercent($uniqueClicks, $delivered);
    $ctor = ratePercent($uniqueClicks, $uniqueOpens);

    // Series for Chart.js
    $labelsDays[]         = $day;
    $sentSeries[]         = $sent;
    $delivSeries[]        = $delivered;
    $uniqueClicksSeries[] = $uniqueClicks;
    $uniqueOpensSeries[]  = $uniqueOpens;
    $orSeries[]           = $or;
    $ctrSeries[]          = $ctr;
    $ctorSeries[]         = $ctor;
    $bouncesSeries[]      = $bounces;

    // Totais
    $totalSent          += $sent;
    $totalDelivered     += $delivered;
    $totalUniqueOpens   += $uniqueOpens;
    $totalUniqueClicks  += $uniqueClicks;
    $totalBounces       += $bounces;
    $totalUnsubs        += $unsubs;
    $totalComplaints    += $complaints;
    $totalAppleMPP      += $appleMPP;

    // Hoje
    if ($day === $today) {
        $todayStats = [
            'sent'          => $sent,
            'delivered'     => $delivered,
            'unique_opens'  => $uniqueOpens,
            'unique_clicks' => $uniqueClicks,
            'bounces'       => $bounces,
            'unsubs'        => $unsubs,
        ];
    }
}

// KPIs de período (email)
$periodOR   = ratePercent($totalUniqueOpens,  $totalDelivered);
$periodCTR  = ratePercent($totalUniqueClicks, $totalDelivered);
$periodCTOR = ratePercent($totalUniqueClicks, $totalUniqueOpens);

// EPC global baseado em clicks de email (funnel de ESP)
$globalEPC = ($totalUniqueClicks > 0)
    ? round($totalRevenueUsd / $totalUniqueClicks, 4)
    : 0.0;

// Médias diárias (se quiser usar depois)
$avgSent      = $daysWithData ? round($totalSent / $daysWithData, 0) : 0;
$avgDelivered = $daysWithData ? round($totalDelivered / $daysWithData, 0) : 0;
$avgOR        = $daysWithData ? round(array_sum($orSeries) / $daysWithData, 2) : 0;
$avgCTR       = $daysWithData ? round(array_sum($ctrSeries) / $daysWithData, 2) : 0;

// JSON para JS
$jsLabelsDays   = json_encode($labelsDays);
$jsSentSeries         = json_encode($sentSeries);
$jsDelivSeries        = json_encode($delivSeries);
$jsUniqueClicksSeries = json_encode($uniqueClicksSeries);
$jsUniqueOpensSeries  = json_encode($uniqueOpensSeries);
$jsORSeries           = json_encode($orSeries);
$jsCTRSeries    = json_encode($ctrSeries);
$jsCTORSeries   = json_encode($ctorSeries);
$jsBounceSeries = json_encode($bouncesSeries);
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <!-- Main KPIs -->
        <div class="cards">
            <!-- Sends in period -->
            <div class="card">
                <div class="card-title">Sends in period</div>
                <div class="card-value"><?= number_format($totalSent) ?></div>
                <div class="card-helper">Total global_sent in <?= $days ?> day(s)</div>
            </div>

            <!-- Unique clicks in period -->
            <div class="card">
                <div class="card-title">Unique clicks in period</div>
                <div class="card-value"><?= number_format($totalUniqueClicks) ?></div>
                <div class="card-helper">Sum of global_unique_clicks</div>
            </div>

            <!-- Revenue in period -->
            <div class="card">
                <div class="card-title">Revenue in period</div>
                <div class="card-value">$<?= number_format($totalRevenueUsd, 2) ?></div>
                <div class="card-helper">Sum of earnings_cents / 100 (earnings_daily)</div>
            </div>

            <!-- Global EPC (period) -->
            <div class="card">
                <div class="card-title">Global EPC (period)</div>
                <div class="card-value">$<?= number_format($globalEPC, 4) ?></div>
                <div class="card-helper">Revenue / unique email clicks</div>
            </div>

            <!-- AVG OR / CTR / CTOR -->
            <div class="card">
                <div class="card-title">Avg OR (period)</div>
                <div class="card-value"><?= $periodOR ?>%</div>
                <div class="card-helper">unique_opens / delivered</div>
            </div>
            <div class="card">
                <div class="card-title">Avg CTR (period)</div>
                <div class="card-value"><?= $periodCTR ?>%</div>
                <div class="card-helper">unique_clicks / delivered</div>
            </div>
            <div class="card">
                <div class="card-title">Avg CTOR (period)</div>
                <div class="card-value"><?= $periodCTOR ?>%</div>
                <div class="card-helper">unique_clicks / unique_opens</div>
            </div>

            <!-- Unsubs in period -->
            <div class="card">
                <div class="card-title">Unsubs in period</div>
                <div class="card-value"><?= number_format($totalUnsubs) ?></div>
                <div class="card-helper">global_unsubscriptions</div>
            </div>

            <!-- Monetized clicks (provider side) -->
            <div class="card">
                <div class="card-title">Monetized clicks</div>
                <div class="card-value"><?= number_format($totalMonetizedClicks) ?></div>
                <div class="card-helper">Sum of clicks in earnings_daily</div>
            </div>

            <!-- Job clicks requested -->
            <div class="card">
                <div class="card-title">Job clicks requested</div>
                <div class="card-value"><?= number_format($totalJobClicks) ?></div>
                <div class="card-helper">Rows in job_clicks by requested provider</div>
            </div>

            <!-- Job clicks out -->
            <div class="card">
                <div class="card-title">Job clicks out</div>
                <div class="card-value"><?= number_format($totalJobClicksOut) ?></div>
                <div class="card-helper">Rows in job_clicks_out by final provider</div>
            </div>

            <!-- Provider fallback switches -->
            <div class="card">
                <div class="card-title">Provider switches</div>
                <div class="card-value"><?= number_format($totalProviderSwitches) ?></div>
                <div class="card-helper"><?= number_format($providerSwitchRate, 2) ?>% of job_clicks_out changed provider</div>
            </div>

            <!-- Brevo Credits shortcut -->
            <a href="brevo_credits.php" class="card" style="text-decoration:none;cursor:pointer;">
                <div class="card-title">Brevo Credits</div>
                <div class="card-value" style="font-size:1.4rem;">Check →</div>
                <div class="card-helper">Ver créditos restantes e projeção por conta</div>
            </a>

            <!-- Provider Clicks shortcut -->
            <a href="provider_clicks.php<?= htmlspecialchars($daysQuery) ?>" class="card" style="text-decoration:none;cursor:pointer;">
                <div class="card-title">Provider Clicks</div>
                <div class="card-value" style="font-size:1.4rem;">Check →</div>
                <div class="card-helper">Leads por provider que clicaram em vagas</div>
            </a>

            <!-- Campaign Schedule shortcut -->
            <a href="brevo_schedule.php" class="card" style="text-decoration:none;cursor:pointer;">
                <div class="card-title">Campaign Schedule</div>
                <div class="card-value" style="font-size:1.4rem;">Check →</div>
                <div class="card-helper">Campanhas agendadas nos próximos 7 dias</div>
            </a>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- Chart 1: Sends and deliveries per day -->
            <div class="chart-card">
                <div class="chart-title">Daily volume (Sent vs Delivered)</div>
                <div class="chart-helper">
                    Distribution of sends/deliveries by day in the last <?= $days ?> day(s).
                </div>
                <div class="chart-container">
                    <canvas id="volumeChart"></canvas>
                </div>
            </div>

             <!-- Chart 4: OR / CTR / CTOR per day -->
            <div class="chart-card">
                <div class="chart-title">Daily rates (OR, CTR, CTOR)</div>
                <div class="chart-helper">
                    OR = opens / delivered · CTR = clicks / delivered · CTOR = clicks / opens
                </div>
                <div class="chart-container">
                    <canvas id="ratesChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Daily table -->
        <div class="table-wrapper">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Daily breakdown</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Each row represents the aggregated metrics of all campaigns sent on that day.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th class="text-right">Sent</th>
                        <th class="text-right">Delivered</th>
                        <th class="text-right">Unique Opens</th>
                        <th class="text-right">Unique Clicks</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">EPC (USD)</th>
                        <th class="text-right">Bounces</th>
                        <th class="text-right">Unsubs</th>
                        <th class="text-right">Complaints</th>
                        <th class="text-right">Apple MPP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($dailyRows) as $row): ?>
                        <?php
                        $day          = $row['day'];
                        $sent         = (int)$row['sent'];
                        $delivered    = (int)$row['delivered'];
                        $hard         = (int)$row['hard_bounces'];
                        $soft         = (int)$row['soft_bounces'];
                        $bounces      = $hard + $soft;
                        $uniqueOpens  = (int)$row['unique_opens'];
                        $uniqueClicks = (int)$row['unique_clicks'];
                        $unsubs       = (int)$row['unsubs'];
                        $complaints   = (int)$row['complaints'];
                        $appleMPP     = (int)$row['apple_mpp'];

                        $or   = ratePercent($uniqueOpens,  $delivered);
                        $ctr  = ratePercent($uniqueClicks, $delivered);
                        $ctor = ratePercent($uniqueClicks, $uniqueOpens);

                        $dayRevenueUsd = $revenueByDay[$day] ?? 0.0;
                        $dayEpc        = ($uniqueClicks > 0)
                            ? round($dayRevenueUsd / $uniqueClicks, 4)
                            : 0.0;

                        $isToday = ($day === $today);
                        ?>
                        <tr>
                            <td>
                                <span class="badge-date"><?= htmlspecialchars($day) ?></span>
                                <?php if ($isToday): ?>
                                    <span class="pill-today">Today</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= number_format($sent) ?></td>
                            <td class="text-right"><?= number_format($delivered) ?></td>
                            <td class="text-right"><?= number_format($uniqueOpens) ?></td>
                            <td class="text-right"><?= number_format($uniqueClicks) ?></td>
                            <td class="text-right">$<?= number_format($dayRevenueUsd, 2) ?></td>
                            <td class="text-right">$<?= number_format($dayEpc, 4) ?></td>
                            <td class="text-right"><?= number_format($bounces) ?></td>
                            <td class="text-right"><?= number_format($unsubs) ?></td>
                            <td class="text-right"><?= number_format($complaints) ?></td>
                            <td class="text-right"><?= number_format($appleMPP) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Provider requested distribution -->
        <div class="table-wrapper" style="margin-top: 18px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Provider requested by day</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Source: job_clicks. This is the provider originally requested before fallback.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Provider</th>
                        <th class="text-right">Clicks</th>
                        <th class="text-right">Provider %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobClickProviderRows as $row): ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($row['click_date']) ?></span></td>
                            <td><?= htmlspecialchars($row['provider']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['total_clicks']) ?></td>
                            <td class="text-right"><?= number_format((float)$row['provider_percent'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Provider used distribution -->
        <div class="table-wrapper" style="margin-top: 18px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Provider used by day</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Source: job_clicks_out. This is the final provider used after fallback.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Provider</th>
                        <th class="text-right">Clicks out</th>
                        <th class="text-right">Provider %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobOutProviderRows as $row): ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($row['click_date']) ?></span></td>
                            <td><?= htmlspecialchars($row['provider']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['total_clicks_out']) ?></td>
                            <td class="text-right"><?= number_format((float)$row['provider_percent'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Provider fallback changes -->
        <div class="table-wrapper" style="margin-top: 18px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Provider fallback changes</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Last 200 rows where job_clicks.provider is different from job_clicks_out.provider.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Out time</th>
                        <th class="text-right">Click ID</th>
                        <th class="text-right">Out ID</th>
                        <th>Requested</th>
                        <th>Used</th>
                        <th>Email</th>
                        <th>UTM Source</th>
                        <th>UTM Campaign</th>
                        <th class="text-right">UTM ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($providerSwitchRows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['out_created_at']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['job_click_id']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['job_click_out_id']) ?></td>
                            <td><?= htmlspecialchars($row['provider_requested']) ?></td>
                            <td><?= htmlspecialchars($row['provider_used']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['utm_source']) ?></td>
                            <td><?= htmlspecialchars($row['utm_campaign']) ?></td>
                            <td class="text-right"><?= htmlspecialchars($row['utm_id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        const labelsDays = <?= $jsLabelsDays ?>;
        const sentSeries = <?= $jsSentSeries ?>;
        const delivSeries = <?= $jsDelivSeries ?>;
        const orSeries = <?= $jsORSeries ?>;
        const ctrSeries = <?= $jsCTRSeries ?>;
        const ctorSeries = <?= $jsCTORSeries ?>;

        // Volume chart (sent vs delivered)
        const ctxVolume = document.getElementById('volumeChart').getContext('2d');

        new Chart(ctxVolume, {
            type: 'bar',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'Sent',
                        data: sentSeries
                    },
                    {
                        label: 'Delivered',
                        data: delivSeries
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
                            callback: function(value) {
                                const rawLabel = this.getLabelForValue(value);
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

        // Rates chart (OR, CTR, CTOR)
        const ctxRates = document.getElementById('ratesChart').getContext('2d');

        new Chart(ctxRates, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'OR %',
                        data: orSeries,
                        tension: 0.3
                    },
                    {
                        label: 'CTR %',
                        data: ctrSeries,
                        tension: 0.3
                    },
                    {
                        label: 'CTOR %',
                        data: ctorSeries,
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
                            callback: function(value) {
                                const rawLabel = this.getLabelForValue(value);
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

    </script>

    <script src="assets/dashboard.js"></script>
</body>

</html>