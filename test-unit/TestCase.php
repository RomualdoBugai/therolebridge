<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?PDO $pdo = null;

    // ----------------------------------------------------------------
    // Schema
    // ----------------------------------------------------------------

    private static function schema(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS provider_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                provider_type ENUM('api','affiliate','both') NOT NULL DEFAULT 'api',
                api_base_url VARCHAR(255),
                api_id VARCHAR(255),
                api_secret VARCHAR(255),
                api_param_keyword VARCHAR(50) DEFAULT 'q',
                api_param_location VARCHAR(50) DEFAULT 'l',
                api_param_ip VARCHAR(50) DEFAULT 'ip',
                api_param_page VARCHAR(50),
                api_param_per_page VARCHAR(50),
                api_fixed_params LONGTEXT,
                affiliate_base_url VARCHAR(255),
                affiliate_id VARCHAR(100),
                affiliate_param_id VARCHAR(50) DEFAULT 'aff_id',
                affiliate_fixed_params LONGTEXT,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS provider_jobs_traffic_split (
                id INT AUTO_INCREMENT PRIMARY KEY,
                provider_job_id INT NOT NULL,
                weight INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_provider_job (provider_job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS job_clicks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                provider VARCHAR(50),
                utm_source VARCHAR(100),
                utm_medium VARCHAR(100),
                utm_campaign VARCHAR(150),
                utm_id VARCHAR(191),
                email VARCHAR(191),
                keyword VARCHAR(191),
                city VARCHAR(100),
                state VARCHAR(50),
                zip VARCHAR(20),
                user_agent VARCHAR(255),
                ip_address VARCHAR(45)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS job_clicks_out (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_click_id BIGINT UNSIGNED DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                provider VARCHAR(50),
                provider_job_id VARCHAR(64),
                provider_job_price INT,
                click_source VARCHAR(50),
                utm_source VARCHAR(100),
                utm_medium VARCHAR(100),
                utm_campaign VARCHAR(150),
                utm_id VARCHAR(191),
                email VARCHAR(191),
                keyword VARCHAR(191),
                city VARCHAR(100),
                state VARCHAR(50),
                zip VARCHAR(20),
                user_agent VARCHAR(255),
                ip_address VARCHAR(45)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS job_clicks_suspicious (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                provider VARCHAR(50),
                utm_source VARCHAR(100),
                utm_medium VARCHAR(100),
                utm_campaign VARCHAR(150),
                utm_id VARCHAR(191),
                email VARCHAR(191),
                keyword VARCHAR(191),
                city VARCHAR(100),
                state VARCHAR(50),
                zip VARCHAR(20),
                user_agent VARCHAR(255),
                ip_address VARCHAR(45)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS record_leads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                job_keyword VARCHAR(100),
                city VARCHAR(100),
                state VARCHAR(100),
                zip VARCHAR(10),
                is_valid TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
    }

    // ----------------------------------------------------------------
    // DB helpers
    // ----------------------------------------------------------------

    protected static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . TEST_DB_HOST . ';port=' . TEST_DB_PORT .
                ';dbname=' . TEST_DB_NAME . ';charset=' . TEST_DB_CHARSET,
                TEST_DB_USER,
                TEST_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $pdo = self::db();

        // Create all tables
        foreach (array_filter(array_map('trim', explode(';', self::schema()))) as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }

        // Seed default providers for most tests
        self::seedDefaultProviders($pdo);

        // Include shadow (patched) files so mock functions survive loading
        require_once TEST_SHADOW_DIR . '/includes/functions.php';
        require_once TEST_SHADOW_DIR . '/includes/provider_jobs.php';
    }

    protected static function seedDefaultProviders(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM provider_jobs_traffic_split");
        $pdo->exec("DELETE FROM provider_jobs");

        $pdo->exec("
            INSERT INTO provider_jobs (slug, name, provider_type, api_base_url, api_id, status)
            VALUES
                ('jooble_fallback', 'Jooble', 'api', 'https://jooble.org/api/', 'test_key', 'active'),
                ('talroo',          'Talroo', 'api', 'https://api.talroo.com/', 'test_key', 'active')
        ");

        $pdo->exec("
            INSERT INTO provider_jobs_traffic_split (provider_job_id, weight)
            SELECT id, 50 FROM provider_jobs
        ");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateClickTables();
        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];
        $GLOBALS['__test_mock_jobs'] = [];
    }

    protected function truncateClickTables(): void
    {
        $pdo = self::db();
        $pdo->exec('DELETE FROM job_clicks_suspicious');
        $pdo->exec('DELETE FROM job_clicks_out');
        $pdo->exec('DELETE FROM job_clicks');
        $pdo->exec('DELETE FROM record_leads');
    }

    // ----------------------------------------------------------------
    // Page runner (subprocess)
    // ----------------------------------------------------------------

    /**
     * Runs a page file in a subprocess simulating an HTTP request.
     *
     * Returns an array with:
     *   output            - captured HTML/redirect output
     *   job_clicks        - rows inserted into job_clicks
     *   job_clicks_out    - rows inserted into job_clicks_out
     *   job_clicks_suspicious - rows inserted into job_clicks_suspicious
     */
    protected function runPage(
        string $pageFile,
        array $getParams = [],
        array $mockJobs = [],
        array $extraServer = []
    ): array {
        $resultFile = sys_get_temp_dir() . '/bugai_test_' . uniqid('', true) . '.json';
        $configFile = sys_get_temp_dir() . '/bugai_cfg_' . uniqid('', true) . '.json';

        file_put_contents($configFile, json_encode([
            'page'        => TEST_SHADOW_DIR . '/' . $pageFile,
            'get'         => $getParams,
            'server'      => $extraServer,
            'db_host'     => TEST_DB_HOST,
            'db_port'     => TEST_DB_PORT,
            'db_name'     => TEST_DB_NAME,
            'db_user'     => TEST_DB_USER,
            'db_pass'     => TEST_DB_PASS,
            'mock_jobs'   => $mockJobs,
            'result_file' => $resultFile,
        ]));

        $runnerPath = TEST_DIR . '/helpers/page_runner.php';
        $cmd = PHP_BINARY . ' ' . escapeshellarg($runnerPath) . ' ' . escapeshellarg($configFile);

        exec($cmd . ' 2>/dev/null', $lines, $exitCode);

        @unlink($configFile);

        $result = ['output' => '', 'job_clicks' => [], 'job_clicks_out' => [], 'job_clicks_suspicious' => []];

        if (file_exists($resultFile)) {
            $data   = json_decode(file_get_contents($resultFile), true);
            $result = array_merge($result, $data ?? []);
            @unlink($resultFile);
        }

        return $result;
    }

    /**
     * Insert a job_clicks row and return its ID (used to set up jobs-out.php tests).
     */
    protected function insertJobClick(array $override = []): int
    {
        $data = array_merge([
            'provider'     => 'talroo',
            'utm_source'   => 'test',
            'utm_medium'   => 'email',
            'utm_campaign' => 'test_campaign',
            'utm_id'       => '',
            'email'        => 'test@example.com',
            'keyword'      => 'nurse',
            'city'         => 'Columbia',
            'state'        => 'SC',
            'zip'          => '',
            'user_agent'   => TEST_USER_AGENT,
            'ip_address'   => '1.2.3.4',
            'created_at'   => date('Y-m-d H:i:s'),
        ], $override);

        $pdo = self::db();
        $stmt = $pdo->prepare("
            INSERT INTO job_clicks
                (provider, utm_source, utm_medium, utm_campaign, utm_id, email, keyword,
                 city, state, zip, user_agent, ip_address, created_at)
            VALUES
                (:provider, :utm_source, :utm_medium, :utm_campaign, :utm_id, :email, :keyword,
                 :city, :state, :zip, :user_agent, :ip_address, :created_at)
        ");
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }
}
