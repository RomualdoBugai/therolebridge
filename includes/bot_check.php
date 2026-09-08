<?php
// Pega o user-agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$botPatterns = [
    // SEO / crawlers clássicos
    'AhrefsBot',
    'SemrushBot',
    'MJ12bot',
    'DotBot',
    'crawler',
    'spider',

    // fetchers / clients automáticos
    // 'python-requests',
    // 'python-urllib',
    // 'curl/',
    // 'wget/',
    // 'go-http-client',
    // 'apache-httpclient',
    // 'libwww-perl',
    // 'aiohttp',
    // 'httpclient',
    // 'okhttp',

    // browser automation
    // 'HeadlessChrome',
    // 'PhantomJS',
    // 'Selenium',
    // 'Puppeteer',
    // 'Playwright',

    // email/image proxies
    // 'YahooMailProxy',
    // 'GoogleImageProxy',
    // 'ggpht.com',
];

// Se bater com qualquer padrão, mata o script aqui
foreach ($botPatterns as $pattern) {
    if (stripos($userAgent, $pattern) !== false) {

        // log
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/functions.php';

        // -----------------------------------------------------
        // Salvar log de clique suspicioso (AGORA com utm_id + provider)
        // -----------------------------------------------------
        insertJobClickSuspicious(
            $provider,
            $utmSource,
            $utmMedium,
            $utmCampaign,
            $utmId,
            $email,
            $keyword,
            $city,
            $state,
            $zip,
            $userAgent,
            $ipAddress
        );

        // Opção 1: só retorna 403 e não faz nada
        http_response_code(403);
        exit;
    }
}
