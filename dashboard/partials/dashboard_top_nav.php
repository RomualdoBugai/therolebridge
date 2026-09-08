<?php
// partials/dashboard_top_nav.php

require_once __DIR__ . '/dashboard_filters.php';

$siteTitle = $siteTitle ?? 'The Role Bridge';
$siteSubtitle = $siteSubtitle ?? '';
$activeTab = $activeTab ?? '';
?>

<div class="top-nav">
    <a style="font-size:1rem;color:#6b7280;" href="https://therolebridge.com" target="_blank">
        <?= htmlspecialchars($siteTitle) ?> · ESP Analytics
    </a>

    <!-- Desktop links -->
    <div class="top-nav-right">
        <a href="email_revenue_dashboard.php<?= htmlspecialchars($daysQuery) ?>"
            class="nav-pill <?= $activeTab === 'revenue' ? 'primary' : '' ?>">
            Revenue
        </a>

        <a href="email_campaigns_daily_monitor.php<?= htmlspecialchars($daysQuery) ?>"
            class="nav-pill <?= $activeTab === 'daily' ? 'primary' : '' ?>">
            Daily Monitor
        </a>

        <a href="email_clicks_funnel.php<?= htmlspecialchars($daysQuery) ?>"
            class="nav-pill <?= $activeTab === 'clicks_funnel' ? 'primary' : '' ?>">
            Clicks Funnel
        </a>

        <a href="email_campaigns_performance.php<?= htmlspecialchars($daysQuery) ?>"
            class="nav-pill <?= $activeTab === 'performance' ? 'primary' : '' ?>">
            Campaign Performance
        </a>

        <a href="email_leads_volume.php<?= htmlspecialchars($daysQuery) ?>"
            class="nav-pill <?= $activeTab === 'volume' ? 'primary' : '' ?>">
            Leads Volume
        </a>
    </div>

    <!-- Mobile menu button -->
    <button class="top-nav-toggle" type="button" onclick="toggleTopNavMenu()">
        ☰
    </button>
</div>

<!-- Mobile menu -->
<div class="top-nav-mobile-menu" id="topNavMobileMenu">
    <a href="email_revenue_dashboard.php<?= htmlspecialchars($daysQuery) ?>"
        class="nav-pill <?= $activeTab === 'revenue' ? 'primary' : '' ?>">
        Revenue
    </a>

    <a href="email_campaigns_daily_monitor.php<?= htmlspecialchars($daysQuery) ?>"
        class="nav-pill <?= $activeTab === 'daily' ? 'primary' : '' ?>">
        Daily Monitor
    </a>

    <a href="email_clicks_funnel.php<?= htmlspecialchars($daysQuery) ?>"
        class="nav-pill <?= $activeTab === 'clicks_funnel' ? 'primary' : '' ?>">
        Clicks Funnel
    </a>

    <a href="email_campaigns_performance.php<?= htmlspecialchars($daysQuery) ?>"
        class="nav-pill <?= $activeTab === 'performance' ? 'primary' : '' ?>">
        Campaign Performance
    </a>

    <a href="email_leads_volume.php<?= htmlspecialchars($daysQuery) ?>"
        class="nav-pill <?= $activeTab === 'volume' ? 'primary' : '' ?>">
        Leads Volume
    </a>
</div>

<!-- Period filters -->
<?php if (empty($hideFilters)): ?>
<div class="filters">
    <?php
    $quickOptions = [
        0 => 'Today',
    ];

    $rangeOptions = [
        1   => 'Yesterday',
        7   => 'Last 7 days',
        30  => 'Last 30 days',
        90  => 'Last 90 days',
        120 => 'Last 120 days',
    ];

    $monthOptions = [
        'this_day_last_week' => 'This Day Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
    ];

    foreach ($quickOptions as $val => $label):
        $classes = ['btn-filter'];

        if (!$period && $days === (int) $val) {
            $classes[] = 'active';
        }

        if ($weekday) {
            $classes[] = 'has-weekday';
        }
    ?>
        <a href="<?= htmlspecialchars(dashboardBuildFilterUrl(['days' => $val])) ?>"
            class="<?= htmlspecialchars(implode(' ', $classes)) ?>">
            <?= htmlspecialchars($label) ?>
        </a>
    <?php endforeach; ?>

    <?php
    $rangeSelectClasses = ['btn-filter'];
    $isRangeSelectActive = (!$period && in_array($days, array_keys($rangeOptions), true));

    if ($isRangeSelectActive) {
        $rangeSelectClasses[] = 'active';
    }

    if ($weekday) {
        $rangeSelectClasses[] = 'has-weekday';
    }
    ?>

    <select class="<?= htmlspecialchars(implode(' ', $rangeSelectClasses)) ?>"
        onchange="if (this.value) window.location.href = this.value;">
        <option value="" <?= !$isRangeSelectActive ? 'selected' : '' ?>>
            More ranges
        </option>

        <?php foreach ($rangeOptions as $val => $label): ?>
            <option value="<?= htmlspecialchars(dashboardBuildFilterUrl(['days' => $val])) ?>"
                <?= (!$period && $days === (int) $val) ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php
    $monthSelectClasses = ['btn-filter'];
    $isMonthSelectActive = in_array($period, array_keys($monthOptions), true);

    if ($isMonthSelectActive) {
        $monthSelectClasses[] = 'active';
    }

    if ($weekday) {
        $monthSelectClasses[] = 'has-weekday';
    }
    ?>

    <select class="<?= htmlspecialchars(implode(' ', $monthSelectClasses)) ?>"
        onchange="if (this.value) window.location.href = this.value;">
        <option value="" <?= !$isMonthSelectActive ? 'selected' : '' ?>>
            Month
        </option>

        <?php foreach ($monthOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars(dashboardBuildFilterUrl(['period' => $value])) ?>"
                <?= ($period === $value) ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>