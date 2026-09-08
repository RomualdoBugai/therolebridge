<?php

/**
 * Small helper to safely read query string parameters.
 *
 * @param string      $key
 * @param string|null $default
 * @param int|null    $maxLen  Optional max length (for safety)
 * @return string|null
 */
if (!function_exists('jld_get_query_param')) {
    function jld_get_query_param(string $key, ?string $default = '', ?int $maxLen = null): ?string
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        $value = trim((string) $_GET[$key]);

        if ($maxLen !== null && $maxLen > 0) {
            $value = mb_substr($value, 0, $maxLen);
        }

        return $value;
    }
}

// ---------------------------------------------------------------------
// Page-level defaults (can be overridden before including this file)
// ---------------------------------------------------------------------
$host = $_SERVER['HTTP_HOST'] ?? '';

$bareHost = preg_replace('/^www\./', '', $host);
$p = 'trb';

$siteTitle   = 'The Role Bridge';
$pageTitle   = 'The Role Bridge';
$pageDesc    = 'The Role Bridge job search platform';
$pageAuthor  = 'Jeferson Martins';
$pageUrl     = 'https://therolebridge.com/';
$pageEmail   = 'jeferson.martins@therolebridge.com';
$logoDark    = "$p-logo-dark.png";
$logoWhite   = "$p-logo-white.png";
$logoFavicon = "$p-favicon.ico";
$customCss   = "$p-custom.css";

// ---------------------------------------------------------------------
// Query params (normalized)
// ---------------------------------------------------------------------
$email       = jld_get_query_param('email', '', 190);
$keyword     = jld_get_query_param('keyword', '');
$location    = jld_get_query_param('location', '');
$city        = jld_get_query_param('city', '');
$state       = jld_get_query_param('state', '');
$zip         = jld_get_query_param('zip', '');
$src         = jld_get_query_param('src', '');
$clickSource = jld_get_query_param('click_source', '');
$utmSource   = jld_get_query_param('utm_source', '');
$utmMedium   = jld_get_query_param('utm_medium', '');
$utmCampaign = jld_get_query_param('utm_campaign', '');

$contactId   = jld_get_query_param('cid', null);
$campaignId  = jld_get_query_param('cmpid', null);
$utmId       = jld_get_query_param('utm_id', '');
$ipAddress   = resolveClientIp();

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255)
    : null;

// ---------------------------------------------------------------------
// Short aliases ?k=&l= support
// ---------------------------------------------------------------------
if ($keyword === '' && isset($_GET['k'])) {
    $keyword = trim((string) $_GET['k']);
}
if ($location === '' && isset($_GET['l'])) {
    $location = trim((string) $_GET['l']);
}

// Se não veio city/state/zip na URL, tenta resolver pelo IP (Leads facebook)
$noLocation = $city === '' && $state === '' && $zip === '';
$hasLead    = $email !== '' && $keyword !== '';
$hasIp      = $ipAddress !== '' && $ipAddress !== '0.0.0.0';

if ($noLocation && $hasLead && $hasIp) {
    [$city, $state, $zip, $geoCountry] = geoLookupCityStateZipByIp($ipAddress);
    if ($geoCountry !== 'US') {
        $city = $state = $zip = '';
    } else {
        geoEnrichLeadLocation($email, $city, $state, $zip);
    }
}

if (
    ($keyword !== '' && $location === '' && $utmSource === '' && $utmMedium === '' && $utmCampaign === '')
) {
    $_GET['provider'] = 'jooble_fallback';
}

// ---------------------------------------------------------------------
// Provider selection
// ---------------------------------------------------------------------
$provider = selectProviderByTrafficSplit();

// ---------------------------------------------------------------------
// Human-friendly location label
// ---------------------------------------------------------------------
$parts = array_filter([$city, strtoupper($state)]);
$locationLabel = implode(', ', $parts);
if ($zip !== '') {
    $locationLabel = trim($locationLabel . ' ' . $zip);
}
$locationLabel = $locationLabel ?: ($location ?: 'United States');

// ---------------------------------------------------------------------
// Forward params (base query for links, without page)
// ---------------------------------------------------------------------
$forwardParams = $_GET;

// Remove page param to avoid carrying pagination around
unset($forwardParams['page']);

$forwardParams['provider'] = $provider;

foreach (['keyword' => $keyword, 'location' => $location, 'city' => $city, 'state' => $state, 'zip' => $zip] as $key => $val) {
    if (($forwardParams[$key] ?? '') === '' && $val !== '') {
        $forwardParams[$key] = $val;
    }
}

// Used in breadcrumb "Home" to preserve filters (without page)
$linkCompleteHref = '?' . http_build_query($forwardParams);
