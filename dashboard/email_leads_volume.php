<?php
// email_leads_volume.php
// Dashboard: Volume de leads na record_leads e volume sincronizado por ESP + origem (provider_data_id)

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


// =====================================
// GLOBAL LEADS STATS (record_leads)
// =====================================
$sqlGlobal = "
    SELECT 
        COUNT(*) AS total_leads,
        COUNT(DISTINCT provider_data_id) AS provider_count
    FROM record_leads
    WHERE created_at BETWEEN :startDateTime AND :endDateTime
";
$stmtGlobal = $pdo->prepare($sqlGlobal);
$stmtGlobal->bindValue(':startDateTime', $startDateTime);
$stmtGlobal->bindValue(':endDateTime',   $endDateTime);
$stmtGlobal->execute();
$global = $stmtGlobal->fetch(PDO::FETCH_ASSOC) ?: [
    'total_leads'    => 0,
    'provider_count' => 0,
];

$totalLeads    = (int) ($global['total_leads'] ?? 0);
$providerCount = (int) ($global['provider_count'] ?? 0);

// =====================================
// VALID vs INVALID LEADS (GLOBAL DO PERÍODO)
// =====================================
// Assumindo coluna record_leads.is_valid: 1 = válido, 0 = inválido
$sqlValidInvalid = "
    SELECT 
        COALESCE(is_valid, 0) AS is_valid,
        COUNT(*) AS total
    FROM record_leads
    WHERE created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY COALESCE(is_valid, 0)
";
$stmtValidInvalid = $pdo->prepare($sqlValidInvalid);
$stmtValidInvalid->bindValue(':startDateTime', $startDateTime);
$stmtValidInvalid->bindValue(':endDateTime',   $endDateTime);
$stmtValidInvalid->execute();
$validInvalidRows = $stmtValidInvalid->fetchAll(PDO::FETCH_ASSOC);

// default
$validLeads   = 0;
$invalidLeads = 0;

foreach ($validInvalidRows as $row) {
    $flag  = (int)$row['is_valid'];
    $count = (int)$row['total'];

    if ($flag === 1) {
        $validLeads = $count;
    } else {
        // tudo que não for 1, jogo como inválido (0, NULL, etc.)
        $invalidLeads += $count;
    }
}

$totalValidity = $validLeads + $invalidLeads;

$validRate   = ratePercent($validLeads, $totalValidity);
$invalidRate = ratePercent($invalidLeads, $totalValidity);

// dados para gráfico de validade
$validityLabels = json_encode(['Valid leads', 'Invalid leads']);
$validityCounts = json_encode([$validLeads, $invalidLeads]);

// =====================================
// TOTAL SYNCED LEADS (ANY ESP)
// =====================================
$sqlSynced = "
    SELECT 
        COUNT(DISTINCT record_lead_id) AS total_synced
    FROM record_sync
    WHERE synced_at BETWEEN :startDateTime AND :endDateTime
";
$stmtSynced = $pdo->prepare($sqlSynced);
$stmtSynced->bindValue(':startDateTime', $startDateTime);
$stmtSynced->bindValue(':endDateTime',   $endDateTime);
$stmtSynced->execute();
$rowSynced = $stmtSynced->fetch(PDO::FETCH_ASSOC) ?: ['total_synced' => 0];

$totalSynced = (int) ($rowSynced['total_synced'] ?? 0);
$syncRate    = ratePercent($totalSynced, $totalLeads);

// =====================================
// LEADS AVAILABLE TO SYNC (GLOBAL, ALL TIME)
// =====================================
// Definition: all valid leads in record_leads that have NEVER
// been synced to any ESP, regardless of created_at.

$sqlAvailableGlobal = "
    SELECT 
        COUNT(*) AS total_available_global
    FROM record_leads rl
    LEFT JOIN record_sync rs 
           ON rs.record_lead_id = rl.id
    WHERE COALESCE(rl.is_valid, 0) = 1
      AND rs.record_lead_id IS NULL
";

$stmtAvailableGlobal = $pdo->prepare($sqlAvailableGlobal);
$stmtAvailableGlobal->execute();
$rowAvailableGlobal = $stmtAvailableGlobal->fetch(PDO::FETCH_ASSOC) ?: ['total_available_global' => 0];

$availableToSyncGlobal = (int) ($rowAvailableGlobal['total_available_global'] ?? 0);

// =====================================
// LISTA CONTÍNUA DE DIAS (PARA SÉRIES DIÁRIAS)
// =====================================
$datePeriod = [];
for ($i = $days; $i >= 0; $i--) {
    $d = $today->modify("-{$i} days")->format('Y-m-d');
    $datePeriod[] = $d;
}
$dateIndex = array_flip($datePeriod); // '2026-01-01' => 0, etc.

// =====================================
// DAILY LEADS BY DATA PROVIDER (ORIGEM)
// =====================================
// Cada provider_data_id vira uma série no gráfico "Daily leads entering record_leads"

$sqlDailyByProvider = "
    SELECT 
        DATE(rl.created_at) AS day,
        rl.provider_data_id AS provider_id,
        COALESCE(dp.name, 'Unknown') AS provider_name,
        COUNT(*) AS leads
    FROM record_leads rl
    LEFT JOIN provider_data dp ON dp.id = rl.provider_data_id
    WHERE rl.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY day, provider_id, provider_name
    ORDER BY day, provider_name
";
$stmtDailyByProvider = $pdo->prepare($sqlDailyByProvider);
$stmtDailyByProvider->bindValue(':startDateTime', $startDateTime);
$stmtDailyByProvider->bindValue(':endDateTime',   $endDateTime);
$stmtDailyByProvider->execute();
$dailyProviderRows = $stmtDailyByProvider->fetchAll(PDO::FETCH_ASSOC);

$dailyProviderSeries = []; // [provider_id => ['label' => 'Provider (ID X)', 'data' => [...]]]

foreach ($dailyProviderRows as $row) {
    $day          = $row['day'];
    $providerId   = $row['provider_id'] !== null ? (int)$row['provider_id'] : 0;
    $providerName = $row['provider_name'] ?? 'Unknown';
    $count        = (int)$row['leads'];

    if (!isset($dateIndex[$day])) {
        continue;
    }

    if (!isset($dailyProviderSeries[$providerId])) {
        $label = $providerName . ' (ID ' . $providerId . ')';
        $dailyProviderSeries[$providerId] = [
            'label' => $label,
            'data'  => array_fill(0, count($datePeriod), 0),
        ];
    }

    $idx = $dateIndex[$day];
    $dailyProviderSeries[$providerId]['data'][$idx] = $count;
}

// =====================================
// DAILY UNSYNCED LEADS BY DATA PROVIDER
// =====================================
// Mesma ideia, mas somente leads que ainda NÃO têm registro em record_sync

$sqlUnsyncedByProvider = "
    SELECT 
        DATE(rl.created_at) AS day,
        rl.provider_data_id AS provider_id,
        COALESCE(dp.name, 'Unknown') AS provider_name,
        COUNT(*) AS unsynced
    FROM record_leads rl
    LEFT JOIN provider_data dp ON dp.id = rl.provider_data_id
    LEFT JOIN record_sync rs 
           ON rs.record_lead_id = rl.id
    WHERE rl.created_at BETWEEN :startDateTime AND :endDateTime
      AND rs.record_lead_id IS NULL
    GROUP BY day, provider_id, provider_name
    ORDER BY day, provider_name
";
$stmtUnsyncedByProvider = $pdo->prepare($sqlUnsyncedByProvider);
$stmtUnsyncedByProvider->bindValue(':startDateTime', $startDateTime);
$stmtUnsyncedByProvider->bindValue(':endDateTime',   $endDateTime);
$stmtUnsyncedByProvider->execute();
$unsyncedProviderRows = $stmtUnsyncedByProvider->fetchAll(PDO::FETCH_ASSOC);

$unsyncedProviderSeries = []; // [provider_id => ['label' => 'Provider (ID X)', 'data' => [...]]]

foreach ($unsyncedProviderRows as $row) {
    $day          = $row['day'];
    $providerId   = $row['provider_id'] !== null ? (int)$row['provider_id'] : 0;
    $providerName = $row['provider_name'] ?? 'Unknown';
    $count        = (int)$row['unsynced'];

    if (!isset($dateIndex[$day])) {
        continue;
    }

    if (!isset($unsyncedProviderSeries[$providerId])) {
        $label = $providerName . ' (ID ' . $providerId . ')';
        $unsyncedProviderSeries[$providerId] = [
            'label' => $label,
            'data'  => array_fill(0, count($datePeriod), 0),
        ];
    }

    $idx = $dateIndex[$day];
    $unsyncedProviderSeries[$providerId]['data'][$idx] = $count;
}

// JSON para os gráficos diários (por origem)
$jsDailyLabels              = json_encode($datePeriod);
$jsDailyProviderDatasets    = json_encode(array_values($dailyProviderSeries));
$jsUnsyncedLabels           = json_encode($datePeriod);
$jsUnsyncedProviderDatasets = json_encode(array_values($unsyncedProviderSeries));

// =====================================
// DAILY UNSYNCED LEADS: VALID vs INVALID (record_leads.is_valid)
// =====================================
// 1 = válido, 0 ou NULL = inválido

$sqlUnsyncedByValidity = "
    SELECT 
        DATE(rl.created_at) AS day,
        CASE 
            WHEN COALESCE(rl.is_valid, 0) = 1 THEN 'Valid leads'
            ELSE 'Invalid leads'
        END AS validity_label,
        COUNT(*) AS unsynced
    FROM record_leads rl
    LEFT JOIN record_sync rs 
           ON rs.record_lead_id = rl.id
    WHERE rl.created_at BETWEEN :startDateTime AND :endDateTime
      AND rs.record_lead_id IS NULL
    GROUP BY day, validity_label
    ORDER BY day, validity_label
";

$stmtUnsyncedByValidity = $pdo->prepare($sqlUnsyncedByValidity);
$stmtUnsyncedByValidity->bindValue(':startDateTime', $startDateTime);
$stmtUnsyncedByValidity->bindValue(':endDateTime',   $endDateTime);
$stmtUnsyncedByValidity->execute();
$unsyncedValidityRows = $stmtUnsyncedByValidity->fetchAll(PDO::FETCH_ASSOC);

// séries fixas: Valid / Invalid
$unsyncedValiditySeries = [
    'Valid leads' => [
        'label' => 'Valid (unsynced)',
        'data'  => array_fill(0, count($datePeriod), 0),
    ],
    'Invalid leads' => [
        'label' => 'Invalid (unsynced)',
        'data'  => array_fill(0, count($datePeriod), 0),
    ],
];

foreach ($unsyncedValidityRows as $row) {
    $day     = $row['day'];
    $label   = $row['validity_label']; // 'Valid leads' ou 'Invalid leads'
    $count   = (int)$row['unsynced'];

    if (!isset($dateIndex[$day])) {
        continue; // fora da janela, segurança
    }

    if (!isset($unsyncedValiditySeries[$label])) {
        // fallback se aparecer algum label esquisito
        $unsyncedValiditySeries[$label] = [
            'label' => $label,
            'data'  => array_fill(0, count($datePeriod), 0),
        ];
    }

    $idx = $dateIndex[$day];
    $unsyncedValiditySeries[$label]['data'][$idx] = $count;
}

// JSON para o gráfico diário de unsynced por validade
$jsUnsyncedValidityLabels   = json_encode($datePeriod);
$jsUnsyncedValidityDatasets = json_encode(array_values($unsyncedValiditySeries));

// =====================================
// LEADS BY DATA PROVIDER (TABELA RESUMO)
// =====================================
$sqlProviders = "
    SELECT 
        dp.id,
        dp.name,
        COUNT(rl.id) AS leads_count
    FROM provider_data dp
    LEFT JOIN record_leads rl 
        ON rl.provider_data_id = dp.id
       AND rl.created_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY dp.id, dp.name
    ORDER BY leads_count DESC, dp.name ASC
";
$stmtProviders = $pdo->prepare($sqlProviders);
$stmtProviders->bindValue(':startDateTime', $startDateTime);
$stmtProviders->bindValue(':endDateTime',   $endDateTime);
$stmtProviders->execute();
$providers = $stmtProviders->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// LEADS BY ESP (TOTAL IN PERIOD)
// =====================================
$sqlEspTotal = "
    SELECT 
        e.id,
        e.name,
        COUNT(DISTINCT rs.record_lead_id) AS leads_synced,
        MIN(rs.synced_at) AS first_sync,
        MAX(rs.synced_at) AS last_sync
    FROM esp e
    LEFT JOIN record_sync rs 
        ON rs.esp_id = e.id
       AND rs.synced_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY e.id, e.name
    ORDER BY leads_synced DESC, e.name ASC
";
$stmtEspTotal = $pdo->prepare($sqlEspTotal);
$stmtEspTotal->bindValue(':startDateTime', $startDateTime);
$stmtEspTotal->bindValue(':endDateTime',   $endDateTime);
$stmtEspTotal->execute();
$espTotalRows = $stmtEspTotal->fetchAll(PDO::FETCH_ASSOC);

// dados para gráfico de TOTAL por ESP
$espTotalLabels = [];
$espTotalCounts = [];
foreach ($espTotalRows as $e) {
    $espTotalLabels[] = $e['name'] . ' (ID ' . $e['id'] . ')';
    $espTotalCounts[] = (int)$e['leads_synced'];
}
$jsEspTotalLabels = json_encode($espTotalLabels);
$jsEspTotalCounts = json_encode($espTotalCounts);

// =====================================
// DAILY LEADS SYNCED PER ESP (FOR CHART)
// =====================================

// Busca volume de sync por dia e por ESP
$sqlEspDaily = "
    SELECT 
        DATE(rs.synced_at) AS day,
        e.id AS esp_id,
        e.name,
        COUNT(DISTINCT rs.record_lead_id) AS leads_synced
    FROM record_sync rs
    INNER JOIN esp e ON e.id = rs.esp_id
    WHERE rs.synced_at BETWEEN :startDateTime AND :endDateTime
    GROUP BY day, e.id, e.name
    ORDER BY day, e.id
";
$stmtEspDaily = $pdo->prepare($sqlEspDaily);
$stmtEspDaily->bindValue(':startDateTime', $startDateTime);
$stmtEspDaily->bindValue(':endDateTime',   $endDateTime);
$stmtEspDaily->execute();
$espDailyRows = $stmtEspDaily->fetchAll(PDO::FETCH_ASSOC);

// Monta séries por ESP para o gráfico diário
$espSeries = []; // [esp_id => ['label' => 'Nome (ID X)', 'data' => [0,0,5,...]]]

foreach ($espDailyRows as $row) {
    $espId   = (int)$row['esp_id'];
    $espName = $row['name'];
    $day     = $row['day'];
    $count   = (int)$row['leads_synced'];

    if (!isset($dateIndex[$day])) {
        continue; // fora da janela (por segurança)
    }

    if (!isset($espSeries[$espId])) {
        $espSeries[$espId] = [
            'label' => $espName . ' (ID ' . $espId . ')',
            'data'  => array_fill(0, count($datePeriod), 0),
        ];
    }

    $idx = $dateIndex[$day];
    $espSeries[$espId]['data'][$idx] = $count;
}

// prepara dados JS para o gráfico diário por ESP
$jsEspDailyLabels   = json_encode($datePeriod);
$jsEspDailyDatasets = json_encode(array_values($espSeries));

// =====================================
// AVERAGE LEADS PER DAY
// =====================================
$avgPerDay = ($totalLeads > 0 && $days > 0)
    ? round($totalLeads / $days, 2)
    : 0.0;
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <!-- Global summary -->
        <div class="global-summary" style="margin-bottom: 24px; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                <div>
                    <strong>Leads flow (last <?= $days ?> day(s))</strong><br>
                    <span style="font-size: 0.8rem; color: #6b7280;">
                        Volume entering <code>record_leads</code>,
                        their origin (<code>provider_data_id</code>),
                        synced to ESPs and pending sync.
                    </span>
                </div>
                <div style="font-size: 0.8rem; color: #6b7280;">
                    Data based on <code>created_at</code> (record_leads)
                    and <code>synced_at</code> (record_sync).
                </div>
            </div>

            <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 16px;">
                <!-- Leads Volume -->
                <div style="min-width: 180px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Leads volume
                    </div>
                    <div>
                        Total leads: <strong><?= number_format($totalLeads) ?></strong><br>
                        Avg / day: <strong><?= number_format($avgPerDay, 2) ?></strong><br>
                        Data providers: <strong><?= number_format($providerCount) ?></strong>
                    </div>
                </div>

                <!-- Sync volume -->
                <div style="min-width: 180px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Synced to ESPs
                    </div>
                    <div>
                        Synced leads (by synced_at, period): <strong><?= number_format($totalSynced) ?></strong><br>
                        Sync rate (vs leads in period): <strong><?= $syncRate ?>%</strong><br>

                        <!-- TOTAL disponível na base inteira -->
                        Total available in DB (valid, unsynced):
                        <strong><?= number_format($availableToSyncGlobal) ?></strong>
                    </div>
                </div>

                <!-- Validity breakdown -->
                <div style="min-width: 180px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Lead validity
                    </div>
                    <div>
                        Valid: <strong><?= number_format($validLeads) ?></strong> (<?= $validRate ?>%)<br>
                        Invalid: <strong><?= number_format($invalidLeads) ?></strong> (<?= $invalidRate ?>%)
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- 1) Daily leads entrando em record_leads (por provider_data_id) -->
            <div class="chart-card">
                <div class="chart-title">Daily leads entering record_leads (by data provider)</div>
                <div class="chart-helper">
                    Each series is a <code>provider_data_id</code> (origin). Bars show number of leads created per day.
                </div>
                <div class="chart-container">
                    <canvas id="dailyLeadsChart"></canvas>
                </div>
            </div>

            <!-- 2) Leads synced by ESP (daily) -->
            <div class="chart-card">
                <div class="chart-title">Leads synced by ESP (daily)</div>
                <div class="chart-helper">
                    Daily volume of distinct leads synced per ESP in the last <?= $days ?> day(s).
                </div>
                <div class="chart-container">
                    <canvas id="espVolumeDailyChart"></canvas>
                </div>
            </div>

            <!-- 3) Total leads synced by ESP (no período) -->
            <div class="chart-card">
                <div class="chart-title">Total leads synced by ESP</div>
                <div class="chart-helper">
                    Distinct leads synced to each ESP in the selected period.
                </div>
                <div class="chart-container">
                    <canvas id="espVolumeTotalChart"></canvas>
                </div>
            </div>

            <!-- 4) Daily unsynced leads (pending sync) por provider_data_id -->
            <div class="chart-card">
                <div class="chart-title">Daily unsynced leads (pending sync by data provider)</div>
                <div class="chart-helper">
                    Leads created in record_leads that have not yet been synced to any ESP, broken down by origin.
                </div>
                <div class="chart-container">
                    <canvas id="unsyncedLeadsChart"></canvas>
                </div>
            </div>

            <!-- 5) Valid vs Invalid leads (total no período) -->
            <div class="chart-card">
                <div class="chart-title">Valid vs Invalid leads (total in period)</div>
                <div class="chart-helper">
                    Distribution of leads classified as valid vs invalid in the last <?= $days ?> day(s).
                </div>
                <div class="chart-container">
                    <canvas id="validityChart"></canvas>
                </div>
            </div>

            <!-- 6) Daily unsynced leads by validity (record_leads.is_valid) -->
            <div class="chart-card">
                <div class="chart-title">Daily unsynced leads by validity</div>
                <div class="chart-helper">
                    Leads created in <code>record_leads</code> that have not yet been synced to any ESP,
                    broken down by <strong>Valid</strong> vs <strong>Invalid</strong> (using <code>record_leads.is_valid</code>).
                </div>
                <div class="chart-container">
                    <canvas id="unsyncedLeadsValidityChart"></canvas>
                </div>
            </div>

        </div>

        <!-- Table: Leads by Data Provider -->
        <div class="table-wrapper" style="margin-top: 32px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Leads by Data Provider</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Ranking of data providers by number of leads created in the selected period.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th class="text-right">Provider ID</th>
                        <th class="text-right">Leads</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($providers)): ?>
                        <?php foreach ($providers as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td class="text-right"><?= (int)$p['id'] ?></td>
                                <td class="text-right"><?= number_format((int)$p['leads_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center;color:#9ca3af;padding:16px;">
                                No leads found for this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table: Leads by ESP (total) -->
        <div class="table-wrapper" style="margin-top: 32px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Leads synced by ESP</h2>
            <p style="margin-top: 0; font-size: 0.8rem; color: #6b7280;">
                Distinct leads synced to each ESP using <code>record_sync.esp_id</code> in the selected period.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>ESP</th>
                        <th class="text-right">ESP ID</th>
                        <th class="text-right">Leads synced</th>
                        <th>First sync</th>
                        <th>Last sync</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($espTotalRows)): ?>
                        <?php foreach ($espTotalRows as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['name']) ?></td>
                                <td class="text-right"><?= (int)$e['id'] ?></td>
                                <td class="text-right"><?= number_format((int)$e['leads_synced']) ?></td>
                                <td>
                                    <?php if (!empty($e['first_sync'])): ?>
                                        <span class="badge-date"><?= htmlspecialchars($e['first_sync']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($e['last_sync'])): ?>
                                        <span class="badge-date"><?= htmlspecialchars($e['last_sync']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:#9ca3af;padding:16px;">
                                No sync events found for this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        const dailyLabels = <?= $jsDailyLabels ?>;
        const dailyDatasets = <?= $jsDailyProviderDatasets ?>;

        const espDailyLabels = <?= $jsEspDailyLabels ?>;
        const espDailyDatasets = <?= $jsEspDailyDatasets ?>;

        const espTotalLabels = <?= $jsEspTotalLabels ?>;
        const espTotalCounts = <?= $jsEspTotalCounts ?>;

        const unsyncedLabels = <?= $jsUnsyncedLabels ?>;
        const unsyncedDatasets = <?= $jsUnsyncedProviderDatasets ?>;

        const unsyncedValidityLabels = <?= $jsUnsyncedValidityLabels ?>;
        const unsyncedValidityDatasets = <?= $jsUnsyncedValidityDatasets ?>;

        const validityLabels = <?= $validityLabels ?>;
        const validityCounts = <?= $validityCounts ?>;

        // 1) Daily leads chart (record_leads) by provider_data_id
        const ctxDaily = document.getElementById('dailyLeadsChart').getContext('2d');

        new Chart(ctxDaily, {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: dailyDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
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

                                if (!rawLabel && dailyLabels[index]) {
                                    rawLabel = dailyLabels[index];
                                }

                                if (!rawLabel && dailyLabels[value]) {
                                    rawLabel = dailyLabels[value];
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
                    },
                    y: {
                        beginAtZero: true
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

        // 2) Leads synced by ESP (daily)
        const ctxEspDaily = document.getElementById('espVolumeDailyChart').getContext('2d');

        new Chart(ctxEspDaily, {
            type: 'line',
            data: {
                labels: espDailyLabels,
                datasets: espDailyDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
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

                                if (!rawLabel && espDailyLabels[index]) {
                                    rawLabel = espDailyLabels[index];
                                }

                                if (!rawLabel && espDailyLabels[value]) {
                                    rawLabel = espDailyLabels[value];
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
                    },
                    y: {
                        beginAtZero: true
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

        // 3) Total leads synced by ESP (barras horizontais)
        const ctxEspTotal = document.getElementById('espVolumeTotalChart').getContext('2d');

        new Chart(ctxEspTotal, {
            type: 'bar',
            data: {
                labels: espTotalLabels,
                datasets: [{
                    label: 'Leads synced',
                    data: espTotalCounts
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                return ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // 4) Daily unsynced leads (pending sync) by provider_data_id
        const ctxUnsynced = document.getElementById('unsyncedLeadsChart').getContext('2d');

        new Chart(ctxUnsynced, {
            type: 'bar',
            data: {
                labels: unsyncedLabels,
                datasets: unsyncedDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
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

                                if (!rawLabel && unsyncedLabels[index]) {
                                    rawLabel = unsyncedLabels[index];
                                }

                                if (!rawLabel && unsyncedLabels[value]) {
                                    rawLabel = unsyncedLabels[value];
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
                    },
                    y: {
                        beginAtZero: true
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

        // 5) Valid vs Invalid leads (total in period)
        const ctxValidity = document.getElementById('validityChart').getContext('2d');

        new Chart(ctxValidity, {
            type: 'doughnut',
            data: {
                labels: validityLabels,
                datasets: [{
                    label: 'Leads count',
                    data: validityCounts
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = validityCounts.reduce((a, b) => a + b, 0);
                                const perc = total > 0 ? ((value * 100) / total).toFixed(2) : 0;

                                return `${label}: ${Number(value).toLocaleString()} (${perc}%)`;
                            }
                        }
                    }
                }
            }
        });

        // 6) Daily unsynced leads by validity (Valid vs Invalid)
        const ctxUnsyncedValidity = document.getElementById('unsyncedLeadsValidityChart').getContext('2d');

        new Chart(ctxUnsyncedValidity, {
            type: 'bar',
            data: {
                labels: unsyncedValidityLabels,
                datasets: unsyncedValidityDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        stacked: true,
                        ticks: {
                            autoSkip: true,
                            maxRotation: 45,
                            minRotation: 0,
                            callback: function(value, index) {
                                let rawLabel = '';

                                if (typeof this.getLabelForValue === 'function') {
                                    rawLabel = this.getLabelForValue(value);
                                }

                                if (!rawLabel && unsyncedValidityLabels[index]) {
                                    rawLabel = unsyncedValidityLabels[index];
                                }

                                if (!rawLabel && unsyncedValidityLabels[value]) {
                                    rawLabel = unsyncedValidityLabels[value];
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
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
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
    </script>

    <script src="assets/dashboard.js"></script>
</body>

</html>