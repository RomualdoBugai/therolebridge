<?php

declare(strict_types=1);

class EspController
{
    public function index(Request $request, array $_params = []): void
    {
        $search  = $request->string('search');
        $sortDir = strtoupper($request->string('sort_dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $conditions = [];
        $bindings   = [];

        if ($search !== '') {
            $conditions[]         = '(e.name LIKE :search OR e.domain_name LIKE :search2)';
            $bindings[':search']  = '%' . $search . '%';
            $bindings[':search2'] = '%' . $search . '%';
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = pdoFetchAll(
            "SELECT e.*, COUNT(DISTINCT es.id) AS schedule_count
             FROM esp e
             LEFT JOIN esp_schedule es ON es.esp_id = e.id AND es.is_active = 1
             {$where}
             GROUP BY e.id
             ORDER BY e.name {$sortDir}",
            $bindings
        );

        Response::success($rows);
    }
}
