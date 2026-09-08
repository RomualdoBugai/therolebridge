<?php

declare(strict_types=1);

/**
 * Runner centralizado para todos os crons.
 * Cada script roda em processo PHP separado via proc_open,
 * evitando conflitos de nomes de função entre scripts.
 */

date_default_timezone_set('America/New_York'); // garante timezone

$logFile = __DIR__ . '/../logs/cron_runner.log';

function logMessage(string $message, ?string $logFile = null): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    echo $line;

    if ($logFile) {
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0775, true);
        }
        file_put_contents($logFile, $line, FILE_APPEND);
    }
}

// ==========================
// CONFIG DOS "JOBS"
// ==========================
//
// hours:
//   '*'     => roda sempre que o runner for chamado
//   [8,20]  => só roda se a hora atual for 8 ou 20
//
$jobs = [
    [
        'file'  => 'brevo_push_leads.php',
        'hours' => [7, 15, 19],
    ],
    [
        'file'  => 'brevo_contact_update.php',
        'hours' => [7, 15, 19],
    ],
    [
        'file'  => 'brevo_fetch_campaigns.php',
        'hours' => [7, 13, 17, 21, 0],
    ],
    [
        'file'  => 'brevo_fetch_templates.php',
        'hours' => [6],
    ],
    [
        'file'  => 'hostinger_fetch_emails.php',
        'hours' => [14],
    ],
    [
        'file'  => 'ai_reply_inbound_emails.php',
        'hours' => [15],
    ],
    [
        'file'  => 'talroo_fetch_earnings.php',
        'hours' => array_merge(range(8, 23), range(0, 1)), // roda de 8h às 1h
    ],
    [
        'file'  => 'ai_log_monitor.php',
        'hours' => [8, 13, 17, 21, 0],
    ],
    [
        'file'  => 'brevo_fix_utm_clicks.php',
        'hours' => [10, 14, 22],
    ],
    [
        'file'  => 'brevo_schedule_campaigns.php',
        'args'  => [0],
        'hours' => [8],
    ],
];

$currentHour = (int) date('G'); // 0..23

logMessage('=== Iniciando run_all_crons.php ===', $logFile);

foreach ($jobs as $job) {
    $file  = $job['file'];
    $hours = $job['hours'];

    // Regra de horário
    if ($hours !== '*') {
        if (!in_array($currentHour, $hours, true)) {
            logMessage("Pulando {$file} (hora atual {$currentHour}, permitido somente: " . implode(',', $hours) . ")", $logFile);
            continue;
        }
    }

    $scriptPath = __DIR__ . '/' . $file;

    if (!file_exists($scriptPath)) {
        logMessage("ERRO: arquivo não encontrado: {$scriptPath}", $logFile);
        continue;
    }

    $args    = $job['args'] ?? [];
    $cmdArgs = array_map('escapeshellarg', array_map('strval', $args));
    $cmd     = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . (count($cmdArgs) ? ' ' . implode(' ', $cmdArgs) : '');

    logMessage("Executando: {$cmd}", $logFile);

    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($proc)) {
        logMessage("ERRO: proc_open falhou para {$file}", $logFile);
        continue;
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($output !== '') {
        foreach (explode("\n", rtrim($output)) as $line) {
            logMessage("  [{$file}] {$line}", $logFile);
        }
    }

    if ($exitCode !== 0) {
        logMessage("ERRO: {$file} terminou com exit code {$exitCode}", $logFile);
    } else {
        logMessage("OK: {$file} finalizado.", $logFile);
    }
}

logMessage('=== Finalizando run_all_crons.php ===', $logFile);
