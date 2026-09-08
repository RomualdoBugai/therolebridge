<?php

/*
 * Bloqueia bots/crawlers/fetchers antes de gastar request com provider.
 * Deve ser incluído ANTES de chamar Jooble/Talroo.
 *
 * Variáveis esperadas:
 * $provider, $utmSource, $utmMedium, $utmCampaign, $utmId, $email,
 * $keyword, $city, $state, $zip, $ipAddress
 */

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? mb_substr(trim((string) $_SERVER['HTTP_USER_AGENT']), 0, 255)
    : '';

$accept = isset($_SERVER['HTTP_ACCEPT'])
    ? mb_substr(trim((string) $_SERVER['HTTP_ACCEPT']), 0, 255)
    : '';

$acceptLanguage = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
    ? mb_substr(trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']), 0, 255)
    : '';

$blockReason = null;

$botPatterns = [
    // SEO / crawlers clássicos
    'AhrefsBot',
    'SemrushBot',
    'MJ12bot',
    'DotBot',
    'BLEXBot',
    'PetalBot',
    'Bytespider',
    'YandexBot',
    'Baiduspider',
    'DuckDuckBot',
    'Sogou',
    'Exabot',
    'MegaIndex',
    'SeznamBot',
    'DataForSeo',
    'Googlebot',
    'bingbot',
    'Slurp',
    'crawler',
    'spider',

    // Social/link preview bots
    'facebookexternalhit',
    'Facebot',
    'Twitterbot',
    'LinkedInBot',
    'WhatsApp',
    'TelegramBot',
    'Discordbot',
    'Slackbot',
    'SkypeUriPreview',

    // Fetchers / clients automáticos
    'python-requests',
    'python-urllib',
    'curl/',
    'wget/',
    'go-http-client',
    'apache-httpclient',
    'libwww-perl',
    'aiohttp',
    'httpclient',
    'okhttp',
    'Java/',
    'node-fetch',
    'axios',
    'GuzzleHttp',
    'PostmanRuntime',
    'Scrapy',

    // Browser automation
    'HeadlessChrome',
    'PhantomJS',
    'Selenium',
    'Puppeteer',
    'Playwright',

    // Email/image proxies
    'YahooMailProxy',
    'GoogleImageProxy',
    'ggpht.com',
    'Google-Read-Aloud',
    'FeedFetcher-Google',
    'Google-Apps-Script',
];

// 1) User-agent vazio
if ($userAgent === '') {
    $blockReason = 'empty_user_agent';
}

// 2) Padrões óbvios de bot
if ($blockReason === null) {
    foreach ($botPatterns as $pattern) {
        if (stripos($userAgent, $pattern) !== false) {
            $blockReason = 'ua_match:' . $pattern;
            break;
        }
    }
}

// 3) Accept vazio ou genérico demais
if ($blockReason === null && ($accept === '' || $accept === '*/*')) {
    $blockReason = 'suspicious_accept_header';
}

// 4) UA precisa parecer browser real.
// Mas NÃO bloqueia Safari/WebKit incompleto direto,
// porque pode ser webview/scanner legítimo do app de email.
if ($blockReason === null) {
    $looksLikeBrowser =
        stripos($userAgent, 'Mozilla/') !== false &&
        (
            stripos($userAgent, 'Chrome/') !== false ||
            stripos($userAgent, 'CriOS/') !== false ||
            stripos($userAgent, 'Safari/') !== false ||
            stripos($userAgent, 'Firefox/') !== false ||
            stripos($userAgent, 'FxiOS/') !== false ||
            stripos($userAgent, 'Edg/') !== false ||
            stripos($userAgent, 'EdgiOS/') !== false ||
            stripos($userAgent, 'OPR/') !== false ||
            stripos($userAgent, 'SamsungBrowser/') !== false ||

            // iPhone/iPad Gmail/Safari às vezes vem sem "Safari/"
            (
                (
                    stripos($userAgent, 'iPhone') !== false ||
                    stripos($userAgent, 'iPad') !== false
                ) &&
                stripos($userAgent, 'AppleWebKit') !== false &&
                stripos($userAgent, 'Mobile/') !== false
            ) ||

            // Android WebView/Gmail também pode vir sem Chrome completo
            (
                stripos($userAgent, 'Android') !== false &&
                stripos($userAgent, 'AppleWebKit') !== false &&
                stripos($userAgent, 'Mobile') !== false
            ) ||

            // WebKit incompleto em Mac.
            // Exemplo:
            // Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)
            // AppleWebKit/605.1.15 (KHTML, like Gecko)
            // Não é bot claro. Não bloqueia direto.
            (
                stripos($userAgent, 'Macintosh') !== false &&
                stripos($userAgent, 'AppleWebKit') !== false &&
                stripos($userAgent, 'KHTML, like Gecko') !== false
            )
        );

    if (!$looksLikeBrowser) {
        $blockReason = 'non_browser_ua';
    }
}

/*
 * 5) Accept-Language ausente:
 * Eu NÃO bloquearia sozinho. Pode dar falso positivo.
 * Mas se quiser ser mais agressivo, descomenta.
 */

// if ($blockReason === null && $acceptLanguage === '') {
//     $blockReason = 'missing_accept_language';
// }

if ($blockReason !== null) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/functions.php';

    insertJobClickSuspicious(
        $provider ?? null,
        $utmSource ?? null,
        $utmMedium ?? null,
        $utmCampaign ?? null,
        $utmId ?? null,
        $email ?? null,
        $keyword ?? null,
        $city ?? null,
        $state ?? null,
        $zip ?? null,
        $userAgent,
        $ipAddress ?? null
    );

    error_log(
        'Suspicious job request blocked: ' . $blockReason
            . ' | UA=' . $userAgent
            . ' | Accept=' . $accept
            . ' | Lang=' . $acceptLanguage
            . ' | IP=' . ($ipAddress ?? '')
    );

    http_response_code(204);
    exit;
}
