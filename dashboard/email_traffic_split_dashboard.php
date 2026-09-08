<?php

declare(strict_types=1);
// provider_jobs_traffic_split_dashboard.php
// Dashboard: edit provider_jobs_traffic_split + view EPC by provider.
// Tables: provider_jobs, provider_jobs_traffic_split, earnings_daily

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

$pdo = getPdoConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('trafficSplitDashH')) {
    function trafficSplitDashH($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// ======================================
// SESSION flash message
// ======================================
$flashMessage = $_SESSION['traffic_split_message'] ?? null;
$flashType    = $_SESSION['traffic_split_message_type'] ?? 'success';
unset($_SESSION['traffic_split_message'], $_SESSION['traffic_split_message_type']);


function trafficSplitRedirectSelf(): void
{
    // Keeps the same URL/query params, including ?days=... from the shared header.
    $redirectUrl = $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirectUrl);
    exit;
}

// ==================================================
// HANDLE POST: save traffic split
// ==================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_traffic_split') {
    $weights = $_POST['weights'] ?? [];

    if (!is_array($weights)) {
        $_SESSION['traffic_split_message'] = 'Invalid traffic split payload.';
        $_SESSION['traffic_split_message_type'] = 'error';
        trafficSplitRedirectSelf();
    }

    try {
        $pdo->beginTransaction();

        $checkProviderStmt = $pdo->prepare("SELECT id FROM provider_jobs WHERE id = :provider_job_id LIMIT 1");

        $findSplitStmt = $pdo->prepare("SELECT id FROM provider_jobs_traffic_split WHERE provider_job_id = :provider_job_id LIMIT 1");

        $updateSplitStmt = $pdo->prepare("UPDATE provider_jobs_traffic_split SET weight = :weight, updated_at = CURRENT_TIMESTAMP WHERE provider_job_id = :provider_job_id");

        $insertSplitStmt = $pdo->prepare("INSERT INTO provider_jobs_traffic_split (provider_job_id, weight, updated_at) VALUES(:provider_job_id, :weight, CURRENT_TIMESTAMP)");

        $updatedCount = 0;

        foreach ($weights as $providerJobIdRaw => $weightRaw) {
            $providerJobId = (int)$providerJobIdRaw;
            if ($providerJobId <= 0) {
                continue;
            }

            $weight = (int)$weightRaw;
            if ($weight < 0) {
                $weight = 0;
            }
            if ($weight > 100000) {
                $weight = 100000;
            }

            $checkProviderStmt->execute([':provider_job_id' => $providerJobId]);
            if (!$checkProviderStmt->fetchColumn()) {
                continue;
            }

            $findSplitStmt->execute([':provider_job_id' => $providerJobId]);
            $splitId = $findSplitStmt->fetchColumn();

            if ($splitId) {
                $updateSplitStmt->execute([
                    ':provider_job_id' => $providerJobId,
                    ':weight' => $weight,
                ]);
            } else {
                $insertSplitStmt->execute([
                    ':provider_job_id' => $providerJobId,
                    ':weight' => $weight,
                ]);
            }

            $updatedCount++;
        }

        $pdo->commit();

        $_SESSION['traffic_split_message'] = 'Traffic split saved. Providers updated: ' . $updatedCount . '.';
        $_SESSION['traffic_split_message_type'] = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['traffic_split_message'] = 'Error saving traffic split: ' . $e->getMessage();
        $_SESSION['traffic_split_message_type'] = 'error';
    }

    trafficSplitRedirectSelf();
}

// ==================================================
// GLOBAL KPIs for selected period
// ==================================================
$sqlGlobal = "SELECT SUM(clicks) AS total_clicks, SUM(earnings_cents) AS total_earnings_cents, SUM(expired_clicks) AS total_expired_clicks FROM earnings_daily WHERE report_date BETWEEN :startDate AND :endDate";
$stmt = $pdo->prepare($sqlGlobal);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$global = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalMetricClicks        = (int)($global['total_clicks'] ?? 0);
$totalMetricEarningsCents = (int)($global['total_earnings_cents'] ?? 0);
$totalMetricExpiredClicks = (int)($global['total_expired_clicks'] ?? 0);
$totalMetricRevenueUsd    = $totalMetricEarningsCents / 100.0;
$globalEpc = $totalMetricClicks > 0 ? round($totalMetricRevenueUsd / $totalMetricClicks, 4) : 0.0;

// ==================================================
// PROVIDERS + CURRENT SPLIT + EPC METRICS
// ==================================================
$sqlProviders = "SELECT pj.id AS provider_job_id, COALESCE(pj.slug, CONCAT('Provider #', pj.id)) AS provider_name, COALESCE(ts.weight, 0) AS weight, ts.updated_at AS split_updated_at, COALESCE(m.clicks, 0) AS metric_clicks, COALESCE(m.earnings_cents, 0) AS metric_earnings_cents, COALESCE(m.expired_clicks, 0) AS metric_expired_clicks FROM provider_jobs pj LEFT JOIN provider_jobs_traffic_split ts ON ts.provider_job_id = pj.id LEFT JOIN (SELECT provider_job_id, SUM(clicks) AS clicks, SUM(earnings_cents) AS earnings_cents, SUM(expired_clicks) AS expired_clicks FROM earnings_daily WHERE report_date BETWEEN :startDate AND :endDate GROUP BY provider_job_id) m ON m.provider_job_id = pj.id ORDER BY pj.id ASC";
$stmt = $pdo->prepare($sqlProviders);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
]);
$providerRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalWeight = 0;
$activeProviders = 0;
foreach ($providerRows as $row) {
    $weight = (int)($row['weight'] ?? 0);
    $totalWeight += $weight;
    if ($weight > 0) {
        $activeProviders++;
    }
}

$todayStr = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <?php if ($flashMessage !== null): ?>
            <div class="alert" style="margin-bottom:16px;<?= $flashType === 'error' ? 'background:#fee2e2;color:#991b1b;' : '' ?>">
                <?= trafficSplitDashH($flashMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Main KPIs -->
        <div class="cards">
            <!-- <div class="card">
                <div class="card-title">Providers</div>
                <div class="card-value"><?= number_format(count($providerRows)) ?></div>
                <div class="card-helper">Rows from <code>provider_jobs</code>.</div>
            </div> -->
            <div class="card">
                <div class="card-title">Providers with traffic</div>
                <div class="card-value"><?= number_format($activeProviders) ?></div>
                <div class="card-helper">Providers with <code>weight &gt; 0</code>.</div>
            </div>
            <div class="card">
                <div class="card-title">Total weight</div>
                <div class="card-value"><?= number_format($totalWeight) ?></div>
                <div class="card-helper">Sum of all split weights.</div>
            </div>
            <div class="card">
                <div class="card-title">Revenue</div>
                <div class="card-value">$<?= number_format($totalMetricRevenueUsd, 2) ?></div>
                <div class="card-helper">Selected period revenue.</div>
            </div>
            <div class="card">
                <div class="card-title">Clicks</div>
                <div class="card-value"><?= number_format($totalMetricClicks) ?></div>
                <div class="card-helper">Selected period monetized clicks.</div>
            </div>
            <div class="card">
                <div class="card-title">Global EPC</div>
                <div class="card-value">$<?= number_format($globalEpc, 4) ?></div>
                <div class="card-helper">Revenue / clicks.</div>
            </div>
        </div>

        <div class="table-wrapper">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Traffic split editor</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Edit <code>weight</code>. Split percentage is calculated from total active weight. EPC is only for the selected period.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="save_traffic_split">

                <table>
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th class="text-right">Provider ID</th>
                            <th class="text-right">Weight</th>
                            <th class="text-right">Split %</th>
                            <th class="text-right">Monetized clicks</th>
                            <th class="text-right">Expired clicks</th>
                            <th class="text-right">Revenue (USD)</th>
                            <th class="text-right">EPC (USD)</th>
                            <th class="text-right">Updated at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($providerRows as $row): ?>
                            <?php
                            $providerJobId = (int)$row['provider_job_id'];
                            $providerName  = $row['provider_name'] ?: ('Provider #' . $providerJobId);
                            $weight        = (int)($row['weight'] ?? 0);
                            $splitPercent  = $totalWeight > 0 ? round(($weight * 100.0) / $totalWeight, 2) : 0.0;

                            $clicks     = (int)($row['metric_clicks'] ?? 0);
                            $cents      = (int)($row['metric_earnings_cents'] ?? 0);
                            $expired    = (int)($row['metric_expired_clicks'] ?? 0);
                            $usd        = $cents / 100.0;
                            $epc        = $clicks > 0 ? round($usd / $clicks, 4) : 0.0;

                            $epcStyle = '';
                            if ($clicks > 0 && $globalEpc > 0) {
                                $epcStyle = $epc >= $globalEpc ? 'color:#166534;font-weight:700;' : 'color:#991b1b;font-weight:700;';
                            }
                            ?>
                            <tr>
                                <td><strong><?= trafficSplitDashH($providerName) ?></strong></td>
                                <td class="text-right"><?= number_format($providerJobId) ?></td>
                                <td class="text-right">
                                    <input
                                        type="number"
                                        name="weights[<?= (int)$providerJobId ?>]"
                                        value="<?= (int)$weight ?>"
                                        min="0"
                                        max="100000"
                                        step="1"
                                        style="width:90px;text-align:right;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">
                                </td>
                                <td class="text-right"><?= number_format($splitPercent, 2) ?>%</td>
                                <td class="text-right"><?= number_format($clicks) ?></td>
                                <td class="text-right"><?= number_format($expired) ?></td>
                                <td class="text-right">$<?= number_format($usd, 2) ?></td>
                                <td class="text-right" style="<?= $epcStyle ?>">$<?= number_format($epc, 4) ?></td>
                                <td class="text-right" style="color:#6b7280;font-size:0.8rem;">
                                    <?= trafficSplitDashH($row['split_updated_at'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($providerRows)): ?>
                            <tr>
                                <td colspan="9" class="text-center" style="padding:12px;">
                                    No providers found in <code>provider_jobs</code>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div style="margin-top:16px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary">Save traffic split</button>
                    <div style="font-size:0.8rem;color:#6b7280;">
                        Do not increase traffic only because EPC looks high. Tiny volume can lie. Compare EPC + clicks together.
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/dashboard.js"></script>
</body>

</html>