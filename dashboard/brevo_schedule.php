<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';
require_once __DIR__ . '/partials/dashboard_filters.php';

// ========================
// HELPERS
// ========================

function bdCallBrevo(string $url, string $apiKey): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["api-key: {$apiKey}", "accept: application/json"],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $httpCode >= 400) return [];

    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}

/**
 * Fetch all queued campaigns for one account, filtered by scheduledAt in [startDate, endDate].
 * API doesn't support date filter for queued — filtered manually, sorted ASC.
 */
function bdFetchQueued(string $apiKey, string $startDate, string $endDate): array
{
    $baseUrl = 'https://api.brevo.com/v3/emailCampaigns';
    $limit   = 100;
    $offset  = 0;
    $all     = [];

    while (true) {
        $url = $baseUrl . '?' . http_build_query([
            'status'             => 'queued',
            'limit'              => $limit,
            'offset'             => $offset,
            'sort'               => 'desc',
            'excludeHtmlContent' => 'true',
        ]);

        $data      = bdCallBrevo($url, $apiKey);
        $campaigns = $data['campaigns'] ?? [];
        $total     = (int) ($data['count'] ?? 0);

        if (empty($campaigns)) break;

        $pastWindow = false;

        foreach ($campaigns as $c) {
            $raw = $c['scheduledAt'] ?? null;
            if (!$raw) continue;

            $scheduled = date('Y-m-d', strtotime((string) $raw));

            if ($scheduled > $endDate) continue;
            if ($scheduled < $startDate) {
                $pastWindow = true;
                continue;
            }

            $all[] = $c;
        }

        $offset += $limit;
        if ($offset >= $total) break;
        if ($offset >= 500) break;
        if ($pastWindow) break;
    }

    usort($all, function ($a, $b) {
        return strtotime((string) ($a['scheduledAt'] ?? '0'))
             <=> strtotime((string) ($b['scheduledAt'] ?? '0'));
    });

    return $all;
}

// ========================
// DATE RANGE: next 7 days
// ========================

$tz         = new DateTimeZone('America/New_York');
$todayDt    = new DateTimeImmutable('today', $tz);
$schedStart = $todayDt->format('Y-m-d');
$schedEnd   = $todayDt->modify('+6 days')->format('Y-m-d');

$dayLabels = [];
for ($i = 0; $i < 7; $i++) {
    $dt  = $todayDt->modify("+{$i} days");
    $ymd = $dt->format('Y-m-d');
    if ($i === 0) {
        $dayLabels[$ymd] = 'Today · ' . $dt->format('D, M j');
    } elseif ($i === 1) {
        $dayLabels[$ymd] = 'Tomorrow · ' . $dt->format('D, M j');
    } else {
        $dayLabels[$ymd] = $dt->format('D, M j');
    }
}

// ========================
// FETCH DATA
// ========================

$accounts = pdoFetchAll(
    "SELECT id, name, api_key FROM esp WHERE api_key IS NOT NULL ORDER BY id ASC"
);

$campaignsByEsp = [];
$errors         = [];

foreach ($accounts as $account) {
    $espId  = (int) $account['id'];
    $name   = (string) $account['name'];
    $apiKey = (string) $account['api_key'];

    $campaigns = [];
    try {
        $campaigns = bdFetchQueued($apiKey, $schedStart, $schedEnd);
    } catch (Throwable $e) {
        $errors[] = "[{$name}]: " . $e->getMessage();
    }

    $countPerDay = array_fill_keys(array_keys($dayLabels), 0);
    foreach ($campaigns as $c) {
        $d = date('Y-m-d', strtotime((string) ($c['scheduledAt'] ?? '0')));
        if (isset($countPerDay[$d])) $countPerDay[$d]++;
    }

    $campaignsByEsp[$espId] = [
        'name'        => $name,
        'campaigns'   => $campaigns,
        'countPerDay' => $countPerDay,
        'total'       => count($campaigns),
    ];
}

$grandTotal  = 0;
$grandPerDay = array_fill_keys(array_keys($dayLabels), 0);

foreach ($campaignsByEsp as $esp) {
    $grandTotal += $esp['total'];
    foreach ($esp['countPerDay'] as $day => $cnt) {
        $grandPerDay[$day] += $cnt;
    }
}

// ========================
// SCHEDULE COMPARISON: DB (esp_schedule) vs Brevo (queued)
// ========================

// Map each date in range to its weekday name
$dateToWeekday = [];
for ($i = 0; $i < 7; $i++) {
    $dt = $todayDt->modify("+{$i} days");
    $dateToWeekday[$dt->format('Y-m-d')] = $dt->format('l'); // Monday, Tuesday, …
}

$alerts = [];
$espIds = array_keys($campaignsByEsp);

if (!empty($espIds)) {
    $uniqueWeekdays = array_unique(array_values($dateToWeekday));
    $todayWeekday   = $todayDt->format('l');
    $currentTime    = (new DateTimeImmutable('now', $tz))->format('H:i:s');

    $espPh = implode(',', array_fill(0, count($espIds), '?'));
    $wdPh  = implode(',', array_fill(0, count($uniqueWeekdays), '?'));

    // For today: only count slots whose time hasn't passed yet (campaign should still be queued).
    // For other days: count all active slots.
    $schedRows = pdoFetchAll(
        "SELECT esp_id, weekday, COUNT(*) AS expected_count
         FROM esp_schedule
         WHERE is_active = 1
           AND esp_id IN ({$espPh})
           AND weekday IN ({$wdPh})
           AND (weekday != ? OR time > ?)
         GROUP BY esp_id, weekday",
        array_merge($espIds, $uniqueWeekdays, [$todayWeekday, $currentTime])
    );

    // Build: [esp_id][weekday] => expected count
    $expectedMap = [];
    foreach ($schedRows as $r) {
        $expectedMap[(int) $r['esp_id']][$r['weekday']] = (int) $r['expected_count'];
    }

    foreach ($dateToWeekday as $ymd => $weekday) {
        if ($ymd !== $schedStart) continue; // alertas só para hoje

        foreach ($campaignsByEsp as $espId => $esp) {
            $expected = $expectedMap[$espId][$weekday] ?? 0;
            $found    = $esp['countPerDay'][$ymd] ?? 0;

            if ($expected === $found) continue;

            $label = $dayLabels[$ymd];

            if ($found < $expected) {
                $alerts[] = [
                    'type' => 'missing',
                    'msg'  => "[{$esp['name']}] {$label}: {$expected} esperadas, {$found} na Brevo — " . ($expected - $found) . " faltando",
                ];
            } else {
                $alerts[] = [
                    'type' => 'extra',
                    'msg'  => "[{$esp['name']}] {$label}: {$expected} esperadas, {$found} na Brevo — " . ($found - $expected) . " a mais",
                ];
            }
        }
    }
}

$siteTitle    = 'Campaign Schedule';
$siteSubtitle = 'Campanhas agendadas (queued) nos próximos 7 dias, por conta Brevo.';
$activeTab    = 'brevo_schedule';
$hideFilters  = true;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<?php include __DIR__ . '/partials/head.php'; ?>
<body>
<div class="container">

    <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

    <?php if (!empty($errors)): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.8rem;color:#991b1b;">
        <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($alerts)): ?>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px;">
        <?php foreach ($alerts as $a):
            if ($a['type'] === 'missing') {
                $bg     = '#fef3c7';
                $border = '#fcd34d';
                $color  = '#78350f';
                $icon   = '⚠️';
            } else {
                $bg     = '#dbeafe';
                $border = '#93c5fd';
                $color  = '#1e3a8a';
                $icon   = 'ℹ️';
            }
        ?>
        <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:6px;padding:8px 14px;font-size:.82rem;color:<?= $color ?>;">
            <?= $icon ?> <?= htmlspecialchars($a['msg']) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Global summary -->
    <div class="cards">
        <div class="card">
            <div class="card-title">Total agendadas</div>
            <div class="card-value"><?= number_format($grandTotal) ?></div>
            <div class="card-helper"><?= htmlspecialchars($schedStart) ?> → <?= htmlspecialchars($schedEnd) ?></div>
        </div>
        <?php foreach ($dayLabels as $ymd => $label): ?>
        <div class="card">
            <div class="card-title"><?= htmlspecialchars($label) ?></div>
            <div class="card-value" style="<?= $grandPerDay[$ymd] === 0 ? 'color:#9ca3af;' : '' ?>">
                <?= number_format($grandPerDay[$ymd]) ?>
            </div>
            <div class="card-helper">campanhas</div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 4px;">
        <p style="margin:0;font-size:.8rem;color:#6b7280;">
            Dados buscados diretamente da API Brevo · somente status <strong>queued</strong>.
        </p>
        <a href="brevo_schedule.php"
           style="font-size:.8rem;padding:6px 14px;text-decoration:none;background:#111827;color:#fff;border-radius:6px;white-space:nowrap;flex-shrink:0;margin-left:12px;">
            ↺ Refresh
        </a>
    </div>

    <?php foreach ($campaignsByEsp as $espId => $esp):
        $espName      = $esp['name'];
        $espCampaigns = $esp['campaigns'];
        $espTotal     = $esp['total'];
        $espPerDay    = $esp['countPerDay'];
    ?>
    <div class="table-wrapper" style="margin-top:18px;">

        <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
            <h2 style="margin:0;font-size:1rem;"><?= htmlspecialchars($espName) ?></h2>
            <span style="font-size:.8rem;color:#6b7280;">ESP <?= $espId ?></span>
            <span style="background:#fef9c3;color:#713f12;padding:2px 8px;border-radius:4px;font-size:.75rem;">
                <?= $espTotal ?> queued
            </span>
            <?php foreach ($dayLabels as $ymd => $label):
                if ($espPerDay[$ymd] === 0) continue; ?>
            <span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:4px;font-size:.75rem;white-space:nowrap;">
                <?= htmlspecialchars($label) ?>: <?= $espPerDay[$ymd] ?>
            </span>
            <?php endforeach; ?>
        </div>

        <?php if (empty($espCampaigns)): ?>
            <p style="color:#9ca3af;font-size:.85rem;margin:0;">Nenhuma campanha agendada nos próximos 7 dias.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Scheduled At</th>
                    <th class="text-right">ID</th>
                    <th>Name</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $lastDay = null;
            foreach ($espCampaigns as $c):
                $rawAt        = $c['scheduledAt'] ?? '';
                $dayKey       = $rawAt ? date('Y-m-d', strtotime((string) $rawAt)) : '';
                $scheduledFmt = $rawAt ? date('D, M j · H:i', strtotime((string) $rawAt)) : '—';
                $dayLabel     = $dayLabels[$dayKey] ?? $dayKey;

                if ($dayKey !== $lastDay):
                    $lastDay = $dayKey;
            ?>
            <tr>
                <td colspan="3"
                    style="background:#f9fafb;font-size:.75rem;font-weight:600;color:#6b7280;padding:6px 12px;border-top:2px solid #e5e7eb;letter-spacing:.04em;text-transform:uppercase;">
                    <?= htmlspecialchars($dayLabel) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td style="white-space:nowrap;"><?= htmlspecialchars($scheduledFmt) ?></td>
                <td class="text-right"><?= (int) ($c['id'] ?? 0) ?></td>
                <td style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= htmlspecialchars($c['name'] ?? '') ?>">
                    <?= htmlspecialchars($c['name'] ?? '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div>

<script src="assets/dashboard.js"></script>
</body>
</html>
