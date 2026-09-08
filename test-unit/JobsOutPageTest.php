<?php

declare(strict_types=1);

/**
 * Integration tests for jobs-out.php.
 *
 * Each test runs jobs-out.php in a subprocess via page_runner.php.
 * The page always exits via header(Location:)/exit, so we assert on:
 *  - DB rows inserted into job_clicks_out
 *  - The Location header captured in output (page_runner captures raw output
 *    before headers are sent; in CLI mode PHP does not suppress header() output)
 */
class JobsOutPageTest extends TestCase
{
    // ----------------------------------------------------------------
    // Basic clickout insertion
    // ----------------------------------------------------------------

    public function test_job_clicks_out_row_inserted_on_valid_request(): void
    {
        $clickId = $this->insertJobClick([
            'provider'     => 'talroo',
            'keyword'      => 'nurse',
            'city'         => 'Columbia',
            'state'        => 'SC',
            'email'        => 'user@example.com',
            'utm_source'   => 'email',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'camp1',
        ]);

        $result = $this->runPage('jobs-out.php', [
            'job_click_id' => (string) $clickId,
            'job_url'      => 'https://jobs.talroo.com/apply/test-123',
            'job_id'       => 'talroo-test-123',
            'job_price'    => '95',
            'provider'     => 'talroo',
            'keyword'      => 'nurse',
            'city'         => 'Columbia',
            'state'        => 'SC',
            'email'        => 'user@example.com',
            'utm_source'   => 'email',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'camp1',
        ]);

        $this->assertCount(1, $result['job_clicks_out'],
            'Expected one job_clicks_out row');

        $row = $result['job_clicks_out'][0];
        $this->assertSame((string) $clickId, (string) $row['job_click_id']);
        $this->assertSame('talroo', $row['provider']);
        $this->assertSame('nurse',  $row['keyword']);
        $this->assertSame('Columbia', $row['city']);
        $this->assertSame('SC',     $row['state']);
        $this->assertSame('user@example.com', $row['email']);
    }

    public function test_job_clicks_out_stores_utm_fields(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'      => 'https://jobs.talroo.com/apply/test-456',
            'provider'     => 'talroo',
            'keyword'      => 'driver',
            'location'     => '29063',
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'summer_2026',
            'utm_id'       => 'utm-abc-123',
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $row = $result['job_clicks_out'][0];
        $this->assertSame('facebook',    $row['utm_source']);
        $this->assertSame('cpc',         $row['utm_medium']);
        $this->assertSame('summer_2026', $row['utm_campaign']);
        $this->assertSame('utm-abc-123', $row['utm_id']);
    }

    public function test_job_clicks_out_stores_provider_job_id(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'   => 'https://jobs.talroo.com/apply/ext-999',
            'job_id'    => 'ext-999',
            'job_price' => '120',
            'provider'  => 'talroo',
            'keyword'   => 'welder',
            'location'  => '10001',
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $row = $result['job_clicks_out'][0];
        $this->assertSame('ext-999', $row['provider_job_id']);
        $this->assertSame('120',     (string) $row['provider_job_price']);
    }

    // ----------------------------------------------------------------
    // Direct fallback when UTM is empty (no job_click_id)
    // ----------------------------------------------------------------

    public function test_direct_access_fallback_utm_filled(): void
    {
        // No job_click_id → page sets utm_source='direct', utm_medium='site'
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jobs.talroo.com/apply/direct-test',
            'provider' => 'talroo',
            'keyword'  => 'cook',
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $row = $result['job_clicks_out'][0];
        $this->assertSame('direct', $row['utm_source']);
        $this->assertSame('site',   $row['utm_medium']);
        $this->assertSame('job_out_fallback', $row['utm_campaign']);
    }

    // ----------------------------------------------------------------
    // First click uses original URL (no rotation)
    // ----------------------------------------------------------------

    public function test_first_click_uses_original_url(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jobs.talroo.com/apply/first-click',
            'provider' => 'talroo',
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'email'    => 'first@example.com',
        ]);

        $this->assertStringContainsString(
            'jobs.talroo.com/apply/first-click',
            $result['location'],
            'First click should redirect to the original job URL'
        );
    }

    // ----------------------------------------------------------------
    // Provider-specific sub-ID appended to redirect URL
    // ----------------------------------------------------------------

    public function test_talroo_t1_param_appended_to_redirect(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jobs.talroo.com/apply/t1-test',
            'provider' => 'talroo',
            'keyword'  => 'nurse',
            'location' => '29063',
        ]);

        $this->assertStringContainsString('t1=', $result['location'],
            'Talroo redirect URL should include t1 sub-ID');
    }

    public function test_jooble_source_id_appended_to_redirect(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'    => 'https://jooble.org/jdp/-1234567890',
            'provider'   => 'jooble_fallback',
            'keyword'    => 'nurse',
            'location'   => '29063',
            'utm_source' => 'email',
        ]);

        $this->assertStringContainsString('source_id=', $result['location'],
            'Jooble redirect URL should include source_id');
        $this->assertStringContainsString('source_id=email', $result['location']);
    }

    public function test_jooble_direct_access_uses_direct_source_id(): void
    {
        // When no utm_source is passed (direct access), jobs-out.php falls back to
        // utm_source='direct', so source_id=direct is still appended to jooble URLs.
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jooble.org/jdp/-9999',
            'provider' => 'jooble_fallback',
            'keyword'  => 'nurse',
            'location' => '29063',
            // utm_source intentionally omitted → page fills in 'direct'
        ]);

        $this->assertStringContainsString('source_id=direct', $result['location']);
    }

    // ----------------------------------------------------------------
    // Location fallback: city/state/zip parsed from 'location' param
    // ----------------------------------------------------------------

    public function test_location_param_parsed_when_city_state_absent(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jobs.talroo.com/apply/loc-test',
            'provider' => 'talroo',
            'keyword'  => 'nurse',
            'location' => 'Columbia, SC',
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $row = $result['job_clicks_out'][0];
        $this->assertSame('Columbia', $row['city']);
        $this->assertSame('SC', $row['state']);
    }

    // ----------------------------------------------------------------
    // Fallback redirect when job_url missing/invalid
    // ----------------------------------------------------------------

    public function test_fallback_to_job_grid_when_no_job_url(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'provider' => 'talroo',
            'keyword'  => 'nurse',
            'location' => '29063',
            // job_url omitted
        ]);

        $this->assertStringContainsString('job-grid.php', $result['location'],
            'Missing job_url should redirect to job-grid.php fallback');
    }

    // ----------------------------------------------------------------
    // click_source field stored
    // ----------------------------------------------------------------

    public function test_click_source_stored_correctly(): void
    {
        $result = $this->runPage('jobs-out.php', [
            'job_url'      => 'https://jobs.talroo.com/apply/src-test',
            'provider'     => 'talroo',
            'keyword'      => 'nurse',
            'click_source' => 'email_campaign',
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $this->assertSame('email_campaign', $result['job_clicks_out'][0]['click_source']);
    }

    // ----------------------------------------------------------------
    // job_click_id > 0 does NOT apply direct UTM fallback
    // ----------------------------------------------------------------

    public function test_with_job_click_id_nonzero_utm_not_defaulted_to_direct(): void
    {
        $clickId = $this->insertJobClick(['utm_source' => 'facebook']);

        $result = $this->runPage('jobs-out.php', [
            'job_click_id' => (string) $clickId,
            'job_url'      => 'https://jobs.talroo.com/apply/utm-block-test',
            'provider'     => 'talroo',
            'keyword'      => 'nurse',
            // utm_source intentionally omitted — job_click_id > 0 skips the direct fallback
        ]);

        $this->assertNotEmpty($result['job_clicks_out']);
        $row = $result['job_clicks_out'][0];
        $this->assertNotSame('direct',          $row['utm_source'],   'job_click_id > 0 must not fill in "direct"');
        $this->assertNotSame('job_out_fallback', $row['utm_campaign'], 'job_click_id > 0 must not fill in fallback campaign');
    }

    // ----------------------------------------------------------------
    // Invalid URL — appendProviderSubId must be skipped
    // ----------------------------------------------------------------

    public function test_invalid_job_url_redirects_without_provider_subid(): void
    {
        // filter_var('not-a-valid-url', FILTER_VALIDATE_URL) = false
        // → appendProviderSubId is never called → t1= must not appear
        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'not-a-valid-url',
            'provider' => 'talroo',
            'keyword'  => 'nurse',
        ]);

        $this->assertStringContainsString('not-a-valid-url', $result['location']);
        $this->assertStringNotContainsString('t1=', $result['location']);
    }

    // ----------------------------------------------------------------
    // Rotation: repeated clicks on same provider should rotate
    // ----------------------------------------------------------------

    public function test_click_rotation_tried_when_provider_has_recent_clicks(): void
    {
        $pdo = self::db();

        // Simulate 2 prior clicks for talroo by this user
        $pdo->exec("
            INSERT INTO job_clicks_out (provider, email, keyword, ip_address, created_at)
            VALUES ('talroo', 'rotate@test.com', 'nurse', '127.0.0.1', NOW()),
                   ('talroo', 'rotate@test.com', 'nurse', '127.0.0.1', NOW())
        ");

        $result = $this->runPage('jobs-out.php', [
            'job_url'  => 'https://jobs.talroo.com/apply/rotate-test',
            'provider' => 'talroo',
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'email'    => 'rotate@test.com',
        ], []); // no mock jobs → rotation won't find alternate, falls back to original

        // A row is still inserted (rotation attempted but fell back to original URL)
        $this->assertNotEmpty($result['job_clicks_out'],
            'Even when rotation falls back, a click_out row should be inserted');
    }
}
