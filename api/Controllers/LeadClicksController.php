<?php

declare(strict_types=1);

class LeadClicksController
{
    public function index(Request $request, array $_params = []): void
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = min(200, max(1, $request->integer('per_page', 50)));
        $sortDir = strtoupper($request->string('sort_dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $bindings] = $this->buildWhere($request);

        $total = (int) pdoFetchValue(
            "SELECT COUNT(*)
             FROM job_clicks jc
             {$where}",
            $bindings
        );

        $offset = ($page - 1) * $perPage;

        $rows = pdoFetchAll(
            "SELECT
                jc.id,
                jc.email,
                jc.provider,
                jc.keyword,
                jc.city,
                jc.state,
                jc.zip,
                jc.utm_source,
                jc.utm_medium,
                jc.utm_campaign,
                jc.user_agent,
                jc.created_at,
                jco.id           AS click_out_id,
                jco.provider     AS provider_out,
                jco.created_at   AS clicked_out_at
             FROM job_clicks jc
             LEFT JOIN job_clicks_out jco ON jco.job_click_id = jc.id
             {$where}
             ORDER BY jc.created_at {$sortDir}
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $summary = $this->fetchSummary($where, $bindings);

        Response::json([
            'success' => true,
            'data'    => $rows,
            'summary' => $summary,
            'meta'    => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / max($perPage, 1)),
            ],
        ]);
    }

    private function buildWhere(Request $request): array
    {
        $conditions = [];
        $bindings   = [];

        $email = strtolower($request->string('email'));
        if ($email !== '') {
            $conditions[]      = 'jc.email = :email';
            $bindings[':email'] = $email;
        }

        $dateFrom = $request->string('date_from');
        $dateTo   = $request->string('date_to');
        if ($dateFrom !== '' || $dateTo !== '') {
            $from = $dateFrom !== '' ? $dateFrom . ' 00:00:00' : '2000-01-01 00:00:00';
            $to   = $dateTo   !== '' ? $dateTo   . ' 23:59:59' : date('Y-m-d 23:59:59');
            $conditions[]           = 'jc.created_at BETWEEN :date_from AND :date_to';
            $bindings[':date_from'] = $from;
            $bindings[':date_to']   = $to;
        }

        $provider = $request->string('provider');
        if ($provider !== '') {
            $conditions[]         = 'jc.provider = :provider';
            $bindings[':provider'] = $provider;
        }

        $state = strtoupper($request->string('state'));
        if ($state !== '') {
            $conditions[]      = 'jc.state = :state';
            $bindings[':state'] = $state;
        }

        $utmSource = $request->string('utm_source');
        if ($utmSource !== '') {
            $conditions[]            = 'jc.utm_source = :utm_source';
            $bindings[':utm_source'] = $utmSource;
        }

        $utmCampaign = $request->string('utm_campaign');
        if ($utmCampaign !== '') {
            $conditions[]              = 'jc.utm_campaign = :utm_campaign';
            $bindings[':utm_campaign'] = $utmCampaign;
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $bindings];
    }

    private function fetchSummary(string $where, array $bindings): array
    {
        $row = pdoFetchOne(
            "SELECT
                COUNT(*)             AS total_link_clicks,
                MIN(jc.created_at)   AS first_click,
                MAX(jc.created_at)   AS last_click,
                COUNT(DISTINCT jc.email)       AS unique_emails,
                COUNT(DISTINCT jc.utm_campaign) AS unique_campaigns,
                SUM(CASE WHEN jco.id IS NOT NULL THEN 1 ELSE 0 END) AS total_button_clicks
             FROM job_clicks jc
             LEFT JOIN job_clicks_out jco ON jco.job_click_id = jc.id
             {$where}",
            $bindings
        ) ?? [];

        $link   = (int) ($row['total_link_clicks']   ?? 0);
        $button = (int) ($row['total_button_clicks']  ?? 0);

        return [
            'total_link_clicks'   => $link,
            'total_button_clicks' => $button,
            'conversion_rate'     => $link > 0 ? round(($button / $link) * 100, 2) : 0.0,
            'unique_emails'       => (int) ($row['unique_emails']      ?? 0),
            'unique_campaigns'    => (int) ($row['unique_campaigns']   ?? 0),
            'first_click'         => $row['first_click'] ?? null,
            'last_click'          => $row['last_click']  ?? null,
        ];
    }
}
