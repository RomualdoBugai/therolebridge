<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/*
 * Bot / crawler / scanner protection.
 *
 * Espera que default_get.php já tenha definido:
 * $userAgent, $ipAddress, $provider, $utmSource, $utmMedium, $utmCampaign,
 * $utmId, $email, $keyword, $city, $state, $zip
 */

$acceptLang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
    ? mb_substr(trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']), 0, 255)
    : '';

$accept = isset($_SERVER['HTTP_ACCEPT'])
    ? mb_substr(trim((string) $_SERVER['HTTP_ACCEPT']), 0, 255)
    : '';

$userAgent = isset($userAgent)
    ? mb_substr(trim((string) $userAgent), 0, 255)
    : '';

$ipAddress = isset($ipAddress)
    ? trim((string) $ipAddress)
    : '';

$provider    = isset($provider) ? (string) $provider : '';
$utmSource   = isset($utmSource) ? (string) $utmSource : '';
$utmMedium   = isset($utmMedium) ? (string) $utmMedium : '';
$utmCampaign = isset($utmCampaign) ? (string) $utmCampaign : '';
$utmId       = isset($utmId) ? (string) $utmId : '';
$email       = isset($email) ? strtolower(trim((string) $email)) : '';
$keyword     = isset($keyword) ? (string) $keyword : '';
$city        = isset($city) ? (string) $city : '';
$state       = isset($state) ? (string) $state : '';
$zip         = isset($zip) ? (string) $zip : '';

$ctx = [
    'provider'    => $provider,
    'utmSource'   => $utmSource,
    'utmMedium'   => $utmMedium,
    'utmCampaign' => $utmCampaign,
    'utmId'       => $utmId,
    'email'       => $email,
    'keyword'     => $keyword,
    'city'        => $city,
    'state'       => $state,
    'zip'         => $zip,
    'userAgent'   => $userAgent,
    'ipAddress'   => $ipAddress,
];

$botPatterns = [
    // Crawlers / SEO
    'AhrefsBot',
    'SemrushBot',
    'MJ12bot',
    'DotBot',
    'BLEXBot',
    'Googlebot',
    'bingbot',
    'Baiduspider',
    'YandexBot',
    'DuckDuckBot',
    'crawler',
    'spider',
    'slurp',

    // Google / scanners / AI
    'Google-Read-Aloud',
    'GoogleOther',
    'AdsBot-Google',
    'Google-InspectionTool',
    'Applebot',
    'Bytespider',
    'PetalBot',
    'ClaudeBot',
    'GPTBot',
    'ChatGPT-User',
    'CCBot',

    // Internal / imports / SES failures
    'amazon-ses-failure',
    'manual unsubscribe import',

    // HTTP clients
    'python-requests',
    'python-urllib',
    'curl/',
    'wget/',
    'Go-http-client',
    'apache-httpclient',
    'libwww-perl',
    'aiohttp',
    'okhttp',
    'httpclient',
    'java/',
    'ruby',
    'perl/',

    // Browser automation
    'HeadlessChrome',
    'PhantomJS',
    'Selenium',
    'WebDriver',
    'Puppeteer',
    'Playwright',

    // Social / email preview / proxy
    'facebookexternalhit',
    'Facebot',
    'Twitterbot',
    'LinkedInBot',
    'TelegramBot',
    'WhatsApp',
    'Slackbot',
    'YahooMailProxy',
    'GoogleImageProxy',
    'Discordbot',
    'iframely',
    'Embedly',

    // Monitoring
    'Pingdom',
    'UptimeRobot',
    'StatusCake',
    'GTmetrix',
    'Site24x7',
    'DatadogSynthetics',
];

function blockSuspicious(string $reason, array $context): void
{
    if (function_exists('insertJobClickSuspicious')) {
        insertJobClickSuspicious(
            $context['provider'] ?? '',
            $context['utmSource'] ?? '',
            $context['utmMedium'] ?? '',
            $context['utmCampaign'] ?? '',
            $context['utmId'] ?? '',
            $context['email'] ?? '',
            $context['keyword'] ?? '',
            $context['city'] ?? '',
            $context['state'] ?? '',
            $context['zip'] ?? '',
            $context['userAgent'] ?? '',
            $context['ipAddress'] ?? '',
        );
    } else {
        error_log('bot_check blocked: ' . $reason . ' ip=' . ($context['ipAddress'] ?? '') . ' email=' . ($context['email'] ?? ''));
    }

    http_response_code(403);
    exit;
}

function ipStartsWith(string $ip, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (strncmp($ip, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }

    return false;
}

function isValidPublicIp(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function isLocalOrPrivateIp(string $ip): bool
{
    if ($ip === '') {
        return true;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && !isValidPublicIp($ip);
}

function simpleRateLimit(string $key, int $limit, int $windowSeconds): bool
{
    $dir = sys_get_temp_dir() . '/click_rl';

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('rate_limit: failed to create dir ' . $dir);
        return false;
    }

    $file = $dir . '/' . md5($key) . '.json';
    $now = time();

    $data = [
        'count' => 0,
        'since' => $now,
    ];

    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            $data['count'] = isset($decoded['count']) ? (int) $decoded['count'] : 0;
            $data['since'] = isset($decoded['since']) ? (int) $decoded['since'] : $now;
        }
    }

    if (($now - $data['since']) > $windowSeconds) {
        $data = [
            'count' => 0,
            'since' => $now,
        ];
    }

    $data['count']++;

    file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] > $limit;
}

function looksLikeRealBrowser(string $userAgent): bool
{
    return preg_match('/Chrome|CriOS|Safari|Firefox|FxiOS|Edg|EdgiOS|SamsungBrowser|DuckDuckGo|Ddg|GSA/i', $userAgent) === 1;
}

function hasKnownBotPattern(string $userAgent, array $patterns): ?string
{
    foreach ($patterns as $pattern) {
        if ($pattern !== '' && stripos($userAgent, $pattern) !== false) {
            return $pattern;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| 1. Bloqueios básicos
|--------------------------------------------------------------------------
*/

if ($userAgent === '') {
    blockSuspicious('empty_user_agent', $ctx);
}

$matchedBot = hasKnownBotPattern($userAgent, $botPatterns);

if ($matchedBot !== null) {
    blockSuspicious('bot_pattern:' . $matchedBot, $ctx);
}

/*
|--------------------------------------------------------------------------
| 2. IP local, privado ou inválido
|--------------------------------------------------------------------------
| 127.0.0.1 / private IP não deveria chegar como usuário real.
| Se chegar, é evento interno, proxy mal resolvido ou erro no resolveClientIp().
*/

if ($ipAddress === '') {
    blockSuspicious('empty_ip', $ctx);
}

if (isLocalOrPrivateIp($ipAddress)) {
    blockSuspicious('local_or_private_ip:' . $ipAddress, $ctx);
}

/*
|--------------------------------------------------------------------------
| 3. Headers suspeitos
|--------------------------------------------------------------------------
| Não bloqueia Accept vazio sozinho se parece browser real.
| Bloqueia quando combina com UA não-browser.
*/

$hasBrowserUa = looksLikeRealBrowser($userAgent);

if ($acceptLang === '' && !$hasBrowserUa) {
    blockSuspicious('missing_accept_language_non_browser', $ctx);
}

if (($accept === '' || $accept === '*/*') && !$hasBrowserUa) {
    blockSuspicious('missing_or_wildcard_accept_non_browser', $ctx);
}

/*
|--------------------------------------------------------------------------
| 4. Datacenter / hosting conhecido
|--------------------------------------------------------------------------
| Esses prefixes apareceram muito nos seus logs com padrão artificial.
| Para click-out/provider, pode bloquear direto.
*/

$datacenterIpPrefixes = [
    '5.39.',
    '5.135.',
    '37.187.',
    '46.105.',
    '51.38.',
    '51.89.',
    '51.195.',
    '51.255.',
    '54.36.',
    '54.37.',
    '54.38.',
    '79.137.',
    '87.98.',
    '91.121.',
    '91.134.',
    '145.239.',
    '147.135.',
    '151.80.',
    '164.132.',
    '178.32.',
    '178.33.',
    '188.165.',
    '193.70.',
    '217.182.',
];

if (ipStartsWith($ipAddress, $datacenterIpPrefixes)) {
    blockSuspicious('datacenter_ip_prefix:' . $ipAddress, $ctx);
}

/*
|--------------------------------------------------------------------------
| 5. Rate limit
|--------------------------------------------------------------------------
| Não deixa baixo demais porque mobile carrier NAT pode compartilhar IP.
| Aqui está mais equilibrado:
| - IP: 25/h
| - email: 8/h
| - IP + email: 4 em 10min
*/

if (simpleRateLimit('ip:' . $ipAddress, 25, 3600)) {
    blockSuspicious('rate_limit_ip_per_hour:' . $ipAddress, $ctx);
}

if ($email !== '' && simpleRateLimit('email:' . $email, 8, 3600)) {
    blockSuspicious('rate_limit_email_per_hour:' . $email, $ctx);
}

if ($email !== '' && simpleRateLimit('ip_email:' . $ipAddress . ':' . $email, 4, 600)) {
    blockSuspicious('rate_limit_ip_email_10min:' . $email . ':' . $ipAddress, $ctx);
}

/*
|--------------------------------------------------------------------------
| 6. OK - segue fluxo normal
|--------------------------------------------------------------------------
*/