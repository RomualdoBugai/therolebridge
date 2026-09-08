<?php

declare(strict_types=1);

class CampaignController
{
    private const SEND_DATE = 'COALESCE(c.scheduled_at, c.created_at_esp, c.created_at_local)';

    private const TEMPLATE_EXPR = "CASE WHEN c.name LIKE '%#%'
        THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.name, '#', -1), ' ', 1) AS UNSIGNED)
        ELSE NULL END";

    private const ALLOWED_SORT = [
        'id'             => 'c.id',
        'name'           => 'c.name',
        'esp'            => 'e.name',
        'template_id'    => 'template_id_extracted',
        'esp_campaign_id'=> 'c.esp_campaign_id',
        'scheduled_at'   => 'send_datetime',
        'total_sent'     => 'total_sent',
        'delivered'      => 'delivered',
        'unique_opened'  => 'unique_opened',
        'unique_clicked' => 'unique_clicked',
        'open_rate'      => 'open_rate',
        'ctr'            => 'ctr',
        'ctor'           => 'ctor',
        'unsubs'         => 'unsubs',
        'bounced'        => 'bounced',
    ];

    private const ALLOWED_WEEKDAYS = [
        'monday'    => 0,
        'tuesday'   => 1,
        'wednesday' => 2,
        'thursday'  => 3,
        'friday'    => 4,
        'saturday'  => 5,
        'sunday'    => 6,
    ];

    public function index(Request $request, array $_params = []): void
    {
        $page      = max(1, $request->integer('page', 1));
        $perPage   = min(200, max(1, $request->integer('per_page', 25)));
        $sortKey   = $request->string('sort_by', 'scheduled_at');
        $sortDir   = strtoupper($request->string('sort_dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $orderExpr = self::ALLOWED_SORT[$sortKey] ?? 'send_datetime';

        [$where, $bindings] = $this->buildWhere($request);

        $total = (int) pdoFetchValue(
            "SELECT COUNT(*)
             FROM email_campaigns c
             LEFT JOIN esp e ON e.id = c.esp_id
             {$where}",
            $bindings
        );

        $offset = ($page - 1) * $perPage;

        $rows = pdoFetchAll(
            "SELECT
                c.id,
                c.esp_id,
                c.esp_campaign_id,
                e.name                                      AS esp_name,
                c.name,
                c.subject,
                c.sender_email,
                c.sender_name,
                " . self::SEND_DATE . "                     AS send_datetime,
                DAYNAME(" . self::SEND_DATE . ")            AS weekday_name,
                " . self::TEMPLATE_EXPR . "                 AS template_id_extracted,
                COALESCE(c.global_sent, 0)                  AS total_sent,
                COALESCE(c.global_delivered, 0)             AS delivered,
                COALESCE(c.global_unique_views, 0)          AS unique_opened,
                COALESCE(c.global_unique_clicks, 0)         AS unique_clicked,
                COALESCE(c.global_unsubscriptions, 0)       AS unsubs,
                COALESCE(c.global_complaints, 0)            AS complaints,
                (COALESCE(c.global_hard_bounces, 0)
                    + COALESCE(c.global_soft_bounces, 0))   AS bounced,
                ROUND((COALESCE(c.global_unique_views, 0)
                    / NULLIF(COALESCE(c.global_delivered, 0), 0)) * 100, 2) AS open_rate,
                ROUND((COALESCE(c.global_unique_clicks, 0)
                    / NULLIF(COALESCE(c.global_delivered, 0), 0)) * 100, 2) AS ctr,
                ROUND((COALESCE(c.global_unique_clicks, 0)
                    / NULLIF(COALESCE(c.global_unique_views, 0), 0)) * 100, 2) AS ctor
             FROM email_campaigns c
             LEFT JOIN esp e ON e.id = c.esp_id
             {$where}
             ORDER BY {$orderExpr} {$sortDir}, c.id DESC
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
        $sendDate   = self::SEND_DATE;
        $conditions = [];
        $bindings   = [];

        $dateFrom = $request->string('date_from');
        $dateTo   = $request->string('date_to');

        if ($dateFrom !== '' || $dateTo !== '') {
            $from = $dateFrom !== '' ? $dateFrom : '2000-01-01';
            $to   = $dateTo   !== '' ? $dateTo   : date('Y-m-d');

            $conditions[]          = "DATE({$sendDate}) BETWEEN :date_from AND :date_to";
            $bindings[':date_from'] = $from;
            $bindings[':date_to']   = $to;
        }

        $espIds = $this->parseIntList($request, 'esp_id');
        if (!empty($espIds)) {
            $placeholders = [];
            foreach ($espIds as $i => $id) {
                $key = ':esp_id_' . $i;
                $placeholders[]  = $key;
                $bindings[$key]  = $id;
            }
            $conditions[] = 'c.esp_id IN (' . implode(', ', $placeholders) . ')';
        }

        $search = $request->string('search');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(c.name LIKE :s1 OR c.subject LIKE :s2 OR e.name LIKE :s3
                              OR c.sender_email LIKE :s4 OR c.sender_name LIKE :s5)';
            $bindings[':s1'] = $like;
            $bindings[':s2'] = $like;
            $bindings[':s3'] = $like;
            $bindings[':s4'] = $like;
            $bindings[':s5'] = $like;
        }

        $templateId = $request->string('template_id');
        if ($templateId !== '') {
            $conditions[]             = self::TEMPLATE_EXPR . ' = :template_id';
            $bindings[':template_id'] = (int) $templateId;
        }

        $espCampaignId = $request->string('esp_campaign_id');
        if ($espCampaignId !== '') {
            $conditions[]                = 'c.esp_campaign_id = :esp_campaign_id';
            $bindings[':esp_campaign_id'] = (int) $espCampaignId;
        }

        $weekday = strtolower($request->string('weekday'));
        if ($weekday !== '' && isset(self::ALLOWED_WEEKDAYS[$weekday])) {
            $conditions[]          = "WEEKDAY({$sendDate}) = :weekday_num";
            $bindings[':weekday_num'] = self::ALLOWED_WEEKDAYS[$weekday];
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $bindings];
    }

    /**
     * Aceita ?esp_id=1,2,3  ou  ?esp_id[]=1&esp_id[]=2
     * Retorna array de inteiros únicos e positivos.
     */
    private function parseIntList(Request $request, string $key): array
    {
        $raw = $_GET[$key] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw)
            ? $raw
            : explode(',', (string) $raw);

        $ids = [];
        foreach ($values as $v) {
            $id = (int) trim((string) $v);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function fetchSummary(string $where, array $bindings): array
    {
        $row = pdoFetchOne(
            "SELECT
                COUNT(*)                                            AS campaigns,
                SUM(COALESCE(c.global_sent, 0))                    AS total_sent,
                SUM(COALESCE(c.global_delivered, 0))               AS delivered,
                SUM(COALESCE(c.global_unique_views, 0))            AS unique_opened,
                SUM(COALESCE(c.global_unique_clicks, 0))           AS unique_clicked,
                SUM(COALESCE(c.global_unsubscriptions, 0))         AS unsubs,
                SUM(COALESCE(c.global_complaints, 0))              AS complaints,
                SUM(COALESCE(c.global_hard_bounces, 0)
                    + COALESCE(c.global_soft_bounces, 0))          AS bounced
             FROM email_campaigns c
             LEFT JOIN esp e ON e.id = c.esp_id
             {$where}",
            $bindings
        ) ?? [];

        $delivered = (int) ($row['delivered'] ?? 0);
        $opened    = (int) ($row['unique_opened'] ?? 0);
        $clicked   = (int) ($row['unique_clicked'] ?? 0);
        $unsubs    = (int) ($row['unsubs'] ?? 0);

        return [
            'campaigns'     => (int) ($row['campaigns'] ?? 0),
            'total_sent'    => (int) ($row['total_sent'] ?? 0),
            'delivered'     => $delivered,
            'unique_opened' => $opened,
            'unique_clicked'=> $clicked,
            'unsubs'        => $unsubs,
            'complaints'    => (int) ($row['complaints'] ?? 0),
            'bounced'       => (int) ($row['bounced'] ?? 0),
            'open_rate'     => $delivered > 0 ? round(($opened  / $delivered) * 100, 2) : 0.0,
            'ctr'           => $delivered > 0 ? round(($clicked / $delivered) * 100, 2) : 0.0,
            'ctor'          => $opened    > 0 ? round(($clicked / $opened)    * 100, 2) : 0.0,
            'unsub_rate'    => $delivered > 0 ? round(($unsubs  / $delivered) * 100, 2) : 0.0,
        ];
    }
}
