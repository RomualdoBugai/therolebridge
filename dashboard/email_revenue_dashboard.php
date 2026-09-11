<?php

declare(strict_types=1);
// email_revenue_dashboard.php
// Dashboard: Revenue (earnings_daily) + botão pra atualizar earnings
// Tabelas: earnings_daily, provider_data

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

$pdo = getPdoConnection();

/**
 * Percent helper
 */
function ratePercent(int $num, int $den): float
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(($num * 100.0) / $den, 2);
}

// ======================================
// SESSION (para flash message do PRG)
// ======================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Lê e limpa a flash message (se houver)
$refreshMessage = $_SESSION['refresh_message'] ?? null;
unset($_SESSION['refresh_message']);

// ======================================
// HANDLE POST: refresh_revenue (PRG)
// ======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_revenue') {

    $msg = '';
    $earningsScript = dirname(__DIR__) . '/scripts/talroo_fetch_earnings.php';

    if (!file_exists($earningsScript)) {
        $msg = 'Error: earnings script not found at ' . $earningsScript;
    } else {
        if (!defined('JLD_ALLOW_WEB_TRIGGER')) {
            define('JLD_ALLOW_WEB_TRIGGER', true);
        }

        ob_start();
        try {
            require $earningsScript;
            $output = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            $msg = 'Error while running earnings script: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Guarda mensagem e REDIRECIONA (PRG)
    $_SESSION['refresh_message'] = $msg;

    // Mantém os mesmos parâmetros (?days=...)
    $redirectUrl = $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirectUrl);
    exit;
}


// ==================================================
// GLOBAL KPIs (período) – earnings_daily
// ==================================================
$sqlGlobal = "
    SELECT
        SUM(clicks)         AS total_clicks,
        SUM(earnings_cents) AS total_earnings_cents,
        SUM(expired_clicks) AS total_expired_clicks,
        MIN(report_date)    AS first_date,
        MAX(report_date)    AS last_date
    FROM earnings_daily
    WHERE report_date BETWEEN :startDate AND :endDate
";
$stmt = $pdo->prepare($sqlGlobal);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$global = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_clicks'         => 0,
    'total_earnings_cents' => 0,
    'total_expired_clicks' => 0,
    'first_date'           => null,
    'last_date'            => null,
];

$totalClicks        = (int)($global['total_clicks'] ?? 0);
$totalEarningsCents = (int)($global['total_earnings_cents'] ?? 0);
$totalExpiredClicks = (int)($global['total_expired_clicks'] ?? 0);
$periodEarningsUsd  = $totalEarningsCents / 100.0;

// EPC global (earnings por click)
$epcGlobal = $totalClicks > 0
    ? round($periodEarningsUsd / $totalClicks, 4)
    : 0.0;

// Avg revenue per calendar day in the selected period
$periodDayCount = max(1, (new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days + 1);
$avgRevenuePerDay = round($periodEarningsUsd / $periodDayCount, 2);

// ==================================================
// LAST IMPORT TIME (MAX(created_at))
// ==================================================
$sqlLastImport = "SELECT MAX(updated_at) AS last_import_at FROM earnings_daily";
$stmt = $pdo->query($sqlLastImport);
$rowLastImport = $stmt->fetch(PDO::FETCH_ASSOC);
$lastImportAt  = $rowLastImport['last_import_at'] ?? null;

// ==================================================
// DAILY SERIES (earnings_daily)
// ==================================================
$sqlDaily = "
    SELECT
        report_date AS day,
        SUM(clicks)         AS day_clicks,
        SUM(earnings_cents) AS day_earnings_cents,
        SUM(expired_clicks) AS day_expired_clicks
    FROM earnings_daily
    WHERE report_date BETWEEN :startDate AND :endDate
    GROUP BY report_date
    ORDER BY report_date ASC
";
$stmt = $pdo->prepare($sqlDaily);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Arrays para Chart.js
$labelsDays       = [];
$revenueUsdSeries = [];
$clicksSeries     = [];
$epcSeries        = [];
$expiredSeries    = [];

foreach ($dailyRows as $r) {
    $day        = $r['day'];
    $dayClicks  = (int)$r['day_clicks'];
    $dayCents   = (int)$r['day_earnings_cents'];
    $dayExpired = (int)$r['day_expired_clicks'];

    $dayUsd = $dayCents / 100.0;
    $dayEpc = $dayClicks > 0
        ? round($dayUsd / $dayClicks, 4)
        : 0.0;

    $labelsDays[]       = $day;
    $revenueUsdSeries[] = $dayUsd;
    $clicksSeries[]     = $dayClicks;
    $epcSeries[]        = $dayEpc;
    $expiredSeries[]    = $dayExpired;
}

// ==================================================
// BREAKDOWN POR PROVIDER POR DIA
// ==================================================
$sqlProviderDetail = "
    SELECT
        e.report_date AS day,
        e.provider_job_id,
        COALESCE(pd.slug, CONCAT('Provider #', e.provider_job_id)) AS provider_name,
        SUM(e.clicks)         AS clicks,
        SUM(e.earnings_cents) AS earnings_cents,
        SUM(e.expired_clicks) AS expired_clicks
    FROM earnings_daily e
    LEFT JOIN provider_jobs pd ON pd.id = e.provider_job_id
    WHERE e.report_date BETWEEN :startDate AND :endDate
    GROUP BY e.report_date, e.provider_job_id, provider_name
    ORDER BY e.report_date DESC, provider_name ASC
";
$stmt = $pdo->prepare($sqlProviderDetail);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);

$providerRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals by day used to calculate provider revenue share inside each day.
$providerDailyTotalsCents = [];

foreach ($providerRows as $providerRow) {
    $providerDay = (string)($providerRow['day'] ?? '');
    $providerCents = (int)($providerRow['earnings_cents'] ?? 0);

    if ($providerDay === '') {
        continue;
    }

    if (!isset($providerDailyTotalsCents[$providerDay])) {
        $providerDailyTotalsCents[$providerDay] = 0;
    }

    $providerDailyTotalsCents[$providerDay] += $providerCents;
}

// ==================================================
// MONTHLY SERIES / TABLE
// ==================================================
$sqlMonthly = "
    SELECT
        DATE_FORMAT(report_date, '%Y-%m') AS month_key,
        MIN(report_date)                  AS first_day,
        MAX(report_date)                  AS last_day,
        SUM(clicks)                       AS month_clicks,
        SUM(earnings_cents)               AS month_earnings_cents,
        SUM(expired_clicks)               AS month_expired_clicks
    FROM earnings_daily
    WHERE report_date BETWEEN :startDate AND :endDate
    GROUP BY DATE_FORMAT(report_date, '%Y-%m')
    ORDER BY month_key DESC
";
$stmt = $pdo->prepare($sqlMonthly);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================================================
// JSON para JS
// ==================================================
$jsLabelsDays       = json_encode($labelsDays);
$jsRevenueUsdSeries = json_encode($revenueUsdSeries);
$jsClicksSeries     = json_encode($clicksSeries);
$jsEpcSeries        = json_encode($epcSeries);
$jsExpiredSeries    = json_encode($expiredSeries);

$todayStr = date('Y-m-d');

// ==================================================
// RESUMO: hoje x hoje semana passada x hoje mês passado
// (independente do filtro de período; sempre datas fixas)
// ==================================================
$compareDates = [
    'today'      => $todayStr,
    'last_week'  => (new DateTimeImmutable($todayStr))->modify('-7 days')->format('Y-m-d'),
    'last_month' => (new DateTimeImmutable($todayStr))->modify('-1 month')->format('Y-m-d'),
];

$sqlCompare = "
    SELECT
        report_date         AS day,
        SUM(clicks)         AS day_clicks,
        SUM(earnings_cents) AS day_earnings_cents,
        SUM(expired_clicks) AS day_expired_clicks
    FROM earnings_daily
    WHERE report_date IN (:dToday, :dWeek, :dMonth)
    GROUP BY report_date
";
$stmt = $pdo->prepare($sqlCompare);
$stmt->execute([
    ':dToday' => $compareDates['today'],
    ':dWeek'  => $compareDates['last_week'],
    ':dMonth' => $compareDates['last_month'],
]);

$compareByDate = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
    $compareByDate[(string)$cr['day']] = $cr;
}

$compareLabels = [
    'today'      => 'Today',
    'last_week'  => 'Today last week',
    'last_month' => 'Today last month',
];

$compareRows = [];
foreach ($compareDates as $key => $dateStr) {
    $row     = $compareByDate[$dateStr] ?? null;
    $clicks  = (int)($row['day_clicks'] ?? 0);
    $cents   = (int)($row['day_earnings_cents'] ?? 0);
    $expired = (int)($row['day_expired_clicks'] ?? 0);
    $usd     = $cents / 100.0;

    $compareRows[$key] = [
        'label'   => $compareLabels[$key],
        'date'    => $dateStr,
        'clicks'  => $clicks,
        'expired' => $expired,
        'usd'     => $usd,
        'epc'     => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <!-- Botão para atualizar revenue -->
        <form method="post" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="refresh_revenue">
            <button type="submit" class="btn-primary">
                Update now
            </button>
            <?php if ($lastImportAt): ?>
                <span style="margin-left:12px;font-size:0.8rem;color:#6b7280;">
                    Last import: <?= htmlspecialchars($lastImportAt) ?>
                </span>
            <?php endif; ?>
        </form>

        <?php if ($refreshMessage !== null): ?>
            <div class="alert" style="margin-bottom:16px;">
                <?= $refreshMessage ?>
            </div>
        <?php endif; ?>

        <!-- Main KPIs -->
        <div class="cards">
            <div class="card">
                <div class="card-title">Total revenue (USD)</div>
                <div class="card-value">$<?= number_format($periodEarningsUsd, 2) ?></div>
                <div class="card-helper">
                    Sum of <code>earnings_cents</code> / 100 in <code>earnings_daily</code>.
                </div>
            </div>
            <div class="card">
                <div class="card-title">Monetized clicks</div>
                <div class="card-value"><?= number_format($totalClicks) ?></div>
                <div class="card-helper">
                    Sum of <code>clicks</code> in <code>earnings_daily</code>.
                </div>
            </div>
            <!-- <div class="card">
            <div class="card-title">Expired clicks</div>
            <div class="card-value"><?= number_format($totalExpiredClicks) ?></div>
            <div class="card-helper">
                Sum of <code>expired_clicks</code> in <code>earnings_daily</code>.
            </div>
        </div> -->
            <div class="card">
                <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span>EPC (global)</span>
                    <a href="email_traffic_split_dashboard.php<?= htmlspecialchars($daysQuery) ?>" style="font-size:0.72rem;font-weight:600;text-decoration:none;color:#2563eb;">Edit Split</a>
                </div>
                <div class="card-value">$<?= number_format($epcGlobal, 4) ?></div>
                <div class="card-helper">
                    Revenue / monetized clicks (período inteiro).
                </div>
            </div>
            <div class="card">
                <div class="card-title">Avg revenue per day</div>
                <div class="card-value">$<?= number_format($avgRevenuePerDay, 2) ?></div>
                <div class="card-helper">
                    Total revenue / <?= (int)$days ?> dia(s).
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- Chart 1: Revenue x clicks por dia -->
            <div class="chart-card">
                <div class="chart-title">Daily revenue & clicks</div>
                <div class="chart-helper">
                    Revenue USD (linha) e monetized clicks (linha) por dia.
                </div>
                <div class="chart-container">
                    <canvas id="revenueClicksChart"></canvas>
                </div>
            </div>

            <!-- Chart 2: EPC e expired clicks por dia -->
            <div class="chart-card">
                <div class="chart-title">Daily EPC & expired clicks</div>
                <div class="chart-helper">
                    EPC (earnings per click) por dia e expired clicks.
                </div>
                <div class="chart-container">
                    <canvas id="epcExpiredChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Detalhe por provider/date -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Daily revenue detail by provider</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Agrupado por <strong>day / provider</strong> (via <code>provider_job_id</code> + <code>provider_data.name</code>).
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Provider</th>
                        <th class="text-right">Monetized clicks</th>
                        <th class="text-right">Expired clicks</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">Share</th>
                        <th class="text-right">EPC (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($providerRows as $r): ?>
                        <?php
                        $day        = $r['day'];
                        $provName   = $r['provider_name'] ?: ('Provider #' . $r['provider_job_id']);
                        $clicks     = (int)$r['clicks'];
                        $cents      = (int)$r['earnings_cents'];
                        $expired    = (int)$r['expired_clicks'];
                        $usd        = $cents / 100.0;
                        $epc        = $clicks > 0 ? round($usd / $clicks, 4) : 0.0;
                        $dayTotalCents = (int)($providerDailyTotalsCents[$day] ?? 0);
                        $sharePercent  = $dayTotalCents > 0
                            ? round(($cents * 100.0) / $dayTotalCents, 2)
                            : 0.0;
                        ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($day) ?></span></td>
                            <td><?= htmlspecialchars($provName) ?></td>
                            <td class="text-right"><?= number_format($clicks) ?></td>
                            <td class="text-right"><?= number_format($expired) ?></td>
                            <td class="text-right">$<?= number_format($usd, 2) ?></td>
                            <td class="text-right"><?= number_format($sharePercent, 2) ?>%</td>
                            <td class="text-right">$<?= number_format($epc, 4) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($providerRows)): ?>
                        <tr>
                            <td colspan="7" class="text-center" style="padding:12px;">
                                No provider-level data in this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabela diária agregada -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Daily revenue breakdown (aggregated)</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Um resumo por dia, somando todos os providers.<br>
                Fonte: <code>earnings_daily</code>.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th class="text-right">Monetized clicks</th>
                        <th class="text-right">Expired clicks</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">EPC (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($dailyRows, true) as $row): ?>
                        <?php
                        $day        = $row['day'];
                        $dayClicks  = (int)$row['day_clicks'];
                        $dayCents   = (int)$row['day_earnings_cents'];
                        $dayExpired = (int)$row['day_expired_clicks'];

                        $dayUsd = $dayCents / 100.0;
                        $dayEpc = $dayClicks > 0
                            ? round($dayUsd / $dayClicks, 4)
                            : 0.0;

                        $isToday = ($day === $todayStr);
                        ?>
                        <tr>
                            <td>
                                <span class="badge-date"><?= htmlspecialchars($day) ?></span>
                                <?php if ($isToday): ?>
                                    <span class="pill-today">Today</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= number_format($dayClicks) ?></td>
                            <td class="text-right"><?= number_format($dayExpired) ?></td>
                            <td class="text-right">$<?= number_format($dayUsd, 2) ?></td>
                            <td class="text-right">$<?= number_format($dayEpc, 4) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($dailyRows)): ?>
                        <tr>
                            <td colspan="5" class="text-center" style="padding:12px;">
                                No data in this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabela mensal agregada -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Monthly earnings summary</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Resumo agrupado por <strong>mês</strong> dentro do período selecionado.<br>
                Fonte: <code>earnings_daily</code>.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Monetized clicks</th>
                        <th class="text-right">Expired clicks</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">EPC (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyRows as $row): ?>
                        <?php
                        $monthKey    = $row['month_key'];
                        $monthClicks = (int)$row['month_clicks'];
                        $monthCents  = (int)$row['month_earnings_cents'];
                        $monthExpire = (int)$row['month_expired_clicks'];
                        $monthUsd    = $monthCents / 100.0;
                        $monthEpc    = $monthClicks > 0
                            ? round($monthUsd / $monthClicks, 4)
                            : 0.0;
                        ?>
                        <tr>
                            <td><span class="badge-date"><?= htmlspecialchars($monthKey) ?></span></td>
                            <td class="text-right"><?= number_format($monthClicks) ?></td>
                            <td class="text-right"><?= number_format($monthExpire) ?></td>
                            <td class="text-right">$<?= number_format($monthUsd, 2) ?></td>
                            <td class="text-right">$<?= number_format($monthEpc, 4) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthlyRows)): ?>
                        <tr>
                            <td colspan="5" class="text-center" style="padding:12px;">
                                No monthly data in this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Resumo rápido: hoje x semana passada x mês passado -->
        <div class="table-wrapper" style="margin-top: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Quick summary — today vs last week vs last month</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Hoje (<?= htmlspecialchars($todayStr) ?>) comparado com o mesmo dia da semana passada e do mês passado.<br>
                Independente do filtro de período. Fonte: <code>earnings_daily</code>.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Day</th>
                        <th class="text-right">Monetized clicks</th>
                        <th class="text-right">Revenue (USD)</th>
                        <th class="text-right">EPC (USD)</th>
                        <th class="text-right">Δ Revenue vs today</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $todayUsd = $compareRows['today']['usd'];
                    foreach ($compareRows as $key => $c):
                        $deltaHtml = '<span style="color:#9ca3af;">—</span>';
                        if ($key !== 'today') {
                            $diff    = $todayUsd - $c['usd'];
                            $pct     = $c['usd'] > 0 ? round(($diff / $c['usd']) * 100, 1) : null;
                            $sign    = $diff >= 0 ? '+' : '−';
                            $color   = $diff >= 0 ? '#16a34a' : '#dc2626';
                            $pctText = $pct !== null ? ' (' . $sign . number_format(abs($pct), 1) . '%)' : '';
                            $deltaHtml = '<span style="color:' . $color . ';">' . $sign . '$' . number_format(abs($diff), 2) . $pctText . '</span>';
                        }
                        $isTodayRow = ($key === 'today');
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($c['label']) ?></strong>
                                <?php if ($isTodayRow): ?>
                                    <span class="pill-today">Today</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge-date"><?= htmlspecialchars($c['date']) ?></span></td>
                            <td class="text-right"><?= number_format($c['clicks']) ?></td>
                            <td class="text-right">$<?= number_format($c['usd'], 2) ?></td>
                            <td class="text-right">$<?= number_format($c['epc'], 4) ?></td>
                            <td class="text-right"><?= $deltaHtml ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        const labelsDays = <?= $jsLabelsDays ?>;
        const revenueUsdSeries = <?= $jsRevenueUsdSeries ?>;
        const clicksSeries = <?= $jsClicksSeries ?>;
        const epcSeries = <?= $jsEpcSeries ?>;
        const expiredSeries = <?= $jsExpiredSeries ?>;

        // Chart 1: Revenue & clicks
        const ctxRevClicks = document.getElementById('revenueClicksChart').getContext('2d');
        new Chart(ctxRevClicks, {
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
                        label: 'Monetized clicks',
                        data: clicksSeries,
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
                            callback: value => '$' + value.toFixed(2)
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
                            callback: value => value.toLocaleString()
                        }
                    },
                    x: {
                        ticks: {
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
                                if (ctx.dataset.label.includes('Revenue')) {
                                    return ctx.dataset.label + ': $' + ctx.formattedValue;
                                }
                                return ctx.dataset.label + ': ' + ctx.formattedValue;
                            }
                        }
                    }
                }
            }
        });

        // Chart 2: EPC & expired
        const ctxEpcExpired = document.getElementById('epcExpiredChart').getContext('2d');

        new Chart(ctxEpcExpired, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [{
                        label: 'EPC (USD)',
                        data: epcSeries,
                        yAxisID: 'y',
                        tension: 0.3
                    },
                    {
                        label: 'Expired clicks',
                        data: expiredSeries,
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
                            callback: value => '$' + Number(value).toFixed(4)
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
                                if (ctx.dataset.label.includes('EPC')) {
                                    return ctx.dataset.label + ': $' + ctx.formattedValue;
                                }

                                return ctx.dataset.label + ': ' + ctx.formattedValue;
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