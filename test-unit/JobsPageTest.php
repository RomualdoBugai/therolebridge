<?php

declare(strict_types=1);

/**
 * Integration tests for jobs.php.
 *
 * Each test runs jobs.php in a subprocess via page_runner.php,
 * checks DB state and HTML output.
 */
class JobsPageTest extends TestCase
{
    // ----------------------------------------------------------------
    // Suspicious click path (no keyword + no location)
    // ----------------------------------------------------------------

    public function test_suspicious_click_logged_when_missing_keyword_and_location(): void
    {
        $result = $this->runPage('jobs.php', [
            'provider'     => 'talroo',
            'utm_source'   => 'email',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'campaign1',
            'email'        => 'test@example.com',
            // keyword and location intentionally omitted
        ]);

        $this->assertCount(1, $result['job_clicks_suspicious'],
            'Expected one suspicious click row');

        $row = $result['job_clicks_suspicious'][0];
        $this->assertSame('talroo',      $row['provider']);
        $this->assertSame('email',       $row['utm_source']);
        $this->assertSame('campaign1',   $row['utm_campaign']);
    }

    public function test_suspicious_click_no_job_click_inserted(): void
    {
        $result = $this->runPage('jobs.php', [
            'provider' => 'talroo',
        ]);

        // Missing keyword/location → suspicious path, no normal job_click
        $this->assertEmpty($result['job_clicks'],
            'Should not insert into job_clicks for suspicious requests');
    }

    // ----------------------------------------------------------------
    // Normal click path (keyword + location)
    // ----------------------------------------------------------------

    public function test_job_click_inserted_on_valid_request(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'      => 'nurse',
            'city'         => 'Columbia',
            'state'        => 'SC',
            'email'        => 'user@example.com',
            'provider'     => 'jooble_fallback',
            'utm_source'   => 'email',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'campaign1',
        ]);

        $this->assertCount(1, $result['job_clicks'],
            'Expected one job_clicks row for valid request');

        $row = $result['job_clicks'][0];
        $this->assertSame('nurse',           $row['keyword']);
        $this->assertSame('Columbia',        $row['city']);
        $this->assertSame('SC',              $row['state']);
        $this->assertSame('user@example.com',$row['email']);
    }

    public function test_job_click_stores_correct_provider(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'  => 'driver',
            'location' => '29063',
            'provider' => 'talroo',
        ]);

        $this->assertNotEmpty($result['job_clicks']);
        $row = $result['job_clicks'][0];
        $this->assertSame('talroo', $row['provider']);
    }

    // ----------------------------------------------------------------
    // HTML output - headline generation
    // ----------------------------------------------------------------

    public function test_headline_with_keyword_city_and_state(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword' => 'nurse',
            'city'    => 'Columbia',
            'state'   => 'SC',
        ], [/* no mock jobs = grid fallback path */]);

        $this->assertStringContainsString(
            'New Nurse jobs near Columbia, SC',
            $result['output'],
            'Expected headline with keyword, city and state'
        );
    }

    public function test_headline_with_keyword_and_city_only(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword' => 'driver',
            'city'    => 'Austin',
        ]);

        $this->assertStringContainsString(
            'New Driver jobs near Austin',
            $result['output']
        );
    }

    public function test_headline_with_keyword_only(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'  => 'welder',
            'location' => '29063',
        ]);

        $this->assertStringContainsString(
            'New Welder job opportunities',
            $result['output']
        );
    }

    public function test_headline_no_keyword_falls_back_to_generic(): void
    {
        // Providing location but no keyword still shows generic headline
        // (after the suspicious check - we need keyword OR location)
        $result = $this->runPage('jobs.php', [
            'location' => '29063',
        ]);

        $this->assertStringContainsString(
            'New job opportunities near you',
            $result['output']
        );
    }

    // ----------------------------------------------------------------
    // HTML output - partner name, job card
    // ----------------------------------------------------------------

    public function test_output_contains_partner_name_from_provider_config(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'provider' => 'jooble_fallback',
        ]);

        // Provider config for jooble_fallback has name 'Jooble'
        $this->assertStringContainsString('Jooble', $result['output']);
    }

    public function test_output_contains_job_title_when_jobs_available(): void
    {
        $mockJobs = [[
            'title'       => 'ICU Registered Nurse',
            'company'     => 'St. Francis Hospital',
            'url'         => 'https://jobs.talroo.com/apply/test123',
            'external_id' => 'talroo-test123',
            'score'       => 150,
            'salary_text' => null,
        ]];

        $result = $this->runPage('jobs.php', [
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'provider' => 'talroo',
        ], $mockJobs);

        $this->assertStringContainsString('ICU Registered Nurse', $result['output']);
    }

    // ----------------------------------------------------------------
    // Grid fallback when no jobs found
    // ----------------------------------------------------------------

    public function test_grid_fallback_text_when_no_jobs(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'provider' => 'talroo',
        ], []); // no mock jobs

        $this->assertStringContainsString(
            'job grid',
            strtolower($result['output']),
            'Expected grid fallback text when no jobs are available'
        );
    }

    public function test_job_url_goes_through_jobs_out_when_job_found(): void
    {
        $mockJobs = [[
            'title'       => 'Test Job',
            'url'         => 'https://jobs.example.com/job/456',
            'external_id' => 'ext-456',
            'score'       => 100,
            'salary_text' => null,
        ]];

        $result = $this->runPage('jobs.php', [
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'provider' => 'jooble_fallback',
        ], $mockJobs);

        // The job link should point to jobs-out.php with the job_url param
        $this->assertStringContainsString('jobs-out.php', $result['output']);
        $this->assertStringContainsString('job_url=', $result['output']);
    }

    // ----------------------------------------------------------------
    // job_click_id embedded in job link
    // ----------------------------------------------------------------

    public function test_job_link_contains_job_click_id(): void
    {
        $mockJobs = [[
            'title'       => 'Nurse',
            'url'         => 'https://jobs.talroo.com/apply/link-id-test',
            'external_id' => 'ext-link-id',
            'score'       => 100,
            'salary_text' => null,
        ]];

        $result = $this->runPage('jobs.php', [
            'keyword'  => 'nurse',
            'city'     => 'Columbia',
            'state'    => 'SC',
            'provider' => 'talroo',
        ], $mockJobs);

        // jobs.php inserts a job_click row and embeds its ID in the link to jobs-out.php
        $this->assertNotEmpty($result['job_clicks'], 'job_clicks row must be inserted');
        $insertedId = $result['job_clicks'][0]['id'];
        $this->assertStringContainsString(
            'job_click_id=' . $insertedId,
            $result['output'],
            'Link to jobs-out.php must embed the job_click_id'
        );
    }

    // ----------------------------------------------------------------
    // UTM tracking fields stored correctly
    // ----------------------------------------------------------------

    public function test_utm_fields_stored_in_job_click(): void
    {
        $result = $this->runPage('jobs.php', [
            'keyword'      => 'chef',
            'location'     => '10001',
            'provider'     => 'talroo',
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'summer_2026',
            'utm_id'       => 'utm-abc-123',
        ]);

        $this->assertNotEmpty($result['job_clicks']);
        $row = $result['job_clicks'][0];
        $this->assertSame('facebook',     $row['utm_source']);
        $this->assertSame('cpc',          $row['utm_medium']);
        $this->assertSame('summer_2026',  $row['utm_campaign']);
        $this->assertSame('utm-abc-123',  $row['utm_id']);
    }
}
