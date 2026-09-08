<?php

declare(strict_types=1);

class LeadController
{
    private const MAX_LENGTHS = [
        'email'       => 255,
        'first_name'  => 100,
        'last_name'   => 100,
        'job_keyword' => 100,
        'city'        => 100,
        'state'       => 2,
        'zip'         => 10,
    ];

    private const BLOCKED_STATES = [];

    private const ALLOWED_SORT = [
        'id'               => 'rl.id',
        'email'            => 'rl.email',
        'state'            => 'rl.state',
        'provider_data_id' => 'rl.provider_data_id',
        'created_at'       => 'rl.created_at',
    ];

    public function index(Request $request, array $_params = []): void
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = min(200, max(1, $request->integer('per_page', 25)));
        $sortKey = $request->string('sort_by', 'created_at');
        $sortDir = strtoupper($request->string('sort_dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $orderBy = self::ALLOWED_SORT[$sortKey] ?? 'rl.created_at';

        [$where, $bindings] = $this->buildIndexWhere($request);

        $total = (int) pdoFetchValue(
            "SELECT COUNT(*) FROM record_leads rl LEFT JOIN provider_data dp ON dp.id = rl.provider_data_id {$where}",
            $bindings
        );

        $offset = ($page - 1) * $perPage;

        $rows = pdoFetchAll(
            "SELECT
                rl.id,
                rl.provider_data_id,
                COALESCE(dp.name, 'Unknown') AS provider_name,
                rl.email,
                rl.first_name,
                rl.last_name,
                rl.job_keyword,
                rl.city,
                rl.state,
                rl.zip,
                rl.is_valid,
                rl.created_at
             FROM record_leads rl
             LEFT JOIN provider_data dp ON dp.id = rl.provider_data_id
             {$where}
             ORDER BY {$orderBy} {$sortDir}
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $summary = $this->fetchIndexSummary($where, $bindings);

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

    private function buildIndexWhere(Request $request): array
    {
        $conditions = [];
        $bindings   = [];

        $dateFrom = $request->string('date_from');
        $dateTo   = $request->string('date_to');

        if ($dateFrom !== '' || $dateTo !== '') {
            $from = $dateFrom !== '' ? $dateFrom . ' 00:00:00' : '2000-01-01 00:00:00';
            $to   = $dateTo   !== '' ? $dateTo   . ' 23:59:59' : date('Y-m-d 23:59:59');
            $conditions[]            = 'rl.created_at BETWEEN :date_from AND :date_to';
            $bindings[':date_from']  = $from;
            $bindings[':date_to']    = $to;
        }

        $providerIds = $this->parseIntListRaw('provider_data_id');
        if (!empty($providerIds)) {
            $placeholders = [];
            foreach ($providerIds as $i => $id) {
                $key            = ':pdid_' . $i;
                $placeholders[] = $key;
                $bindings[$key] = $id;
            }
            $conditions[] = 'rl.provider_data_id IN (' . implode(', ', $placeholders) . ')';
        }

        $state = strtoupper($request->string('state'));
        if ($state !== '') {
            $conditions[]      = 'rl.state = :state';
            $bindings[':state'] = $state;
        }

        $isValid = $request->string('is_valid');
        if ($isValid !== '') {
            $conditions[]          = 'rl.is_valid = :is_valid';
            $bindings[':is_valid'] = (int) $isValid;
        }

        $search = $request->string('search');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(rl.email LIKE :s1 OR rl.first_name LIKE :s2 OR rl.last_name LIKE :s3 OR rl.job_keyword LIKE :s4)';
            $bindings[':s1'] = $like;
            $bindings[':s2'] = $like;
            $bindings[':s3'] = $like;
            $bindings[':s4'] = $like;
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $bindings];
    }

    private function fetchIndexSummary(string $where, array $bindings): array
    {
        $row = pdoFetchOne(
            "SELECT
                COUNT(*)                                   AS total,
                SUM(COALESCE(rl.is_valid, 0) = 1)         AS valid,
                SUM(COALESCE(rl.is_valid, 0) = 0)         AS invalid,
                COUNT(DISTINCT rl.provider_data_id)        AS provider_count,
                COUNT(DISTINCT rl.state)                   AS state_count
             FROM record_leads rl
             LEFT JOIN provider_data dp ON dp.id = rl.provider_data_id
             {$where}",
            $bindings
        ) ?? [];

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'valid'          => (int) ($row['valid'] ?? 0),
            'invalid'        => (int) ($row['invalid'] ?? 0),
            'provider_count' => (int) ($row['provider_count'] ?? 0),
            'state_count'    => (int) ($row['state_count'] ?? 0),
        ];
    }

    private function parseIntListRaw(string $key): array
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

    public function store(Request $request, array $_params = []): void
    {
        $input = [
            'provider_data_id' => $request->integer('provider_data_id'),
            'email'            => strtolower($request->string('email')),
            'first_name'       => $request->string('first_name'),
            'last_name'        => $request->string('last_name'),
            'job_keyword'      => $request->string('job_keyword'),
            'city'             => $request->string('city'),
            'state'            => strtoupper($request->string('state')),
            'zip'              => $request->string('zip'),
        ];

        $errors = $this->validate($input);

        if (!empty($errors)) {
            Response::json(['success' => false, 'message' => 'Validation error.', 'errors' => $errors], 422);
        }

        $provider = pdoFetchOne(
            "SELECT id FROM provider_data WHERE id = :id LIMIT 1",
            [':id' => $input['provider_data_id']]
        );

        if (!$provider) {
            Response::json(['success' => false, 'message' => 'Validation error.', 'errors' => ['provider_data_id not found.']], 422);
        }

        $now = date('Y-m-d H:i:s');

        try {
            $id = pdoInsertGetId(
                "INSERT INTO record_leads
                    (provider_data_id, email, first_name, last_name, job_keyword, city, state, zip, created_at, updated_at)
                 VALUES
                    (:provider_data_id, :email, :first_name, :last_name, :job_keyword, :city, :state, :zip, :created_at, :updated_at)",
                [
                    ':provider_data_id' => $input['provider_data_id'],
                    ':email'            => $input['email'],
                    ':first_name'       => $input['first_name'],
                    ':last_name'        => $input['last_name'],
                    ':job_keyword'      => $input['job_keyword'],
                    ':city'             => $input['city'],
                    ':state'            => $input['state'],
                    ':zip'              => $input['zip'],
                    ':created_at'       => $now,
                    ':updated_at'       => $now,
                ]
            );

            Response::json(['success' => true, 'message' => 'Lead inserted successfully.', 'id' => $id], 201);
        } catch (PDOException $e) {
            $sqlState  = (string) $e->getCode();
            $errorInfo = (string) ($e->errorInfo[2] ?? '');

            if ($sqlState === '23000') {
                if (stripos($errorInfo, 'email') !== false) {
                    Response::json(['success' => false, 'message' => 'Email already exists.'], 409);
                }
                Response::json(['success' => false, 'message' => 'Integrity constraint violation.', 'error' => $errorInfo], 409);
            }

            throw $e;
        }
    }

    private function validate(array &$input): array
    {
        $errors = [];

        // Required fields
        $required = ['provider_data_id', 'email', 'first_name', 'last_name', 'job_keyword', 'city', 'state', 'zip'];
        foreach ($required as $field) {
            $val = $field === 'provider_data_id' ? $input[$field] : ($input[$field] ?? '');
            if ($val === '' || $val === 0) {
                $errors[] = "Field '{$field}' is required.";
            }
        }

        if ($input['provider_data_id'] <= 0) {
            $errors[] = 'provider_data_id must be a positive integer.';
        }

        if ($input['email'] !== '' && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }

        if (!empty($errors)) {
            return $errors;
        }

        // Length + format
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($input[$field]) > $max) {
                $errors[] = "Field '{$field}' exceeds max length of {$max} characters.";
                $input[$field] = mb_substr($input[$field], 0, $max);
            }
        }

        if ($input['zip'] !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $input['zip'])) {
            $errors[] = 'Invalid ZIP code format (expected 12345 or 12345-6789).';
        }

        if ($input['state'] !== '' && !preg_match('/^[A-Z]{2}$/', $input['state'])) {
            $errors[] = 'Invalid state format. Use 2-letter code (e.g., CA, NY).';
        }

        if (!empty($errors)) {
            return $errors;
        }

        // State allow-list
        if (in_array($input['state'], self::BLOCKED_STATES, true)) {
            $errors[] = "Leads from state '{$input['state']}' are not accepted.";
            return $errors;
        }

        if (!$this->isAllowedState($input['state'])) {
            $errors[] = "Leads from state '{$input['state']}' are not accepted.";
        }

        return $errors;
    }

    private function isAllowedState(string $state): bool
    {
        static $allowed = null;

        if ($allowed === null) {
            $allowed = [];
            $row = pdoFetchOne("SELECT allow_records_json FROM record_config ORDER BY id DESC LIMIT 1");

            if ($row && !empty($row['allow_records_json'])) {
                $decoded = json_decode((string) $row['allow_records_json'], true);
                if (is_array($decoded)) {
                    $allowed = array_map(
                        static fn($s): string => strtoupper(trim((string) $s)),
                        $decoded
                    );
                }
            }
        }

        return in_array($state, $allowed, true);
    }
}
