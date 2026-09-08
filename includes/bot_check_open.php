<?php

/*
 * Guard leve para abertura/visualização de email.
 *
 * Objetivo:
 * - NÃO gastar processamento com bots óbvios.
 * - NÃO quebrar tracking de abertura por Gmail/Yahoo/Apple/Outlook proxies.
 *
 * Use este arquivo SOMENTE em pixel/open/view de email.
 * NÃO use este arquivo para Jooble/Talroo/API paga.
 *
 * Variáveis opcionais aceitas antes da inclusão:
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

$ipAddress = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null);

$blockReason = null;

/*
 * Para abertura de email, NÃO bloquear:
 * - GoogleImageProxy
 * - YahooMailProxy
 * - Apple image proxy / mail proxy
 * - Outlook / Microsoft proxy
 *
 * Esses podem representar abertura real ou pré-carregamento de email.
 * Se bloquear, seus opens ficam errados.
 */
$blockedPatterns = [
    // SEO / crawlers pesados
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
    'crawler',
    'spider',

    // Scripts / fetchers automáticos
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

    // Social/link previews que não representam abertura real do usuário
    'facebookexternalhit',
    'Facebot',
    'Twitterbot',
    'LinkedInBot',
    'TelegramBot',
    'Discordbot',
    'Slackbot',
    'SkypeUriPreview',
];

// 1) UA vazio: para open tracking, NÃO bloqueia. Só registra se quiser no log.
// Muitos proxies podem ter headers incompletos.

// 2) Bloqueia somente padrões claramente ruins.
foreach ($blockedPatterns as $pattern) {
    if ($userAgent !== '' && stripos($userAgent, $pattern) !== false) {
        $blockReason = 'email_open_ua_match:' . $pattern;
        break;
    }
}

// 3) Accept vazio ou */* NÃO bloqueia abertura de email.
// Em pixel tracking isso pode acontecer e não justifica matar o request.

// 4) Accept-Language vazio NÃO bloqueia abertura de email.
// Proxies de email frequentemente não mandam isso.

if ($blockReason !== null) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/functions.php';

    if (function_exists('insertJobClickSuspicious')) {
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
            $ipAddress
        );
    }

    error_log(
        'Suspicious email open blocked: ' . $blockReason
        . ' | UA=' . $userAgent
        . ' | Accept=' . $accept
        . ' | Lang=' . $acceptLanguage
        . ' | IP=' . ($ipAddress ?? '')
    );

    // Para pixel/open tracking, não precisa devolver 403.
    // 204 é mais leve e não entrega conteúdo.
    http_response_code(204);
    exit;
}
