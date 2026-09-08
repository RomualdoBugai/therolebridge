<?php

function resolveClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    $normalizeIp = function (?string $ip): string {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return '';
        }

        // Convert IPv4-mapped IPv6 to IPv4.
        // Example: ::ffff:107.115.239.126
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            return $m[1];
        }

        return $ip;
    };

    $isValidIp = function (?string $ip) use ($normalizeIp): bool {
        $ip = $normalizeIp($ip);

        if ($ip === '') {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    };

    $isPublicIp = function (?string $ip) use ($normalizeIp): bool {
        $ip = $normalizeIp($ip);

        if ($ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    };

    $ipInCidr = function (string $ip, string $cidr): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);

        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong   = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    };

    $ipInCidrV6 = function (string $ip, string $cidr): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);

        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $mask = (int) $mask;
        $bytes = intdiv($mask, 8);
        $bits  = $mask % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $maskByte = chr((0xFF << (8 - $bits)) & 0xFF);

        return ($ipBin[$bytes] & $maskByte) === ($subnetBin[$bytes] & $maskByte);
    };

    $ipInAnyCidr = function (string $ip, array $cidrs) use ($ipInCidr, $ipInCidrV6): bool {
        foreach ($cidrs as $cidr) {
            if (strpos($cidr, '/') === false) {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ipInCidr($ip, $cidr)) {
                return true;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $ipInCidrV6($ip, $cidr)) {
                return true;
            }
        }

        return false;
    };

    $remoteAddr = $normalizeIp($remoteAddr);

    /*
     * Cloudflare public IP ranges.
     * These allow us to trust CF-Connecting-IP only when REMOTE_ADDR
     * is really Cloudflare.
     */
    $cloudflareCidrs = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',

        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /*
     * Add only proxies/load balancers that YOU control.
     * If your PHP is behind local Nginx/FPM only, localhost is enough.
     * If you have another reverse proxy, add its private IP here.
     */
    $trustedProxyIps = [
        '127.0.0.1',
        '::1',

        // Examples only:
        // '10.0.0.5',
        // '172.31.0.10',
    ];

    $isCloudflare = $isValidIp($remoteAddr) && $ipInAnyCidr($remoteAddr, $cloudflareCidrs);
    $isTrustedProxy = in_array($remoteAddr, $trustedProxyIps, true);

    /*
     * Best case:
     * Request came through Cloudflare. Trust only CF-Connecting-IP.
     */
    if ($isCloudflare) {
        $cfIp = $normalizeIp($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

        if ($isPublicIp($cfIp)) {
            return $cfIp;
        }

        error_log(
            'IP WARNING | Cloudflare REMOTE_ADDR detected but invalid CF_CONNECTING_IP | remote_addr=' . $remoteAddr .
            ' | cf=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') .
            ' | x_real=' . ($_SERVER['HTTP_X_REAL_IP'] ?? '') .
            ' | xff=' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
        );

        return $remoteAddr;
    }

    /*
     * Trusted internal proxy.
     * Example: Nginx/load balancer that you control.
     */
    if ($isTrustedProxy) {
        $xRealIp = $normalizeIp($_SERVER['HTTP_X_REAL_IP'] ?? '');

        if ($isPublicIp($xRealIp)) {
            return $xRealIp;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedIps = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);

            foreach ($forwardedIps as $forwardedIp) {
                $forwardedIp = $normalizeIp($forwardedIp);

                if ($isPublicIp($forwardedIp)) {
                    return $forwardedIp;
                }
            }
        }

        error_log(
            'IP WARNING | Trusted proxy detected but no valid forwarded public IP | remote_addr=' . $remoteAddr .
            ' | x_real=' . ($_SERVER['HTTP_X_REAL_IP'] ?? '') .
            ' | xff=' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
        );

        return $remoteAddr;
    }

    /*
     * Direct request.
     * Do NOT trust spoofable headers.
     */
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP'])) {
        error_log(
            'IP SPOOF CHECK | Direct request with proxy headers ignored | resolved=' . $remoteAddr .
            ' | remote_addr=' . $remoteAddr .
            ' | cf=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') .
            ' | x_real=' . ($_SERVER['HTTP_X_REAL_IP'] ?? '') .
            ' | xff=' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') .
            ' | ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    if ($isValidIp($remoteAddr)) {
        return $remoteAddr;
    }

    error_log(
        'IP ERROR | Unable to resolve valid client IP | remote_addr=' . ($_SERVER['REMOTE_ADDR'] ?? '') .
        ' | cf=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') .
        ' | x_real=' . ($_SERVER['HTTP_X_REAL_IP'] ?? '') .
        ' | xff=' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
    );

    return '0.0.0.0';
}

function getJobProvidersCacheDir(): string
{
    return __DIR__ . '/../cache';
}

function getJobProvidersCacheFile(): string
{
    return getJobProvidersCacheDir() . '/provider_jobs_active.json';
}

function getJobProvidersCacheLockFile(): string
{
    return getJobProvidersCacheDir() . '/provider_jobs_active.lock';
}

function getJobProvidersCacheTtl(): int
{
    return 86400; // 24h
}

function rebuildJobProvidersCache(int $maxRetries = 1): array
{
    $cacheDir  = getJobProvidersCacheDir();
    $cacheFile = getJobProvidersCacheFile();
    $lockFile  = getJobProvidersCacheLockFile();
    $cacheTtl  = getJobProvidersCacheTtl();

    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Failed to create cache directory: ' . $cacheDir);
    }

    $lockHandle = fopen($lockFile, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Failed to open cache lock file: ' . $lockFile);
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Failed to lock provider cache rebuild.');
        }

        // Pode ter sido recriado por outro processo enquanto este esperava o lock
        if (is_file($cacheFile)) {
            $existingJson = file_get_contents($cacheFile);
            if ($existingJson !== false && $existingJson !== '') {
                $existing = json_decode($existingJson, true);

                if (is_array($existing) && isJobProvidersCacheValid($existing)) {
                    return $existing;
                }
            }
        }

        $sql = "
            SELECT *
            FROM provider_jobs
            WHERE status = 'active'
            ORDER BY id ASC
        ";

        $rows = pdoFetchAll($sql, [], $maxRetries);

        $indexed = [];

        foreach ($rows as $row) {
            foreach (['api_fixed_params', 'affiliate_fixed_params'] as $jsonField) {
                if (!empty($row[$jsonField])) {
                    $decoded = json_decode((string) $row[$jsonField], true);
                    $row[$jsonField] = is_array($decoded) ? $decoded : [];
                } else {
                    $row[$jsonField] = [];
                }
            }

            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $indexed[$slug] = $row;
        }

        $payload = [
            'generated_at' => date('c'),
            'expires_at'   => date('c', time() + $cacheTtl),
            'count'        => count($indexed),
            'providers'    => $indexed,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode provider_jobs cache JSON.');
        }

        $tmpFile = $cacheFile . '.tmp';

        if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary cache file: ' . $tmpFile);
        }

        if (!rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            throw new RuntimeException('Failed to move temporary cache file into place.');
        }

        return $payload;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function loadJobProvidersCache(): array
{
    $cacheFile = getJobProvidersCacheFile();

    if (!is_file($cacheFile)) {
        throw new RuntimeException('Provider cache file not found: ' . $cacheFile);
    }

    $json = file_get_contents($cacheFile);
    if ($json === false || $json === '') {
        throw new RuntimeException('Failed to read provider cache file.');
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['providers']) || !is_array($data['providers'])) {
        throw new RuntimeException('Provider cache file is invalid.');
    }

    return $data;
}

function isJobProvidersCacheValid(array $cacheData): bool
{
    if (empty($cacheData['expires_at']) || !is_string($cacheData['expires_at'])) {
        return false;
    }

    $expiresAt = strtotime($cacheData['expires_at']);
    if ($expiresAt === false) {
        return false;
    }

    return $expiresAt >= time();
}

function selectProviderByTrafficSplit(string $defaultProvider = 'talroo', int $maxRetries = 1): string
{
    // Manual override by URL:
    // example: ?provider=jooble
    if (isset($_GET['provider']) && trim((string) $_GET['provider']) !== '') {
        $provider = strtolower(trim((string) $_GET['provider']));
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider);

        return $provider ?: $defaultProvider;
    }

    $sql = "
        SELECT 
            ts.provider_job_id,
            ts.weight,
            pj.slug
        FROM provider_jobs_traffic_split ts
        INNER JOIN provider_jobs pj
            ON pj.id = ts.provider_job_id
        WHERE ts.weight > 0
        AND pj.status = 'active'
        ORDER BY ts.id ASC
    ";

    $rows = pdoFetchAll($sql, [], $maxRetries);

    if (empty($rows)) {
        return $defaultProvider;
    }

    $totalWeight = 0;

    foreach ($rows as $row) {
        $totalWeight += (int) ($row['weight'] ?? 0);
    }

    if ($totalWeight <= 0) {
        return $defaultProvider;
    }

    try {
        $random = random_int(1, $totalWeight);
    } catch (Exception $e) {
        $random = mt_rand(1, $totalWeight);
    }

    $currentWeight = 0;

    foreach ($rows as $row) {
        $currentWeight += (int) ($row['weight'] ?? 0);

        if ($random <= $currentWeight) {
            $provider = (string) ($row['slug'] ?? '');

            $provider = strtolower(trim($provider));
            $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider);

            return $provider ?: $defaultProvider;
        }
    }

    return $defaultProvider;
}

/**
 * Get one active provider config by slug using file cache.
 *
 * Flow:
 * - If in-memory cache exists, use it
 * - Otherwise try file cache
 * - If file cache is missing/invalid/expired, rebuild all providers cache
 * - If rebuild fails, fallback to old file cache only if it exists and is readable
 */
function getJobProviderConfig(string $slug, int $maxRetries = 1): ?array
{
    static $memoryCache = null;

    $cacheKey = strtolower(trim($slug));
    if ($cacheKey === '') {
        return null;
    }

    if ($memoryCache === null) {
        $cacheData = null;
        $hadReadableOldCache = false;

        try {
            $cacheData = loadJobProvidersCache();
            $hadReadableOldCache = true;
        } catch (Throwable $e) {
            $cacheData = null;
        }

        if (!is_array($cacheData) || !isJobProvidersCacheValid($cacheData)) {
            try {
                $cacheData = rebuildJobProvidersCache($maxRetries);
            } catch (Throwable $e) {
                if (!$hadReadableOldCache) {
                    throw $e;
                }

                // fallback para cache velho, mesmo expirado, se era legível
                $cacheData = loadJobProvidersCache();
            }
        }

        $memoryCache = $cacheData;
    }

    return $memoryCache['providers'][$cacheKey] ?? null;
}

/**
 * Returns active job provider slugs from database.
 *
 * Expected table:
 * provider_jobs
 *
 * Expected columns:
 * - slug
 * - status
 * - priority OR id
 */
function getActiveJobProviderSlugs(?string $preferredProvider = null, int $maxRetries = 1): array
{
    $providers = [];

    $sql = "
        SELECT 
            pj.id,
            pj.slug,
            ts.weight
        FROM provider_jobs_traffic_split ts
        INNER JOIN provider_jobs pj
            ON pj.id = ts.provider_job_id
        WHERE pj.status = 'active' AND weight > 0
        ORDER BY ts.weight DESC, ts.id ASC
    ";

    try {
        $rows = pdoFetchAll($sql, [], $maxRetries);
    } catch (Throwable $e) {
        error_log('Traffic split lookup failed in getActiveJobProviderSlugs: ' . $e->getMessage());
        return [];
    }

    /*
     * Preferred provider goes first,
     * but only if it exists in active traffic split.
     */
    if ($preferredProvider !== null && trim($preferredProvider) !== '') {
        $preferredProvider = strtolower(trim($preferredProvider));
        $preferredProvider = preg_replace('/[^a-z0-9_\-]/i', '', $preferredProvider);

        if ($preferredProvider !== '') {
            foreach ($rows as $row) {
                $slug = strtolower(trim((string) ($row['slug'] ?? '')));
                $slug = preg_replace('/[^a-z0-9_\-]/i', '', $slug);

                if ($slug === $preferredProvider) {
                    $providers[] = $preferredProvider;
                    break;
                }
            }
        }
    }

    /*
     * Add providers ordered by weight DESC.
     * Weight 0 still enters, but comes last.
     */
    foreach ($rows as $row) {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', $slug);

        if ($slug === '') {
            continue;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            continue;
        }

        $providers[] = $slug;
    }

    return array_values(array_unique($providers));
}

/**
 * Retorna o ID de perfil de usuário (lead) a partir do email.
 * - Se já existir em record_leads, retorna o id (int).
 * - Se não existir ou se o email for inválido, retorna null.
 *
 * @param string|null $email
 * @param int         $maxRetries
 * @return int|null
 */
function getRecordLeadIdByEmail(?string $email, int $maxRetries = 1): ?int
{
    if (empty($email)) {
        return null;
    }

    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return null;
    }

    $sql = "
        SELECT id
        FROM record_leads
        WHERE email = :email
        LIMIT 1
    ";

    // Usa o helper com auto-reconnect
    $row = pdoFetchOne($sql, [':email' => $normalizedEmail], $maxRetries);

    if ($row === null || !isset($row['id'])) {
        return null;
    }

    return (int) $row['id'];
}

/**
 * Interpreta uma string de localização e retorna [city, state, zip].
 *
 * Regras:
 * - "29063"        -> zip = 29063
 * - "29063-1234"   -> zip = 29063
 * - "Irmo, SC"     -> city = Irmo, state = SC
 * - "Irmo SC"      -> city = Irmo, state = SC  (fallback sem vírgula)
 * - "SC"           -> state = SC
 * - "Orlando"      -> city = Orlando
 */
function parseLocationString(?string $location): array
{
    $location = trim((string) $location);

    if ($location === '') {
        return ['', '', '']; // [city, state, zip]
    }

    // ZIP 5 dígitos
    if (preg_match('/^\d{5}$/', $location)) {
        return ['', '', $location];
    }

    // ZIP+4 (12345-6789 ou 12345 6789) -> guarda só os 5 primeiros
    if (preg_match('/^(\d{5})[-\s]\d{4}$/', $location, $m)) {
        return ['', '', $m[1]];
    }

    // "Cidade, ST"
    if (preg_match('/^(.+),\s*([A-Za-z]{2})$/', $location, $m)) {
        $city  = trim($m[1]);
        $state = strtoupper($m[2]);
        return [$city, $state, ''];
    }

    // "Cidade ST" (sem vírgula, último token é estado)
    if (preg_match('/^(.+)\s+([A-Za-z]{2})$/', $location, $m)) {
        $city  = trim($m[1]);
        $state = strtoupper($m[2]);
        return [$city, $state, ''];
    }

    // Só estado (2 letras)
    if (preg_match('/^[A-Za-z]{2}$/', $location)) {
        return ['', strtoupper($location), ''];
    }

    // Fallback: assume que é nome de cidade
    return [$location, '', ''];
}

/**
 * Retorna [city, state, zip, countryCode] a partir do IP.
 * Se não achar, devolve ['', '', '', ''].
 */
function geoLookupCityStateZipByIp(string $ip): array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['', '', '', ''];
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,region,zip,countryCode';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return ['', '', '', ''];
    }

    curl_close($ch);

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return ['', '', '', ''];
    }

    $city        = $data['city']        ?? '';
    $state       = $data['region']      ?? ''; // ex: 'SC', 'TX'
    $zip         = $data['zip']         ?? '';
    $countryCode = $data['countryCode'] ?? ''; // ex: 'US'

    return [$city, $state, $zip, $countryCode];
}

/**
 * Enriches a lead's location in record_leads using geo data resolved from IP.
 * Only fills empty fields — never overwrites existing data.
 * Sets geo_enriched_at so the Brevo sync cron can pick it up.
 */
function geoEnrichLeadLocation(string $email, string $city, string $state, string $zip): void
{
    $email = strtolower(trim($email));
    if ($email === '' || ($city === '' && $state === '' && $zip === '')) {
        return;
    }

    try {
        pdoExecute("
            UPDATE record_leads
            SET
                city       = CASE WHEN (city  IS NULL OR city  = '') AND :city_chk  != '' THEN :city_val  ELSE city  END,
                state      = CASE WHEN (state IS NULL OR state = '') AND :state_chk != '' THEN :state_val ELSE state END,
                zip        = CASE WHEN (zip   IS NULL OR zip   = '') AND :zip_chk   != '' THEN :zip_val   ELSE zip   END,
                updated_at = NOW()
            WHERE email = :email
              AND (city IS NULL OR city = '')
              AND (state IS NULL OR state = '')
              AND (zip IS NULL OR zip = '')
        ", [
            ':city_chk'  => $city,
            ':city_val'  => $city,
            ':state_chk' => $state,
            ':state_val' => $state,
            ':zip_chk'   => $zip,
            ':zip_val'   => $zip,
            ':email'     => $email,
        ]);
    } catch (Throwable $e) {
        error_log('geoEnrichLeadLocation error: ' . $e->getMessage());
    }
}

/**
 * ============================
 *  GENERAL HELPERS
 * ============================
 */

function cleanSnippet(string $html, int $limit = 160): string
{
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text    = strip_tags($decoded);
    $text    = preg_replace('/\s+/u', ' ', $text);
    $text    = trim($text, " \t\n\r\0\x0B\xC2\xA0.…");

    if (mb_strlen($text) > $limit) {
        $text = mb_substr($text, 0, $limit) . '...';
    }

    return $text;
}

function formatJobDate(?string $isoDate): ?string
{
    if (empty($isoDate)) {
        return null;
    }

    try {
        $dt = new DateTime($isoDate);
        return $dt->format('m-d-Y'); // 01-14-2026
    } catch (Throwable $e) {
        error_log('Invalid job date: ' . $isoDate . ' | ' . $e->getMessage());
        return null;
    }
}

function insertJobClickSuspicious(
    $provider,
    $utmSource,
    $utmMedium,
    $utmCampaign,
    $utmId,
    $email,
    $keyword,
    $city,
    $state,
    $zip,
    $userAgent,
    $ipAddress
) {
    try {
        $sql = "
            INSERT INTO job_clicks_suspicious (
                provider,
                utm_source,
                utm_medium,
                utm_campaign,
                utm_id,
                email,
                keyword,
                city,
                state,
                zip,
                user_agent,
                ip_address,
                created_at
            ) VALUES (
                :provider,
                :utm_source,
                :utm_medium,
                :utm_campaign,
                :utm_id,
                :email,
                :keyword,
                :city,
                :state,
                :zip,
                :user_agent,
                :ip_address,
                :created_at
            )
        ";

        $params = [
            ':provider'     => $provider ?: '',
            ':utm_source'   => $utmSource ?: '',
            ':utm_medium'   => $utmMedium ?: '',
            ':utm_campaign' => $utmCampaign ?: '',
            ':utm_id'       => $utmId ?: '',
            ':email'        => $email ?: '',
            ':keyword'      => $keyword ?: '',
            ':city'         => $city ?: '',
            ':state'        => $state ?: '',
            ':zip'          => $zip ?: '',
            ':user_agent'   => $userAgent ?: '',
            ':ip_address'   => $ipAddress ?: '',
            ':created_at'   => date('Y-m-d H:i:s'),
        ];

        pdoExecute($sql, $params, 1);

        return true;
    } catch (Throwable $e) {
        error_log('Error inserting job_clicks_suspicious: ' . $e->getMessage());
        return false;
    }
}

function updateUrlQueryParam(string $url, string $key, string $value): string
{
    $parts = parse_url($url);

    $path = $parts['path'] ?? '';
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query[$key] = $value;

    $newUrl = $path;

    if (!empty($query)) {
        $newUrl .= '?' . http_build_query($query);
    }

    return $newUrl;
}

function updateQueryStringParam(string $queryString, string $key, $value): string
{
    $queryString = trim($queryString);

    // Remove leading ? or &
    $queryString = ltrim($queryString, '?&');

    $params = [];

    if ($queryString !== '') {
        parse_str($queryString, $params);
    }

    // Avoid duplicate param
    unset($params[$key]);

    // Add current value
    if ($value !== null && $value !== '') {
        $params[$key] = $value;
    }

    $newQuery = http_build_query($params);

    return $newQuery !== '' ? '?' . $newQuery : '';
}

/**
 * Appends the provider-specific sub-ID parameter to an outbound job URL.
 *
 * talroo / talroo2 → t1=<jobClickOutId>   (unique per-click DB ID)
 * jooble           → source_id=<utmSource> (aggregated traffic source)
 * others           → URL returned unchanged
 */
function appendProviderSubId(string $provider, string $targetUrl, int $jobClickOutId, string $utmSource = ''): string
{
    if ($targetUrl === '') {
        return $targetUrl;
    }

    $provider = strtolower(trim($provider));

    if (in_array($provider, ['talroo', 'talroo2'], true)) {
        if ($jobClickOutId <= 0) {
            return $targetUrl;
        }
        $param = 't1';
        $value = (string) $jobClickOutId;
    } elseif (str_starts_with($provider, 'jooble')) {
        if ($utmSource === '') {
            return $targetUrl;
        }
        $param = 'source_id';
        $value = $utmSource;
    } else {
        return $targetUrl;
    }

    $separator = (strpos($targetUrl, '?') !== false) ? '&' : '?';

    return $targetUrl . $separator . $param . '=' . urlencode($value);
}

function hasRecentClickForProvider(
    string $provider,
    string $email,
    string $keyword,
    string $ipAddress,
    int $minutes
): bool {
    $provider = strtolower(trim($provider));

    if ($provider === '' || $email === '' || $keyword === '' || $ipAddress === '') {
        return false;
    }

    $duplicateCutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));

    try {
        return pdoRunWithReconnect(function (PDO $pdo) use (
            $provider,
            $email,
            $keyword,
            $ipAddress,
            $duplicateCutoff
        ): bool {
            $sql = "
                SELECT id
                FROM job_clicks_out
                WHERE provider = :provider
                  AND email = :email
                  AND keyword = :keyword
                  AND ip_address = :ip_address
                  AND created_at >= :duplicate_cutoff
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':provider'         => $provider,
                ':email'            => $email,
                ':keyword'          => $keyword,
                ':ip_address'       => $ipAddress,
                ':duplicate_cutoff' => $duplicateCutoff,
            ]);

            return (bool) $stmt->fetchColumn();
        }, 1);
    } catch (Throwable $e) {
        error_log('Error checking recent click for provider: ' . $e->getMessage());
        return false;
    }
}

function normalizeProviderSlugForRotation(string $provider): string
{
    $provider = strtolower(trim($provider));
    $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider);

    return $provider !== null ? $provider : '';
}

function getDuplicateWindowMinutesForProviderRotation(string $provider): int
{
    $provider = normalizeProviderSlugForRotation($provider);

    if (str_starts_with($provider, 'jooble')) {
        return 1440; // 24 hours
    }

    return 720; // Talroo/Talroo2/others
}

function getRecentClickCountForProviderRotation(
    string $provider,
    string $email,
    string $keyword,
    string $ipAddress
): int {
    $provider = normalizeProviderSlugForRotation($provider);
    $email = strtolower(trim($email));
    $keyword = strtolower(trim($keyword));
    $ipAddress = trim($ipAddress);

    if ($provider === '') {
        return 0;
    }

    if ($email === '' && $ipAddress === '') {
        return 0;
    }

    $windowMinutes = getDuplicateWindowMinutesForProviderRotation($provider);
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    try {
        return pdoRunWithReconnect(function (PDO $pdo) use (
            $provider,
            $email,
            $keyword,
            $ipAddress,
            $cutoff
        ): int {
            $sql = "
                SELECT COUNT(*)
                FROM job_clicks_out
                WHERE LOWER(provider) = :provider
                  AND created_at >= :cutoff
                  AND (
                        (:email_check <> '' AND LOWER(email) = :email_value)
                        OR
                        (:ip_check <> '' AND ip_address = :ip_value)
                      )
                  AND (
                        :keyword_check = ''
                        OR LOWER(keyword) = :keyword_value
                      )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':provider'      => $provider,
                ':cutoff'        => $cutoff,

                ':email_check'   => $email,
                ':email_value'   => $email,

                ':ip_check'      => $ipAddress,
                ':ip_value'      => $ipAddress,

                ':keyword_check' => $keyword,
                ':keyword_value' => $keyword,
            ]);

            return (int) $stmt->fetchColumn();
        }, 1);
    } catch (Throwable $e) {
        error_log('Error counting recent provider clicks for rotation: ' . $e->getMessage());
        return 0;
    }
}

function getRecentClickCountsForProviderRotation(
    array $providers,
    string $email,
    string $keyword,
    string $ipAddress
): array {
    $providers = array_values(array_unique(array_filter(array_map(function ($provider) {
        return normalizeProviderSlugForRotation((string) $provider);
    }, $providers))));

    $counts = [];

    foreach ($providers as $provider) {
        $counts[$provider] = getRecentClickCountForProviderRotation(
            $provider,
            $email,
            $keyword,
            $ipAddress
        );
    }

    return $counts;
}

function getServedProviderJobIdsForRotation(
    string $provider,
    string $email,
    string $keyword,
    string $ipAddress
): array {
    $provider  = normalizeProviderSlugForRotation($provider);
    $email     = strtolower(trim($email));
    $keyword   = strtolower(trim($keyword));
    $ipAddress = trim($ipAddress);

    if ($provider === '' || ($email === '' && $ipAddress === '')) {
        return [];
    }

    $windowMinutes = getDuplicateWindowMinutesForProviderRotation($provider);
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    try {
        return pdoRunWithReconnect(function (PDO $pdo) use (
            $provider,
            $email,
            $keyword,
            $ipAddress,
            $cutoff
        ): array {
            $sql = "
                SELECT provider_job_id
                FROM job_clicks_out
                WHERE LOWER(provider) = :provider
                  AND provider_job_id IS NOT NULL
                  AND created_at >= :cutoff
                  AND (
                        (:email_check <> '' AND LOWER(email) = :email_value)
                        OR
                        (:ip_check <> '' AND ip_address = :ip_value)
                      )
                  AND (
                        :keyword_check = ''
                        OR LOWER(keyword) = :keyword_value
                      )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':provider'      => $provider,
                ':cutoff'        => $cutoff,
                ':email_check'   => $email,
                ':email_value'   => $email,
                ':ip_check'      => $ipAddress,
                ':ip_value'      => $ipAddress,
                ':keyword_check' => $keyword,
                ':keyword_value' => $keyword,
            ]);

            return array_values(array_filter(
                array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'provider_job_id')
            ));
        }, 1);
    } catch (Throwable $e) {
        error_log('Error fetching served provider job IDs for rotation: ' . $e->getMessage());
        return [];
    }
}

function getValidProviderJobUrlByIndexForRotation(
    string $provider,
    string $email,
    string $keyword,
    string $location,
    string $state,
    string $city,
    int $jobIndex,
    array $excludeJobIds = []
): array {
    $provider = normalizeProviderSlugForRotation($provider);
    $jobIndex = max(0, $jobIndex);

    if ($provider === '') {
        return ['url' => '', 'external_id' => null, 'score' => null];
    }

    $excludeCount = count($excludeJobIds);
    $limit = max(2, $jobIndex + 2 + $excludeCount);

    $response = fetchUnifiedJobs(
        $provider,
        $email,
        $keyword,
        $location,
        $state,
        $city,
        0,
        $limit
    );

    $jobs  = $response['jobs'] ?? [];
    $error = $response['error'] ?? false;

    if ($error || empty($jobs)) {
        return ['url' => '', 'external_id' => null, 'score' => null];
    }

    $validJobs = [];

    foreach ($jobs as $candidateJob) {
        $candidateUrl = trim((string) ($candidateJob['url'] ?? ''));

        if ($candidateUrl !== '' && filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
            $validJobs[] = $candidateJob;
        }
    }

    if (empty($validJobs)) {
        return ['url' => '', 'external_id' => null];
    }

    // Prefer the first job whose external_id has not been served yet.
    if (!empty($excludeJobIds)) {
        foreach ($validJobs as $job) {
            $extId = isset($job['external_id']) ? (string) $job['external_id'] : null;
            if ($extId !== null && !in_array($extId, $excludeJobIds, true)) {
                return [
                    'url'         => (string) ($job['url'] ?? ''),
                    'external_id' => $extId,
                    'score'       => isset($job['score']) && is_numeric($job['score']) ? (int) $job['score'] : null,
                ];
            }
        }
    }

    // Fall back to index-based selection (covers jobs without external_id or all excluded).
    $selected = $validJobs[min($jobIndex, count($validJobs) - 1)];

    return [
        'url'         => (string) ($selected['url'] ?? ''),
        'external_id' => isset($selected['external_id']) ? (string) $selected['external_id'] : null,
        'score'       => isset($selected['score']) && is_numeric($selected['score']) ? (int) $selected['score'] : null,
    ];
}

function resolveProviderClickRotationTarget(
    string $currentProvider,
    string $email,
    string $keyword,
    string $ipAddress,
    string $location,
    string $state,
    string $city,
    string $originalTargetUrl
): array {
    $currentProvider = normalizeProviderSlugForRotation($currentProvider);
    $targetUrl = $originalTargetUrl;

    if ($currentProvider === '') {
        return [
            'provider'          => $currentProvider,
            'target_url'        => $targetUrl,
            'provider_job_id'   => null,
            'provider_job_price' => null,
            'recent_clicks'     => 0,
            'rotation_applied'  => false,
            'reason'            => 'empty_provider',
        ];
    }

    $providersToCheck = getActiveJobProviderSlugs($currentProvider);

    $clickCounts = getRecentClickCountsForProviderRotation(
        $providersToCheck,
        $email,
        $keyword,
        $ipAddress
    );

    $currentProviderClicks = $clickCounts[$currentProvider] ?? 0;

    if ($currentProviderClicks <= 0) {
        return [
            'provider'           => $currentProvider,
            'target_url'         => $targetUrl,
            'provider_job_id'    => null,
            'provider_job_price' => null,
            'recent_clicks'      => $currentProviderClicks,
            'rotation_applied'   => false,
            'reason'             => 'no_recent_click_for_current_provider',
        ];
    }

    $providersWithLessClicks = [];

    foreach ($clickCounts as $providerSlug => $totalClicks) {
        if ($providerSlug === $currentProvider) {
            continue;
        }

        if ($totalClicks < $currentProviderClicks) {
            $providersWithLessClicks[$providerSlug] = $totalClicks;
        }
    }

    if (!empty($providersWithLessClicks)) {
        asort($providersWithLessClicks);

        foreach (array_keys($providersWithLessClicks) as $tryProvider) {
            $excludeIds = getServedProviderJobIdsForRotation($tryProvider, $email, $keyword, $ipAddress);

            $candidate = getValidProviderJobUrlByIndexForRotation(
                $tryProvider,
                $email,
                $keyword,
                $location,
                $state,
                $city,
                (int) ($clickCounts[$tryProvider] ?? 0),
                $excludeIds
            );

            if ($candidate['url'] !== '') {
                return [
                    'provider'           => $tryProvider,
                    'target_url'         => $candidate['url'],
                    'provider_job_id'    => $candidate['external_id'],
                    'provider_job_price' => $candidate['score'] ?? null,
                    'recent_clicks'      => $clickCounts[$tryProvider] ?? 0,
                    'rotation_applied'   => true,
                    'reason'             => 'provider_with_less_recent_clicks',
                ];
            }
        }
    }

    $excludeIds = getServedProviderJobIdsForRotation($currentProvider, $email, $keyword, $ipAddress);

    $candidate = getValidProviderJobUrlByIndexForRotation(
        $currentProvider,
        $email,
        $keyword,
        $location,
        $state,
        $city,
        $currentProviderClicks,
        $excludeIds
    );

    if ($candidate['url'] !== '') {
        return [
            'provider'           => $currentProvider,
            'target_url'         => $candidate['url'],
            'provider_job_id'    => $candidate['external_id'],
            'provider_job_price' => $candidate['score'] ?? null,
            'recent_clicks'      => $currentProviderClicks,
            'rotation_applied'   => true,
            'reason'             => 'same_provider_next_job',
        ];
    }

    return [
        'provider'           => $currentProvider,
        'target_url'         => $targetUrl,
        'provider_job_id'    => null,
        'provider_job_price' => null,
        'recent_clicks'      => $currentProviderClicks,
        'rotation_applied'   => false,
        'reason'             => 'no_valid_rotated_job_found',
    ];
}

function getUsStateName(string $state): string
{
    $state = strtoupper(trim($state));

    $states = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];

    return $states[$state] ?? '';
}

include_once __DIR__ . '/default_get.php';
