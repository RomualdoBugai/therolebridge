<?php

declare(strict_types=1);

class EspScheduleController
{
    private const ALLOWED_SORT = [
        'id'         => 'es.id',
        'esp'        => 'e.name',
        'weekday'    => 'es.weekday',
        'slot_index' => 'es.slot_index',
        'time'       => 'es.time',
        'is_active'  => 'es.is_active',
    ];

    private const WEEKDAY_ORDER = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    public function index(Request $request, array $_params = []): void
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = min(200, max(1, $request->integer('per_page', 50)));
        $sortKey = $request->string('sort_by', 'weekday');
        $sortDir = strtoupper($request->string('sort_dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $orderBy = self::ALLOWED_SORT[$sortKey] ?? 'es.weekday';

        [$where, $bindings] = $this->buildWhere($request);

        $total = (int) pdoFetchValue(
            "SELECT COUNT(*)
             FROM esp_schedule es
             LEFT JOIN esp e ON e.id = es.esp_id
             {$where}",
            $bindings
        );

        $offset = ($page - 1) * $perPage;

        $rows = pdoFetchAll(
            "SELECT
                es.id,
                es.esp_id,
                e.name                          AS esp_name,
                es.weekday,
                es.slot_index,
                es.time,
                es.template_remote_id,
                es.name_pattern,
                es.is_active,
                es.segment_ids_json,
                es.exclusion_segment_ids_json,
                es.list_ids_json
             FROM esp_schedule es
             LEFT JOIN esp e ON e.id = es.esp_id
             {$where}
             ORDER BY {$orderBy} {$sortDir}, es.slot_index ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        Response::paginated($rows, $total, $page, $perPage);
    }

    private function buildWhere(Request $request): array
    {
        $conditions = [];
        $bindings   = [];

        $espIds = $this->parseIntList($request, 'esp_id');
        if (!empty($espIds)) {
            $placeholders = [];
            foreach ($espIds as $i => $id) {
                $key              = ':esp_id_' . $i;
                $placeholders[]   = $key;
                $bindings[$key]   = $id;
            }
            $conditions[] = 'es.esp_id IN (' . implode(', ', $placeholders) . ')';
        }

        $weekday = strtolower($request->string('weekday'));
        if ($weekday !== '' && isset(self::WEEKDAY_ORDER[$weekday])) {
            $conditions[]         = 'es.weekday = :weekday';
            $bindings[':weekday'] = $weekday;
        }

        $isActive = $request->string('is_active');
        if ($isActive !== '') {
            $conditions[]          = 'es.is_active = :is_active';
            $bindings[':is_active'] = (int) $isActive;
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $bindings];
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
