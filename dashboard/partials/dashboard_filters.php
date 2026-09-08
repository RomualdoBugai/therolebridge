<?php
// partials/dashboard_filters.php
// Shared dashboard date/filter state. Include once in every dashboard page and in dashboard_top_nav.php.

$dashboardAllowedDays = [0, 1, 7, 30, 90, 120];
$days = isset($_GET['days']) ? (int) $_GET['days'] : 0;

if (!in_array($days, $dashboardAllowedDays, true)) {
    $days = 30;
}

$dashboardAllowedPeriods = ['this_day_last_week', 'this_month', 'last_month'];
$period = $_GET['period'] ?? null;

if (!in_array($period, $dashboardAllowedPeriods, true)) {
    $period = null;
}

$dashboardWeekdaysList = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

$todayName = (new DateTimeImmutable('now'))->format('l');
$weekdaysList = $dashboardWeekdaysList;

$weekday = $_GET['weekday'] ?? null;
if (!in_array($weekday, $dashboardWeekdaysList, true)) {
    $weekday = null;
}

// Used mainly by email_campaigns_performance.php schedule health block.
// If no weekday is selected, default to today instead of leaving NULL.
$selectedWeekday = $weekday ?: $todayName;

$today = new DateTimeImmutable('today');

if ($period === 'this_day_last_week') {
    $targetDate = $today->modify('-7 days');
    $startDate = $targetDate->format('Y-m-d');
    $endDate = $targetDate->format('Y-m-d');
    $periodLabel = "This day last week ({$startDate})";
} elseif ($period === 'this_month') {
    $startDate = $today->modify('first day of this month')->format('Y-m-d');
    $endDate = $today->format('Y-m-d');
    $periodLabel = "This month ({$startDate} to {$endDate})";
} elseif ($period === 'last_month') {
    $startDate = $today->modify('first day of last month')->format('Y-m-d');
    $endDate = $today->modify('last day of last month')->format('Y-m-d');
    $periodLabel = "Last month ({$startDate} to {$endDate})";
} elseif ($days === 0) {
    $startDate = $today->format('Y-m-d');
    $endDate = $today->format('Y-m-d');
    $periodLabel = 'Today only';
} elseif ($days === 1) {
    $startDate = $today->modify('-1 day')->format('Y-m-d');
    $endDate = $today->modify('-1 day')->format('Y-m-d');
    $periodLabel = "Yesterday ({$startDate})";
} else {
    // Last N days includes today, so 7 days = today + previous 6 days.
    $startDate = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $endDate = $today->format('Y-m-d');
    $periodLabel = "Last {$days} days ({$startDate} to {$endDate})";
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';
$endDateTimeExclusive = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d 00:00:00');

if (!function_exists('dashboardBuildFilterUrl')) {
    function dashboardBuildFilterUrl(array $params = []): string
    {
        $query = [];

        if (!empty($_GET['weekday'])) {
            $query['weekday'] = $_GET['weekday'];
        }

        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }

        return '?' . http_build_query($query);
    }
}

$dashboardTopNavParams = [];

if ($period) {
    $dashboardTopNavParams['period'] = $period;
} else {
    $dashboardTopNavParams['days'] = $days;
}

if ($weekday) {
    $dashboardTopNavParams['weekday'] = $weekday;
}

$daysQuery = '?' . http_build_query($dashboardTopNavParams);
