<?php
// email_campaigns_performance.php
// Dashboard: compare campaign performance with filters by period, ESP, weekday and campaign code extracted from name (#XXX)

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

$pdo = getPdoConnection();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getParam(string $key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function ratePercent(int $num, int $den): float
{
    if ($den <= 0) {
        return 0.0;
    }

    return round(($num * 100.0) / $den, 2);
}

function buildQueryString(array $overrides = array()): string
{
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return http_build_query($params);
}

function sortLink(string $column, string $label, string $currentOrderBy, string $currentOrderDir): string
{
    $dir = 'asc';
    if ($currentOrderBy === $column && $currentOrderDir === 'asc') {
        $dir = 'desc';
    }

    $arrow = '';
    if ($currentOrderBy === $column) {
        $arrow = $currentOrderDir === 'asc' ? ' ▲' : ' ▼';
    }

    $query = buildQueryString(array(
        'order_by' => $column,
        'order_dir' => $dir,
        'page' => 1,
    ));

    return '<a href="?' . h($query) . '">' . h($label . $arrow) . '</a>';
}

function getStatusClass(float $ctr, float $ctor, float $openRate): string
{
    if ($ctr >= 1.50 && $ctor >= 12.00 && $openRate >= 12.00) {
        return 'status-good';
    }

    if ($ctr >= 0.70 && $ctor >= 7.00 && $openRate >= 8.00) {
        return 'status-average';
    }

    return 'status-bad';
}

function getStatusLabel(float $ctr, float $ctor, float $openRate): string
{
    if ($ctr >= 1.50 && $ctor >= 12.00 && $openRate >= 12.00) {
        return 'Good';
    }

    if ($ctr >= 0.70 && $ctor >= 7.00 && $openRate >= 8.00) {
        return 'Average';
    }

    return 'Needs attention';
}

$dateStart = getParam('date_start', $startDate);
$dateEnd   = getParam('date_end', $endDate);
$espId     = getParam('esp_id', '');
$search    = getParam('search', '');
$templateId = getParam('template_id', '');
$espCampaignId = getParam('esp_campaign_id', '');
$weekday   = strtolower(getParam('weekday', ''));
$orderBy   = getParam('order_by', 'scheduled_at');
$orderDir  = strtolower(getParam('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
$page      = (int)getParam('page', 1);
$perPage   = (int)getParam('per_page', 100);

if ($page < 1) {
    $page = 1;
}

$allowedPerPage = array(25, 50, 100, 200);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 100;
}

$allowedWeekdays = array(
    'monday' => 1,
    'tuesday' => 2,
    'wednesday' => 3,
    'thursday' => 4,
    'friday' => 5,
    'saturday' => 6,
    'sunday' => 7,
);

if ($weekday !== '' && !isset($allowedWeekdays[$weekday])) {
    $weekday = '';
}

// Campaign code is extracted from name after #, e.g. "#161"
$templateExpr = "CASE WHEN c.name LIKE '%#%' THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.name, '#', -1), ' ', 1) AS UNSIGNED) ELSE NULL END";
$sendDateExpr = 'COALESCE(c.scheduled_at, c.created_at_esp, c.created_at_local)';

$allowedOrderBy = array(
    'id' => 'c.id',
    'name' => 'c.name',
    'esp' => 'e.name',
    'template_id' => 'template_id_extracted',
    'esp_campaign_id' => 'c.esp_campaign_id',
    'scheduled_at' => 'send_datetime',
    'total_sent' => 'total_sent',
    'delivered' => 'delivered',
    'unique_opened' => 'unique_opened',
    'unique_clicked' => 'unique_clicked',
    'open_rate' => 'open_rate',
    'ctr' => 'ctr',
    'ctor' => 'ctor',
    'unsubs' => 'unsubs',
    'bounced' => 'bounced',
    'complaints' => 'complaints'
);

if (!isset($allowedOrderBy[$orderBy])) {
    $orderBy = 'scheduled_at';
}

$offset = ($page - 1) * $perPage;

$espStmt = $pdo->query('SELECT id, name FROM esp ORDER BY name ASC');
$espList = $espStmt->fetchAll(PDO::FETCH_ASSOC);

$filters = array();
$params = array();

$filters[] = 'DATE(' . $sendDateExpr . ') BETWEEN :date_start AND :date_end';
$params[':date_start'] = $dateStart;
$params[':date_end'] = $dateEnd;

if ($espId !== '') {
    $filters[] = 'c.esp_id = :esp_id';
    $params[':esp_id'] = (int)$espId;
}

if ($search !== '') {
    $filters[] = '(
        c.name LIKE :search_name
        OR c.subject LIKE :search_subject
        OR e.name LIKE :search_esp
        OR c.sender_email LIKE :search_sender_email
        OR c.sender_name LIKE :search_sender_name
    )';

    $searchLike = '%' . $search . '%';
    $params[':search_name'] = $searchLike;
    $params[':search_subject'] = $searchLike;
    $params[':search_esp'] = $searchLike;
    $params[':search_sender_email'] = $searchLike;
    $params[':search_sender_name'] = $searchLike;
}

if ($templateId !== '') {
    $filters[] = $templateExpr . ' = :template_id';
    $params[':template_id'] = (int)$templateId;
}

if ($espCampaignId !== '') {
    $filters[] = 'c.esp_campaign_id = :esp_campaign_id';
    $params[':esp_campaign_id'] = (int)$espCampaignId;
}

if ($weekday !== '') {
    $filters[] = 'WEEKDAY(' . $sendDateExpr . ') = :weekday_number';
    $params[':weekday_number'] = $allowedWeekdays[$weekday] - 1;
}

$whereSql = '';
if (!empty($filters)) {
    $whereSql = 'WHERE ' . implode("\nAND ", $filters);
}

$countSql = "
    SELECT COUNT(*)
    FROM email_campaigns c
    LEFT JOIN esp e ON e.id = c.esp_id
    $whereSql
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT
        c.id,
        c.esp_id,
        c.esp_campaign_id,
        c.name,
        c.subject,
        c.sender_email,
        c.sender_name,
        c.created_at_esp,
        c.modified_at_esp,
        c.scheduled_at,
        c.global_sent,
        c.global_delivered,
        c.global_hard_bounces,
        c.global_soft_bounces,
        c.global_unique_views,
        c.global_unique_clicks,
        c.global_unsubscriptions,
        c.global_complaints,
        e.name AS esp_name,
        {$templateExpr} AS template_id_extracted,
        {$sendDateExpr} AS send_datetime,
        DAYNAME({$sendDateExpr}) AS weekday_name,
        COALESCE(c.global_sent, 0) AS total_sent,
        COALESCE(c.global_delivered, 0) AS delivered,
        (COALESCE(c.global_hard_bounces, 0) + COALESCE(c.global_soft_bounces, 0)) AS bounced,
        COALESCE(c.global_unique_views, 0) AS unique_opened,
        COALESCE(c.global_unique_clicks, 0) AS unique_clicked,
        COALESCE(c.global_unsubscriptions, 0) AS unsubs,
        COALESCE(c.global_complaints, 0) AS complaints,
        ROUND((COALESCE(c.global_unique_views, 0) / NULLIF(COALESCE(c.global_delivered, 0), 0)) * 100, 2) AS open_rate,
        ROUND((COALESCE(c.global_unique_clicks, 0) / NULLIF(COALESCE(c.global_delivered, 0), 0)) * 100, 2) AS ctr,
        ROUND((COALESCE(c.global_unique_clicks, 0) / NULLIF(COALESCE(c.global_unique_views, 0), 0)) * 100, 2) AS ctor
    FROM email_campaigns c
    LEFT JOIN esp e ON e.id = c.esp_id
    $whereSql
    ORDER BY {$allowedOrderBy[$orderBy]} $orderDir, c.id DESC
    LIMIT :limit OFFSET :offset
";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$summarySql = "
    SELECT
        COUNT(*) AS campaigns,
        SUM(COALESCE(c.global_sent, 0)) AS total_sent,
        SUM(COALESCE(c.global_delivered, 0)) AS delivered,
        SUM(COALESCE(c.global_unique_views, 0)) AS unique_opened,
        SUM(COALESCE(c.global_unique_clicks, 0)) AS unique_clicked,
        SUM(COALESCE(c.global_unsubscriptions, 0)) AS unsubs,
        SUM(COALESCE(c.global_complaints, 0)) AS complaints,
        SUM(COALESCE(c.global_hard_bounces, 0) + COALESCE(c.global_soft_bounces, 0)) AS bounced
    FROM email_campaigns c
    LEFT JOIN esp e ON e.id = c.esp_id
    $whereSql
";
$summaryStmt = $pdo->prepare($summarySql);
foreach ($params as $key => $value) {
    $summaryStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$summaryStmt->execute();
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

$summaryCampaigns = (int)($summary['campaigns'] ?? 0);
$summarySent = (int)($summary['total_sent'] ?? 0);
$summaryDelivered = (int)($summary['delivered'] ?? 0);
$summaryOpened = (int)($summary['unique_opened'] ?? 0);
$summaryClicked = (int)($summary['unique_clicked'] ?? 0);
$summaryUnsubs = (int)($summary['unsubs'] ?? 0);
$summaryComplaints = (int)($summary['complaints'] ?? 0);
$summaryBounced = (int)($summary['bounced'] ?? 0);

$summaryOpenRate = ratePercent($summaryOpened, $summaryDelivered);
$summaryCtr = ratePercent($summaryClicked, $summaryDelivered);
$summaryCtor = ratePercent($summaryClicked, $summaryOpened);

$summaryUnsubRate = ratePercent($summaryUnsubs, $summaryDelivered);

$dailyChartSql = "
    SELECT
        DATE({$sendDateExpr}) AS day,
        SUM(COALESCE(c.global_unique_views, 0)) AS unique_opened,
        SUM(COALESCE(c.global_unique_clicks, 0)) AS unique_clicked
    FROM email_campaigns c
    LEFT JOIN esp e ON e.id = c.esp_id
    $whereSql
    GROUP BY DATE({$sendDateExpr})
    ORDER BY day ASC
";
$dailyChartStmt = $pdo->prepare($dailyChartSql);
foreach ($params as $key => $value) {
    $dailyChartStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$dailyChartStmt->execute();
$dailyChartRows = $dailyChartStmt->fetchAll(PDO::FETCH_ASSOC);

$chartLabelsDays = array();
$chartUniqueOpensSeries = array();
$chartUniqueClicksSeries = array();

foreach ($dailyChartRows as $chartRow) {
    $chartLabelsDays[] = $chartRow['day'];
    $chartUniqueOpensSeries[] = (int)($chartRow['unique_opened'] ?? 0);
    $chartUniqueClicksSeries[] = (int)($chartRow['unique_clicked'] ?? 0);
}

$jsChartLabelsDays = json_encode($chartLabelsDays);
$jsChartUniqueOpensSeries = json_encode($chartUniqueOpensSeries);
$jsChartUniqueClicksSeries = json_encode($chartUniqueClicksSeries);

$weekdayOptions = array(
    '' => 'All weekdays',
    'monday' => 'Monday',
    'tuesday' => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday' => 'Thursday',
    'friday' => 'Friday',
    'saturday' => 'Saturday',
    'sunday' => 'Sunday',
);
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/partials/head.php'; ?>

<body>
    <div class="container">

        <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>
        <div class="table-wrapper" style="margin-bottom: 24px;">
            <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Filters</h2>

            <form method="get" class="filters" style="align-items: end;">
                <?php if ($period): ?>
                    <input type="hidden" name="period" value="<?= h($period) ?>">
                <?php else: ?>
                    <input type="hidden" name="days" value="<?= h($days) ?>">
                <?php endif; ?>

                <label>
                    Start date<br>
                    <input type="date" name="date_start" value="<?= h($dateStart) ?>">
                </label>

                <label>
                    End date<br>
                    <input type="date" name="date_end" value="<?= h($dateEnd) ?>">
                </label>

                <label>
                    ESP<br>
                    <select name="esp_id">
                        <option value="">All ESPs</option>
                        <?php foreach ($espList as $esp): ?>
                            <option value="<?= h($esp['id']) ?>" <?= (string)$espId === (string)$esp['id'] ? 'selected' : '' ?>>
                                <?= h($esp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Weekday<br>
                    <select name="weekday">
                        <?php foreach ($weekdayOptions as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $weekday === $key ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Search<br>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Campaign, subject, sender...">
                </label>

                <label>
                    Template ID (#)<br>
                    <input type="text" name="template_id" value="<?= h($templateId) ?>" placeholder="161">
                </label>

                <label>
                    ESP campaign ID<br>
                    <input type="text" name="esp_campaign_id" value="<?= h($espCampaignId) ?>" placeholder="245">
                </label>

                <label>
                    Rows<br>
                    <select name="per_page">
                        <?php foreach ($allowedPerPage as $size): ?>
                            <option value="<?= h($size) ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= h($size) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit">Apply</button>
                <a href="?days=<?= h($days) ?>" class="nav-pill">Reset</a>
            </form>
        </div>

        <div class="table-wrapper" style="margin-bottom: 24px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
                <div>
                    <h2 style="margin: 0 0 8px 0; font-size: 1rem;">Campaign performance</h2>
                    <p style="margin:0;font-size:0.8rem;color:#6b7280;">
                        Showing <?= number_format(count($rows)) ?> of <?= number_format($totalRows) ?> campaigns.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                    <span class="nav-pill">Campaigns: <?= number_format($summaryCampaigns) ?></span>
                    <span class="nav-pill">Sent: <?= number_format($summarySent) ?></span>
                    <span class="nav-pill">Delivered: <?= number_format($summaryDelivered) ?></span>
                    <span class="nav-pill">Clicks: <?= number_format($summaryClicked) ?></span>
                    <span class="nav-pill">OR: <?= number_format($summaryOpenRate, 2) ?>%</span>
                    <span class="nav-pill">CTR: <?= number_format($summaryCtr, 2) ?>%</span>
                    <span class="nav-pill">CTOR: <?= number_format($summaryCtor, 2) ?>%</span>
                    <span class="nav-pill">
                        Unsubs: <?= number_format($summaryUnsubs) ?>
                        (<?= number_format($summaryUnsubRate, 2) ?>%)
                    </span> <!-- <span class="nav-pill">Complaints: <?= number_format($summaryComplaints) ?></span> -->
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th><?= sortLink('id', 'ID', $orderBy, $orderDir) ?></th>
                        <th><?= sortLink('esp_campaign_id', 'ESP ID', $orderBy, $orderDir) ?></th>
                        <th><?= sortLink('esp', 'ESP', $orderBy, $orderDir) ?></th>
                        <th><?= sortLink('scheduled_at', 'Send Date', $orderBy, $orderDir) ?></th>
                        <th><?= sortLink('name', 'Campaign', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('total_sent', 'Sent', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('delivered', 'Delivered', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('unique_opened', 'Opened', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('unique_clicked', 'Clicked', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('open_rate', 'OR', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('ctr', 'CTR', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('ctor', 'CTOR', $orderBy, $orderDir) ?></th>
                        <th class="text-right"><?= sortLink('unsubs', 'Unsubs', $orderBy, $orderDir) ?></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="17" style="text-align:center;color:#9ca3af;padding:16px;">No campaigns found for these filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $openRate = (float)$row['open_rate'];
                            $ctr = (float)$row['ctr'];
                            $ctor = (float)$row['ctor'];
                            $statusCss = getStatusClass($ctr, $ctor, $openRate);
                            $statusText = getStatusLabel($ctr, $ctor, $openRate);
                            ?>
                            <tr>
                                <td><?= number_format((int)$row['id']) ?></td>
                                <td class="text-right"><?= number_format((int)$row['esp_campaign_id']) ?></td>
                                <td><?= h($row['esp_name']) ?></td>
                                <td>
                                    <?php if (!empty($row['send_datetime'])): ?>
                                        <span class="badge-date"><?= h($row['send_datetime']) ?></span>
                                        <div class="campaign-id"><?= h($row['weekday_name']) ?></div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= h($row['name']) ?></strong>
                                </td>
                                <td class="text-right"><?= number_format((int)$row['total_sent']) ?></td>
                                <td class="text-right"><?= number_format((int)$row['delivered']) ?></td>
                                <td class="text-right"><?= number_format((int)$row['unique_opened']) ?></td>
                                <td class="text-right"><?= number_format((int)$row['unique_clicked']) ?></td>
                                <td class="text-right"><?= number_format($openRate, 2) ?>%</td>
                                <td class="text-right"><?= number_format($ctr, 2) ?>%</td>
                                <td class="text-right"><?= number_format($ctor, 2) ?>%</td>
                                <td class="text-right"><?= number_format((int)$row['unsubs']) ?></td>
                                <td><span class="badge-status <?= h($statusCss) ?>"><?= h($statusText) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;">
                <div style="font-size:0.8rem;color:#6b7280;">
                    Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                </div>
                <div class="top-nav-right">
                    <?php if ($page > 1): ?>
                        <a class="nav-pill" href="?<?= h(buildQueryString(array('page' => 1))) ?>">First</a>
                        <a class="nav-pill" href="?<?= h(buildQueryString(array('page' => $page - 1))) ?>">Prev</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                        <a class="nav-pill <?= $p === $page ? 'primary' : '' ?>" href="?<?= h(buildQueryString(array('page' => $p))) ?>"><?= h($p) ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="nav-pill" href="?<?= h(buildQueryString(array('page' => $page + 1))) ?>">Next</a>
                        <a class="nav-pill" href="?<?= h(buildQueryString(array('page' => $totalPages))) ?>">Last</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-title">Daily unique opens</div>
                <div class="chart-helper">
                    Daily global_unique_views for campaigns matching the current filters, with a 7-day moving average.
                </div>
                <div class="chart-container">
                    <canvas id="uniqueOpensChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-title">Daily unique clicks</div>
                <div class="chart-helper">
                    Daily global_unique_clicks for campaigns matching the current filters, with a 7-day moving average.
                </div>
                <div class="chart-container">
                    <canvas id="uniqueClicksChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        const labelsDays = <?= $jsChartLabelsDays ?>;
        const uniqueOpensSeries = <?= $jsChartUniqueOpensSeries ?>;
        const uniqueClicksSeries = <?= $jsChartUniqueClicksSeries ?>;

        function buildSevenDayAverage(series) {
            return series.map((value, index, arr) => {
                const start = Math.max(0, index - 6);
                const windowValues = arr.slice(start, index + 1);
                const sum = windowValues.reduce((total, current) => total + Number(current || 0), 0);

                return Number((sum / windowValues.length).toFixed(2));
            });
        }

        function formatChartDate(rawLabel) {
            const date = new Date(rawLabel + 'T00:00:00');

            if (isNaN(date.getTime())) {
                return rawLabel;
            }

            const weekday = date.toLocaleDateString('en-US', { weekday: 'short' });
            const month = date.toLocaleDateString('en-US', { month: 'short' });
            const day = date.getDate();

            return weekday + ' ' + month + ' ' + day;
        }

        function getSharedDailyChartOptions() {
            return {
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
                                return formatChartDate(this.getLabelForValue(value));
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
            };
        }

        const ctxUniqueOpens = document.getElementById('uniqueOpensChart').getContext('2d');
        new Chart(ctxUniqueOpens, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [
                    {
                        label: 'Unique opens',
                        data: uniqueOpensSeries,
                        tension: 0.3
                    },
                    {
                        label: '7-day average',
                        data: buildSevenDayAverage(uniqueOpensSeries),
                        tension: 0.3,
                        borderWidth: 3,
                        pointRadius: 0
                    }
                ]
            },
            options: getSharedDailyChartOptions()
        });

        const ctxUniqueClicks = document.getElementById('uniqueClicksChart').getContext('2d');
        new Chart(ctxUniqueClicks, {
            type: 'line',
            data: {
                labels: labelsDays,
                datasets: [
                    {
                        label: 'Unique clicks',
                        data: uniqueClicksSeries,
                        tension: 0.3
                    },
                    {
                        label: '7-day average',
                        data: buildSevenDayAverage(uniqueClicksSeries),
                        tension: 0.3,
                        borderWidth: 3,
                        pointRadius: 0
                    }
                ]
            },
            options: getSharedDailyChartOptions()
        });
    </script>

    <script src="assets/dashboard.js"></script>
</body>

</html>