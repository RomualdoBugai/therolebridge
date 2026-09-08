<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/partials/dashboard_auth.php';

// ===============================
// FUNÇÕES (reutilizadas do CLI)
// ===============================

function bcGetAccounts(): array
{
    return pdoFetchAll(
        "SELECT id, name, api_key FROM esp WHERE api_key IS NOT NULL ORDER BY id"
    );
}

function bcFetchAccount(string $apiKey): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.brevo.com/v3/account',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["api-key: {$apiKey}", "accept: application/json"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        throw new RuntimeException("cURL: " . curl_error($ch));
    }
    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Erro desconhecido';
        throw new RuntimeException("HTTP {$httpCode}: {$msg}");
    }
    if (!is_array($data)) {
        throw new RuntimeException("Resposta inválida");
    }
    return $data;
}

function bcParsePlan(array $accountData): array
{
    $plans      = $accountData['plan'] ?? [];
    $credits    = null;
    $endDate    = null;
    $startDate  = null;
    $maxCredits = null;
    $nonEmail   = ['smsCredits', 'whatsappCredits', 'inboxCredits', 'pushCredits'];

    foreach ($plans as $plan) {
        $type = isset($plan['type']) ? (string) $plan['type'] : '';
        if (in_array($type, $nonEmail, true)) {
            continue;
        }
        $c = isset($plan['credits'])   ? (int)    $plan['credits']   : null;
        $e = isset($plan['endDate'])   ? (string) $plan['endDate']   : null;
        $s = isset($plan['startDate']) ? (string) $plan['startDate'] : null;

        if ($c !== null) {
            $credits = ($credits ?? 0) + $c;
        }
        if ($c !== null && ($maxCredits === null || $c > $maxCredits)) {
            $maxCredits = $c;
            $startDate  = $s;
            $endDate    = $e;
        }
    }
    // planVerticals contains Unix timestamps with the exact renewal time (not just date).
    // Use it when available so we don't assume midnight UTC for accounts that renew later in the day.
    $exactEndTs = null;
    foreach ($accountData['planVerticals'] ?? [] as $v) {
        if (!is_array($v) || ($v['status'] ?? '') !== 'active') {
            continue;
        }
        $ts = isset($v['endDate']) ? (int) $v['endDate'] : 0;
        if ($ts > time()) {
            $exactEndTs = $ts;
            break;
        }
    }

    return ['credits' => $credits, 'end_date' => $endDate, 'start_date' => $startDate, 'exact_end_ts' => $exactEndTs];
}

function bcSentSince(int $espId, string $since): int
{
    $row = pdoFetchOne(
        "SELECT COALESCE(SUM(global_sent), 0) AS total
         FROM email_campaigns
         WHERE esp_id = :id AND scheduled_at >= :since AND global_sent IS NOT NULL",
        [':id' => $espId, ':since' => $since]
    );
    return $row ? (int) $row['total'] : 0;
}

function bcAvgDailySent(int $espId, int $days): float
{
    $row = pdoFetchOne(
        "SELECT COALESCE(SUM(global_sent), 0) AS total
         FROM email_campaigns
         WHERE esp_id = :id
           AND scheduled_at >= NOW() - INTERVAL :days DAY
           AND global_sent IS NOT NULL",
        [':id' => $espId, ':days' => $days]
    );
    $total = $row ? (float) $row['total'] : 0.0;
    return $days > 0 ? round($total / $days, 1) : 0.0;
}

// ===============================
// COLETAR DADOS
// ===============================

$now      = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$accounts = bcGetAccounts();
$rows     = [];

foreach ($accounts as $account) {
    $espId  = (int)    $account['id'];
    $name   = (string) $account['name'];
    $apiKey = (string) $account['api_key'];

    try {
        $data  = bcFetchAccount($apiKey);
        $plan  = bcParsePlan($data);

        $credits    = $plan['credits'];
        $endDate    = $plan['end_date'];
        $startDate  = $plan['start_date'];
        $exactEndTs = $plan['exact_end_ts'];

        if ($credits !== null && $startDate !== null) {
            $sentPeriod      = bcSentSince($espId, $startDate);
            $contracted      = number_format($credits + $sentPeriod);
        } else {
            $sentPeriod = null;
            $contracted = 'N/A';
        }

        $hoursLeft  = null;
        $endDisplay = '—';
        $expDisplay = '—';

        if ($endDate !== null) {
            // Prefer planVerticals Unix timestamp (has exact renewal time).
            // Fall back to date-only string parsed at midnight UTC.
            if ($exactEndTs !== null) {
                $expiry = (new DateTimeImmutable('@' . $exactEndTs))->setTimezone(new DateTimeZone('America/New_York'));
            } else {
                $expiry = new DateTimeImmutable($endDate, new DateTimeZone('UTC'));
            }
            $secondsLeft = $expiry->getTimestamp() - $now->getTimestamp();
            $hoursLeft   = $secondsLeft / 3600;
            $endDisplay  = $expiry->format('d/m/Y');

            if ($hoursLeft > 0) {
                $d = (int) floor($hoursLeft / 24);
                $h = (int) round(fmod($hoursLeft, 24));
                $expDisplay = $d . 'd ' . $h . 'h';
            } else {
                $expDisplay = '0h';
            }
        }

        $avg30      = bcAvgDailySent($espId, 30);
        $avg7       = bcAvgDailySent($espId, 7);
        $lastDay    = bcAvgDailySent($espId, 1);
        $hourlyAvg  = $avg30 / 24;

        if ($credits === null || $hoursLeft === null) {
            $projText  = 'sem dados';
            $projClass = '';
        } elseif ($hoursLeft <= 0) {
            $projText  = 'VENCIDO';
            $projClass = 'status-bad';
        } else {
            $expected  = (int) round($hourlyAvg * $hoursLeft);
            $balance   = $credits - $expected;
            if ($balance >= 0) {
                $projText  = '+' . number_format($balance) . ' sobrando';
                $projClass = 'status-good';
            } else {
                $projText  = '-' . number_format(abs($balance)) . ' FALTANDO';
                $projClass = 'status-bad';
            }
        }

        $daysLeft   = $hoursLeft !== null && $hoursLeft > 0 ? $hoursLeft / 24 : null;
        $recPerDay  = ($credits !== null && $daysLeft !== null)
            ? number_format((int) round($credits / $daysLeft))
            : '—';

        $rows[] = [
            'id'         => $espId,
            'name'       => $name,
            'contracted' => $contracted,
            'credits'    => $credits !== null ? number_format($credits) : 'N/A',
            'end'        => $endDisplay,
            'exp'        => $expDisplay,
            'avg30'      => $avg30 > 0 ? number_format($avg30, 0) : '—',
            'avg7'       => $avg7  > 0 ? number_format($avg7,  0) : '—',
            'lastDay'    => $lastDay > 0 ? number_format($lastDay, 0) : '0',
            'recPerDay'  => $recPerDay,
            'projText'   => $projText,
            'projClass'  => $projClass,
            'error'      => null,
        ];

    } catch (RuntimeException $e) {
        $rows[] = [
            'id'    => $espId,
            'name'  => $name,
            'error' => $e->getMessage(),
        ];
    }
}

$generatedAt = $now->format('d/m/Y H:i') . ' ET';

$siteTitle    = 'Brevo Credits';
$siteSubtitle = 'Créditos restantes, projeção de uso e médias de envio por conta Brevo.';
$activeTab    = 'brevo_credits';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<?php include __DIR__ . '/partials/head.php'; ?>
<body>
<div class="container">

    <?php include __DIR__ . '/partials/dashboard_top_nav.php'; ?>

    <div class="table-wrapper" style="margin-top:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-size:.8rem;color:#6b7280;">Gerado em <?= htmlspecialchars($generatedAt) ?></div>
            <a href="brevo_credits.php" class="btn-primary" style="font-size:.8rem;padding:6px 14px;text-decoration:none;">↺ Atualizar</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Conta</th>
                    <th style="text-align:right;">Contratado</th>
                    <th style="text-align:right;">Restam</th>
                    <th style="text-align:right;">Vencimento</th>
                    <th style="text-align:right;">Tempo rest.</th>
                    <th style="text-align:right;">Média/30d</th>
                    <th style="text-align:right;">Média/7d</th>
                    <th style="text-align:right;">Ult. dia</th>
                    <th style="text-align:right;">Rec./dia</th>
                    <th>Projeção</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php if ($r['error'] !== null): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td colspan="9" style="color:#b91c1c;"><?= htmlspecialchars($r['error']) ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['contracted']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['credits']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['end']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['exp']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['avg30']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['avg7']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['lastDay']) ?></td>
                    <td style="text-align:right;"><?= htmlspecialchars($r['recPerDay']) ?></td>
                    <td>
                        <?php if ($r['projClass'] !== ''): ?>
                            <span class="<?= htmlspecialchars($r['projClass']) ?>"
                                  style="padding:2px 8px;border-radius:4px;font-size:.8rem;white-space:nowrap;">
                                <?= htmlspecialchars($r['projText']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#9ca3af;"><?= htmlspecialchars($r['projText']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:12px;font-size:.75rem;color:#9ca3af;line-height:1.6;">
            Contratado = créditos restantes + enviados desde o início do período.<br>
            Média/30d e Média/7d = média diária de envios no período.<br>
            Ult. dia = total enviado nas últimas 24h.<br>
            Rec./dia = créditos restantes ÷ dias restantes (limite diário para não estourar).<br>
            Projeção = créditos restantes − (média/hora × horas até vencimento).
        </div>
    </div>

</div>
</body>
</html>
