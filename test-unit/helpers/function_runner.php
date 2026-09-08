<?php

/**
 * Subprocess runner for testing fetchUnifiedJobs() against the real implementation.
 *
 * Unlike page_runner.php, this runner does NOT define a fetchUnifiedJobs mock.
 * The low-level HTTP functions (talroo/jooble_fallback) are mocked with
 * controlled data from the config, so the real orchestration logic is exercised.
 */

$cfg = json_decode(file_get_contents($argv[1] ?? ''), true);
if (!$cfg) {
    fwrite(STDERR, "function_runner: invalid config\n");
    exit(1);
}

$GLOBALS['__fn_cfg'] = $cfg;

// ----------------------------------------------------------------
// Mock the three raw HTTP fetch functions with controlled data.
// The shadow provider_jobs.php has if (!function_exists()) guards
// on these, so the mocks survive when the file is loaded below.
// ----------------------------------------------------------------

function talrooFetchRawJobs(
    string $provider, string $email, string $keyword, string $location,
    string $state, string $city, int $page = 1, int $perPage = 20
): array {
    return $GLOBALS['__fn_cfg']['mock_talroo']
        ?? ['jobs' => [], 'error' => false, 'total' => 0, 'start' => 0, 'count' => 0];
}

function joobleFallBackFetchRawJobs(
    string $provider, string $keyword, string $location,
    string $state, string $city, int $page = 1, int $perPage = 20
): array {
    return $GLOBALS['__fn_cfg']['mock_jooble_fallback']
        ?? ['jobs' => [], 'error' => false];
}

// ----------------------------------------------------------------
// Load shadow provider_jobs.php.
// fetchUnifiedJobs is NOT pre-defined, so the guard evaluates
// to false and the real implementation is loaded.
// ----------------------------------------------------------------

require_once $cfg['shadow_dir'] . '/includes/provider_jobs.php';

// ----------------------------------------------------------------
// Call the real function and write the result
// ----------------------------------------------------------------

$result = fetchUnifiedJobs(...$cfg['fn_args']);
file_put_contents($cfg['result_file'], json_encode($result, JSON_UNESCAPED_UNICODE));
