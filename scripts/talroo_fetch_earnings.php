#!/usr/bin/env php
<?php

// =======================================
// CLI ONLY (mas permite dashboard se liberar via constante)
// =======================================
if (
    PHP_SAPI !== 'cli'
    && (
        !defined('JLD_ALLOW_WEB_TRIGGER')
        || JLD_ALLOW_WEB_TRIGGER !== true
    )
) {
    echo "This script must be run from CLI or via authorized dashboard.\n";
    return;
}

// =======================================
// BOOTSTRAP
// =======================================
require_once __DIR__ . '/../includes/config.php';

// =======================================
// CONFIG
// =======================================

const TALROO_DASH_URL = 'https://dashboard.talroo.com/publisher.php';

// COPIAR do DevTools (Request Headers -> Cookie) da tela de earnings
const TALROO_COOKIE =
'';

const TALROO_PROVIDER_ID = 2;
const TALROO_PROVIDER_ID_FIXED = 3;

// janela de dias
const TALROO_WINDOW_DAYS = 2;

// =======================================
// TIME RANGE
// =======================================

date_default_timezone_set('America/New_York');

$today = new DateTimeImmutable('today');

// Se quer terminar ontem, usa -1 day.
// O seu código original dizia "ontem", mas estava usando hoje.
$endDate = $today->modify('-0 day');
$startDate = $endDate->modify('-' . (TALROO_WINDOW_DAYS - 1) . ' days');

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');

echo "[Talroo Earnings] Range: {$start} -> {$end}\n";

// =======================================
// MAIN
// =======================================

try {
    $stats = fetchTalrooEarningsStats($start, $end);

    if (empty($stats)) {
        echo "No earnings data returned for this range.\n";
        return;
    }

    ksort($stats);

    $totalRows = 0;

    foreach ($stats as $date => $affData) {
        if (!is_array($affData)) {
            continue;
        }

        foreach ($affData as $affId => $arr) {
            if (!is_array($arr)) {
                continue;
            }

            if ((int)$affId === 7795) {
                continue;
            }

            $idTalroo = TALROO_PROVIDER_ID;
            if ((int)$affId === 7872) {
                $idTalroo = TALROO_PROVIDER_ID_FIXED;
            }

            // Mapeamento baseado no JS da página:
            // arr[7]    = total clicks
            // arr[1][0] = earnings cents
            // arr[3]    = expired clicks
            $clicks = (int)($arr[7] ?? 0);
            $earningsCents = (int)($arr[1][0] ?? 0);
            $expiredClicks = (int)($arr[3] ?? 0);

            upsertEarningsDaily(
                $idTalroo,
                (string)$date,
                $clicks,
                $earningsCents,
                $expiredClicks
            );

            $totalRows++;
        }
    }

    echo "Imported/updated {$totalRows} rows into earnings_daily.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    throw $e;
}

// =======================================
// FUNCTIONS
// =======================================

/**
 * Faz o mesmo POST que o JavaScript faz para atualizar _stats.
 *
 * @return array<string,array<int|string,array>>
 */
function fetchTalrooEarningsStats(string $start, string $end): array
{
    if (TALROO_COOKIE === '') {
        throw new RuntimeException('TALROO_COOKIE is empty. Set it from DevTools Request Headers.');
    }

    $ch = curl_init(TALROO_DASH_URL);
    if ($ch === false) {
        throw new RuntimeException('Failed to init cURL.');
    }

    $postFields = http_build_query([
        'start' => $start,
        'end'   => $end,
    ]);

    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; TheRoleBridgeBot/1.0; +https://therolebridge.com)',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . TALROO_COOKIE,
        'Origin: https://dashboard.talroo.com',
        'Referer: https://dashboard.talroo.com/publisher.php?start=' . $start . '&end=' . $end . '&aff_id=' . TALROO_PROVIDER_ID,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
    ]);

    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    if ($errNo !== 0) {
        throw new RuntimeException("cURL error ({$errNo}): {$errMsg}");
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Unexpected HTTP status from Talroo: {$status}");
    }

    if ($body === false || $body === '') {
        throw new RuntimeException('Empty response from Talroo (body empty).');
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        if (stripos($body, '<html') !== false) {
            throw new RuntimeException('Got HTML (likely login page). Check/refresh TALROO_COOKIE.');
        }

        throw new RuntimeException('Invalid JSON from Talroo (json_decode failed).');
    }

    return $data;
}

function upsertEarningsDaily(
    int $providerJobId,
    string $reportDate,
    int $clicks,
    int $earningsCents,
    int $expiredClicks
): void {
    $existingId = (int)pdoFetchValue(
        "
        SELECT id
        FROM earnings_daily
        WHERE provider_job_id = :provider_job_id
          AND report_date = :report_date
        LIMIT 1
        ",
        [
            ':provider_job_id' => $providerJobId,
            ':report_date'      => $reportDate,
        ]
    );

    $now = date('Y-m-d H:i:s');

    if ($existingId > 0) {
        pdoExecute(
            "
            UPDATE earnings_daily
            SET
                clicks = :clicks,
                earnings_cents = :earnings_cents,
                expired_clicks = :expired_clicks,
                updated_at = :updated_at
            WHERE id = :id
            ",
            [
                ':clicks'         => $clicks,
                ':earnings_cents' => $earningsCents,
                ':expired_clicks' => $expiredClicks,
                ':updated_at'     => $now,
                ':id'             => $existingId,
            ]
        );

        return;
    }

    pdoInsertGetId(
        "
        INSERT INTO earnings_daily (
            provider_job_id,
            report_date,
            clicks,
            earnings_cents,
            expired_clicks,
            created_at,
            updated_at
        ) VALUES (
            :provider_job_id,
            :report_date,
            :clicks,
            :earnings_cents,
            :expired_clicks,
            :created_at,
            :updated_at
        )
        ",
        [
            ':provider_job_id' => $providerJobId,
            ':report_date'      => $reportDate,
            ':clicks'           => $clicks,
            ':earnings_cents'   => $earningsCents,
            ':expired_clicks'   => $expiredClicks,
            ':created_at'       => $now,
            ':updated_at'       => $now,
        ]
    );
}
