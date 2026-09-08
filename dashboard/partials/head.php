<?php
$currentScript = basename($_SERVER['PHP_SELF']);

// Título, subtítulo e aba ativa por página
if ($currentScript === 'email_revenue_dashboard.php') {
    $siteTitle    = 'Revenue Dashboard';
    $siteSubtitle = 'Track revenue (earnings_daily) and update it manually.';
    $activeTab    = 'revenue';
} elseif ($currentScript === 'email_campaigns_daily_monitor.php') {
    $siteTitle    = 'Daily Monitor';
    $siteSubtitle = 'Daily monitoring of Brevo sends (table <code>email_campaigns</code>): volume, rates, and health by day.';
    $activeTab    = 'daily';
} elseif ($currentScript === 'email_clicks_funnel.php') {
    $siteTitle    = 'Clicks Funnel';
    $siteSubtitle = 'Funnel de cliques entre: <code>email_campaigns</code> (ESP clicks), <code>job_clicks</code> (link no email) e <code>job_clicks_out</code> (botão na página de jobs).';
    $activeTab    = 'clicks_funnel';
} elseif ($currentScript === 'email_campaigns_performance.php') {
    $siteTitle    = 'Campaign Performance';
    $siteSubtitle = 'Compare Brevo campaigns by OR, CTR, CTOR, bounce, unsubs and complaints. Campaign ID is the number after <code>#</code>.';
    $activeTab    = 'performance';
} elseif ($currentScript === 'email_leads_volume.php') {
    $siteTitle    = 'Leads Volume';
    $siteSubtitle = 'Track how many leads are entering <code>record_leads</code>, from which <code>provider_data_id</code>, how many are synced to each ESP, and how many are still pending sync.';
    $activeTab    = 'volume';
} elseif ($currentScript === 'provider_clicks.php') {
    $siteTitle    = 'Provider Clicks';
    $siteSubtitle = 'Leads por provider que clicaram em vagas (job_clicks / job_clicks_out).';
    $activeTab    = 'provider_clicks';
} elseif ($currentScript === 'brevo_credits.php') {
    $siteTitle    = 'Brevo Credits';
    $siteSubtitle = 'Créditos restantes, projeção de uso e médias de envio por conta Brevo.';
    $activeTab    = 'brevo_credits';
} elseif ($currentScript === 'brevo_schedule.php') {
    $siteTitle    = 'Campaign Schedule';
    $siteSubtitle = 'Campanhas agendadas (queued) nos próximos 7 dias, por conta Brevo.';
    $activeTab    = 'brevo_schedule';
} else {
    // fallback defensivo
    $siteTitle    = 'ESP Analytics';
    $siteSubtitle = '';
    $activeTab    = '';
}
?>

<head>
    <meta charset="UTF-8">
    <title><?= $siteTitle ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- favicon -->
    <link rel="shortcut icon" href="assets/favicon.ico">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/dashboard.css">
</head>