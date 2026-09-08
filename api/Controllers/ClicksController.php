<?php

declare(strict_types=1);

class ClicksController
{
    public function index(Request $request, array $_params = []): void
    {
        $dateFrom = $request->string('date_from') ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo   = $request->string('date_to')   ?: date('Y-m-d');

        $startDt = $dateFrom . ' 00:00:00';
        $endDt   = $dateTo   . ' 23:59:59';

        $dtBindings = [':start' => $startDt, ':end' => $endDt];

        $summary      = $this->fetchSummary($dtBindings);
        $daily        = $this->fetchDaily($dtBindings, $dateFrom, $dateTo);
        $hourly       = $this->fetchHourly($dtBindings);
        $byProvider   = $this->fetchByProvider($dtBindings);

        Response::json([
            'success'     => true,
            'summary'     => $summary,
            'daily'       => $daily,
            'hourly'      => $hourly,
            'by_provider' => $byProvider,
        ]);
    }

    private function fetchSummary(array $bindings): array
    {
        $clicks = (int) pdoFetchValue(
            "SELECT COUNT(*) FROM job_clicks WHERE created_at BETWEEN :start AND :end",
            $bindings
        );

        $clicksOut = (int) pdoFetchValue(
            "SELECT COUNT(*) FROM job_clicks_out WHERE created_at BETWEEN :start AND :end",
            $bindings
        );

        $blocked = (int) pdoFetchValue(
            "SELECT COUNT(*) FROM job_clicks_suspicious WHERE created_at BETWEEN :start AND :end",
            $bindings
        );

        return [
            'link_clicks'   => $clicks,
            'button_clicks' => $clicksOut,
            'blocked'       => $blocked,
            'conversion_rate' => $clicks > 0 ? round(($clicksOut / $clicks) * 100, 2) : 0.0,
        ];
    }

    private function fetchDaily(array $bindings, string $dateFrom, string $dateTo): array
    {
        $clicks = pdoFetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM job_clicks
             WHERE created_at BETWEEN :start AND :end
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $bindings
        );

        $clicksOut = pdoFetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM job_clicks_out
             WHERE created_at BETWEEN :start AND :end
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $bindings
        );

        $blocked = pdoFetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM job_clicks_suspicious
             WHERE created_at BETWEEN :start AND :end
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $bindings
        );

        $clicksMap    = array_column($clicks,    'total', 'day');
        $clicksOutMap = array_column($clicksOut, 'total', 'day');
        $blockedMap   = array_column($blocked,   'total', 'day');

        $days   = [];
        $cursor = new DateTimeImmutable($dateFrom);
        $last   = new DateTimeImmutable($dateTo);

        while ($cursor <= $last) {
            $day    = $cursor->format('Y-m-d');
            $lc     = (int) ($clicksMap[$day]    ?? 0);
            $bc     = (int) ($clicksOutMap[$day] ?? 0);
            $days[] = [
                'day'             => $day,
                'link_clicks'     => $lc,
                'button_clicks'   => $bc,
                'blocked'         => (int) ($blockedMap[$day] ?? 0),
                'conversion_rate' => $lc > 0 ? round(($bc / $lc) * 100, 2) : 0.0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function fetchHourly(array $bindings): array
    {
        $clicks = pdoFetchAll(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS total
             FROM job_clicks
             WHERE created_at BETWEEN :start AND :end
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            $bindings
        );

        $clicksOut = pdoFetchAll(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS total
             FROM job_clicks_out
             WHERE created_at BETWEEN :start AND :end
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            $bindings
        );

        $clicksMap    = array_column($clicks,    'total', 'hour');
        $clicksOutMap = array_column($clicksOut, 'total', 'hour');

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = [
                'hour'          => $h,
                'link_clicks'   => (int) ($clicksMap[$h]    ?? 0),
                'button_clicks' => (int) ($clicksOutMap[$h] ?? 0),
            ];
        }

        return $hours;
    }

    private function fetchByProvider(array $bindings): array
    {
        $clicks = pdoFetchAll(
            "SELECT COALESCE(NULLIF(provider, ''), 'unknown') AS provider, COUNT(*) AS link_clicks
             FROM job_clicks
             WHERE created_at BETWEEN :start AND :end
             GROUP BY provider
             ORDER BY link_clicks DESC",
            $bindings
        );

        $clicksOut = pdoFetchAll(
            "SELECT COALESCE(NULLIF(provider, ''), 'unknown') AS provider, COUNT(*) AS button_clicks
             FROM job_clicks_out
             WHERE created_at BETWEEN :start AND :end
             GROUP BY provider
             ORDER BY button_clicks DESC",
            $bindings
        );

        $outMap = array_column($clicksOut, 'button_clicks', 'provider');

        return array_map(function (array $r) use ($outMap): array {
            $lc = (int) $r['link_clicks'];
            $bc = (int) ($outMap[$r['provider']] ?? 0);
            return [
                'provider'        => $r['provider'],
                'link_clicks'     => $lc,
                'button_clicks'   => $bc,
                'conversion_rate' => $lc > 0 ? round(($bc / $lc) * 100, 2) : 0.0,
            ];
        }, $clicks);
    }
}
