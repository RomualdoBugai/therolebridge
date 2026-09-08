<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Execute via CLI.\n");
}

require_once __DIR__ . '/../includes/config.php';

$days = 5;

$dates = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $dates[] = date('Y-m-d', strtotime("-{$i} days"));
}

// ── Brevo helpers ─────────────────────────────────────────────────────────────

function brevoGet(string $apiKey, string $path, array $query = []): array
{
    $url = 'https://api.brevo.com/v3/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['api-key: ' . $apiKey, 'accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL: {$err}");
    }
    if ($code === 404) {
        return [];
    }
    if ($code >= 400) {
        throw new RuntimeException("Brevo {$code}: " . substr((string) $body, 0, 300));
    }

    return json_decode((string) $body, true) ?: [];
}

/**
 * Campanhas sent na data (paginadas).
 * Retorna array indexado por campaign_id => ['id', 'name', 'scheduled_ts'].
 */
function getCampaignsForDate(string $apiKey, string $date): array
{
    $tz        = new DateTimeZone('America/New_York');
    $startDate = date('Y-m-d', strtotime($date . ' -30 days'));
    $startTs   = (new DateTimeImmutable($startDate . ' 00:00:00', $tz))->getTimestamp();
    $start     = (new DateTimeImmutable('@' . $startTs))->format('Y-m-d\TH:i:s.000\Z');
    $endTs     = min((new DateTimeImmutable($date . ' 23:59:59', $tz))->getTimestamp(), time() - 60);
    $end       = (new DateTimeImmutable('@' . $endTs))->format('Y-m-d\TH:i:s.000\Z');

    $all    = [];
    $offset = 0;

    do {
        $resp  = brevoGet($apiKey, 'emailCampaigns', [
            'status'             => 'sent',
            'startDate'          => $start,
            'endDate'            => $end,
            'excludeHtmlContent' => 'true',
            'limit'              => 100,
            'offset'             => $offset,
            'sort'               => 'asc',
        ]);
        $batch = $resp['campaigns'] ?? [];
        $total = (int) ($resp['count'] ?? 0);

        foreach ($batch as $c) {
            $id  = (int) ($c['id'] ?? 0);
            $sat = (string) ($c['scheduledAt'] ?? ($c['createdAt'] ?? ''));
            $ts  = $sat ? (int) strtotime($sat) : 0;
            if ($id && $ts) {
                $all[$id] = ['id' => $id, 'name' => (string) ($c['name'] ?? ''), 'scheduled_ts' => $ts];
            }
        }

        $offset += 100;
    } while (count($batch) === 100 && $offset < $total);

    return $all;
}

/**
 * Retorna os eventos de click do contato para o período.
 * Endpoint: GET /v3/contacts/{email}/campaignStats?startDate=...&endDate=...
 *
 * Usa $campaigns para resolver utm_campaign e utm_id quando a URL clicada
 * não contém os parâmetros (campanhas enviadas sem UTM).
 *
 * Retorna lista de eventos: [ campaign_id, ts (unix), utm_source, utm_medium, utm_campaign, utm_id ]
 */
function getContactClickEvents(string $apiKey, string $email, string $date, array $campaigns): array
{
    $startDate = date('Y-m-d', strtotime($date . ' -30 days'));
    $endDate   = date('Y-m-d', strtotime($date));

    $data = brevoGet($apiKey, 'contacts/' . urlencode($email) . '/campaignStats', [
        'startDate' => $startDate,
        'endDate'   => $endDate,
    ]);

    if (empty($data['clicked'])) {
        return [];
    }

    $events = [];

    foreach ($data['clicked'] as $item) {
        $campaignId = (int) ($item['campaignId'] ?? 0);
        if ($campaignId === 0) {
            continue;
        }

        foreach (($item['links'] ?? []) as $link) {
            $eventTime = (string) ($link['eventTime'] ?? '');

            $ts = $eventTime !== '' ? (int) strtotime($eventTime) : 0;
            if ($ts === 0) {
                continue;
            }

            $utmSource   = 'brevo';
            $utmMedium   = 'email';
            $utmCampaign = '';
            $utmId       = (string) $campaignId;

            // utm_campaign e utm_id sempre da campanha, nunca da URL
            if (isset($campaigns[$campaignId])) {
                $raw         = $campaigns[$campaignId]['name'];
                $utmCampaign = trim(preg_replace('/\s+/', ' ', str_replace(['(', ')', '/', '#'], '', $raw)));
            }

            $events[] = [
                'campaign_id'  => $campaignId,
                'ts'           => $ts,
                'utm_source'   => $utmSource,
                'utm_medium'   => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'utm_id'       => $utmId,
            ];
        }
    }

    return $events;
}

// ── MAIN ─────────────────────────────────────────────────────────────────────

echo "=== brevo_fix_utm_clicks ===\n";
echo "Range: " . reset($dates) . " → " . end($dates) . " (" . count($dates) . " dia(s))\n\n";

// ESPs — carregado uma vez
$esps  = pdoFetchAll("SELECT id, name, api_key FROM esp WHERE api_key != '' ORDER BY id");

if (empty($esps)) {
    exit("Nenhum ESP encontrado.\n");
}

// Prefixos válidos de utm_campaign
// Ex: "HF LIVE DATEUPDATE TEMPLATEID - 30C" → prefixo limpo "HF"
$patternRows = pdoFetchAll(
    "SELECT DISTINCT name_pattern FROM esp_schedule WHERE is_active = 1 AND name_pattern != ''"
);
$validPrefixes = [];
foreach ($patternRows as $pr) {
    // Parte literal antes do primeiro placeholder (DATEUPDATE, TEMPLATEID, LIVE)
    $before = preg_split('/DATEUPDATE|TEMPLATEID|LIVE/', (string) $pr['name_pattern'])[0];
    $clean  = trim(preg_replace('/\s+/', ' ', str_replace(['(', ')', '/', '#'], '', $before)));
    if ($clean !== '') {
        $validPrefixes[] = $clean;
    }
}

$espIds = implode(',', array_map('intval', array_column($esps, 'id')));

// Condição SQL extra: utm_campaign com valor errado (sem prefixo de alias)
$badCampaignCond = '';
if (!empty($validPrefixes)) {
    $notLikes = array_map(
        fn($p) => "utm_campaign NOT LIKE '" . str_replace("'", "''", $p) . "%'",
        array_unique($validPrefixes)
    );
    $badCampaignCond = ' OR (utm_source = \'brevo\' AND ' . implode(' AND ', $notLikes) . ')';
}

$totalUpdated    = 0;
$totalUpdatedOut = 0;
$totalSkipped    = 0;

// ── Loop por data ─────────────────────────────────────────────────────────────

foreach ($dates as $date) {
    echo "──────────────────────────────────────────\n";
    echo "Data: {$date}\n";

    // 1. Registros com defeito na data (sem UTM ou utm_campaign errado)
    $clicks = pdoFetchAll(
        "SELECT id, email, created_at
           FROM job_clicks
          WHERE DATE(created_at) = :date
            AND email != ''
            AND (utm_source = '' OR utm_source IS NULL{$badCampaignCond})
         ORDER BY created_at ASC",
        [':date' => $date]
    );

    echo "job_clicks sem UTM: " . count($clicks) . "\n";

    if (empty($clicks)) {
        echo "Nada para corrigir nesta data.\n\n";
        continue;
    }

    // 2. Loop pelos ESPs — processa linha a linha
    $processedIds = []; // IDs de job_clicks já atualizados (evita reprocessar em outro ESP)

    foreach ($esps as $esp) {
        $espId      = (int) $esp['id'];
        $apiKey     = (string) $esp['api_key'];
        $brevoCache = []; // cache de eventos Brevo por email para este ESP

        echo "  ESP [{$espId}] {$esp['name']}\n";

        try {
            $campaigns = getCampaignsForDate($apiKey, $date);
        } catch (Throwable $e) {
            echo "  ERRO ao buscar campanhas: {$e->getMessage()}\n\n";
            continue;
        }

        if (empty($campaigns)) {
            echo "  Nenhuma campanha sent em {$date}.\n\n";
            continue;
        }

        echo "  " . count($campaigns) . " campanha(s):\n";
        foreach ($campaigns as $c) {
            printf("    [%d] %s  (sent %s)\n", $c['id'], $c['name'], date('H:i', $c['scheduled_ts']));
        }
        echo "\n";

        // 3. Por linha: checa sync, busca eventos Brevo (cache por email), faz match
        foreach ($clicks as $row) {
            $clickId = (int) $row['id'];

            if (isset($processedIds[$clickId])) {
                continue;
            }

            $email   = strtolower(trim((string) $row['email']));
            $clickTs = (int) strtotime((string) $row['created_at']);

            $isSynced = pdoFetchOne(
                "SELECT 1
                   FROM record_leads rl
                   JOIN record_sync rs ON rs.record_lead_id = rl.id AND rs.esp_id = :esp_id
                  WHERE LOWER(rl.email) = :email
                  LIMIT 1",
                [':esp_id' => $espId, ':email' => $email]
            );

            if ($isSynced === null) {
                continue;
            }

            if (!array_key_exists($email, $brevoCache)) {
                try {
                    $brevoCache[$email] = getContactClickEvents($apiKey, $email, $date, $campaigns);
                } catch (Throwable $e) {
                    echo "  WARN {$email}: {$e->getMessage()}\n";
                    $brevoCache[$email] = [];
                }
            }

            $brevoClickEvents = $brevoCache[$email];

            if (empty($brevoClickEvents)) {
                echo "  SKIP id={$clickId} {$email} — sem clicks na Brevo\n";
                $totalSkipped++;
                continue;
            }

            $best     = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($brevoClickEvents as $ev) {
                $diff = abs($ev['ts'] - $clickTs);
                if ($diff < $bestDiff && $diff <= 600) {
                    $bestDiff = $diff;
                    $best     = $ev;
                }
            }

            if ($best === null) {
                foreach ($brevoClickEvents as $ev) {
                    $diff = abs($ev['ts'] - $clickTs);
                    if ($diff < $bestDiff) {
                        $bestDiff = $diff;
                        $best     = $ev;
                    }
                }
            }

            if ($best === null) {
                echo "  SKIP id={$clickId} {$email} — sem evento Brevo para match\n";
                $totalSkipped++;
                continue;
            }

            $utmCampaign = $best['utm_campaign'];

            if ($utmCampaign === '') {
                echo "  SKIP id={$clickId} {$email} — utm_campaign vazio (campanha {$best['campaign_id']} fora da janela)\n";
                $totalSkipped++;
                continue;
            }

            printf(
                "  OK id=%-7d %-36s %s → campanha %d \"%s\" diff=%ds\n",
                $clickId,
                $email,
                date('H:i:s', $clickTs),
                $best['campaign_id'],
                $utmCampaign,
                $bestDiff
            );

            $updated = pdoExecute(
                "UPDATE job_clicks
                    SET utm_source   = :src,
                        utm_medium   = :med,
                        utm_campaign = :cmp,
                        utm_id       = :uid
                  WHERE id = :id",
                [
                    ':src' => $best['utm_source'],
                    ':med' => $best['utm_medium'],
                    ':cmp' => $utmCampaign,
                    ':uid' => $best['utm_id'],
                    ':id'  => $clickId,
                ]
            );

            if ($updated === 0) {
                echo "  WARN id={$clickId} — UPDATE não afetou nenhuma linha\n";
                $totalSkipped++;
                continue;
            }

            $totalUpdated++;
            $processedIds[$clickId] = true;
        } // fim foreach clicks

        echo "\n";

    } // fim foreach esps

    // 5. Propaga para job_clicks_out: copia UTM de job_clicks para os out sem UTM
    $outRows = pdoFetchAll(
        "SELECT id, job_click_id
           FROM job_clicks_out
          WHERE DATE(created_at) = :date
            AND job_click_id > 0
            AND (utm_source = '' OR utm_source IS NULL{$badCampaignCond})",
        [':date' => $date]
    );

    echo "job_clicks_out sem UTM: " . count($outRows) . "\n";

    foreach (array_chunk($outRows, 500) as $chunk) {
        $clickIds = array_unique(array_column($chunk, 'job_click_id'));
        $ph       = implode(',', array_fill(0, count($clickIds), '?'));

        $jcMap = pdoRunWithReconnect(function (PDO $pdo) use ($ph, $clickIds): array {
            $st = $pdo->prepare(
                "SELECT id, utm_source, utm_medium, utm_campaign, utm_id
                   FROM job_clicks
                  WHERE id IN ({$ph})
                    AND utm_source != ''
                    AND utm_source IS NOT NULL"
            );
            $st->execute(array_values($clickIds));
            return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], null, 'id');
        });

        foreach ($chunk as $out) {
            $jcId = (int) $out['job_click_id'];
            if (!isset($jcMap[$jcId])) {
                continue;
            }
            $jc = $jcMap[$jcId];

            $n = pdoExecute(
                "UPDATE job_clicks_out
                    SET utm_source   = :src,
                        utm_medium   = :med,
                        utm_campaign = :cmp,
                        utm_id       = :uid
                  WHERE id = :id
                    AND (utm_source = '' OR utm_source IS NULL)",
                [
                    ':src' => $jc['utm_source'],
                    ':med' => $jc['utm_medium'],
                    ':cmp' => $jc['utm_campaign'],
                    ':uid' => $jc['utm_id'],
                    ':id'  => (int) $out['id'],
                ]
            );
            $totalUpdatedOut += $n;
        }
    }

    echo "\n";

} // fim foreach dates

// ── Fallback: job_clicks_out ainda sem UTM → busca direto na Brevo ────────────

echo "\n=== FALLBACK job_clicks_out → Brevo ===\n";

foreach ($dates as $date) {
    echo "──────────────────────────────────────────\n";
    echo "Data: {$date}\n";

    $fallbackRows = pdoFetchAll(
        "SELECT id, email, created_at
           FROM job_clicks_out
          WHERE DATE(created_at) = :date
            AND email != ''
            AND (utm_source = '' OR utm_source IS NULL{$badCampaignCond})
         ORDER BY created_at ASC",
        [':date' => $date]
    );

    echo "job_clicks_out ainda sem UTM: " . count($fallbackRows) . "\n";

    if (empty($fallbackRows)) {
        echo "Nada para o fallback nesta data.\n\n";
        continue;
    }

    $processedOutIds = []; // IDs de job_clicks_out já atualizados

    foreach ($esps as $esp) {
        $espId      = (int) $esp['id'];
        $apiKey     = (string) $esp['api_key'];
        $brevoCache = [];

        echo "  ESP [{$espId}] {$esp['name']}\n";

        try {
            $campaigns = getCampaignsForDate($apiKey, $date);
        } catch (Throwable $e) {
            echo "  ERRO ao buscar campanhas: {$e->getMessage()}\n\n";
            continue;
        }

        if (empty($campaigns)) {
            echo "  Nenhuma campanha sent em {$date}.\n\n";
            continue;
        }

        foreach ($fallbackRows as $row) {
            $outId = (int) $row['id'];

            if (isset($processedOutIds[$outId])) {
                continue;
            }

            $email   = strtolower(trim((string) $row['email']));
            $clickTs = (int) strtotime((string) $row['created_at']);

            $isSynced = pdoFetchOne(
                "SELECT 1
                   FROM record_leads rl
                   JOIN record_sync rs ON rs.record_lead_id = rl.id AND rs.esp_id = :esp_id
                  WHERE LOWER(rl.email) = :email
                  LIMIT 1",
                [':esp_id' => $espId, ':email' => $email]
            );

            if ($isSynced === null) {
                continue;
            }

            if (!array_key_exists($email, $brevoCache)) {
                try {
                    $brevoCache[$email] = getContactClickEvents($apiKey, $email, $date, $campaigns);
                } catch (Throwable $e) {
                    echo "  WARN {$email}: {$e->getMessage()}\n";
                    $brevoCache[$email] = [];
                }
            }

            $brevoClickEvents = $brevoCache[$email];

            if (empty($brevoClickEvents)) {
                echo "  SKIP out_id={$outId} {$email} — sem clicks na Brevo\n";
                $totalSkipped++;
                continue;
            }

            $best     = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($brevoClickEvents as $ev) {
                $diff = abs($ev['ts'] - $clickTs);
                if ($diff < $bestDiff && $diff <= 600) {
                    $bestDiff = $diff;
                    $best     = $ev;
                }
            }

            if ($best === null) {
                foreach ($brevoClickEvents as $ev) {
                    $diff = abs($ev['ts'] - $clickTs);
                    if ($diff < $bestDiff) {
                        $bestDiff = $diff;
                        $best     = $ev;
                    }
                }
            }

            if ($best === null) {
                echo "  SKIP out_id={$outId} {$email} — sem evento Brevo\n";
                $totalSkipped++;
                continue;
            }

            $utmCampaign = $best['utm_campaign'];

            if ($utmCampaign === '') {
                echo "  SKIP out_id={$outId} {$email} — utm_campaign vazio\n";
                $totalSkipped++;
                continue;
            }

            printf(
                "  FB  out_id=%-7d %-36s %s → campanha %d \"%s\" diff=%ds\n",
                $outId,
                $email,
                date('H:i:s', $clickTs),
                $best['campaign_id'],
                $utmCampaign,
                $bestDiff
            );

            $n = pdoExecute(
                "UPDATE job_clicks_out
                    SET utm_source   = :src,
                        utm_medium   = :med,
                        utm_campaign = :cmp,
                        utm_id       = :uid
                  WHERE id = :id",
                [
                    ':src' => $best['utm_source'],
                    ':med' => $best['utm_medium'],
                    ':cmp' => $utmCampaign,
                    ':uid' => $best['utm_id'],
                    ':id'  => $outId,
                ]
            );

            $totalUpdatedOut += $n;
            if ($n > 0) {
                $processedOutIds[$outId] = true;
            }
        }

        echo "\n";
    }

    echo "\n";
}

// ── Resumo ────────────────────────────────────────────────────────────────────

echo "==========================================\n";
echo "job_clicks atualizados    : {$totalUpdated}\n";
echo "job_clicks_out atualizados: {$totalUpdatedOut}\n";
echo "pulados (sem match Brevo) : {$totalSkipped}\n";
echo "\nFim.\n";
