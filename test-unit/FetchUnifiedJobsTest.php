<?php

declare(strict_types=1);

/**
 * Integration tests for fetchUnifiedJobs().
 *
 * The real implementation is executed in a subprocess via function_runner.php.
 * Only the three low-level HTTP functions are mocked; all orchestration logic
 * (routing, adaptation, sorting, error handling) runs for real.
 *
 * If you change anything in fetchUnifiedJobs() — routing, adapters, sorting,
 * return structure — at least one of these tests will fail.
 */
class FetchUnifiedJobsTest extends TestCase
{
    // ----------------------------------------------------------------
    // Canonical raw job fixtures (match the shape each API returns)
    // ----------------------------------------------------------------

    private static function talrooJob(array $override = []): array
    {
        return array_merge([
            'title'       => 'Registered Nurse',
            'company'     => 'Memorial Hospital',
            'city'        => ['Columbia,SC'],
            'description' => 'Nursing job description.',
            'url'         => 'https://track.talroo.com/job/abc123',
            'id'          => '111',
            'price'       => 80,
            'date'        => '2024-01-15',
        ], $override);
    }

    private static function fallbackJob(array $override = []): array
    {
        return array_merge([
            'title'    => 'LPN Nurse',
            'company'  => 'Clinic',
            'location' => 'Columbia, SC',
            'snippet'  => 'LPN position available.',
            'link'     => 'https://jooble.org/jdp/-1234567890',
            'updated'  => '2024-01-05',
        ], $override);
    }

    // ----------------------------------------------------------------
    // Subprocess helper
    // ----------------------------------------------------------------

    private function runFetch(
        string $provider,
        array $rawResp = [],
        string $keyword = 'nurse',
        string $location = 'Columbia, SC',
        string $state = 'SC',
        string $city = 'Columbia',
        int $page = 1,
        int $perPage = 20
    ): array {
        $resultFile = sys_get_temp_dir() . '/bugai_fn_' . uniqid('', true) . '.json';
        $configFile = sys_get_temp_dir() . '/bugai_fn_cfg_' . uniqid('', true) . '.json';

        // Route $rawResp to the right mock slot based on provider
        $p = strtolower($provider);
        $talrooMock   = ['jobs' => [], 'error' => false, 'total' => 0, 'start' => 0, 'count' => 0];
        $fallbackMock = ['jobs' => [], 'error' => false];

        if (in_array($p, ['talroo', 'talroo2'], true)) {
            $talrooMock = array_merge($talrooMock, $rawResp);
        } else {
            $fallbackMock = array_merge($fallbackMock, $rawResp);
        }

        file_put_contents($configFile, json_encode([
            'shadow_dir'          => TEST_SHADOW_DIR,
            'fn_args'             => [$provider, 'test@example.com', $keyword, $location, $state, $city, $page, $perPage],
            'mock_talroo'         => $talrooMock,
            'mock_jooble_fallback'=> $fallbackMock,
            'result_file'         => $resultFile,
        ]));

        $runner = TEST_DIR . '/helpers/function_runner.php';
        exec(PHP_BINARY . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($configFile) . ' 2>/dev/null');

        @unlink($configFile);

        $result = [];
        if (file_exists($resultFile)) {
            $result = json_decode(file_get_contents($resultFile), true) ?? [];
            @unlink($resultFile);
        }

        return $result;
    }

    // ----------------------------------------------------------------
    // Return structure
    // ----------------------------------------------------------------

    public function test_return_structure_has_required_keys(): void
    {
        $result = $this->runFetch('talroo');

        $this->assertArrayHasKey('jobs',  $result, 'Must return array with "jobs" key');
        $this->assertArrayHasKey('error', $result, 'Must return array with "error" key');
        $this->assertArrayHasKey('meta',  $result, 'Must return array with "meta" key');
        $this->assertIsArray($result['jobs']);
        $this->assertIsArray($result['meta']);
    }

    public function test_jobs_array_is_always_present_even_when_empty(): void
    {
        $result = $this->runFetch('jooble_fallback');

        $this->assertIsArray($result['jobs'] ?? null);
        $this->assertCount(0, $result['jobs']);
        $this->assertFalse($result['error']);
    }

    // ----------------------------------------------------------------
    // Adapted job shape
    // ----------------------------------------------------------------

    public function test_adapted_job_has_all_unified_keys(): void
    {
        $result = $this->runFetch('talroo', ['jobs' => [self::talrooJob()]]);
        $job    = $result['jobs'][0];

        $required = ['external_id', 'title', 'company', 'location_label', 'city',
                     'state', 'country', 'snippet', 'url', 'salary_text',
                     'posted_at', 'logo_url', 'score', 'raw'];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $job, "Unified job missing key: $key");
        }
    }

    // ----------------------------------------------------------------
    // Provider routing — Talroo
    // ----------------------------------------------------------------

    public function test_talroo_routes_to_talroo_adapter(): void
    {
        $raw    = self::talrooJob(['id' => '999', 'price' => 75]);
        $result = $this->runFetch('talroo', ['jobs' => [$raw]]);

        $this->assertCount(1, $result['jobs']);
        $job = $result['jobs'][0];
        $this->assertSame('999', $job['external_id']); // talrooAdaptJob maps 'id' → external_id
        $this->assertSame(75,    $job['score']);         // talrooAdaptJob maps 'price' → score
        $this->assertSame('Columbia', $job['city']);
        $this->assertSame('SC',       $job['state']);
    }

    public function test_talroo2_uses_talroo_adapter(): void
    {
        $raw    = self::talrooJob(['id' => '42', 'price' => 50]);
        $result = $this->runFetch('talroo2', ['jobs' => [$raw]]);

        $this->assertCount(1, $result['jobs']);
        $this->assertSame('42', $result['jobs'][0]['external_id']);
        $this->assertSame(50,   $result['jobs'][0]['score']);
    }

    public function test_talroo_provider_is_case_insensitive(): void
    {
        $result = $this->runFetch('TALROO', ['jobs' => [self::talrooJob()]]);

        $this->assertCount(1, $result['jobs']);
    }

    // ----------------------------------------------------------------
    // Provider routing — Jooble fallback
    // ----------------------------------------------------------------

    public function test_jooble_fallback_uses_fallback_adapter(): void
    {
        $raw    = self::fallbackJob(['link' => 'https://jooble.org/jdp/-9876543210']);
        $result = $this->runFetch('jooble_fallback', ['jobs' => [$raw]]);

        $this->assertCount(1, $result['jobs']);
        $job = $result['jobs'][0];
        $this->assertSame('-9876543210', $job['external_id']); // extracted from link path
        $this->assertNull($job['score']);                       // fallback has no score
        $this->assertSame('Columbia', $job['city']);
        $this->assertSame('SC',       $job['state']);
    }

    public function test_unknown_provider_falls_through_to_fallback(): void
    {
        $raw    = self::fallbackJob(['link' => 'https://jooble.org/jdp/-1111']);
        $result = $this->runFetch('some_unknown_provider', ['jobs' => [$raw]]);

        $this->assertCount(1, $result['jobs']);
        $this->assertNull($result['jobs'][0]['score']); // fallback adapter → score=null
    }

    // ----------------------------------------------------------------
    // Error handling
    // ----------------------------------------------------------------

    public function test_error_true_returns_empty_jobs(): void
    {
        $result = $this->runFetch('talroo', [
            'jobs'  => [self::talrooJob()],
            'error' => true,
        ]);

        $this->assertCount(0, $result['jobs'], 'When error=true, jobs must be empty');
        $this->assertTrue($result['error']);
    }

    public function test_error_false_returns_adapted_jobs(): void
    {
        $result = $this->runFetch('talroo', [
            'jobs'  => [self::talrooJob()],
            'error' => false,
        ]);

        $this->assertCount(1, $result['jobs']);
        $this->assertFalse($result['error']);
    }

    // ----------------------------------------------------------------
    // Score sorting
    // ----------------------------------------------------------------

    public function test_talroo_jobs_sorted_by_score_descending(): void
    {
        $rawJobs = [
            self::talrooJob(['id' => 'low',  'price' => 10]),
            self::talrooJob(['id' => 'high', 'price' => 90]),
            self::talrooJob(['id' => 'mid',  'price' => 50]),
        ];
        $result = $this->runFetch('talroo', ['jobs' => $rawJobs]);

        $ids = array_column($result['jobs'], 'external_id');
        $this->assertSame(['high', 'mid', 'low'], $ids);
    }

    public function test_jooble_fallback_preserves_original_order(): void
    {
        $rawJobs = [
            self::fallbackJob(['title' => 'First',  'link' => 'https://jooble.org/jdp/-1']),
            self::fallbackJob(['title' => 'Second', 'link' => 'https://jooble.org/jdp/-2']),
            self::fallbackJob(['title' => 'Third',  'link' => 'https://jooble.org/jdp/-3']),
        ];
        $result = $this->runFetch('jooble_fallback', ['jobs' => $rawJobs]);

        $titles = array_column($result['jobs'], 'title');
        $this->assertSame(['First', 'Second', 'Third'], $titles);
    }

    // ----------------------------------------------------------------
    // Meta / pagination
    // ----------------------------------------------------------------

    public function test_talroo_meta_contains_pagination_fields(): void
    {
        $result = $this->runFetch('talroo', [
            'jobs'  => [self::talrooJob()],
            'error' => false,
            'total' => 42,
            'start' => 0,
            'count' => 1,
        ]);

        $this->assertSame(42, $result['meta']['total']);
        $this->assertSame(0,  $result['meta']['start']);
        $this->assertSame(1,  $result['meta']['count']);
    }

    public function test_jooble_fallback_meta_is_empty(): void
    {
        $result = $this->runFetch('jooble_fallback', ['jobs' => [self::fallbackJob()]]);

        $this->assertSame([], $result['meta']);
    }
}
