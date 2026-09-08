<?php

declare(strict_types=1);

class RevenueController
{
    public function index(Request $request, array $_params = []): void
    {
        [$dateFrom, $dateTo] = $this->resolveDates($request);

        $providerIds = $this->parseIntList($request, 'provider_job_id');

        [$providerWhere, $bindings] = $this->buildProviderFilter($providerIds);

        $baseBetween = "report_date BETWEEN :date_from AND :date_to";
        $bindings[':date_from'] = $dateFrom;
        $bindings[':date_to']   = $dateTo;

        $where = "WHERE {$baseBetween}" . ($providerWhere ? " AND {$providerWhere}" : '');

        $summary = $this->fetchSummary($where, $bindings, $dateFrom, $dateTo);
        $daily   = $this->fetchDaily($where, $bindings);
        $byProvider = $this->fetchByProvider($dateFrom, $dateTo, $providerIds);

        Response::json([
            'success'     => true,
            'summary'     => $summary,
            'daily'       => $daily,
            'by_provider' => $byProvider,
        ]);
    }

    private function fetchSummary(string $where, array $bindings, string $dateFrom, string $dateTo): array
    {
        $row = pdoFetchOne(
            "SELECT
                SUM(clicks)         AS total_clicks,
                SUM(earnings_cents) AS total_earnings_cents,
                SUM(expired_clicks) AS total_expired_clicks,
                MIN(report_date)    AS first_date,
                MAX(report_date)    AS last_date
             FROM earnings_daily
             {$where}",
            $bindings
        ) ?? [];

        $clicks   = (int) ($row['total_clicks'] ?? 0);
        $cents    = (int) ($row['total_earnings_cents'] ?? 0);
        $expired  = (int) ($row['total_expired_clicks'] ?? 0);
        $usd      = round($cents / 100, 2);

        $d1      = new DateTimeImmutable($dateFrom);
        $d2      = new DateTimeImmutable($dateTo);
        $daySpan = max(1, $d1->diff($d2)->days + 1);

        return [
            'total_clicks'         => $clicks,
            'total_earnings_usd'   => $usd,
            'total_earnings_cents' => $cents,
            'total_expired_clicks' => $expired,
            'epc'                  => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
            'avg_revenue_per_day'  => round($usd / $daySpan, 2),
            'period_days'          => $daySpan,
        ];
    }

    private function fetchDaily(string $where, array $bindings): array
    {
        $rows = pdoFetchAll(
            "SELECT
                report_date         AS day,
                SUM(clicks)         AS clicks,
                SUM(earnings_cents) AS earnings_cents,
                SUM(expired_clicks) AS expired_clicks
             FROM earnings_daily
             {$where}
             GROUP BY report_date
             ORDER BY report_date ASC",
            $bindings
        );

        return array_map(function (array $r): array {
            $clicks = (int) $r['clicks'];
            $usd    = round((int) $r['earnings_cents'] / 100, 2);
            return [
                'day'            => $r['day'],
                'clicks'         => $clicks,
                'earnings_usd'   => $usd,
                'earnings_cents' => (int) $r['earnings_cents'],
                'expired_clicks' => (int) $r['expired_clicks'],
                'epc'            => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
            ];
        }, $rows);
    }

    private function fetchByProvider(string $dateFrom, string $dateTo, array $providerIds): array
    {
        [$providerWhere, $bindings] = $this->buildProviderFilter($providerIds);
        $bindings[':date_from'] = $dateFrom;
        $bindings[':date_to']   = $dateTo;

        $andProvider = $providerWhere ? "AND {$providerWhere}" : '';

        $rows = pdoFetchAll(
            "SELECT
                pj.id                                       AS provider_job_id,
                COALESCE(pj.slug, CONCAT('Provider #', pj.id)) AS provider_name,
                COALESCE(ts.weight, 0)                      AS weight,
                COALESCE(SUM(ed.clicks), 0)                 AS clicks,
                COALESCE(SUM(ed.earnings_cents), 0)         AS earnings_cents,
                COALESCE(SUM(ed.expired_clicks), 0)         AS expired_clicks
             FROM provider_jobs pj
             LEFT JOIN provider_jobs_traffic_split ts ON ts.provider_job_id = pj.id
             LEFT JOIN earnings_daily ed
                    ON ed.provider_job_id = pj.id
                   AND ed.report_date BETWEEN :date_from AND :date_to
                   {$andProvider}
             GROUP BY pj.id, pj.slug, ts.weight
             ORDER BY pj.id ASC",
            $bindings
        );

        return array_map(function (array $r): array {
            $clicks = (int) $r['clicks'];
            $usd    = round((int) $r['earnings_cents'] / 100, 2);
            return [
                'provider_job_id' => (int) $r['provider_job_id'],
                'provider_name'   => $r['provider_name'],
                'weight'          => (int) $r['weight'],
                'clicks'          => $clicks,
                'earnings_usd'    => $usd,
                'earnings_cents'  => (int) $r['earnings_cents'],
                'expired_clicks'  => (int) $r['expired_clicks'],
                'epc'             => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
            ];
        }, $rows);
    }

    private function buildProviderFilter(array $providerIds): array
    {
        if (empty($providerIds)) {
            return ['', []];
        }

        $placeholders = [];
        $bindings     = [];
        foreach ($providerIds as $i => $id) {
            $key              = ':pjid_' . $i;
            $placeholders[]   = $key;
            $bindings[$key]   = $id;
        }

        return ['provider_job_id IN (' . implode(', ', $placeholders) . ')', $bindings];
    }

    private function resolveDates(Request $request): array
    {
        $from = $request->string('date_from');
        $to   = $request->string('date_to');

        if ($from === '') {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if ($to === '') {
            $to = date('Y-m-d');
        }

        return [$from, $to];
    }

    private function parseIntList(Request $request, string $key): array
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids    = [];
        foreach ($values as $v) {
            $id = (int) trim((string) $v);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
