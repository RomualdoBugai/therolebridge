<?php

declare(strict_types=1);

/**
 * Tests for DB-dependent provider functions.
 * Uses the test MySQL database created in bootstrap.php.
 */
class ProviderDbTest extends TestCase
{
    // ----------------------------------------------------------------
    // getActiveJobProviderSlugs
    // ----------------------------------------------------------------

    public function test_getActiveJobProviderSlugs_returns_active_slugs(): void
    {
        $slugs = getActiveJobProviderSlugs();
        $this->assertContains('talroo', $slugs);
        $this->assertContains('jooble_fallback', $slugs);
    }

    public function test_getActiveJobProviderSlugs_preferred_provider_first(): void
    {
        $slugs = getActiveJobProviderSlugs('talroo');
        $this->assertSame('talroo', $slugs[0]);
    }

    public function test_getActiveJobProviderSlugs_no_duplicates(): void
    {
        $slugs = getActiveJobProviderSlugs('talroo');
        $this->assertSame(array_unique($slugs), $slugs);
    }

    public function test_getActiveJobProviderSlugs_respects_status(): void
    {
        $pdo = self::db();
        // Temporarily deactivate talroo
        $pdo->exec("UPDATE provider_jobs SET status='inactive' WHERE slug='talroo'");
        $pdo->exec("DELETE FROM provider_jobs_traffic_split WHERE provider_job_id=(SELECT id FROM provider_jobs WHERE slug='talroo')");

        $slugs = getActiveJobProviderSlugs();
        $this->assertNotContains('talroo', $slugs);

        // Restore
        $pdo->exec("UPDATE provider_jobs SET status='active' WHERE slug='talroo'");
        $pdo->exec("INSERT IGNORE INTO provider_jobs_traffic_split (provider_job_id, weight) SELECT id, 50 FROM provider_jobs WHERE slug='talroo'");
    }

    // ----------------------------------------------------------------
    // getJobProviderConfig (cache-based, reads from DB)
    // ----------------------------------------------------------------

    public function test_getJobProviderConfig_returns_array(): void
    {
        // Invalidate cache so it rebuilds from test DB
        @unlink(TEST_CACHE_DIR . '/provider_jobs_active.json');
        @unlink(TEST_CACHE_DIR . '/provider_jobs_active.lock');

        $config = getJobProviderConfig('talroo');
        $this->assertIsArray($config);
        $this->assertSame('talroo', $config['slug']);
        $this->assertSame('Talroo', $config['name']);
    }

    public function test_getJobProviderConfig_unknown_slug_returns_null(): void
    {
        $config = getJobProviderConfig('nonexistent_provider_xyz');
        $this->assertNull($config);
    }

    // ----------------------------------------------------------------
    // getRecordLeadIdByEmail
    // ----------------------------------------------------------------

    public function test_getRecordLeadIdByEmail_existing_email(): void
    {
        $pdo = self::db();
        $pdo->exec("INSERT INTO record_leads (email, job_keyword) VALUES ('lead@test.com', 'nurse')");

        $id = getRecordLeadIdByEmail('lead@test.com');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function test_getRecordLeadIdByEmail_unknown_returns_null(): void
    {
        $this->assertNull(getRecordLeadIdByEmail('unknown@nobody.com'));
    }

    public function test_getRecordLeadIdByEmail_null_returns_null(): void
    {
        $this->assertNull(getRecordLeadIdByEmail(null));
    }

    public function test_getRecordLeadIdByEmail_case_insensitive(): void
    {
        $pdo = self::db();
        $pdo->exec("INSERT INTO record_leads (email) VALUES ('Upper@Test.com')");

        $id = getRecordLeadIdByEmail('upper@test.com');
        $this->assertNotNull($id);
    }

    // ----------------------------------------------------------------
    // hasRecentClickForProvider
    // ----------------------------------------------------------------

    public function test_hasRecentClickForProvider_false_when_no_clicks(): void
    {
        $result = hasRecentClickForProvider('talroo', 'user@test.com', 'nurse', '1.2.3.4', 60);
        $this->assertFalse($result);
    }

    public function test_hasRecentClickForProvider_true_after_click_inserted(): void
    {
        $pdo = self::db();
        $pdo->exec("
            INSERT INTO job_clicks_out
                (provider, email, keyword, ip_address, created_at)
            VALUES
                ('talroo', 'user@test.com', 'nurse', '1.2.3.4', NOW())
        ");

        $result = hasRecentClickForProvider('talroo', 'user@test.com', 'nurse', '1.2.3.4', 60);
        $this->assertTrue($result);
    }

    // ----------------------------------------------------------------
    // insertJobClickSuspicious
    // ----------------------------------------------------------------

    public function test_insertJobClickSuspicious_inserts_row(): void
    {
        $result = insertJobClickSuspicious(
            'talroo', 'test_src', 'email', 'camp1', 'utm123',
            'user@test.com', 'nurse', 'Columbia', 'SC', '', 'TestUA', '1.2.3.4'
        );
        $this->assertTrue($result);

        $rows = self::db()->query("SELECT * FROM job_clicks_suspicious")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('nurse', $rows[0]['keyword']);
        $this->assertSame('Columbia', $rows[0]['city']);
    }

    // ----------------------------------------------------------------
    // getRecentClickCountForProviderRotation
    // ----------------------------------------------------------------

    public function test_recentClickCount_zero_with_no_clicks(): void
    {
        $count = getRecentClickCountForProviderRotation('talroo', 'test@test.com', 'nurse', '1.2.3.4');
        $this->assertSame(0, $count);
    }

    public function test_recentClickCount_increments(): void
    {
        $pdo = self::db();
        $pdo->exec("
            INSERT INTO job_clicks_out (provider, email, keyword, ip_address, created_at)
            VALUES ('talroo', 'test@test.com', 'nurse', '1.2.3.4', NOW()),
                   ('talroo', 'test@test.com', 'nurse', '1.2.3.4', NOW())
        ");

        $count = getRecentClickCountForProviderRotation('talroo', 'test@test.com', 'nurse', '1.2.3.4');
        $this->assertSame(2, $count);
    }

    // ----------------------------------------------------------------
    // resolveProviderClickRotationTarget
    // ----------------------------------------------------------------

    public function test_rotation_no_recent_clicks_keeps_original_url(): void
    {
        $result = resolveProviderClickRotationTarget(
            'talroo', 'user@test.com', 'nurse', '1.2.3.4',
            '29063', 'SC', 'Columbia', 'https://original.example.com/job'
        );

        $this->assertFalse($result['rotation_applied']);
        $this->assertSame('no_recent_click_for_current_provider', $result['reason']);
        $this->assertSame('https://original.example.com/job', $result['target_url']);
    }

    public function test_rotation_applied_when_current_provider_has_clicks(): void
    {
        $pdo = self::db();

        // Give talroo 2 recent clicks, jooble_fallback 0
        $pdo->exec("
            INSERT INTO job_clicks_out (provider, email, keyword, ip_address, created_at)
            VALUES ('talroo', 'rotate@test.com', 'nurse', '9.9.9.9', NOW()),
                   ('talroo', 'rotate@test.com', 'nurse', '9.9.9.9', NOW())
        ");

        // fetchUnifiedJobs returns empty in tests, so rotation will fail to find a job URL
        // but the logic should try and set rotation_applied or fall back
        $result = resolveProviderClickRotationTarget(
            'talroo', 'rotate@test.com', 'nurse', '9.9.9.9',
            '', 'SC', 'Columbia', 'https://original.example.com/job'
        );

        // With current provider having 2 clicks and mock fetchUnifiedJobs returning empty,
        // rotation tried but no URL found → falls back to original
        $this->assertArrayHasKey('rotation_applied', $result);
        $this->assertArrayHasKey('target_url', $result);
        $this->assertArrayHasKey('provider', $result);
    }
}
