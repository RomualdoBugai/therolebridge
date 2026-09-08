<?php

declare(strict_types=1);

/**
 * Tests for pure helper functions in functions.php.
 * No real API calls, no external dependencies.
 */
class HelperFunctionsTest extends TestCase
{
    // ----------------------------------------------------------------
    // parseLocationString
    // ----------------------------------------------------------------

    public function test_parseLocation_zip5(): void
    {
        $this->assertSame(['', '', '29063'], parseLocationString('29063'));
    }

    public function test_parseLocation_zip_plus4(): void
    {
        $this->assertSame(['', '', '29063'], parseLocationString('29063-1234'));
    }

    public function test_parseLocation_city_state_comma(): void
    {
        $this->assertSame(['Columbia', 'SC', ''], parseLocationString('Columbia, SC'));
    }

    public function test_parseLocation_city_state_no_comma(): void
    {
        $this->assertSame(['Columbia', 'SC', ''], parseLocationString('Columbia SC'));
    }

    public function test_parseLocation_state_only(): void
    {
        $this->assertSame(['', 'SC', ''], parseLocationString('SC'));
    }

    public function test_parseLocation_city_only(): void
    {
        $this->assertSame(['Orlando', '', ''], parseLocationString('Orlando'));
    }

    public function test_parseLocation_empty(): void
    {
        $this->assertSame(['', '', ''], parseLocationString(''));
    }

    public function test_parseLocation_null(): void
    {
        $this->assertSame(['', '', ''], parseLocationString(null));
    }

    // ----------------------------------------------------------------
    // appendProviderSubId
    // ----------------------------------------------------------------

    public function test_appendSubId_talroo_no_existing_query(): void
    {
        $url = appendProviderSubId('talroo', 'https://jobs.talroo.com/apply/123', 42);
        $this->assertStringContainsString('t1=42', $url);
    }

    public function test_appendSubId_talroo_with_existing_query(): void
    {
        $url = appendProviderSubId('talroo', 'https://jobs.talroo.com/apply/123?foo=bar', 99);
        $this->assertStringContainsString('&t1=99', $url);
    }

    public function test_appendSubId_talroo2_uses_t1(): void
    {
        $url = appendProviderSubId('talroo2', 'https://example.com/job', 7);
        $this->assertStringContainsString('t1=7', $url);
    }

    public function test_appendSubId_other_provider_unchanged(): void
    {
        $original = 'https://example.com/job';
        $url = appendProviderSubId('indeed', $original, 10, 'source');
        $this->assertSame($original, $url);
    }

    public function test_appendSubId_talroo_zero_id_unchanged(): void
    {
        $original = 'https://jobs.talroo.com/apply/123';
        $url = appendProviderSubId('talroo', $original, 0);
        $this->assertSame($original, $url);
    }

    public function test_appendSubId_empty_url_unchanged(): void
    {
        $url = appendProviderSubId('talroo', '', 5);
        $this->assertSame('', $url);
    }

    // ----------------------------------------------------------------
    // updateQueryStringParam
    // ----------------------------------------------------------------

    public function test_updateQueryString_adds_new_param(): void
    {
        $result = updateQueryStringParam('?foo=bar', 'baz', 'qux');
        $this->assertStringContainsString('baz=qux', $result);
        $this->assertStringContainsString('foo=bar', $result);
    }

    public function test_updateQueryString_updates_existing(): void
    {
        $result = updateQueryStringParam('?provider=talroo', 'provider', 'jooble');
        $this->assertStringContainsString('provider=jooble', $result);
        $this->assertStringNotContainsString('talroo', $result);
    }

    public function test_updateQueryString_empty_string_start(): void
    {
        $result = updateQueryStringParam('', 'key', 'val');
        $this->assertSame('?key=val', $result);
    }

    public function test_updateQueryString_null_value_removes_param(): void
    {
        $result = updateQueryStringParam('?foo=bar&baz=qux', 'foo', null);
        $this->assertStringNotContainsString('foo=bar', $result);
        $this->assertStringContainsString('baz=qux', $result);
    }

    // ----------------------------------------------------------------
    // getUsStateName
    // ----------------------------------------------------------------

    public function test_getUsStateName_known_state(): void
    {
        $this->assertSame('South Carolina', getUsStateName('SC'));
    }

    public function test_getUsStateName_lowercase_normalized(): void
    {
        $this->assertSame('Texas', getUsStateName('tx'));
    }

    public function test_getUsStateName_unknown_returns_empty(): void
    {
        $this->assertSame('', getUsStateName('ZZ'));
    }

    // ----------------------------------------------------------------
    // buildTalrooLocationAttempts (from provider_jobs.php)
    // ----------------------------------------------------------------

    public function test_talrooLocationAttempts_full(): void
    {
        $attempts = buildTalrooLocationAttempts('29063', 'Irmo', 'SC');
        $this->assertContains('29063', $attempts);
        $this->assertContains('Irmo, SC', $attempts);
        $this->assertContains('South Carolina', $attempts);
    }

    public function test_talrooLocationAttempts_city_state_only(): void
    {
        $attempts = buildTalrooLocationAttempts('', 'Austin', 'TX');
        $this->assertContains('Austin, TX', $attempts);
        $this->assertNotContains('', $attempts);
    }

    public function test_talrooLocationAttempts_empty_gives_empty(): void
    {
        $attempts = buildTalrooLocationAttempts('', '', '');
        $this->assertEmpty($attempts);
    }

    // ----------------------------------------------------------------
    // cleanSnippet
    // ----------------------------------------------------------------

    public function test_cleanSnippet_strips_html(): void
    {
        $result = cleanSnippet('<p>Hello <strong>World</strong></p>');
        $this->assertSame('Hello World', $result);
    }

    public function test_cleanSnippet_truncates(): void
    {
        $result = cleanSnippet(str_repeat('a', 200), 50);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(53, strlen($result));
    }

    // ----------------------------------------------------------------
    // formatJobDate
    // ----------------------------------------------------------------

    public function test_formatJobDate_valid(): void
    {
        $this->assertSame('01-15-2026', formatJobDate('2026-01-15T00:00:00'));
    }

    public function test_formatJobDate_null_returns_null(): void
    {
        $this->assertNull(formatJobDate(null));
    }

    public function test_formatJobDate_empty_returns_null(): void
    {
        $this->assertNull(formatJobDate(''));
    }
}
