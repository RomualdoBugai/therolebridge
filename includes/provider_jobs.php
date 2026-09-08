<?php

/**
 * Build the provider API URL based on DB config + runtime params.
 *
 * @param array<string,mixed> $providerConfig
 */
function buildProviderApiUrl(
    array $providerConfig,
    ?string $recordLeadId,
    string $keyword,
    string $location,
    int $page = 1,
    int $perPage = 20,
    bool $sendPid = true
): string {
    // Start with fixed params from DB (link, format=json, etc.)
    $params = $providerConfig['api_fixed_params'] ?? [];

    // id / secret (if present)
    if (!empty($providerConfig['api_id'])) {
        $params['id'] = $providerConfig['api_id'];
    }
    if (!empty($providerConfig['api_secret'])) {
        // For Talroo this is "pass"
        $params['pass'] = $providerConfig['api_secret'];
    }

    // Keyword / location param names are configurable
    $keywordParam  = $providerConfig['api_param_keyword']  ?: 'q';
    $locationParam = $providerConfig['api_param_location'] ?: 'l';

    $params[$keywordParam]  = $keyword;
    $params[$locationParam] = $location;

    // User IP: only send if valid IPv4
    if (!empty($providerConfig['api_param_ip'])) {
        $ip = resolveClientIp();
        if ($ip !== null) {
            $params[$providerConfig['api_param_ip']] = $ip;
        }
        // If no IPv4 (localhost etc.), don't send ip at all
    }

    // Pagination params (if configured)
    if (!empty($providerConfig['api_param_page'])) {
        $params[$providerConfig['api_param_page']] = $page;
    }
    if (!empty($providerConfig['api_param_per_page'])) {
        $params[$providerConfig['api_param_per_page']] = $perPage;
    }

    // ------------- PID conforme doc -------------
    if (
        $sendPid
        && !empty($recordLeadId)
        && !empty($providerConfig['affiliate_id'])
        && !empty($providerConfig['api_secret'])
    ) {
        $recordLeadIdNorm = (string) $recordLeadId;

        $salt = $providerConfig['affiliate_id'] . ':' . $providerConfig['api_secret'];

        $params['pid'] = hash('sha256', $salt . $recordLeadIdNorm);
    }

    $baseUrl = rtrim((string) $providerConfig['api_base_url'], '?');

    return $baseUrl . '?' . http_build_query($params);
}

/**
 * ============================
 *  ADAPTERS
 * ============================
 */

/**
 * Adapt a raw Jooble job to the unified model.
 */
function joobleFallBackAdaptJob(array $job): array
{
    $title   = $job['title']   ?? 'Job Opportunity';
    $company = $job['company'] ?? null;
    $locRaw  = $job['location'] ?? ''; // Jooble sends "Columbia, SC"
    $snippet = $job['snippet'] ?? ($job['description'] ?? '');
    $url     = $job['link']    ?? '#';
    $salary  = $job['salary']  ?? null;
    $updated = $job['updated'] ?? null;
    $logoUrl = null;

    $city      = null;
    $state     = null;
    $country   = null;
    $locLabel  = null;

    if (is_string($locRaw) && $locRaw !== '') {
        $locLabel = $locRaw;
        $parts = array_map('trim', explode(',', $locRaw));
        if (count($parts) >= 1 && $parts[0] !== '') {
            $city = $parts[0];
        }
        if (count($parts) >= 2 && $parts[1] !== '') {
            $state = $parts[1];
        }
    }

    // Extract ID from link path to avoid int64 precision loss on json_decode.
    // e.g. https://jooble.org/jdp/-3139894766379259210 → "-3139894766379259210"
    $externalId = null;
    if (!empty($job['link'])) {
        $path = (string) parse_url($job['link'], PHP_URL_PATH);
        if (preg_match('/\/(?:jdp|away)\/(-?\d+)/', $path, $m)) {
            $externalId = $m[1];
        }
    }

    return [
        'external_id'    => $externalId,
        'title'          => $title,
        'company'        => $company,
        'location_label' => $locLabel,
        'city'           => $city,
        'state'          => $state,
        'country'        => $country,
        'snippet'        => $snippet,
        'url'            => $url,
        'salary_text'    => $salary,
        'posted_at'      => $updated,
        'logo_url'       => $logoUrl,
        'score'          => null,
        'raw'            => $job,
    ];
}


/**
 * Adapt a raw Talroo (Jobs2Careers) job to the unified model.
 */
function talrooAdaptJob(array $job): array
{
    $title   = $job['title']   ?? 'Job Opportunity';
    $company = $job['company'] ?? null;

    // city is an array: ['Columbia,SC']
    $locRaw = null;
    if (isset($job['city'])) {
        if (is_array($job['city']) && isset($job['city'][0])) {
            $locRaw = $job['city'][0];
        } elseif (is_string($job['city'])) {
            $locRaw = $job['city'];
        }
    }

    $locLabel  = null;
    $city      = null;
    $state     = null;
    $country   = null;

    if (is_string($locRaw) && $locRaw !== '') {
        $locLabel = $locRaw;
        $parts = array_map('trim', explode(',', $locRaw));
        if (count($parts) >= 1 && $parts[0] !== '') {
            $city = $parts[0];
        }
        if (count($parts) >= 2 && $parts[1] !== '') {
            $state = $parts[1];
        }
    }

    // coordinates: ['34.0,-81.03']
    $coordinates = null;
    if (isset($job['coordinates'])) {
        if (is_array($job['coordinates']) && isset($job['coordinates'][0])) {
            $coordinates = $job['coordinates'][0];
        } elseif (is_string($job['coordinates'])) {
            $coordinates = $job['coordinates'];
        }
    }

    $snippet = $job['description'] ?? '';
    $url     = $job['url']         ?? '#';
    $updated = $job['date']        ?? null;
    $logoUrl = $job['logo_url']    ?? null;

    // salary_details[0]['label'] → "$11-$14/hr"
    $salaryText = null;
    if (
        !empty($job['salary_details'])
        && is_array($job['salary_details'])
        && isset($job['salary_details'][0]['label'])
    ) {
        $salaryText = $job['salary_details'][0]['label'];
    }

    $price = isset($job['price']) && is_numeric($job['price']) ? (int) $job['price'] : null;

    return [
        'external_id'    => isset($job['id']) ? (string) $job['id'] : null,
        'title'          => $title,
        'company'        => $company,
        'location_label' => $locLabel,
        'city'           => $city,
        'state'          => $state,
        'country'        => $country,
        'snippet'        => $snippet,
        'url'            => $url,
        'salary_text'    => $salaryText,
        'posted_at'      => $updated,
        'logo_url'       => $logoUrl,
        'score'          => $price,
        'raw'            => $job,
    ];
}

function joobleFallBackFetchRawJobs(
    string $provider,
    string $keyword,
    string $location,
    string $state,
    string $city,
    int $page = 1,
    int $perPage = 20
): array {
    // ============================
    // 1) Tenta pegar config do DB
    // ============================
    $url          = null;
    $basePayload  = [];

    $config = getJobProviderConfig($provider);
    if (!$config || empty($config['api_base_url'])) {
        error_log('Jooble: missing provider config in provider_jobs');
        return ['jobs' => [], 'error' => true];
    }

    // Ex.: https://jooble.org/api/ + API_KEY
    $baseUrl = rtrim((string) $config['api_base_url'], '/');
    $url     = $baseUrl . '/' . $config['api_id'];

    // api_fixed_params vem decodado pelo Provider Config
    if (!empty($config['api_fixed_params']) && is_array($config['api_fixed_params'])) {
        $basePayload = $config['api_fixed_params'];
    }

    // ============================
    // 2) Normalização de location
    // ============================
    $location = trim($location);
    $city     = trim($city);
    $state    = trim($state);

    // Ordem de tentativas (igual você já fazia):
    // 1) location
    // 2) city
    // 3) state
    $locationsToTry = [];

    if ($location !== '') {
        $locationsToTry[] = $location;
    }
    if ($city !== '' && !in_array($city, $locationsToTry, true)) {
        $locationsToTry[] = $city;
    }
    if ($state !== '' && !in_array($state, $locationsToTry, true)) {
        $locationsToTry[] = $state;
    }

    if (empty($locationsToTry)) {
        // Sem localização utilizável → não bate na API
        return [
            'jobs'  => [],
            'error' => false, // não é erro técnico, só dado insuficiente
        ];
    }

    // ============================
    // 3) Loop de chamadas
    // ============================
    $finalJobs  = [];
    $hadSuccess = false;

    foreach ($locationsToTry as $loc) {
        // Monta payload juntando base + campos variáveis
        $payloadArray = $basePayload;

        $payloadArray['keywords'] = $keyword;
        $payloadArray['location'] = $loc;
        $payloadArray['page']     = $page;

        // Se, no futuro, você quiser usar perPage e o Jooble suportar,
        // pode armazenar "resultsPerPage" em api_fixed_params no DB
        // ou setar aqui manualmente:
        //
        // $payloadArray['resultsPerPage'] = $perPage;

        $payload = json_encode($payloadArray);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $server_output = curl_exec($ch);

        if ($server_output === false) {
            error_log('Jooble API error: ' . curl_error($ch) . ' | location=' . $loc . ' | url=' . $url);
            curl_close($ch);
            continue;
        }

        curl_close($ch);

        $data = json_decode($server_output, true);

        if (!is_array($data)) {
            error_log('Jooble API invalid JSON response | location=' . $loc . ' | raw=' . substr($server_output, 0, 300));
            continue;
        }

        $hadSuccess = true;

        $jobs = [];
        if (isset($data['jobs']) && is_array($data['jobs'])) {
            $jobs = $data['jobs'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            // Fallback se a API resolver devolver uma lista "crua"
            $jobs = $data;
        }

        if (!empty($jobs)) {
            $finalJobs = $jobs;
            break;
        }
    }

    return [
        'jobs'  => $finalJobs,
        'error' => !$hadSuccess, // erro=true só se TODAS as chamadas falharem tecnicamente
    ];
}

/**
 * ============================
 *  LOW-LEVEL: TALROO (via provider_jobs)
 * ============================
 */

function buildTalrooLocationAttempts(string $location, string $city, string $state): array
{
    $location = trim((string) $location);
    $city     = trim((string) $city);
    $state    = strtoupper(trim((string) $state));

    $attempts = [];

    // 1) Original location first: ZIP or whatever came from lead
    if ($location !== '') {
        $attempts[] = $location;
    }

    // 2) City + State
    if ($city !== '' && $state !== '') {
        $attempts[] = $city . ', ' . $state;
    }

    // 3) Full state name
    $stateName = getUsStateName($state);
    if ($stateName !== '') {
        $attempts[] = $stateName;
    }

    return array_values(array_unique(array_filter($attempts, static function ($value) {
        return trim((string) $value) !== '';
    })));
}

function talrooFetchRawJobs(
    string $provider,
    string $email,
    string $keyword,
    string $location,
    string $state,
    string $city,
    int $page = 1,
    int $perPage = 20
): array {
    // 1) Get provider config from DB
    $config = getJobProviderConfig($provider);

    if (!$config || empty($config['api_base_url'])) {
        error_log('Talroo: missing provider config in provider_jobs');
        return ['jobs' => [], 'error' => true];
    }

    if ($perPage != 9 && $provider === 'talroo') {
        $perPage = 10; // Talroo tem que ser 10 para teste de qualidade
    }

    $keyword  = trim((string) $keyword);
    $location = trim((string) $location);
    $city     = trim((string) $city);
    $state    = strtoupper(trim((string) $state));

    $locationAttempts = buildTalrooLocationAttempts($location, $city, $state);

    if (empty($locationAttempts)) {
        $locationAttempts = [''];
    }

    // pega/gera o recordLeadId
    $recordLeadId = getRecordLeadIdByEmail($email ?: null);

    $lastError = false;
    $lastResponseData = null;

    $attempts = [];

    /*
    * Correct order:
    *
    * 1. original location + PID
    * 2. city, state + PID
    * 3. full state + PID
    * 4. only if all PID attempts fail, try original location/ZIP without PID
    */
    foreach ($locationAttempts as $attemptLocation) {
        $attempts[] = [
            'location' => $attemptLocation,
            'send_pid' => true,
        ];
    }

    // WITHOUT PID: only original ZIP/location, not city/state/full state
    if ($location !== '') {
        $attempts[] = [
            'location' => $location,
            'send_pid' => false,
        ];
    }

    foreach ($attempts as $attemptIndex => $attempt) {
        $attemptLocation = $attempt['location'];
        $sendPid = (bool) $attempt['send_pid'];

        if (!$recordLeadId) {
            $sendPid = false;
        }

        $url = buildProviderApiUrl(
            $config,
            $recordLeadId,
            $keyword,
            $attemptLocation,
            $page,
            $perPage,
            $sendPid
        );

        error_log(
            'Talroo URL: ' . $url .
                ' | attempt=' . ($attemptIndex + 1) .
                ' | attempt_location=' . $attemptLocation .
                ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid')
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'TheRoleBridge/1.0',
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            error_log(
                'Talroo API CURL error: ' . curl_error($ch) .
                    ' | provider=' . $provider .
                    ' | attempt_location=' . $attemptLocation .
                    ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid') .
                    ' | URL=' . $url
            );

            curl_close($ch);
            $lastError = true;
            continue;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log(
                'Talroo API HTTP ' . $httpCode .
                    ' | provider=' . $provider .
                    ' | attempt_location=' . $attemptLocation .
                    ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid') .
                    ' | URL=' . $url .
                    ' | body=' . substr($response, 0, 300)
            );

            $lastError = true;
            continue;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log(
                'Talroo JSON error: ' . json_last_error_msg() .
                    ' | provider=' . $provider .
                    ' | attempt_location=' . $attemptLocation .
                    ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid') .
                    ' | raw=' . substr($response, 0, 300)
            );

            $lastError = true;
            continue;
        }

        $lastResponseData = $data;

        if (isset($data['status']) && $data['status'] === 'error') {
            $msg = $data['message'] ?? 'Unknown Talroo error';

            error_log(
                'Talroo APP error: ' . $msg .
                    ' | provider=' . $provider .
                    ' | attempt_location=' . $attemptLocation .
                    ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid') .
                    ' | URL=' . $url
            );

            $lastError = true;
            continue;
        }

        $jobs  = $data['jobs'] ?? [];
        $total = isset($data['total']) ? (int) $data['total'] : 0;
        $start = isset($data['start']) ? (int) $data['start'] : 0;
        $count = isset($data['count']) ? (int) $data['count'] : count($jobs);

        if (!empty($jobs) && $total > 0) {
            if (!$sendPid) {
                error_log(
                    'Talroo WITHOUT_PID fallback SUCCESS' .
                        ' | provider=' . $provider .
                        ' | keyword=' . $keyword .
                        ' | attempt_location=' . $attemptLocation .
                        ' | total=' . $total .
                        ' | count=' . $count
                );
            } else {
                error_log(
                    'Talroo WITH_PID success' .
                        ' | provider=' . $provider .
                        ' | keyword=' . $keyword .
                        ' | attempt_location=' . $attemptLocation .
                        ' | total=' . $total .
                        ' | count=' . $count
                );
            }

            return [
                'jobs'      => $jobs,
                'total'     => $total,
                'start'     => $start,
                'count'     => $count,
                'error'     => false,
                'pid_mode'  => $sendPid ? 'with_pid' : 'without_pid',
                'location'  => $attemptLocation,
            ];
        }

        error_log(
            'Talroo empty result' .
                ' | provider=' . $provider .
                ' | keyword=' . $keyword .
                ' | attempt_location=' . $attemptLocation .
                ' | pid_mode=' . ($sendPid ? 'with_pid' : 'without_pid') .
                ' | resolved_location=' . ($data['resolved_location'] ?? '') .
                ' | total=' . $total .
                ' | count=' . $count
        );
    }

    return [
        'jobs'      => [],
        'total'     => isset($lastResponseData['total']) ? (int) $lastResponseData['total'] : 0,
        'start'     => isset($lastResponseData['start']) ? (int) $lastResponseData['start'] : 0,
        'count'     => isset($lastResponseData['count']) ? (int) $lastResponseData['count'] : 0,
        'error'     => $lastError,
        'pid_mode'  => null,
        'location'  => null,
    ];
}

/**
 * ============================
 *  UNIFIED FACADE
 * ============================
 * -> Single point your job-grid screen needs to call.
 */

function fetchUnifiedJobs(
    string $provider,
    string $email,
    string $keyword,
    string $location,
    string $state,
    string $city,
    int $page = 1,
    int $perPage = 20
): array {
    $provider = strtolower($provider);
    $rawJobs  = [];
    $error    = false;
    $adapter  = null;
    $meta     = [];

    switch ($provider) {
        case 'talroo':
        case 'talroo2':
            $resp    = talrooFetchRawJobs($provider, $email, $keyword, $location, $state, $city, $page, $perPage);
            $rawJobs = $resp['jobs'] ?? [];
            $error   = $resp['error'] ?? false;
            $adapter = 'talrooAdaptJob';
            $meta    = [
                'total' => $resp['total'] ?? null,
                'start' => $resp['start'] ?? null,
                'count' => $resp['count'] ?? null,
            ];
            break;

        case 'jooble_fallback':
        default:
            $resp    = joobleFallBackFetchRawJobs($provider, $keyword, $location, $state, $city, $page, $perPage);
            $rawJobs = $resp['jobs'] ?? [];
            $error   = $resp['error'] ?? false;
            $adapter = 'joobleFallBackAdaptJob';
            break;
    }

    $jobs = [];
    if (!$error && $adapter && function_exists($adapter)) {
        foreach ($rawJobs as $raw) {
            $jobs[] = $adapter($raw);
        }
    }

    if (in_array($provider, ['talroo', 'talroo2'], true) && count($jobs) > 1) {
        usort($jobs, static function (array $a, array $b): int {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
    }

    return [
        'jobs'  => $jobs,
        'error' => $error,
        'meta'  => $meta,
    ];
}
