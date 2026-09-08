<?php

/**
 * Subprocess page runner.
 *
 * Receives a JSON config file path as $argv[1].
 * The config contains the path to the SHADOW copy of the page (already patched
 * with if (!function_exists()) guards), so mock functions defined here survive
 * the page's require_once calls without modifying any production file.
 */

$cfg = json_decode(file_get_contents($argv[1] ?? ''), true);
if (!$cfg) {
    file_put_contents('php://stderr', "page_runner: invalid config\n");
    exit(1);
}

$page       = $cfg['page'];        // path to shadow page (e.g. /tmp/bugai_shadow_.../jobs.php)
$mockJobs   = $cfg['mock_jobs']   ?? [];
$resultFile = $cfg['result_file'] ?? '';

// ----------------------------------------------------------------
// 1. Define mock functions BEFORE loading the shadow includes.
//    The shadow files have if (!function_exists()) guards so these
//    mocks will NOT be overwritten by the real implementations.
// ----------------------------------------------------------------

function getJobProvidersCacheDir(): string
{
    return sys_get_temp_dir() . '/bugai_test_cache_' . getmypid();
}
function getJobProvidersCacheFile(): string
{
    return getJobProvidersCacheDir() . '/provider_jobs_active.json';
}
function getJobProvidersCacheLockFile(): string
{
    return getJobProvidersCacheDir() . '/provider_jobs_active.lock';
}
function getJobProvidersCacheTtl(): int
{
    return 86400;
}

function geoLookupCityStateZipByIp(string $ip): array
{
    return ['', '', '', ''];
}

function geoEnrichLeadLocation(string $email, string $city, string $state, string $zip): void {}

function fetchUnifiedJobs(
    string $provider, string $email, string $keyword, string $location,
    string $state, string $city, int $page = 1, int $perPage = 20
): array {
    $jobs = $GLOBALS['__runner_mock_jobs'] ?? [];
    return ['jobs' => $jobs, 'error' => empty($jobs), 'meta' => []];
}

function joobleFallBackFetchRawJobs(
    string $provider, string $keyword, string $location,
    string $state, string $city, int $page = 1, int $perPage = 20
): array {
    return ['jobs' => [], 'error' => false];
}

function talrooFetchRawJobs(
    string $provider, string $email, string $keyword, string $location,
    string $state, string $city, int $page = 1, int $perPage = 20
): array {
    return ['jobs' => [], 'error' => false, 'total' => 0, 'start' => 0, 'count' => 0];
}

// Captures redirect URL instead of sending HTTP header (no-op in PHP CLI anyway)
function pageRedirect(string $url, int $code = 302): void
{
    $GLOBALS['__page_runner_redirect'] = $url;
    exit;
}

// ----------------------------------------------------------------
// 2. Load the shadow config.php (reads the test .env → test DB creds)
// ----------------------------------------------------------------

require_once dirname($page) . '/includes/config.php';

// ----------------------------------------------------------------
// 3. Ensure per-process test cache dir exists
// ----------------------------------------------------------------

$testCacheDir = getJobProvidersCacheDir();
if (!is_dir($testCacheDir)) {
    mkdir($testCacheDir, 0775, true);
}

// ----------------------------------------------------------------
// 4. Store mock jobs for fetchUnifiedJobs
// ----------------------------------------------------------------

$GLOBALS['__runner_mock_jobs'] = $mockJobs;

// ----------------------------------------------------------------
// 5. Set up HTTP-like superglobals
// ----------------------------------------------------------------

$getParams = $cfg['get'] ?? [];
$_GET      = $getParams;
$_POST     = [];
$_COOKIE   = [];

$_SERVER = array_merge([
    'HTTP_HOST'            => 'localhost',
    'REQUEST_METHOD'       => 'GET',
    'REQUEST_URI'          => '/' . basename($page) . ($getParams ? '?' . http_build_query($getParams) : ''),
    'QUERY_STRING'         => http_build_query($getParams),
    'REMOTE_ADDR'          => '127.0.0.1',
    'HTTP_USER_AGENT'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.5',
    'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
    'HTTPS'                => 'on',
], $cfg['server'] ?? []);

// ----------------------------------------------------------------
// 6. Capture: shutdown function writes results even if exit() is called
// ----------------------------------------------------------------

register_shutdown_function(static function () use ($resultFile, $testCacheDir): void {
    $output = ob_get_contents();
    ob_end_clean();

    $result = [
        'output'                => (string) $output,
        'location'              => $GLOBALS['__page_runner_redirect'] ?? '',
        'job_clicks'            => [],
        'job_clicks_out'        => [],
        'job_clicks_suspicious' => [],
    ];

    try {
        // Open a fresh PDO using the same env vars the page used
        $host    = getenv('DB_HOST') ?: '127.0.0.1';
        $port    = getenv('DB_PORT') ?: '3306';
        $db      = getenv('DB_NAME') ?: '';
        $user    = getenv('DB_USER') ?: 'root';
        $pass    = getenv('DB_PASS') ?: '';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=$charset",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $result['job_clicks']            = $pdo->query('SELECT * FROM job_clicks ORDER BY id DESC LIMIT 10')->fetchAll();
        $result['job_clicks_out']        = $pdo->query('SELECT * FROM job_clicks_out ORDER BY id DESC LIMIT 10')->fetchAll();
        $result['job_clicks_suspicious'] = $pdo->query('SELECT * FROM job_clicks_suspicious ORDER BY id DESC LIMIT 10')->fetchAll();
    } catch (Throwable $e) {
        $result['db_error'] = $e->getMessage();
    }

    if ($resultFile) {
        file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    // Clean per-process cache dir
    foreach (glob($testCacheDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($testCacheDir);
});

ob_start();

// ----------------------------------------------------------------
// 7. Run the shadow page (already patched with guards)
// ----------------------------------------------------------------

include $page;
