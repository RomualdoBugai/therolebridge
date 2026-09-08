<?php
// Polyfill para str_starts_with em PHP < 8
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// =========================
// LOAD .env (somente fora do Docker)
// =========================
$envPath = __DIR__ . '/../.env';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name  = trim($parts[0]);
        $value = trim($parts[1]);

        if ($name === '') {
            continue;
        }

        // remove aspas externas simples ou duplas
        $value = trim($value, " \t\n\r\0\x0B\"'");

        putenv($name . '=' . $value);
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}

// Sempre antes de qualquer uso de date()/DateTime
date_default_timezone_set('America/New_York');

/**
 * Extrai o código numérico mais confiável de uma exception PDO/Runtime.
 */
function getPdoNumericErrorCode(Throwable $e): int
{
    if ($e instanceof PDOException && !empty($e->errorInfo[1])) {
        return (int) $e->errorInfo[1];
    }

    if (preg_match('/\[(\d{3,5})\]/', $e->getMessage(), $m)) {
        return (int) $m[1];
    }

    return 0;
}

/**
 * Diz se o erro parece ser de conexão perdida/rede.
 */
function isTransientPdoConnectionError(Throwable $e): bool
{
    $code = getPdoNumericErrorCode($e);

    return in_array($code, [2002, 2006, 2013], true);
}

/**
 * Diz se o erro é o limite por hora de conexões.
 */
function isHourlyConnectionLimitError(Throwable $e): bool
{
    return getPdoNumericErrorCode($e) === 1226;
}

/**
 * Retorna uma conexão PDO com o MySQL.
 *
 * - Reaproveita a mesma conexão dentro do mesmo processo/request.
 * - Só marca bloqueio interno permanente para erro 1226.
 * - Permite forçar nova conexão quando necessário.
 */
function getPdoConnection(bool $forceNew = false): PDO
{
    static $pdo = null;
    static $blockedBy1226 = false;

    if ($forceNew) {
        $pdo = null;
    }

    if ($blockedBy1226 && !$forceNew) {
        throw new RuntimeException('Database connection blocked in this process due to previous MySQL error 1226.');
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = getenv('DB_HOST') ?: '';
    $port    = getenv('DB_PORT') ?: '3306';
    $db      = getenv('DB_NAME') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    $user    = getenv('DB_USER') ?: '';
    $pass    = getenv('DB_PASS') ?: '';

    if ($host === '' || $db === '' || $user === '') {
        throw new RuntimeException('Database env vars missing (DB_HOST/DB_NAME/DB_USER).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

        return $pdo;
    } catch (Throwable $e) {
        if (isHourlyConnectionLimitError($e)) {
            $blockedBy1226 = true;
            throw new RuntimeException(
                'MySQL hourly connection limit exceeded (error 1226). Try again later.',
                0,
                $e
            );
        }

        throw new RuntimeException(
            'Failed to connect to database: ' . $e->getMessage(),
            0,
            $e
        );
    }
}

/**
 * Executa uma operação com retry apenas para falhas transitórias de conexão.
 *
 * @param callable $callback   Recebe PDO e retorna qualquer coisa.
 * @param int      $maxRetries Número de retries reais após a primeira tentativa.
 * @return mixed
 * @throws Throwable
 */
function pdoRunWithReconnect(callable $callback, int $maxRetries = 1)
{
    $attempt = 0;

    while (true) {
        $attempt++;

        try {
            $pdo = getPdoConnection($attempt > 1);
            return $callback($pdo);
        } catch (Throwable $e) {
            if (isHourlyConnectionLimitError($e)) {
                throw $e;
            }

            $canRetry = isTransientPdoConnectionError($e) && $attempt <= ($maxRetries + 1);

            if (!$canRetry) {
                throw $e;
            }

            usleep(200000); // 200ms
        }
    }
}

function pdoInsertGetId(string $sql, array $params = [], int $maxRetries = 1): int
{
    return pdoRunWithReconnect(
        static function (PDO $pdo) use ($sql, $params): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return (int) $pdo->lastInsertId();
        },
        $maxRetries
    );
}

function pdoFetchAll(string $sql, array $params = [], int $maxRetries = 1): array
{
    return pdoRunWithReconnect(
        static function (PDO $pdo) use ($sql, $params): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();
        },
        $maxRetries
    );
}

function pdoFetchOne(string $sql, array $params = [], int $maxRetries = 1): ?array
{
    return pdoRunWithReconnect(
        static function (PDO $pdo) use ($sql, $params): ?array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $row = $stmt->fetch();

            return $row === false ? null : $row;
        },
        $maxRetries
    );
}

function pdoFetchValue(string $sql, array $params = [], int $maxRetries = 1)
{
    return pdoRunWithReconnect(
        static function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchColumn();
        },
        $maxRetries
    );
}

function pdoExecute(string $sql, array $params = [], int $maxRetries = 1): int
{
    return pdoRunWithReconnect(
        static function (PDO $pdo) use ($sql, $params): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        },
        $maxRetries
    );
}
