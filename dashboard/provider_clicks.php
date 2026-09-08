<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

$pdo = getPdoConnection();

// ── Provider filter ──────────────────────────────────────────────────────────

$allProviders = $pdo->query("SELECT id, COALESCE(name, CONCAT('Provider ', id)) AS name FROM provider_data ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

$selectedProvider = isset($_GET['provider']) && $_GET['provider'] !== ''
    ? (int) $_GET['provider']
    : null;

$validProviderIds = array_column($allProviders, 'id');
if ($selectedProvider !== null && !in_array($selectedProvider, $validProviderIds, true)) {
    $selectedProvider = null;
}

$providerWhere = $selectedProvider !== null
    ? 'AND rl.provider_data_id = ' . $selectedProvider
    : '';

// ── Summary per provider ─────────────────────────────────────────────────────

$sqlSummary = "
    SELECT
        rl.provider_data_id                                           AS provider_id,
        COALESCE(pd.name, CONCAT('Provider ', rl.provider_data_id))  AS provider_name,
        COUNT(DISTINCT rl.id)                                         AS total_leads,
        SUM(CASE WHEN jc.cnt > 0  THEN 1 ELSE 0 END)                 AS leads_with_click,
        SUM(CASE WHEN jco.cnt > 0 THEN 1 ELSE 0 END)                 AS leads_with_click_out,
        COALESCE(SUM(jc.cnt),  0)                                     AS total_job_clicks,
        COALESCE(SUM(jco.cnt), 0)                                     AS total_clicks_out
    FROM record_leads rl
    JOIN provider_data pd ON pd.id = rl.provider_data_id
    JOIN record_sync rs ON rs.record_lead_id = rl.id
                        AND rs.synced_at BETWEEN :start4 AND :end4
    LEFT JOIN (
        SELECT LOWER(email) AS email, COUNT(*) AS cnt
        FROM job_clicks
        WHERE created_at BETWEEN :start1 AND :end1
        GROUP BY LOWER(email)
    ) jc  ON jc.email  = LOWER(rl.email)
    LEFT JOIN (
        SELECT LOWER(email) AS email, COUNT(*) AS cnt
        FROM job_clicks_out
        WHERE created_at BETWEEN :start2 AND :end2
        GROUP BY LOWER(email)
    ) jco ON jco.email = LOWER(rl.email)
    WHERE rl.is_valid = 1
    {$providerWhere}
    GROUP BY rl.provider_data_id, pd.name
    ORDER BY rl.provider_data_id
";

$stmtSummary = $pdo->prepare($sqlSummary);
$stmtSummary->execute([
    ':start1' => $startDateTime,
    ':end1'   => $endDateTime,
    ':start2' => $startDateTime,
    ':end2'   => $endDateTime,
    ':start4' => $startDateTime,
    ':end4'   => $endDateTime,
]);
$summaryRows = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

// ── Detail: leads with at least 1 click ─────────────────────────────────────

$sqlDetail = "
    SELECT
        rl.id                                                         AS lead_id,
        rl.provider_data_id,
        COALESCE(pd.name, CONCAT('Provider ', rl.provider_data_id))  AS provider_name,
        rl.email,
        DATE(rl.created_at)                                           AS lead_created,
        jc.cnt                                                        AS job_clicks,
        jc.first_click,
        jc.last_click,
        COALESCE(jco.cnt, 0)                                          AS clicks_out,
        jco.last_out
    FROM record_leads rl
    JOIN provider_data pd ON pd.id = rl.provider_data_id
    JOIN record_sync rs ON rs.record_lead_id = rl.id
                        AND rs.synced_at BETWEEN :start4 AND :end4
    INNER JOIN (
        SELECT LOWER(email) AS email, COUNT(*) AS cnt,
               MIN(created_at) AS first_click, MAX(created_at) AS last_click
        FROM job_clicks
        WHERE created_at BETWEEN :start1 AND :end1
        GROUP BY LOWER(email)
    ) jc  ON jc.email  = LOWER(rl.email)
    LEFT JOIN (
        SELECT LOWER(email) AS email, COUNT(*) AS cnt,
               MAX(created_at) AS last_out
        FROM job_clicks_out
        WHERE created_at BETWEEN :start2 AND :end2
        GROUP BY LOWER(email)
    ) jco ON jco.email = LOWER(rl.email)
    WHERE rl.is_valid = 1
    {$providerWhere}
    ORDER BY jc.cnt DESC, jc.last_click DESC
    LIMIT 300
";

$stmtDetail = $pdo->prepare($sqlDetail);
$stmtDetail->execute([
    ':start1' => $startDateTime,
    ':end1'   => $endDateTime,
    ':start2' => $startDateTime,
    ':end2'   => $endDateTime,
    ':start4' => $startDateTime,
    ':end4'   => $endDateTime,
]);
$detailRows = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

// ── Totals (all providers combined) ─────────────────────────────────────────

$totLeads      = array_sum(array_column($summaryRows, 'total_leads'));
$totWithClick  = array_sum(array_column($summaryRows, 'leads_with_click'));
$totWithOut    = array_sum(array_column($summaryRows, 'leads_with_click_out'));
$totJobClicks  = array_sum(array_column($summaryRows, 'total_job_clicks'));
$totClicksOut  = array_sum(array_column($summaryRows, 'total_clicks_out'));
$clickRate     = $totLeads > 0 ? round($totWithClick  / $totLeads * 100, 1) : 0.0;
$clickOutRate  = $totLeads > 0 ? round($totWithOut    / $totLeads * 100, 1) : 0.0;

// ── Build provider filter URL ────────────────────────────────────────────────

function providerUrl($providerId, string $daysQuery): string
{
    $base = 'provider_clicks.php' . $daysQuery;
    $sep  = strpos($daysQuery, '?') !== false ? '&' : '?';
    return $providerId !== null ? $base . $sep . 'provider=' . (int)$providerId : $base;
}

$siteTitle    = 'Provider Clicks';
$siteSubtitle = 'Leads por provider que clicaram em vagas (job_clicks / job_clicks_out).';
$activeTab    = 'provider_clicks';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<?php include __DIR__ . '/partials/head.php'; ?>
<body>
<div class="container">

    <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

    <!-- Provider filter pills -->
    <div class="filters" style="margin-top:1rem;">
        <a href="<?= htmlspecialchars(providerUrl(null, $daysQuery)) ?>"
           class="btn-filter <?= $selectedProvider === null ? 'active' : '' ?>">
            Todos
        </a>
        <?php foreach ($allProviders as $p): ?>
        <a href="<?= htmlspecialchars(providerUrl((int)$p['id'], $daysQuery)) ?>"
           class="btn-filter <?= $selectedProvider === (int)$p['id'] ? 'active' : '' ?>">
            <?= htmlspecialchars($p['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Global KPI cards -->
    <div class="cards" style="margin-top:1rem;">
        <div class="card">
            <div class="card-title">Total leads</div>
            <div class="card-value"><?= number_format($totLeads) ?></div>
            <div class="card-helper"><?= htmlspecialchars($periodLabel) ?></div>
        </div>
        <div class="card">
            <div class="card-title">Leads c/ job_click</div>
            <div class="card-value"><?= number_format($totWithClick) ?></div>
            <div class="card-helper"><?= $clickRate ?>% do total</div>
        </div>
        <div class="card">
            <div class="card-title">Leads c/ click_out</div>
            <div class="card-value"><?= number_format($totWithOut) ?></div>
            <div class="card-helper"><?= $clickOutRate ?>% do total</div>
        </div>
        <div class="card">
            <div class="card-title">Total job_clicks</div>
            <div class="card-value"><?= number_format($totJobClicks) ?></div>
            <div class="card-helper">Soma de todos os cliques</div>
        </div>
        <div class="card">
            <div class="card-title">Total clicks_out</div>
            <div class="card-value"><?= number_format($totClicksOut) ?></div>
            <div class="card-helper">Soma de todos os clicks_out</div>
        </div>
    </div>

    <!-- Per-provider summary table -->
    <?php if (count($summaryRows) > 1): ?>
    <div class="table-wrapper" style="margin-top:1.5rem;">
        <h2 style="margin:0 0 8px 0;font-size:1rem;">Resumo por provider</h2>
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th style="text-align:right;">Leads</th>
                    <th style="text-align:right;">c/ job_click</th>
                    <th style="text-align:right;">% click</th>
                    <th style="text-align:right;">c/ click_out</th>
                    <th style="text-align:right;">% click_out</th>
                    <th style="text-align:right;">job_clicks</th>
                    <th style="text-align:right;">clicks_out</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($summaryRows as $r):
                $n  = (int) $r['total_leads'];
                $cr = $n > 0 ? round((int)$r['leads_with_click']     / $n * 100, 1) : 0.0;
                $or = $n > 0 ? round((int)$r['leads_with_click_out'] / $n * 100, 1) : 0.0;
            ?>
                <tr>
                    <td>
                        <a href="<?= htmlspecialchars(providerUrl((int)$r['provider_id'], $daysQuery)) ?>"
                           style="text-decoration:none;color:inherit;">
                            <?= htmlspecialchars($r['provider_name']) ?>
                        </a>
                    </td>
                    <td style="text-align:right;"><?= number_format($n) ?></td>
                    <td style="text-align:right;"><?= number_format((int)$r['leads_with_click']) ?></td>
                    <td style="text-align:right;"><?= $cr ?>%</td>
                    <td style="text-align:right;"><?= number_format((int)$r['leads_with_click_out']) ?></td>
                    <td style="text-align:right;"><?= $or ?>%</td>
                    <td style="text-align:right;"><?= number_format((int)$r['total_job_clicks']) ?></td>
                    <td style="text-align:right;"><?= number_format((int)$r['total_clicks_out']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Detail table -->
    <div class="table-wrapper" style="margin-top:1.5rem;">
        <h2 style="margin:0 0 4px 0;font-size:1rem;">
            Leads com cliques
            <span style="font-weight:400;color:#6b7280;font-size:.8rem;">(top 300, ordenado por volume)</span>
        </h2>
        <?php if (empty($detailRows)): ?>
            <p style="color:#6b7280;font-size:.85rem;">Nenhum lead com clique no período.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Provider</th>
                    <th>Email</th>
                    <th style="text-align:right;">Lead criado</th>
                    <th style="text-align:right;">job_clicks</th>
                    <th style="text-align:right;">clicks_out</th>
                    <th style="text-align:right;">Primeiro click</th>
                    <th style="text-align:right;">Último click</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($detailRows as $r): ?>
                <tr>
                    <td><?= (int) $r['lead_id'] ?></td>
                    <td>
                        <a href="<?= htmlspecialchars(providerUrl((int)$r['provider_data_id'], $daysQuery)) ?>"
                           style="text-decoration:none;color:inherit;">
                            <?= htmlspecialchars($r['provider_name']) ?>
                        </a>
                    </td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($r['email']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['lead_created']) ?></td>
                    <td style="text-align:right;"><?= number_format((int)$r['job_clicks']) ?></td>
                    <td style="text-align:right;"><?= (int)$r['clicks_out'] > 0 ? number_format((int)$r['clicks_out']) : '—' ?></td>
                    <td style="text-align:right;font-size:.78rem;"><?= htmlspecialchars(substr((string)$r['first_click'], 0, 16)) ?></td>
                    <td style="text-align:right;font-size:.78rem;"><?= htmlspecialchars(substr((string)$r['last_click'],  0, 16)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
