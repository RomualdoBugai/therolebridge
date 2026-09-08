<?php

declare(strict_types=1);

class TrafficSplitController
{
    public function index(Request $request, array $_params = []): void
    {
        $dateFrom = $request->string('date_from') ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo   = $request->string('date_to')   ?: date('Y-m-d');

        $bindings = [':date_from' => $dateFrom, ':date_to' => $dateTo];

        $summary   = $this->fetchSummary($bindings);
        $providers = $this->fetchProviders($bindings);

        Response::json([
            'success'   => true,
            'summary'   => $summary,
            'providers' => $providers,
        ]);
    }

    public function update(Request $request, array $_params = []): void
    {
        $weights = $request->input('weights');

        if (!is_array($weights) || empty($weights)) {
            Response::error('Field "weights" is required and must be an object.', 422);
        }

        $updated = 0;

        foreach ($weights as $providerJobIdRaw => $weightRaw) {
            $providerJobId = (int) $providerJobIdRaw;
            $weight        = max(0, min(100000, (int) $weightRaw));

            if ($providerJobId <= 0) {
                continue;
            }

            $exists = pdoFetchOne(
                "SELECT id FROM provider_jobs WHERE id = :id LIMIT 1",
                [':id' => $providerJobId]
            );

            if (!$exists) {
                continue;
            }

            $current = pdoFetchOne(
                "SELECT id FROM provider_jobs_traffic_split WHERE provider_job_id = :id LIMIT 1",
                [':id' => $providerJobId]
            );

            if ($current) {
                pdoExecute(
                    "UPDATE provider_jobs_traffic_split SET weight = :weight, updated_at = NOW() WHERE provider_job_id = :id",
                    [':weight' => $weight, ':id' => $providerJobId]
                );
            } else {
                pdoExecute(
                    "INSERT INTO provider_jobs_traffic_split (provider_job_id, weight, updated_at) VALUES (:id, :weight, NOW())",
                    [':id' => $providerJobId, ':weight' => $weight]
                );
            }

            $updated++;
        }

        Response::success(['providers_updated' => $updated], 'Traffic split saved.');
    }

    private function fetchSummary(array $bindings): array
    {
        $row = pdoFetchOne(
            "SELECT
                SUM(clicks)         AS total_clicks,
                SUM(earnings_cents) AS total_earnings_cents,
                SUM(expired_clicks) AS total_expired_clicks
             FROM earnings_daily
             WHERE report_date BETWEEN :date_from AND :date_to",
            $bindings
        ) ?? [];

        $clicks = (int) ($row['total_clicks'] ?? 0);
        $usd    = round((int) ($row['total_earnings_cents'] ?? 0) / 100, 2);

        return [
            'total_clicks'       => $clicks,
            'total_earnings_usd' => $usd,
            'expired_clicks'     => (int) ($row['total_expired_clicks'] ?? 0),
            'epc'                => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
        ];
    }

    private function fetchProviders(array $bindings): array
    {
        $rows = pdoFetchAll(
            "SELECT
                pj.id                                           AS provider_job_id,
                COALESCE(pj.slug, CONCAT('Provider #', pj.id)) AS provider_name,
                COALESCE(ts.weight, 0)                         AS weight,
                ts.updated_at                                  AS split_updated_at,
                COALESCE(SUM(ed.clicks), 0)                    AS clicks,
                COALESCE(SUM(ed.earnings_cents), 0)            AS earnings_cents,
                COALESCE(SUM(ed.expired_clicks), 0)            AS expired_clicks
             FROM provider_jobs pj
             LEFT JOIN provider_jobs_traffic_split ts ON ts.provider_job_id = pj.id
             LEFT JOIN earnings_daily ed
                    ON ed.provider_job_id = pj.id
                   AND ed.report_date BETWEEN :date_from AND :date_to
             GROUP BY pj.id, pj.slug, ts.weight, ts.updated_at
             ORDER BY pj.id ASC",
            $bindings
        );

        $totalWeight = array_sum(array_column($rows, 'weight'));

        return array_map(function (array $r) use ($totalWeight): array {
            $clicks = (int) $r['clicks'];
            $usd    = round((int) $r['earnings_cents'] / 100, 2);
            $weight = (int) $r['weight'];
            return [
                'provider_job_id'   => (int) $r['provider_job_id'],
                'provider_name'     => $r['provider_name'],
                'weight'            => $weight,
                'weight_percent'    => $totalWeight > 0 ? round(($weight / $totalWeight) * 100, 2) : 0.0,
                'split_updated_at'  => $r['split_updated_at'],
                'clicks'            => $clicks,
                'earnings_usd'      => $usd,
                'earnings_cents'    => (int) $r['earnings_cents'],
                'expired_clicks'    => (int) $r['expired_clicks'],
                'epc'               => $clicks > 0 ? round($usd / $clicks, 4) : 0.0,
            ];
        }, $rows);
    }
}
