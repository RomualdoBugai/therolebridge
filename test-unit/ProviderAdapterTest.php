<?php

declare(strict_types=1);

/**
 * Tests for provider adapter functions (pure transformations, no DB/HTTP).
 */
class ProviderAdapterTest extends TestCase
{
    // ----------------------------------------------------------------
    // joobleFallBackAdaptJob
    // ----------------------------------------------------------------

    public function test_joobleFallbackAdapter_basic_fields(): void
    {
        $raw = [
            'title'    => 'RN ICU',
            'company'  => 'General Hospital',
            'location' => 'Columbia, SC',
            'snippet'  => 'Great opportunity',
            'link'     => 'https://jooble.org/jdp/-1234567890',
            'salary'   => '$50k/yr',
            'updated'  => '2026-01-15',
        ];

        $job = joobleFallBackAdaptJob($raw);

        $this->assertSame('RN ICU', $job['title']);
        $this->assertSame('General Hospital', $job['company']);
        $this->assertSame('Columbia, SC', $job['location_label']);
        $this->assertSame('Columbia', $job['city']);
        $this->assertSame('SC', $job['state']);
        $this->assertSame('https://jooble.org/jdp/-1234567890', $job['url']);
        $this->assertSame('$50k/yr', $job['salary_text']);
        $this->assertSame('-1234567890', $job['external_id']);
    }

    public function test_joobleFallbackAdapter_missing_link_defaults_hash(): void
    {
        $job = joobleFallBackAdaptJob(['title' => 'Driver']);
        $this->assertSame('Driver', $job['title']);
        $this->assertSame('#', $job['url']);
    }

    public function test_joobleFallbackAdapter_default_title(): void
    {
        $job = joobleFallBackAdaptJob([]);
        $this->assertSame('Job Opportunity', $job['title']);
    }

    // ----------------------------------------------------------------
    // talrooAdaptJob
    // ----------------------------------------------------------------

    public function test_talrooAdapter_basic_fields(): void
    {
        $raw = [
            'id'          => 'talroo-999',
            'title'       => 'Warehouse Worker',
            'company'     => 'Acme Corp',
            'city'        => ['Dallas, TX'],
            'description' => 'Night shift available',
            'url'         => 'https://jobs.talroo.com/apply/999',
            'price'       => 85,
            'salary_details' => [['label' => '$18-$22/hr']],
        ];

        $job = talrooAdaptJob($raw);

        $this->assertSame('Warehouse Worker', $job['title']);
        $this->assertSame('Acme Corp', $job['company']);
        $this->assertSame('Dallas, TX', $job['location_label']);
        $this->assertSame('Dallas', $job['city']);
        $this->assertSame('TX', $job['state']);
        $this->assertSame('talroo-999', $job['external_id']);
        $this->assertSame(85, $job['score']);
        $this->assertSame('$18-$22/hr', $job['salary_text']);
    }

    public function test_talrooAdapter_city_as_string(): void
    {
        $raw = ['title' => 'Driver', 'city' => 'Miami, FL', 'url' => 'https://example.com'];
        $job = talrooAdaptJob($raw);
        $this->assertSame('Miami', $job['city']);
        $this->assertSame('FL', $job['state']);
    }

    public function test_talrooAdapter_no_salary_details_null(): void
    {
        $raw = ['title' => 'Cook', 'url' => 'https://example.com'];
        $job = talrooAdaptJob($raw);
        $this->assertNull($job['salary_text']);
    }

    public function test_talrooAdapter_has_required_keys(): void
    {
        $job = talrooAdaptJob(['title' => 'Driver', 'url' => 'https://example.com']);
        $requiredKeys = ['external_id', 'title', 'company', 'location_label', 'city',
                         'state', 'country', 'snippet', 'url', 'salary_text', 'score', 'raw'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $job, "Missing key: $key");
        }
    }
}
