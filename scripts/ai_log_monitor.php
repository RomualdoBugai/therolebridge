#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * jld_log_monitor_simple.php
 *
 * Simple production log monitor for The Role Bridge.
 *
 * Focus:
 * - Confirm the monitor ran.
 * - Count Talroo without_pid usage.
 * - Count Jooble 429 / Too Many Requests.
 * - Count serious system errors.
 * - Send a short, readable email.
 *
 * Cron example, hourly:
 * 0 * * * * /usr/bin/php /var/www/mailhub/current/scripts/jld_log_monitor_simple.php >> /var/www/mailhub/current/logs/jld_log_monitor_simple_cron.log 2>&1
 *
 * Optional env vars:
 * JLD_LOG_MONITOR_TO="you@example.com"
 * JLD_LOG_MONITOR_FROM="no-reply@therolebridge.com"
 * JLD_LOG_MONITOR_FROM_NAME="JLD Monitor"
 * JLD_LOG_MONITOR_ALWAYS_EMAIL="0"          // 0 = only warning/critical, 1 = email every run
 * JLD_WITHOUT_PID_WARNING_PCT="25"      // % of Talroo lines/events in the run window
 * JLD_WITHOUT_PID_CRITICAL_PCT="50"
 * JLD_WITHOUT_PID_MIN_EVENTS="20"
 * JLD_WITHOUT_PID_MIN_COUNT="10"
 * JLD_JOOBLE_429_WARNING_PCT="10"       // % of Jooble lines/events in the run window
 * JLD_JOOBLE_429_CRITICAL_PCT="25"
 * JLD_JOOBLE_429_MIN_EVENTS="10"
 * JLD_JOOBLE_429_MIN_COUNT="2"
 * JLD_LOG_MONITOR_SMTP_ESP_ID="0"           // optional ESP SMTP from esp table
 * JLD_LOG_RETENTION_DAYS="3"                  // keep only last N days in this monitor logs
 */

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

date_default_timezone_set('America/New_York');

const VERSION = '2.2.1-3-day-log-retention';

$baseDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$logsDir = $baseDir . '/logs';

if (!is_dir($logsDir)) {
    mkdir($logsDir, 0775, true);
}

$stateFile = $logsDir . '/jld_log_monitor_simple_state.json';
$runtimeLogFile = $logsDir . '/jld_log_monitor_simple.log';
$lockFile = sys_get_temp_dir() . '/jld_log_monitor_simple.lock';

$emailTo       = envValue('JLD_LOG_MONITOR_TO', 'jeferson.martins@therolebridge.com');
$emailFrom     = envValue('JLD_LOG_MONITOR_FROM', 'no-reply@therolebridge.com');
$emailFromName = envValue('JLD_LOG_MONITOR_FROM_NAME', 'JLD Monitor');
$smtpEspId     = (int) envValue('JLD_LOG_MONITOR_SMTP_ESP_ID', '0');
$alwaysEmail   = envValue('JLD_LOG_MONITOR_ALWAYS_EMAIL', '0') === '1';
$retentionDays = max(1, (int) envValue('JLD_LOG_RETENTION_DAYS', '3'));

$thresholds = [
    // Alert by percentage, not raw count. Raw count is only a minimum sample guard.
    'without_pid_warning_pct'  => (float) envValue('JLD_WITHOUT_PID_WARNING_PCT', '25'),
    'without_pid_critical_pct' => (float) envValue('JLD_WITHOUT_PID_CRITICAL_PCT', '35'),
    'without_pid_min_events'   => (int) envValue('JLD_WITHOUT_PID_MIN_EVENTS', '20'),
    'without_pid_min_count'    => (int) envValue('JLD_WITHOUT_PID_MIN_COUNT', '5'),

    'jooble_429_warning_pct'   => (float) envValue('JLD_JOOBLE_429_WARNING_PCT', '35'),
    'jooble_429_critical_pct'  => (float) envValue('JLD_JOOBLE_429_CRITICAL_PCT', '55'),
    'jooble_429_min_events'    => (int) envValue('JLD_JOOBLE_429_MIN_EVENTS', '12'),
    'jooble_429_min_count'     => (int) envValue('JLD_JOOBLE_429_MIN_COUNT', '3'),

    'suspicious_warning_pct'   => (float) envValue('JLD_SUSPICIOUS_WARNING_PCT', '30'),
    'suspicious_critical_pct'  => (float) envValue('JLD_SUSPICIOUS_CRITICAL_PCT', '40'),
    'suspicious_min_events'    => (int) envValue('JLD_SUSPICIOUS_MIN_EVENTS', '20'),
    'suspicious_min_count'     => (int) envValue('JLD_SUSPICIOUS_MIN_COUNT', '5'),

    // Serious system errors are not percentage-based. One real fatal error is already a problem.
    'critical_warning'         => 1,
];

// Use unique keys. Duplicate array keys silently overwrite each other in PHP.
$logFiles = [
    'nginx_global_error'  => '/var/log/nginx/error.log',
    'nginx_mailhub_error' => '/var/log/nginx/mailhub_error.log',
    'php_fpm'             => '/var/log/php8.3-fpm.log',
    'mail'                => '/var/log/mail.log',
    'cron_runner'         => $logsDir . '/cron_runner.log',
    'monitor'             => $runtimeLogFile,
];

$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    writeRuntimeLog($runtimeLogFile, 'Another monitor process is already running.');
    return;
}

try {
    writeRuntimeLog($runtimeLogFile, 'Starting monitor v' . VERSION);
    pruneMonitorLogs($logsDir, $runtimeLogFile, $retentionDays);

    $state = loadJson($stateFile);
    $previousRunAt = (string)($state['last_run_at'] ?? 'first run');
    $runStartedAt = date('c');

    $readResult = readNewLogContent($logFiles, $state);
    $scan = scanContent($readResult['chunks']);
    $status = decideStatus($scan, $readResult, $thresholds);

    $state['last_run_at'] = $runStartedAt;
    saveJson($stateFile, $state);

    if (!$alwaysEmail && $status['level'] === 'OK') {
        writeRuntimeLog($runtimeLogFile, 'OK. No problem found. Email skipped.');
        return;
    }

    $subject = buildSubject($status);
    $bodyText = buildSimpleTextEmail($status, $scan, $readResult, $previousRunAt, $thresholds);
    $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    sendMonitorEmail($emailTo, $emailFrom, $emailFromName, $subject, $bodyHtml, $bodyText, $smtpEspId);

    writeRuntimeLog($runtimeLogFile, 'Email sent: ' . $subject);
} catch (Throwable $e) {
    writeRuntimeLog($runtimeLogFile, 'MONITOR FATAL: ' . $e->getMessage());
    fwrite(STDERR, 'MONITOR FATAL: ' . $e->getMessage() . PHP_EOL);
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    writeRuntimeLog($runtimeLogFile, 'Finished monitor.');
}


function pruneMonitorLogs(string $logsDir, string $runtimeLogFile, int $retentionDays): void
{
    $cutoff = time() - ($retentionDays * 86400);

    $files = [
        $runtimeLogFile,
        $logsDir . '/jld_log_monitor_simple_cron.log',
    ];

    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
            continue;
        }

        pruneLogFileToCutoff($file, $cutoff);
    }
}

function pruneLogFileToCutoff(string $file, int $cutoff): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false || $lines === []) {
        return;
    }

    $kept = [];

    foreach ($lines as $line) {
        $lineTime = extractLogLineTimestamp($line);

        // Keep lines we cannot date instead of deleting blindly.
        if ($lineTime === null || $lineTime >= $cutoff) {
            $kept[] = $line;
        }
    }

    file_put_contents($file, implode(PHP_EOL, $kept) . ($kept !== [] ? PHP_EOL : ''), LOCK_EX);
}

function extractLogLineTimestamp(string $line): ?int
{
    // Matches this script format: [2026-06-07 17:30:00 EDT] message
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?: [A-Z]{2,5})?\]/', $line, $m)) {
        $ts = strtotime($m[1]);
        return $ts === false ? null : $ts;
    }

    // Matches syslog-ish cron lines if redirected output ever includes them.
    if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\b/', $line, $m)) {
        $year = date('Y');
        $ts = strtotime($m[1] . ' ' . $year);
        return $ts === false ? null : $ts;
    }

    return null;
}

function envValue(string $key, string $default): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function writeRuntimeLog(string $file, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s T') . '] ' . $message . PHP_EOL;
    echo $line;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function loadJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function saveJson(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function readNewLogContent(array $logFiles, array &$state): array
{
    $chunks = [];
    $files = [];
    $totalNewBytes = 0;
    $totalNewLines = 0;

    foreach ($logFiles as $name => $path) {
        $item = [
            'name' => $name,
            'path' => $path,
            'readable' => is_readable($path),
            'new_bytes' => 0,
            'new_lines' => 0,
        ];

        if (!$item['readable']) {
            $files[] = $item;
            continue;
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        $inode = @fileinode($path) ?: 0;

        if ($size === false || $size <= 0) {
            $state['files'][$name] = ['path' => $path, 'size' => 0, 'inode' => $inode, 'checked_at' => date('c')];
            $files[] = $item;
            continue;
        }

        $lastSize = (int)($state['files'][$name]['size'] ?? 0);
        $lastInode = (int)($state['files'][$name]['inode'] ?? 0);

        if ($lastSize <= 0 || ($lastInode > 0 && $inode > 0 && $lastInode !== $inode)) {
            // First run or rotated log: read only the end, not old history.
            $offset = max(0, $size - 300000);
        } elseif ($size < $lastSize) {
            $offset = 0;
        } else {
            $offset = $lastSize;
        }

        $length = $size - $offset;
        if ($length > 0) {
            $content = readRange($path, $offset, min($length, 700000));
            if ($content !== '') {
                $chunks[$name] = ['path' => $path, 'content' => $content];
                $item['new_bytes'] = strlen($content);
                $item['new_lines'] = substr_count($content, "\n") + 1;
                $totalNewBytes += $item['new_bytes'];
                $totalNewLines += $item['new_lines'];
            }
        }

        $state['files'][$name] = ['path' => $path, 'size' => $size, 'inode' => $inode, 'checked_at' => date('c')];
        $files[] = $item;
    }

    return [
        'chunks' => $chunks,
        'files' => $files,
        'total_new_bytes' => $totalNewBytes,
        'total_new_lines' => $totalNewLines,
        'readable_files' => count(array_filter($files, static fn($f) => $f['readable'])),
        'unreadable_files' => count(array_filter($files, static fn($f) => !$f['readable'])),
    ];
}

function readRange(string $path, int $offset, int $length): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    fseek($handle, $offset);
    $content = fread($handle, $length);
    fclose($handle);
    return is_string($content) ? $content : '';
}

function scanContent(array $chunks): array
{
    $scan = [
        'total_scanned_lines' => 0,
        'talroo_events' => 0,
        'jooble_events' => 0,
        'jooble_api_calls' => 0,
        'without_pid' => 0,
        'without_pid_success' => 0,
        'with_pid_empty' => 0,
        'with_pid_success' => 0,
        'jooble_429' => 0,
        'suspicious_events' => 0,
        'suspicious_empty_user_agent' => 0,
        'suspicious_ua_match' => 0,
        'suspicious_accept_header' => 0,
        'suspicious_non_browser_ua' => 0,
        'suspicious_missing_accept_language' => 0,
        'critical_errors' => 0,
        'samples_without_pid' => [],
        'samples_with_pid_empty' => [],
        'samples_jooble_429' => [],
        'samples_suspicious' => [],
        'samples_critical' => [],
    ];

    foreach ($chunks as $source => $chunk) {
        $lines = preg_split('/\R/', (string)$chunk['content']) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $safeLine = maskSensitive($line);
            $lower = strtolower($safeLine);

            $scan['total_scanned_lines']++;

            if (isTalrooEvent($lower)) {
                $scan['talroo_events']++;
            }

            if (isJoobleEvent($lower)) {
                $scan['jooble_events']++;

                // Conta apenas chamadas reais para a API do Jooble
                if (str_contains($lower, 'jooble.org/real-time-search-api')) {
                    $scan['jooble_api_calls'] = ($scan['jooble_api_calls'] ?? 0) + 1;
                }
            }

            $isWithoutPid = false;
            if (preg_match('/pid_mode=without_pid|WITHOUT_PID fallback SUCCESS|talroo without_pid.*fallback/i', $lower)) {
                $isWithoutPid = true;
            }

            if ($isWithoutPid) {
                $scan['without_pid']++;
                addSample($scan['samples_without_pid'], $source, $safeLine);

                if (str_contains($lower, 'fallback success')) {
                    $scan['without_pid_success']++;
                }
            }

            if (
                str_contains($lower, 'talroo empty result') &&
                str_contains($lower, 'pid_mode=with_pid')
            ) {
                $scan['with_pid_empty']++;
                addSample($scan['samples_with_pid_empty'], $source, $safeLine);
            }

            if (
                str_contains($lower, 'talroo with_pid success') ||
                (str_contains($lower, 'with_pid success') && str_contains($lower, 'talroo'))
            ) {
                $scan['with_pid_success']++;
            }

            $isJooble429 = false;
            if (str_contains($lower, 'jooble')) {
                if (
                    str_contains($lower, '429') ||
                    str_contains($lower, 'too many requests') ||
                    str_contains($lower, 'rate limit') ||
                    str_contains($lower, 'http 429')
                ) {
                    $isJooble429 = true;
                }
            }

            if ($isJooble429) {
                $scan['jooble_429']++;
                addSample($scan['samples_jooble_429'], $source, $safeLine);
            }

            $suspiciousReason = detectSuspiciousJobRequestReason($lower);
            if ($suspiciousReason !== null) {
                $scan['suspicious_events']++;
                addSample($scan['samples_suspicious'], $source, $safeLine);

                if ($suspiciousReason === 'empty_user_agent') {
                    $scan['suspicious_empty_user_agent']++;
                } elseif (str_starts_with($suspiciousReason, 'ua_match')) {
                    $scan['suspicious_ua_match']++;
                } elseif ($suspiciousReason === 'suspicious_accept_header') {
                    $scan['suspicious_accept_header']++;
                } elseif ($suspiciousReason === 'non_browser_ua') {
                    $scan['suspicious_non_browser_ua']++;
                } elseif ($suspiciousReason === 'missing_accept_language') {
                    $scan['suspicious_missing_accept_language']++;
                }
            }

            if (isCriticalSystemError($lower)) {
                $scan['critical_errors']++;
                addSample($scan['samples_critical'], $source, $safeLine);
            }
        }
    }

    return $scan;
}

function detectSuspiciousJobRequestReason(string $lower): ?string
{
    /*
     * Matches logs emitted by the bot blocker, for example:
     * Suspicious job request blocked: ua_match:curl/ | UA=...
     * Suspicious job request blocked: suspicious_accept_header | UA=...
     */
    if (
        !str_contains($lower, 'suspicious job request blocked') &&
        !str_contains($lower, 'job click suspicious') &&
        !str_contains($lower, 'insertjobclicksuspicious')
    ) {
        return null;
    }

    if (preg_match('/suspicious job request blocked:\s*([^|\s]+)/i', $lower, $m)) {
        return trim((string)$m[1]);
    }

    $knownReasons = [
        'empty_user_agent',
        'suspicious_accept_header',
        'non_browser_ua',
        'missing_accept_language',
        'ua_match',
    ];

    foreach ($knownReasons as $reason) {
        if (str_contains($lower, $reason)) {
            return $reason;
        }
    }

    return 'unknown_suspicious';
}

function addSample(array &$samples, string $source, string $line): void
{
    if (count($samples) >= 3) {
        return;
    }
    $samples[] = '[' . $source . '] ' . mb_substr($line, 0, 500);
}

function isTalrooEvent(string $lower): bool
{
    return str_contains($lower, 'talroo')
        || str_contains($lower, 'jobs2careers')
        || str_contains($lower, 'api.jobs2careers.com');
}

function isJoobleEvent(string $lower): bool
{
    return str_contains($lower, 'jooble');
}

function pct(int $part, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    return round(($part / $total) * 100, 2);
}

function fmtPct(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
}

function isCriticalSystemError(string $lower): bool
{
    $patterns = [
        'php fatal error',
        'uncaught error',
        'call to undefined function',
        'class not found',
        'sqlstate',
        'integrity constraint violation',
        'allowed memory size',
        'maximum execution time',
        'upstream timed out',
        'connect() failed',
        'connection refused',
        'access denied for user',
        'http 500',
        'http 502',
        'http 503',
        'http 504',
        'primary script unknown',
        // cron runner logs this when a script throws an exception
        'erro ao rodar',
    ];

    foreach ($patterns as $pattern) {
        if (str_contains($lower, $pattern)) {
            return true;
        }
    }

    return false;
}

function decideStatus(array $scan, array $readResult, array $thresholds): array
{
    $level = 'OK';
    $problems = [];

    $withoutPidPct = pct((int)$scan['without_pid'], (int)$scan['talroo_events']);
    $jooble429Pct = pct((int)$scan['jooble_429'], (int)$scan['jooble_events']);
    $suspiciousPct = pct((int)$scan['suspicious_events'], (int)$scan['total_scanned_lines']);

    $withoutPidHasEnoughData = $scan['talroo_events'] >= $thresholds['without_pid_min_events']
        && $scan['without_pid'] >= $thresholds['without_pid_min_count'];

    $jooble429HasEnoughData = $scan['jooble_events'] >= $thresholds['jooble_429_min_events']
        && $scan['jooble_429'] >= $thresholds['jooble_429_min_count'];

    $suspiciousHasEnoughData = $scan['total_scanned_lines'] >= $thresholds['suspicious_min_events']
        && $scan['suspicious_events'] >= $thresholds['suspicious_min_count'];

    if ($scan['critical_errors'] >= $thresholds['critical_warning']) {
        $level = 'CRITICAL';
        $problems[] = 'Erro grave no sistema encontrado. Isso não espera percentual.';
    }

    if ($jooble429HasEnoughData && $jooble429Pct >= $thresholds['jooble_429_critical_pct']) {
        $level = 'CRITICAL';
        $problems[] = 'Jooble 429 virou problema sério: ' . fmtPct($jooble429Pct) . ' das linhas/eventos Jooble.';
    } elseif ($jooble429HasEnoughData && $jooble429Pct >= $thresholds['jooble_429_warning_pct'] && $level !== 'CRITICAL') {
        $level = 'WARNING';
        $problems[] = 'Jooble 429 acima do normal: ' . fmtPct($jooble429Pct) . ' das linhas/eventos Jooble.';
    }

    if ($withoutPidHasEnoughData && $withoutPidPct >= $thresholds['without_pid_critical_pct']) {
        $level = 'CRITICAL';
        $problems[] = 'Talroo without_pid está alto demais: ' . fmtPct($withoutPidPct) . ' das linhas/eventos Talroo.';
    } elseif ($withoutPidHasEnoughData && $withoutPidPct >= $thresholds['without_pid_warning_pct'] && $level !== 'CRITICAL') {
        $level = 'WARNING';
        $problems[] = 'Talroo without_pid acima do normal: ' . fmtPct($withoutPidPct) . ' das linhas/eventos Talroo.';
    }

    if ($suspiciousHasEnoughData && $suspiciousPct >= $thresholds['suspicious_critical_pct']) {
        $level = 'CRITICAL';
        $problems[] = 'Bot/suspicious bloqueado alto demais: ' . fmtPct($suspiciousPct) . ' das linhas analisadas.';
    } elseif ($suspiciousHasEnoughData && $suspiciousPct >= $thresholds['suspicious_warning_pct'] && $level !== 'CRITICAL') {
        $level = 'WARNING';
        $problems[] = 'Bot/suspicious bloqueado acima do normal: ' . fmtPct($suspiciousPct) . ' das linhas analisadas.';
    }

    if ($readResult['unreadable_files'] > 0 && $level === 'OK') {
        $level = 'WARNING';
        $problems[] = 'Alguns logs não estão legíveis. O monitor pode estar cego.';
    }

    if (empty($problems)) {
        $problems[] = 'Monitor rodou. Percentuais dentro do aceitável nessa janela.';
    }

    return [
        'level' => $level,
        'problems' => $problems,
        'rates' => [
            'without_pid_pct' => $withoutPidPct,
            'jooble_429_pct' => $jooble429Pct,
            'suspicious_pct' => $suspiciousPct,
        ],
    ];
}

function buildSubject(array $status): string
{
    return '[JLD ' . $status['level'] . '] Monitor logs - ' . date('Y-m-d H:i');
}

function buildSimpleTextEmail(array $status, array $scan, array $readResult, string $previousRunAt, array $thresholds): string
{
    $withoutPidPct = (float)($status['rates']['without_pid_pct'] ?? pct((int)$scan['without_pid'], (int)$scan['talroo_events']));
    $jooble429Pct = (float)($status['rates']['jooble_429_pct'] ?? pct((int)$scan['jooble_429'], (int)$scan['jooble_events']));
    $suspiciousPct = (float)($status['rates']['suspicious_pct'] ?? pct((int)$scan['suspicious_events'], (int)$scan['total_scanned_lines']));

    $lines = [];
    $lines[] = 'JLD LOG MONITOR';
    $lines[] = '';
    $lines[] = 'Status: ' . $status['level'];
    $lines[] = 'Monitor rodou: SIM';
    $lines[] = 'Janela: desde ' . $previousRunAt . ' até ' . date('c');
    $lines[] = '';
    $lines[] = 'Problema:';
    foreach ($status['problems'] as $problem) {
        $lines[] = '- ' . $problem;
    }
    $lines[] = '';
    $lines[] = 'Percentuais da janela:';
    $lines[] = '- Talroo without_pid: ' . fmtPct($withoutPidPct) . ' (' . $scan['without_pid'] . '/' . $scan['talroo_events'] . ')';
    $lines[] = '- Talroo with_pid empty: ' . ($scan['with_pid_empty'] ?? 0);
    $lines[] = '- Talroo with_pid success: ' . ($scan['with_pid_success'] ?? 0);
    $lines[] = '- Talroo without_pid success: ' . ($scan['without_pid_success'] ?? 0);
    $lines[] = '- Jooble API calls: ' . ($scan['jooble_api_calls'] ?? 0);
    $lines[] = '- Jooble 429: ' . fmtPct($jooble429Pct) . ' (' . $scan['jooble_429'] . '/' . ($scan['jooble_api_calls'] ?? $scan['jooble_events']) . ')';
    $lines[] = '- Bot/suspicious bloqueado: ' . fmtPct($suspiciousPct) . ' (' . $scan['suspicious_events'] . '/' . $scan['total_scanned_lines'] . ')';
    $lines[] = '- Suspicious por UA match: ' . ($scan['suspicious_ua_match'] ?? 0);
    $lines[] = '- Suspicious por Accept ruim: ' . ($scan['suspicious_accept_header'] ?? 0);
    $lines[] = '- Suspicious por UA vazio: ' . ($scan['suspicious_empty_user_agent'] ?? 0);
    $lines[] = '- Suspicious por non-browser UA: ' . ($scan['suspicious_non_browser_ua'] ?? 0);
    $lines[] = '- Erro grave no sistema: ' . $scan['critical_errors'];
    $lines[] = '';
    $lines[] = 'Volume:';
    $lines[] = '- Linhas analisadas: ' . $scan['total_scanned_lines'];
    $lines[] = '- Novas linhas lidas: ' . $readResult['total_new_lines'];
    $lines[] = '- Logs legiveis: ' . $readResult['readable_files'];
    $lines[] = '- Logs nao legiveis: ' . $readResult['unreadable_files'];
    $lines[] = '';
    $lines[] = 'Limites usados:';
    $lines[] = '- without_pid warning/critical: ' . fmtPct((float)$thresholds['without_pid_warning_pct']) . '/' . fmtPct((float)$thresholds['without_pid_critical_pct']) . ' | minimo: ' . $thresholds['without_pid_min_count'] . ' casos e ' . $thresholds['without_pid_min_events'] . ' eventos Talroo';
    $lines[] = '- Jooble 429 warning/critical: ' . fmtPct((float)$thresholds['jooble_429_warning_pct']) . '/' . fmtPct((float)$thresholds['jooble_429_critical_pct']) . ' | minimo: ' . $thresholds['jooble_429_min_count'] . ' casos e ' . $thresholds['jooble_429_min_events'] . ' eventos Jooble';
    $lines[] = '- Bot/suspicious warning/critical: ' . fmtPct((float)$thresholds['suspicious_warning_pct']) . '/' . fmtPct((float)$thresholds['suspicious_critical_pct']) . ' | minimo: ' . $thresholds['suspicious_min_count'] . ' casos e ' . $thresholds['suspicious_min_events'] . ' linhas analisadas';
    $lines[] = '';

    if (!empty($scan['samples_critical'])) {
        $lines[] = 'Amostras de erro grave:';
        foreach ($scan['samples_critical'] as $sample) {
            $lines[] = '- ' . $sample;
        }
        $lines[] = '';
    }

    if (!empty($scan['samples_jooble_429'])) {
        $lines[] = 'Amostras Jooble 429:';
        foreach ($scan['samples_jooble_429'] as $sample) {
            $lines[] = '- ' . $sample;
        }
        $lines[] = '';
    }

    if (!empty($scan['samples_with_pid_empty'])) {
        $lines[] = 'Amostras with_pid empty:';
        foreach ($scan['samples_with_pid_empty'] as $sample) {
            $lines[] = '- ' . $sample;
        }
        $lines[] = '';
    }

    if (!empty($scan['samples_without_pid'])) {
        $lines[] = 'Amostras without_pid:';
        foreach ($scan['samples_without_pid'] as $sample) {
            $lines[] = '- ' . $sample;
        }
        $lines[] = '';
    }

    if (!empty($scan['samples_suspicious'])) {
        $lines[] = 'Amostras bot/suspicious bloqueado:';
        foreach ($scan['samples_suspicious'] as $sample) {
            $lines[] = '- ' . $sample;
        }
        $lines[] = '';
    }

    if ($readResult['unreadable_files'] > 0) {
        $lines[] = 'Logs nao legiveis:';
        foreach ($readResult['files'] as $file) {
            if (!$file['readable']) {
                $lines[] = '- ' . $file['name'] . ': ' . $file['path'];
            }
        }
        $lines[] = '';
    }

    $lines[] = 'Versao: ' . VERSION;

    return implode("\n", $lines);
}

function maskSensitive(string $text): string
{
    $text = preg_replace('/([?&](?:pass|password|api_key|apikey|token|access_token|refresh_token|secret|key)=)[^&\s]+/i', '$1[REDACTED]', $text);
    $text = preg_replace('/(\b(?:api[_-]?key|token|secret|password|pass)\s*[:=]\s*)[^\s,;]+/i', '$1[REDACTED]', $text);
    $text = preg_replace('/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $text);
    $text = preg_replace('/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', '$1***@$2', $text);
    $text = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[TOKEN]', $text);
    return (string)$text;
}

function sendMonitorEmail(
    string $to,
    string $from,
    string $fromName,
    string $subject,
    string $htmlBody,
    string $textBody,
    int $smtpEspId = 0
): void {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && $smtpEspId > 0) {
        sendMonitorEmailViaEspSmtp($to, $from, $fromName, $subject, $htmlBody, $textBody, $smtpEspId);
        return;
    }

    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $ok = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    if (!$ok) {
        throw new RuntimeException('mail() failed sending monitor email to ' . $to);
    }
}

function sendMonitorEmailViaEspSmtp(
    string $to,
    string $from,
    string $fromName,
    string $subject,
    string $htmlBody,
    string $textBody,
    int $espId
): void {
    $row = pdoFetchOne(
        "
        SELECT smtp_host, smtp_user, smtp_pass, smtp_port
        FROM esp
        WHERE id = :id
        LIMIT 1
        ",
        [':id' => $espId]
    );

    if (!$row) {
        throw new RuntimeException('SMTP ESP not found: id=' . $espId);
    }

    $smtpHost = trim((string)($row['smtp_host'] ?? ''));
    $smtpUser = trim((string)($row['smtp_user'] ?? ''));
    $smtpPass = (string)($row['smtp_pass'] ?? '');
    $smtpPort = (int)($row['smtp_port'] ?? 587);

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        throw new RuntimeException('SMTP ESP config incomplete for id=' . $espId);
    }

    $mailClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    $mail = new $mailClass(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = $mailClass::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->send();
    } catch (Throwable $e) {
        throw new RuntimeException('PHPMailer monitor email failed: ' . $e->getMessage());
    }
}
