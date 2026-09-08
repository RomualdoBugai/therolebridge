<?php

declare(strict_types=1);

// ----------------------------------------------------------------
// Constants
// ----------------------------------------------------------------

define('TESTING', true);
define('APP_DIR', dirname(__DIR__));
define('TEST_DIR', __DIR__);
define('TEST_CACHE_DIR', __DIR__ . '/cache');
define('TEST_DB_HOST', '127.0.0.1');
define('TEST_DB_PORT', '3306');
define('TEST_DB_USER', 'root');
define('TEST_DB_PASS', '');
define('TEST_DB_CHARSET', 'utf8mb4');
define('TEST_DB_NAME', 'bugai_test_' . substr(md5((string) getmypid() . microtime()), 0, 8));
define('TEST_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

function log_info(string $msg): void
{
    fwrite(STDERR, "\033[36m[test]\033[0m " . $msg . "\n");
}

function log_ok(string $msg): void
{
    fwrite(STDERR, "\033[32m[test]\033[0m " . $msg . "\n");
}

function log_warn(string $msg): void
{
    fwrite(STDERR, "\033[33m[test]\033[0m " . $msg . "\n");
}

if (!is_dir(TEST_CACHE_DIR)) {
    mkdir(TEST_CACHE_DIR, 0775, true);
}

// ----------------------------------------------------------------
// Safety: never touch production database
// ----------------------------------------------------------------

(static function (): void {
    // TEST_DB_NAME must always follow the expected pattern
    if (!str_starts_with(TEST_DB_NAME, 'bugai_test_')) {
        throw new RuntimeException(
            'ABORTED: TEST_DB_NAME "' . TEST_DB_NAME . '" does not start with "bugai_test_". ' .
            'Refusing to run tests against an unexpected database.'
        );
    }

    // Parse the production .env (active lines only — skip comments and blanks)
    $envFile = APP_DIR . '/.env';
    $prodEnv = [];
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
            $prodEnv[trim($key)] = trim($val);
        }
    }

    $prodDb   = $prodEnv['DB_NAME']   ?? '';
    $prodHost = $prodEnv['DB_HOST']   ?? '';
    $prodPort = $prodEnv['DB_PORT']   ?? '3306';
    $prodUser = $prodEnv['DB_USER']   ?? '';

    // DB name must never match production
    if ($prodDb !== '' && TEST_DB_NAME === $prodDb) {
        throw new RuntimeException(
            'ABORTED: TEST_DB_NAME matches the production database "' . $prodDb . '". ' .
            'Tests must use a dedicated database.'
        );
    }

    // If pointing at the same host+port, user must differ
    $sameHost = (TEST_DB_HOST === $prodHost && TEST_DB_PORT === $prodPort);
    if ($sameHost && $prodUser !== '' && TEST_DB_USER === $prodUser) {
        throw new RuntimeException(
            'ABORTED: Tests are connecting to the same host/port as production ' .
            '(' . TEST_DB_HOST . ':' . TEST_DB_PORT . ') using the production user "' . $prodUser . '". ' .
            'Use a separate user for tests.'
        );
    }

    log_ok('Safety check passed — test DB is isolated from production');
})();

// ----------------------------------------------------------------
// Create test database
// ----------------------------------------------------------------

$__adminPdo = new PDO(
    'mysql:host=' . TEST_DB_HOST . ';port=' . TEST_DB_PORT . ';charset=' . TEST_DB_CHARSET,
    TEST_DB_USER,
    TEST_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

log_info('DB: ' . TEST_DB_NAME . ' @ ' . TEST_DB_HOST . ':' . TEST_DB_PORT);
$__adminPdo->exec('CREATE DATABASE IF NOT EXISTS `' . TEST_DB_NAME . '`');

// ----------------------------------------------------------------
// Build shadow directory — patched copies of production files
// ----------------------------------------------------------------

$shadowRoot = sys_get_temp_dir() . '/bugai_shadow_' . TEST_DB_NAME;

/**
 * Wrap a named function in the given PHP source with if (!function_exists()) guard.
 * Uses the PHP tokenizer so strings/comments containing braces are handled correctly.
 */
function shadowAddGuard(string $source, string $funcName): string
{
    $tokens     = token_get_all($source);
    $tokenCount = count($tokens);

    $offsets = [];
    $pos     = 0;
    foreach ($tokens as $i => $token) {
        $offsets[$i] = $pos;
        $pos += strlen(is_array($token) ? $token[1] : $token);
    }

    $funcIdx = -1;
    for ($i = 0; $i < $tokenCount; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING && $t[1] === $funcName) {
                $funcIdx = $i;
            }
            break;
        }
        if ($funcIdx >= 0) {
            break;
        }
    }

    if ($funcIdx < 0) {
        return $source;
    }

    $funcStart = $offsets[$funcIdx];

    $depth  = 0;
    $endIdx = -1;
    for ($i = $funcIdx + 1; $i < $tokenCount; $i++) {
        $val = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($val === '{') {
            $depth++;
        } elseif ($val === '}') {
            $depth--;
            if ($depth === 0) {
                $endIdx = $i;
                break;
            }
        }
    }

    if ($endIdx < 0) {
        return $source;
    }

    $funcEnd  = $offsets[$endIdx] + strlen(is_array($tokens[$endIdx]) ? $tokens[$endIdx][1] : $tokens[$endIdx]);
    $funcCode = substr($source, $funcStart, $funcEnd - $funcStart);
    $wrapped  = "if (!function_exists('{$funcName}')) {\n{$funcCode}\n}";

    return substr($source, 0, $funcStart) . $wrapped . substr($source, $funcEnd);
}

/**
 * Build the shadow directory from scratch.
 */
function buildShadow(string $appDir, string $shadowRoot, string $testCacheDir): void
{
    if (is_dir($shadowRoot)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($shadowRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($shadowRoot);
    }

    mkdir($shadowRoot . '/includes', 0775, true);

    foreach (glob($appDir . '/includes/*.php') ?: [] as $file) {
        copy($file, $shadowRoot . '/includes/' . basename($file));
    }

    copy($appDir . '/jobs.php',     $shadowRoot . '/jobs.php');
    copy($appDir . '/jobs-out.php', $shadowRoot . '/jobs-out.php');

    foreach (['vendor', 'partials', 'css', 'js', 'images', 'fonts'] as $dir) {
        if (is_dir($appDir . '/' . $dir)) {
            symlink($appDir . '/' . $dir, $shadowRoot . '/' . $dir);
        }
    }

    symlink($testCacheDir, $shadowRoot . '/cache');

    file_put_contents($shadowRoot . '/.env', implode("\n", [
        'DB_HOST='    . TEST_DB_HOST,
        'DB_PORT='    . TEST_DB_PORT,
        'DB_NAME='    . TEST_DB_NAME,
        'DB_USER='    . TEST_DB_USER,
        'DB_PASS='    . TEST_DB_PASS,
        'DB_CHARSET=' . TEST_DB_CHARSET,
    ]));

    // ---- Patch functions.php ----------------------------------------
    $src = file_get_contents($shadowRoot . '/includes/functions.php');

    foreach (['getJobProvidersCacheDir', 'getJobProvidersCacheFile',
              'getJobProvidersCacheLockFile', 'getJobProvidersCacheTtl',
              'geoLookupCityStateZipByIp', 'geoEnrichLeadLocation'] as $fn) {
        $src = shadowAddGuard($src, $fn);
    }

    $src = str_replace(
        "include_once __DIR__ . '/default_get.php';",
        "if (!function_exists('pageRedirect')) {\n" .
        "    function pageRedirect(string \$url, int \$code = 302): void\n" .
        "    {\n" .
        "        header('Location: ' . \$url, true, \$code);\n" .
        "        exit;\n" .
        "    }\n" .
        "}\n\n" .
        "include_once __DIR__ . '/default_get.php';",
        $src
    );

    file_put_contents($shadowRoot . '/includes/functions.php', $src);

    // ---- Patch provider_jobs.php ------------------------------------
    $src = file_get_contents($shadowRoot . '/includes/provider_jobs.php');

    foreach (['joobleFallBackFetchRawJobs',
              'talrooFetchRawJobs', 'fetchUnifiedJobs'] as $fn) {
        $src = shadowAddGuard($src, $fn);
    }

    file_put_contents($shadowRoot . '/includes/provider_jobs.php', $src);

    // ---- Patch jobs-out.php -----------------------------------------
    $src = file_get_contents($shadowRoot . '/jobs-out.php');

    $src = str_replace(
        "    header('Location: ' . \$targetUrl, true, 302);\n    exit;",
        '    pageRedirect($targetUrl);',
        $src
    );
    $src = str_replace(
        "header('Location: job-grid.php' . \$linkCompleteHref, true, 302);\nexit;",
        "pageRedirect('job-grid.php' . \$linkCompleteHref);",
        $src
    );

    file_put_contents($shadowRoot . '/jobs-out.php', $src);
}

log_info('Shadow: building...');
buildShadow(APP_DIR, $shadowRoot, TEST_CACHE_DIR);
define('TEST_SHADOW_DIR', $shadowRoot);

// ----------------------------------------------------------------
// Verify shadow syntax
// ----------------------------------------------------------------

foreach (['includes/functions.php', 'includes/provider_jobs.php', 'jobs-out.php'] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg(TEST_SHADOW_DIR . '/' . $f) . ' 2>&1');
    if (!str_contains((string) $out, 'No syntax errors')) {
        throw new RuntimeException("Shadow patch syntax error in $f:\n$out");
    }
}

log_ok('Ready — DB + shadow OK');

// ----------------------------------------------------------------
// Cleanup on shutdown
// ----------------------------------------------------------------

register_shutdown_function(static function () use ($__adminPdo, $shadowRoot): void {
    try {
        $__adminPdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
    } catch (Throwable $e) {
        log_warn('Failed to drop DB: ' . $e->getMessage());
    }

    foreach (glob(TEST_CACHE_DIR . '/*') ?: [] as $f) {
        @unlink($f);
    }

    if (is_dir($shadowRoot)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($shadowRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isLink() || $f->isFile() ? unlink((string) $f) : rmdir((string) $f);
        }
        @rmdir($shadowRoot);
    }

    log_info('Cleanup done — DB dropped, shadow removed');
});

// ----------------------------------------------------------------
// Load real config from shadow (reads test .env → test DB)
// ----------------------------------------------------------------

require_once TEST_SHADOW_DIR . '/includes/config.php';

// ----------------------------------------------------------------
// Pre-define mock functions (guards in shadow files let these through)
// ----------------------------------------------------------------

function getJobProvidersCacheDir(): string
{
    return TEST_CACHE_DIR;
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
    $jobs = $GLOBALS['__test_mock_jobs'] ?? [];
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

function pageRedirect(string $url, int $code = 302): void
{
    $GLOBALS['__page_runner_redirect'] = $url;
    exit;
}

// ----------------------------------------------------------------
// CLI superglobals
// ----------------------------------------------------------------

$_GET    = [];
$_POST   = [];
$_COOKIE = [];
$_SERVER = array_merge($_SERVER, [
    'HTTP_HOST'            => 'localhost',
    'REQUEST_METHOD'       => 'GET',
    'REQUEST_URI'          => '/',
    'QUERY_STRING'         => '',
    'REMOTE_ADDR'          => '127.0.0.1',
    'HTTP_USER_AGENT'      => TEST_USER_AGENT,
    'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.5',
    'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
    'HTTPS'                => 'on',
]);

require_once APP_DIR . '/vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';
