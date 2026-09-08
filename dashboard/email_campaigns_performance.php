<?php

declare(strict_types=1);
// email_campaigns_performance.php
// Dashboard: Campaign performance + botão para atualizar campanhas + visão do esp_schedule
// Tabelas: email_campaigns, esp_schedule

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
 * Simple classification of campaign performance
 * You can tweak thresholds to match your business rules.
 */
function classifyCampaign(
    float $or,
    float $ctr,
    float $ctor,
    float $bounceRate,
    float $unsubRate,
    float $complaintRate
): string {
    if ($complaintRate >= 0.2 || $unsubRate >= 2.0 || $bounceRate >= 3.0) {
        return 'Needs attention';
    }

    if ($or >= 30.0 && $ctr >= 3.0 && $complaintRate < 0.05 && $unsubRate < 0.8) {
        return 'Good';
    }

    return 'Average';
}

/**
 * Fetch list of ESPs available in either campaigns or schedule.
 */
function fetchAvailableEsps(PDO $pdo): array
{
    $sql = "
        SELECT esp_id
        FROM (
            SELECT DISTINCT esp_id FROM email_campaigns WHERE esp_id IS NOT NULL
            UNION
            SELECT DISTINCT esp_id FROM esp_schedule WHERE esp_id IS NOT NULL
        ) t
        ORDER BY esp_id
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}


/**
 * Fetch ESP options with names for the filter select.
 * This keeps the filter consistent with email_campaigns_report.php.
 */
function fetchAvailableEspOptions(PDO $pdo): array
{
    $sql = "
        SELECT e.id, e.name
        FROM esp e
        INNER JOIN (
            SELECT DISTINCT esp_id FROM email_campaigns WHERE esp_id IS NOT NULL
            UNION
            SELECT DISTINCT esp_id FROM esp_schedule WHERE esp_id IS NOT NULL
        ) x ON x.esp_id = e.id
        ORDER BY e.name ASC, e.id ASC
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch per-campaign stats with filters
 */
function fetchCampaignPerformance(
    PDO $pdo,
    string $startDateTime,
    string $endDateTime,
    ?int $espId = null,
    int $limit = 200
): array {
    $sql = "
        SELECT
            id,
            esp_id,
            esp_campaign_id,
            name,
            subject,
            sender_email,
            sender_name,
            COALESCE(scheduled_at, created_at_esp) AS send_datetime,
            global_sent,
            global_delivered,
            global_hard_bounces,
            global_soft_bounces,
            global_unique_views,
            global_unique_clicks,
            global_unsubscriptions,
            global_complaints
        FROM email_campaigns
        WHERE COALESCE(scheduled_at, created_at_esp)
              BETWEEN :startDateTime AND :endDateTime
    ";

    if ($espId !== null) {
        $sql .= " AND esp_id = :espId ";
    }

    $sql .= "
        ORDER BY send_datetime DESC, id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':startDateTime', $startDateTime);
    $stmt->bindValue(':endDateTime', $endDateTime);

    if ($espId !== null) {
        $stmt->bindValue(':espId', $espId, PDO::PARAM_INT);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch full ESP schedule (one row per weekday/slot).
 */
function fetchEspSchedule(PDO $pdo, ?int $espId = null): array
{
    $sql = "
        SELECT
            s.id,
            s.esp_id,
            s.weekday,
            s.slot_index,
            s.time,
            s.template_remote_id,
            s.template_label_id,
            s.name_pattern,
            s.segment_ids_json,
            s.is_active
        FROM esp_schedule s
        INNER JOIN (
            SELECT
                template_label_id,
                MIN(id) AS selected_id
            FROM esp_schedule
            WHERE is_active = 1
              AND template_label_id IS NOT NULL
    ";

    if ($espId !== null) {
        $sql .= " AND esp_id = :espId ";
    }

    $sql .= "
            GROUP BY template_label_id, weekday
        ) x ON x.selected_id = s.id
        ORDER BY FIELD(
            s.weekday,
            'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'
        ), s.time, s.slot_index, s.id
    ";

    $stmt = $pdo->prepare($sql);

    if ($espId !== null) {
        $stmt->bindValue(':espId', $espId, PDO::PARAM_INT);
    }

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ======================================
// SESSION (PRG flash message)
// ======================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$refreshMessage = $_SESSION['refresh_message'] ?? null;
unset($_SESSION['refresh_message']);

// ==================================================
// HANDLE POST: refresh_campaigns (PRG)
// ==================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_campaigns') {
    $msg = '';

    if (!defined('JLD_ALLOW_WEB_TRIGGER')) {
        define('JLD_ALLOW_WEB_TRIGGER', true);
    }

    // =========================
    // 1) BREVO campaigns
    // =========================
    $campaignScript = dirname(__DIR__) . '/scripts/brevo_fetch_campaigns.php';

    if (!file_exists($campaignScript)) {
        $msg = 'Error: campaigns script not found at ' . $campaignScript;
    } else {
        $GLOBALS['BREVO_DAYS_BACK'] = 2;

        ob_start();
        try {
            require $campaignScript;
            $output = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            $msg = 'Error while running campaigns script: ' . htmlspecialchars($e->getMessage());
        }
    }

    $_SESSION['refresh_message'] = $msg;

    $redirectUrl = $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirectUrl);
    exit;
}

// ==================================================
// READ FILTERS (?days, ?weekday, ?esp_id)
// ==================================================

// Date filters are loaded from partials/dashboard_filters.php.
// ESP filter
$availableEsps = fetchAvailableEsps($pdo);
$availableEspOptions = fetchAvailableEspOptions($pdo);

$selectedEspId = isset($_GET['esp_id']) && $_GET['esp_id'] !== ''
    ? (int)$_GET['esp_id']
    : null;

if (
    $selectedEspId !== null
    && !in_array((string)$selectedEspId, array_map('strval', $availableEsps), true)
) {
    $selectedEspId = null;
}

// Load campaign data
$rows = fetchCampaignPerformance($pdo, $startDateTime, $endDateTime, $selectedEspId, 200);

// --------------------------------------------------------
// Prepare data (campaigns, grouped, global, charts)
// --------------------------------------------------------

$campaigns        = [];
$groupedRaw       = [];
$groupedCampaigns = [];

$topLabels   = [];
$topCtr      = [];
$worstLabels = [];
$worstCtr    = [];
$usageLabels = [];
$usageRuns   = [];

$globalTotals = [
    'sent'          => 0,
    'delivered'     => 0,
    'bounces'       => 0,
    'unique_opens'  => 0,
    'unique_clicks' => 0,
    'unsubs'        => 0,
    'complaints'    => 0,
];

foreach ($rows as $row) {
    $sent         = (int)$row['global_sent'];
    $delivered    = (int)$row['global_delivered'];
    $hard         = (int)$row['global_hard_bounces'];
    $soft         = (int)$row['global_soft_bounces'];
    $bounces      = $hard + $soft;
    $uniqueOpens  = (int)$row['global_unique_views'];
    $uniqueClicks = (int)$row['global_unique_clicks'];
    $unsubs       = (int)$row['global_unsubscriptions'];
    $complaints   = (int)$row['global_complaints'];

    $or            = ratePercent($uniqueOpens, $delivered);
    $ctr           = ratePercent($uniqueClicks, $delivered);
    $ctor          = ratePercent($uniqueClicks, $uniqueOpens);
    $bounceRate    = ratePercent($bounces, $sent);
    $unsubRate     = ratePercent($unsubs, $delivered);
    $complaintRate = ratePercent($complaints, $delivered);

    $status = classifyCampaign($or, $ctr, $ctor, $bounceRate, $unsubRate, $complaintRate);

    $campaigns[] = [
        'raw'            => $row,
        'sent'           => $sent,
        'delivered'      => $delivered,
        'bounces'        => $bounces,
        'unique_opens'   => $uniqueOpens,
        'unique_clicks'  => $uniqueClicks,
        'unsubs'         => $unsubs,
        'complaints'     => $complaints,
        'or'             => $or,
        'ctr'            => $ctr,
        'ctor'           => $ctor,
        'bounce_rate'    => $bounceRate,
        'unsub_rate'     => $unsubRate,
        'complaint_rate' => $complaintRate,
        'status'         => $status,
    ];

    $globalTotals['sent']          += $sent;
    $globalTotals['delivered']     += $delivered;
    $globalTotals['bounces']       += $bounces;
    $globalTotals['unique_opens']  += $uniqueOpens;
    $globalTotals['unique_clicks'] += $uniqueClicks;
    $globalTotals['unsubs']        += $unsubs;
    $globalTotals['complaints']    += $complaints;

    // Agrupamento SEMPRE pelo ID que está no NOME da campanha (#4, #5, etc)
    $name = (string)$row['name'];
    if (!preg_match('/#\s*(\d+)/', $name, $m)) {
        continue;
    }

    $cid = (int)$m[1];

    if (!isset($groupedRaw[$cid])) {
        $groupedRaw[$cid] = [
            'esp_campaign_id' => $cid,
            'name'            => $row['name'],
            'subject'         => $row['subject'],
            'runs'            => 0,
            'sent'            => 0,
            'delivered'       => 0,
            'bounces'         => 0,
            'unique_opens'    => 0,
            'unique_clicks'   => 0,
            'unsubs'          => 0,
            'complaints'      => 0,
            'first_send'      => $row['send_datetime'],
            'last_send'       => $row['send_datetime'],
        ];
    }

    $g = &$groupedRaw[$cid];

    $g['runs']++;
    $g['sent']          += $sent;
    $g['delivered']     += $delivered;
    $g['bounces']       += $bounces;
    $g['unique_opens']  += $uniqueOpens;
    $g['unique_clicks'] += $uniqueClicks;
    $g['unsubs']        += $unsubs;
    $g['complaints']    += $complaints;

    if ($row['send_datetime'] < $g['first_send']) {
        $g['first_send'] = $row['send_datetime'];
    }
    if ($row['send_datetime'] > $g['last_send']) {
        $g['last_send'] = $row['send_datetime'];
    }
    unset($g);
}

// Calcula métricas para cada template
foreach ($groupedRaw as $g2) {
    $or         = ratePercent($g2['unique_opens'], $g2['delivered']);
    $ctr        = ratePercent($g2['unique_clicks'], $g2['delivered']);
    $ctor       = ratePercent($g2['unique_clicks'], $g2['unique_opens']);
    $bounceRate = ratePercent($g2['bounces'], $g2['sent']);
    $unsubRate  = ratePercent($g2['unsubs'], $g2['delivered']);
    $complRate  = ratePercent($g2['complaints'], $g2['delivered']);

    $status = classifyCampaign($or, $ctr, $ctor, $bounceRate, $unsubRate, $complRate);

    $g2['or']             = $or;
    $g2['ctr']            = $ctr;
    $g2['ctor']           = $ctor;
    $g2['bounce_rate']    = $bounceRate;
    $g2['unsub_rate']     = $unsubRate;
    $g2['complaint_rate'] = $complRate;
    $g2['status']         = $status;

    $groupedCampaigns[] = $g2;
}

// Map templates by ID (#ID only)
$templatesById = [];
foreach ($groupedCampaigns as $g) {
    $tid = (int)$g['esp_campaign_id'];
    $templatesById[$tid] = $g;
}

// Load ESP schedule and attach performance info
$scheduleRows     = fetchEspSchedule($pdo, $selectedEspId);
$selectedSchedule = [];
$selectedSummary  = [
    'total_slots'     => 0,
    'good'            => 0,
    'average'         => 0,
    'needs_attention' => 0,
    'no_data'         => 0,
];

foreach ($scheduleRows as &$s) {
    $tid     = (int)($s['template_label_id'] ?? 0);
    $metrics = $templatesById[$tid] ?? null;

    if ($metrics !== null) {
        $s['metrics'] = $metrics;
        $s['status']  = $metrics['status'];
    } else {
        $s['metrics'] = null;
        $s['status']  = 'No data';
    }

    if ($s['weekday'] === $selectedWeekday) {
        $selectedSchedule[] = $s;
        $selectedSummary['total_slots']++;

        switch ($s['status']) {
            case 'Good':
                $selectedSummary['good']++;
                break;
            case 'Needs attention':
                $selectedSummary['needs_attention']++;
                break;
            case 'Average':
                $selectedSummary['average']++;
                break;
            default:
                $selectedSummary['no_data']++;
                break;
        }
    }
}
unset($s);

// LAST IMPORT TIME
$sqlLastImport = "SELECT MAX(updated_at_local) AS last_import_at FROM email_campaigns";
$stmt          = $pdo->query($sqlLastImport);
$rowLastImport = $stmt->fetch(PDO::FETCH_ASSOC);
$lastImportAt  = $rowLastImport['last_import_at'] ?? null;

// Charts data
$sortedByCtrDesc = $groupedCampaigns;
usort($sortedByCtrDesc, function ($a, $b) {
    return $b['ctr'] <=> $a['ctr'];
});
$topSlice = array_slice($sortedByCtrDesc, 0, 10);
foreach ($topSlice as $c) {
    $label       = sprintf('Template #%d · %dx', $c['esp_campaign_id'], $c['runs']);
    $topLabels[] = mb_strimwidth($label, 0, 40, '…', 'UTF-8');
    $topCtr[]    = $c['ctr'];
}

$sortedByCtrAsc = $groupedCampaigns;
usort($sortedByCtrAsc, function ($a, $b) {
    return $a['ctr'] <=> $b['ctr'];
});
$worstSlice = array_slice($sortedByCtrAsc, 0, 10);
foreach ($worstSlice as $c) {
    $label         = sprintf('Template #%d · %dx', $c['esp_campaign_id'], $c['runs']);
    $worstLabels[] = mb_strimwidth($label, 0, 40, '…', 'UTF-8');
    $worstCtr[]    = $c['ctr'];
}

$usageSorted = $groupedCampaigns;
usort($usageSorted, function ($a, $b) {
    return $b['runs'] <=> $a['runs'];
});
$usageSlice = array_slice($usageSorted, 0, 10);
foreach ($usageSlice as $c) {
    $label         = sprintf('Template #%d', $c['esp_campaign_id']);
    $usageLabels[] = mb_strimwidth($label, 0, 40, '…', 'UTF-8');
    $usageRuns[]   = $c['runs'];
}

// JSON for charts
$jsTopLabels   = json_encode($topLabels);
$jsTopCtr      = json_encode($topCtr);
$jsWorstLabels = json_encode($worstLabels);
$jsWorstCtr    = json_encode($worstCtr);
$jsUsageLabels = json_encode($usageLabels);
$jsUsageRuns   = json_encode($usageRuns);

// Global metrics
$globalOr   = ratePercent($globalTotals['unique_opens'], $globalTotals['delivered']);
$globalCtr  = ratePercent($globalTotals['unique_clicks'], $globalTotals['delivered']);
$globalCtor = ratePercent($globalTotals['unique_clicks'], $globalTotals['unique_opens']);
$globalBr   = ratePercent($globalTotals['bounces'], $globalTotals['sent']);
$globalUr   = ratePercent($globalTotals['unsubs'], $globalTotals['delivered']);
$globalCr   = ratePercent($globalTotals['complaints'], $globalTotals['delivered']);

function scheduleStatusClass(string $status): string
{
    switch ($status) {
        case 'Good':
            return 'status-good';
        case 'Needs attention':
            return 'status-bad';
        case 'Average':
            return 'status-average';
        default:
            return 'status-neutral';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

        <style>
            .filter-card {
                margin-bottom: 16px;
                padding: 8px 12px;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
                background: #f9fafb;
                font-size: 0.85rem;
            }

            .filter-row {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
            }

            .filter-row label {
                font-size: 0.8rem;
                color: #4b5563;
                margin-right: 4px;
            }

            .filter-row select {
                padding: 4px 8px;
                font-size: 0.85rem;
                border-radius: 4px;
                border: 1px solid #d1d5db;
                background: #ffffff;
            }


            .filter-row select.active {
                background: #111827;
                color: #ffffff;
                border-color: #111827;
            }

            .filter-row .btn-primary {
                padding: 4px 10px;
                font-size: 0.85rem;
            }

            .collapsible-section {
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                margin-top: 24px;
                background: #ffffff;
            }

            .collapsible-section .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 12px;
                border-bottom: 1px solid #e5e7eb;
                background: #f3f4f6;
            }

            .collapsible-section .section-header h2 {
                margin: 0;
                font-size: 1rem;
            }

            .collapsible-section .section-header small {
                display: block;
                color: #6b7280;
                font-size: 0.7rem;
                margin-top: 2px;
            }

            .collapsible-section .section-body {
                padding: 12px 16px;
            }

            .collapsible-section.collapsed .section-body {
                display: none;
            }

            .toggle-section-btn {
                border: none;
                background: none;
                font-size: 0.8rem;
                color: #4b5563;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                cursor: pointer;
                padding: 4px 6px;
                border-radius: 4px;
            }

            .toggle-section-btn:hover {
                background: #e5e7eb;
            }

            .toggle-section-btn .caret {
                font-size: 0.9rem;
            }
        </style>

        <form method="post" style="margin-bottom:12px;">
            <input type="hidden" name="action" value="refresh_campaigns">
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

        <div class="global-summary" style="margin-bottom: 24px; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem;">
            <div style="display: flex; justify-content: space_between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                <div>
                    <strong>Global performance</strong><br>
                    <span style="font-size: 0.8rem; color: #6b7280;">
                        All campaigns<?= $selectedEspId !== null ? ' for selected ESP' : '' ?> for <?= htmlspecialchars($periodLabel ?? '') ?>.
                    </span>
                </div>
                <div style="font-size: 0.8rem; color: #6b7280;">
                    Totals are aggregated across all sends in this period.
                </div>
            </div>

            <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 16px;">
                <div style="min-width: 160px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Volume
                    </div>
                    <div>
                        Sent: <strong><?= number_format($globalTotals['sent']) ?></strong><br>
                        Delivered: <strong><?= number_format($globalTotals['delivered']) ?></strong>
                    </div>
                </div>

                <div style="min-width: 160px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Engagement
                    </div>
                    <div>
                        OR: <strong><?= $globalOr ?>%</strong><br>
                        CTR: <strong><?= $globalCtr ?>%</strong><br>
                        CTOR: <strong><?= $globalCtor ?>%</strong>
                    </div>
                </div>

                <div style="min-width: 160px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; margin-bottom: 2px;">
                        Negative signals
                    </div>
                    <div>
                        Bounce: <strong><?= $globalBr ?>%</strong><br>
                        Unsub: <strong><?= $globalUr ?>%</strong><br>
                        Complaints: <strong><?= $globalCr ?>%</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-card">
            <form method="get" class="filter-row">
                <div>
                    <label for="esp-id-select">ESP</label>
                    <select id="esp-id-select"
                            name="esp_id"
                            class="btn-filter <?= $selectedEspId !== null ? 'active' : '' ?>">
                        <option value="">All ESPs</option>
                        <?php foreach ($availableEspOptions as $espOption): ?>
                            <?php $espIdOption = (int)($espOption['id'] ?? 0); ?>
                            <option value="<?= $espIdOption ?>" <?= $selectedEspId === $espIdOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($espOption['name'] ?? ('ESP #' . $espIdOption))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="weekday-select">Day</label>
                    <select id="weekday-select" name="weekday">
                        <?php foreach ($weekdaysList as $wd): ?>
                            <option value="<?= htmlspecialchars($wd) ?>" <?= $wd === $selectedWeekday ? 'selected' : '' ?>>
                                <?= htmlspecialchars($wd) ?><?= $wd === $todayName ? ' (today)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($period)): ?>
                    <input type="hidden" name="period" value="<?= htmlspecialchars((string)$period) ?>">
                <?php else: ?>
                    <input type="hidden" name="days" value="<?= (int)$days ?>">
                <?php endif; ?>

                <button type="submit" class="btn-primary">
                    Apply
                </button>
                <a href="email_campaigns_report.php<?= htmlspecialchars($daysQuery) ?>" class="btn-primary">
                    Report
                </a>
            </form>
        </div>

        <div class="global-summary" style="margin-bottom: 24px; padding: 12px 16px; background: #fefce8; border: 1px solid #facc15; border-radius: 8px; font-size: 0.9rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div>
                    <strong>
                        Schedule health for <?= htmlspecialchars($selectedWeekday) ?>
                        <?= $selectedEspId !== null ? ' · ESP #' . (int)$selectedEspId : '' ?>
                    </strong>
                    <?php if ($selectedWeekday === $todayName): ?>
                        <span style="font-size:0.8rem;color:#16a34a;margin-left:4px;">(today)</span>
                    <?php endif; ?>
                    <br>
                    <span style="font-size:0.8rem;color:#6b7280;">
                        Based on template performance in the last <?= $days ?> day(s).
                    </span>
                </div>
                <div style="font-size:0.8rem;color:#6b7280;text-align:right;">
                    Slots: <strong><?= $selectedSummary['total_slots'] ?></strong> ·
                    Good: <strong><?= $selectedSummary['good'] ?></strong> ·
                    Avg: <strong><?= $selectedSummary['average'] ?></strong> ·
                    Needs attention: <strong style="color:#b91c1c;"><?= $selectedSummary['needs_attention'] ?></strong> ·
                    No data: <strong><?= $selectedSummary['no_data'] ?></strong>
                </div>
            </div>

            <?php if (!empty($selectedSchedule)): ?>
                <div style="margin-top:10px;overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ESP</th>
                                <th>Time</th>
                                <th>Template ID</th>
                                <th class="text-right">Total Clicks</th>
                                <th class="text-right">Avg Clicks/Run</th>
                                <th class="text-right">Runs</th>
                                <th class="text-right">OR %</th>
                                <th class="text-right">CTR %</th>
                                <th class="text-right">CTOR %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedSchedule as $slot): ?>
                                <?php
                                $m          = $slot['metrics'] ?? null;
                                $status     = (string)($slot['status'] ?? 'No data');
                                $statusCss  = scheduleStatusClass($status);
                                $templateId = (int)$slot['template_label_id'];

                                if ($m !== null) {
                                    $totalClicks = (int)($m['unique_clicks'] ?? 0);
                                    $runs        = (int)($m['runs'] ?? 0);
                                    $avgClicks   = $runs > 0 ? round($totalClicks / $runs, 2) : null;
                                } else {
                                    $totalClicks = 0;
                                    $runs        = 0;
                                    $avgClicks   = null;
                                }
                                ?>
                                <tr>
                                    <td>#<?= (int)$slot['esp_id'] ?></td>
                                    <td><?= htmlspecialchars(substr((string)$slot['time'], 0, 5)) ?></td>
                                    <td>#<?= htmlspecialchars((string)$templateId) ?></td>
                                    <td class="text-right">
                                        <?= $m !== null ? number_format($totalClicks) : '–' ?>
                                    </td>
                                    <td class="text-right">
                                        <?= ($m !== null && $avgClicks !== null) ? number_format($avgClicks, 2) : '–' ?>
                                    </td>
                                    <td class="text-right">
                                        <?= $m !== null ? number_format($runs) : '–' ?>
                                    </td>
                                    <td class="text-right">
                                        <?= $m !== null ? $m['or'] . '%' : '–' ?>
                                    </td>
                                    <td class="text-right">
                                        <?= $m !== null ? $m['ctr'] . '%' : '–' ?>
                                    </td>
                                    <td class="text-right">
                                        <?= $m !== null ? $m['ctor'] . '%' : '–' ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?= $statusCss ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($selectedSummary['needs_attention'] > 0): ?>
                    <div style="margin-top:8px;font-size:0.8rem;color:#b91c1c;">
                        You have <?= $selectedSummary['needs_attention'] ?> slot(s) using templates marked as
                        <strong>Needs attention</strong> on <?= htmlspecialchars($selectedWeekday) ?>.
                        Consider swapping those templates before sends.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="margin-top:8px;font-size:0.8rem;color:#9ca3af;">
                    No active schedule for this day.
                </div>
            <?php endif; ?>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-title">Top campaigns by CTR (grouped by #ID)</div>
                <div class="chart-helper">
                    Best campaigns (averaged over all sends in the last <?= $days ?> day(s)).
                </div>
                <div class="chart-container">
                    <canvas id="topCtrChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-title">Worst campaigns by CTR (grouped by #ID)</div>
                <div class="chart-helper">
                    Lowest CTR templates (averaged over all sends in the last <?= $days ?> day(s)).
                </div>
                <div class="chart-container">
                    <canvas id="worstCtrChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-title">Most used templates (by number of sends)</div>
                <div class="chart-helper">
                    Templates with the highest number of sends in the last <?= $days ?> day(s).
                </div>
                <div class="chart-container">
                    <canvas id="usageChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-wrapper collapsible-section" data-section-id="esp-schedule">
            <div class="section-header">
                <div>
                    <h2>Current ESP schedule (by weekday / slot)</h2>
                    <small>From <code>esp_schedule</code>, matched to template performance via <code>template_label_id</code>.</small>
                </div>
                <button type="button" class="toggle-section-btn" data-target="esp-schedule">
                    <span class="caret">▶</span> <span class="label">Show</span>
                </button>
            </div>
            <div class="section-body">
                <table>
                    <thead>
                        <tr>
                            <th>ESP</th>
                            <th>Weekday</th>
                            <th>Slot</th>
                            <th>Time</th>
                            <th>Template ID</th>
                            <th>Pattern</th>
                            <th class="text-right">Runs</th>
                            <th class="text-right">OR %</th>
                            <th class="text-right">CTR %</th>
                            <th class="text-right">CTOR %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduleRows as $slot): ?>
                            <?php
                            $m          = $slot['metrics'] ?? null;
                            $status     = (string)($slot['status'] ?? 'No data');
                            $statusCss  = scheduleStatusClass($status);
                            $templateId = (int)$slot['template_label_id'];
                            $isSelected = ($slot['weekday'] === $selectedWeekday);
                            ?>
                            <tr <?= $isSelected ? 'style="background:#ecfeff;"' : '' ?>>
                                <td>#<?= (int)$slot['esp_id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($slot['weekday']) ?>
                                    <?php if ($slot['weekday'] === $todayName): ?>
                                        <span class="badge-date" style="margin-left:4px;">Today</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><?= (int)$slot['slot_index'] ?></td>
                                <td><?= htmlspecialchars(substr((string)$slot['time'], 0, 5)) ?></td>
                                <td>#<?= htmlspecialchars((string)$templateId) ?></td>
                                <td><?= htmlspecialchars($slot['name_pattern']) ?></td>
                                <td class="text-right">
                                    <?= $m !== null ? number_format((int)$m['runs']) : '–' ?>
                                </td>
                                <td class="text-right">
                                    <?= $m !== null ? $m['or'] . '%' : '–' ?>
                                </td>
                                <td class="text-right">
                                    <?= $m !== null ? $m['ctr'] . '%' : '–' ?>
                                </td>
                                <td class="text-right">
                                    <?= $m !== null ? $m['ctor'] . '%' : '–' ?>
                                </td>
                                <td>
                                    <span class="badge-status <?= $statusCss ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($scheduleRows)): ?>
                            <tr>
                                <td colspan="11" style="text-align:center;color:#9ca3af;padding:16px;">
                                    No schedule rows found (table <code>esp_schedule</code> is empty or all slots inactive).
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-wrapper collapsible-section" data-section-id="template-performance">
            <div class="section-header">
                <div>
                    <h2>Template performance (grouped by #ID)</h2>
                    <small>Each row is a template (number after <code>#</code>), aggregated over all sends in the period.</small>
                </div>
                <button type="button" class="toggle-section-btn" data-target="template-performance">
                    <span class="caret">▶</span> <span class="label">Show</span>
                </button>
            </div>
            <div class="section-body">
                <table>
                    <thead>
                        <tr>
                            <th>Template #</th>
                            <th>Subject (sample)</th>
                            <th class="text-right">Runs</th>
                            <th class="text-right">Total Sent</th>
                            <th class="text-right">Total Delivered</th>
                            <th class="text-right">OR %</th>
                            <th class="text-right">CTR %</th>
                            <th class="text-right">CTOR %</th>
                            <th class="text-right">Bounce %</th>
                            <th class="text-right">Unsub %</th>
                            <th class="text-right">Complaints %</th>
                            <th>First / Last send</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupedCampaigns as $g): ?>
                            <?php
                            $status      = $g['status'];
                            $statusClass = 'status-average';
                            if ($status === 'Good') {
                                $statusClass = 'status-good';
                            } elseif ($status === 'Needs attention') {
                                $statusClass = 'status-bad';
                            }
                            ?>
                            <tr>
                                <td>
                                    <span class="campaign-id">#<?= htmlspecialchars((string)$g['esp_campaign_id']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($g['subject']) ?></td>
                                <td class="text-right"><?= number_format($g['runs']) ?></td>
                                <td class="text-right"><?= number_format($g['sent']) ?></td>
                                <td class="text-right"><?= number_format($g['delivered']) ?></td>
                                <td class="text-right"><?= $g['or'] ?>%</td>
                                <td class="text-right"><?= $g['ctr'] ?>%</td>
                                <td class="text-right"><?= $g['ctor'] ?>%</td>
                                <td class="text-right"><?= $g['bounce_rate'] ?>%</td>
                                <td class="text-right"><?= $g['unsub_rate'] ?>%</td>
                                <td class="text-right"><?= $g['complaint_rate'] ?>%</td>
                                <td>
                                    <span class="badge-date"><?= htmlspecialchars($g['first_send']) ?></span><br>
                                    <span class="badge-date"><?= htmlspecialchars($g['last_send']) ?></span>
                                </td>
                                <td>
                                    <span class="badge-status <?= $statusClass ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($groupedCampaigns)): ?>
                            <tr>
                                <td colspan="13" style="text-align:center;color:#9ca3af;padding:16px;">
                                    No templates found for this period / selected ESP.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-wrapper collapsible-section" data-section-id="individual-sends">
            <div class="section-header">
                <div>
                    <h2>Individual sends (last <?= $days ?> day(s))</h2>
                    <small>Each row is a single send, sorted by send date (newest first).</small>
                </div>
                <button type="button" class="toggle-section-btn" data-target="individual-sends">
                    <span class="caret">▶</span> <span class="label">Show</span>
                </button>
            </div>
            <div class="section-body">
                <table>
                    <thead>
                        <tr>
                            <th>ESP</th>
                            <th>Campaign</th>
                            <th>Subject</th>
                            <th>Send Date</th>
                            <th class="text-right">Sent</th>
                            <th class="text-right">Delivered</th>
                            <th class="text-right">OR %</th>
                            <th class="text-right">CTR %</th>
                            <th class="text-right">CTOR %</th>
                            <th class="text-right">Bounce %</th>
                            <th class="text-right">Unsub %</th>
                            <th class="text-right">Complaints %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            $row    = $c['raw'];
                            $or     = $c['or'];
                            $ctr    = $c['ctr'];
                            $ctor   = $c['ctor'];
                            $br     = $c['bounce_rate'];
                            $ur     = $c['unsub_rate'];
                            $cr     = $c['complaint_rate'];
                            $status = $c['status'];

                            $statusClass = 'status-average';
                            if ($status === 'Good') {
                                $statusClass = 'status-good';
                            } elseif ($status === 'Needs attention') {
                                $statusClass = 'status-bad';
                            }
                            ?>
                            <tr>
                                <td>#<?= (int)$row['esp_id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($row['name']) ?><br>
                                    <span class="campaign-id">ID #<?= htmlspecialchars((string)$row['esp_campaign_id']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['subject']) ?></td>
                                <td>
                                    <span class="badge-date">
                                        <?= htmlspecialchars($row['send_datetime']) ?>
                                    </span>
                                </td>
                                <td class="text-right"><?= number_format($c['sent']) ?></td>
                                <td class="text-right"><?= number_format($c['delivered']) ?></td>
                                <td class="text-right"><?= $or ?>%</td>
                                <td class="text-right"><?= $ctr ?>%</td>
                                <td class="text-right"><?= $ctor ?>%</td>
                                <td class="text-right"><?= $br ?>%</td>
                                <td class="text-right"><?= $ur ?>%</td>
                                <td class="text-right"><?= $cr ?>%</td>
                                <td>
                                    <span class="badge-status <?= $statusClass ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($campaigns)): ?>
                            <tr>
                                <td colspan="13" style="text-align:center;color:#9ca3af;padding:16px;">
                                    No campaigns found for this period / selected ESP.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const topLabels = <?= $jsTopLabels ?>;
        const topCtr = <?= $jsTopCtr ?>;
        const worstLabels = <?= $jsWorstLabels ?>;
        const worstCtr = <?= $jsWorstCtr ?>;
        const usageLabels = <?= $jsUsageLabels ?>;
        const usageRuns = <?= $jsUsageRuns ?>;

        const ctxTop = document.getElementById('topCtrChart').getContext('2d');
        new Chart(ctxTop, {
            type: 'bar',
            data: {
                labels: topLabels,
                datasets: [{
                    label: 'CTR %',
                    data: topCtr
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                }
            }
        });

        const ctxWorst = document.getElementById('worstCtrChart').getContext('2d');
        new Chart(ctxWorst, {
            type: 'bar',
            data: {
                labels: worstLabels,
                datasets: [{
                    label: 'CTR %',
                    data: worstCtr
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                }
            }
        });

        const ctxUsage = document.getElementById('usageChart').getContext('2d');
        new Chart(ctxUsage, {
            type: 'bar',
            data: {
                labels: usageLabels,
                datasets: [{
                    label: 'Runs (number of sends)',
                    data: usageRuns
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => value.toLocaleString()
                        }
                    }
                }
            }
        });

        (function() {
            const sections = document.querySelectorAll('.collapsible-section');
            const STORAGE_PREFIX = 'jld_section_';

            function applyState(section, state) {
                const btn = section.querySelector('.toggle-section-btn');
                const caret = btn.querySelector('.caret');
                const label = btn.querySelector('.label');

                if (state === 'open') {
                    section.classList.remove('collapsed');
                    caret.textContent = '▼';
                    label.textContent = 'Hide';
                } else {
                    section.classList.add('collapsed');
                    caret.textContent = '▶';
                    label.textContent = 'Show';
                }
            }

            sections.forEach(section => {
                const id = section.getAttribute('data-section-id');
                if (!id) return;

                const key = STORAGE_PREFIX + id;
                let state = localStorage.getItem(key);

                if (state !== 'open' && state !== 'closed') {
                    state = 'closed';
                }
                applyState(section, state);

                const btn = section.querySelector('.toggle-section-btn');
                if (!btn) return;

                btn.addEventListener('click', () => {
                    const current = section.classList.contains('collapsed') ? 'closed' : 'open';
                    const next = current === 'open' ? 'closed' : 'open';
                    applyState(section, next);
                    localStorage.setItem(key, next);
                });
            });
        })();
    </script>

    <script src="assets/dashboard.js"></script>
</body>

</html>