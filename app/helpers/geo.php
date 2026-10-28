<?php
/**
 * Where the visitor is, well enough to guess what money they think in.
 *
 * This runs on the first request of every human visit, so the rule it is
 * written to is: **it may never be the reason a page is slow, and it may
 * never be asked twice for the same person.** Everything below follows from
 * that.
 *
 *   1. A CDN already knows. Cloudflare puts the country in a request header
 *      it set before PHP started, which costs nothing and cannot fail.
 *      AWS CloudFront and Vercel do the same under their own names.
 *   2. Otherwise a free lookup service, once, with a short timeout and a
 *      circuit breaker - one slow third party must not make the shop slow.
 *   3. Otherwise the browser's own Accept-Language, which carries a country
 *      often enough to beat guessing.
 *   4. Otherwise nothing, and the shop shows its base currency.
 *
 * The answer is then kept in the visitor's own cookie, so steps 2 and 3
 * happen **once per visitor**, not once per request. There is deliberately no
 * server-side IP cache: a human gets a cookie on their first page, and the
 * one population that arrives without cookies over and over - crawlers - is
 * refused a lookup at step 0 anyway. A cache for that would be a table, a
 * file lock and a pruning job bought for nothing.
 */

/** How the country is found. The admin chooses; 'auto' walks the whole list. */
const GEO_PROVIDERS = [
    'auto' => [
        'label' => 'Automatic - CDN header, then a lookup service',
        'hint'  => 'Tries everything, cheapest first. The right answer unless you have a reason.',
    ],
    'cdn' => [
        'label' => 'CDN header only (Cloudflare and friends)',
        'hint'  => 'No outbound calls ever. Needs Cloudflare, CloudFront or Vercel in front of '
                 . 'the site; without one, nothing is detected and everyone sees the base currency.',
    ],
    'ipapi_co' => [
        'label' => 'ipapi.co - free, 1,000 lookups a day, HTTPS',
        'hint'  => 'Used when no CDN header is present. One lookup per new visitor, not per page.',
    ],
    'ip_api_com' => [
        'label' => 'ip-api.com - free, 45 lookups a minute',
        'hint'  => 'A higher daily ceiling than ipapi.co, but the free tier is plain HTTP, so the '
                 . 'lookup travels unencrypted. Only a country code either way.',
    ],
    'language' => [
        'label' => 'Browser language only',
        'hint'  => 'No outbound calls and no CDN needed. Reads the country out of Accept-Language, '
                 . 'which is a guess - a Pakistani reading English gets en-US.',
    ],
    'off' => [
        'label' => 'Off - everyone sees the base currency',
        'hint'  => 'Nothing is detected and no currency is ever added by itself. The switcher in '
                 . 'the header still works.',
    ],
];

/** How long a lookup service may hold the page up. */
const GEO_HTTP_TIMEOUT = 2;

/** How long we stop calling a service that has just failed us. */
const GEO_BREAKER_SECONDS = 900;

/** Which provider is in force. */
function geo_provider(): string
{
    $chosen = (string) setting('geo_provider', 'auto');

    return isset(GEO_PROVIDERS[$chosen]) ? $chosen : 'auto';
}

/**
 * Is this request a robot?
 *
 * Asked before anything else, because a crawler has no currency, keeps no
 * cookie, and arrives tens of thousands of times. Letting one through would
 * mean a lookup per request forever - and it would put a converted price in
 * front of a search engine, which should always see the shop's own.
 */
function geo_is_robot(): bool
{
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($agent === '') {
        return true;    // no agent at all is a script, not a shopper
    }

    foreach (['bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests', 'httpclient',
              'headlesschrome', 'phantomjs', 'facebookexternalhit', 'preview', 'monitor',
              'uptime', 'pingdom', 'lighthouse', 'scrapy', 'go-http-client', 'java/'] as $mark) {
        if (str_contains($agent, $mark)) {
            return true;
        }
    }

    return false;
}

/**
 * The country a CDN in front of us already worked out, as ISO 3166-1 alpha-2.
 *
 * Free in every sense: the header was set before PHP was reached, so there is
 * nothing to time out, nothing to cache and nothing to go wrong.
 */
function geo_country_from_cdn(): string
{
    // A header is only evidence when something we trust set it. `CF-IPCountry`
    // is an ordinary request header: on a site that is *not* behind a CDN,
    // anybody can send one and choose what country we think they are in -
    // which would let a stranger make the shop add a currency and go and
    // fetch a rate for it. So it is read only where the admin has said there
    // is a CDN in front, and off is the default, because off is the state
    // that cannot be abused.
    if (setting('geo_behind_cdn', '0') !== '1') {
        return '';
    }

    foreach (['HTTP_CF_IPCOUNTRY',            // Cloudflare
              'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront
              'HTTP_X_VERCEL_IP_COUNTRY',     // Vercel
              'HTTP_X_GEO_COUNTRY',           // some reverse proxies
              'HTTP_X_COUNTRY_CODE'] as $header) {
        $value = strtolower(trim((string) ($_SERVER[$header] ?? '')));
        // Cloudflare answers XX for an address it cannot place and T1 for Tor.
        if (preg_match('/^[a-z]{2}$/', $value) && !in_array($value, ['xx', 't1'], true)) {
            return $value;
        }
    }

    return '';
}

/**
 * The country out of Accept-Language: `en-GB,en;q=0.9` is GB.
 *
 * A guess, and a weak one - it says which language the browser was set up
 * in, not where the person is - so it is only ever the last thing tried.
 */
function geo_country_from_language(): string
{
    $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');

    if (preg_match('/[a-z]{2,3}[-_]([A-Za-z]{2})\b/', $header, $found)) {
        return strtolower($found[1]);
    }

    return '';
}

/**
 * Ask a lookup service, once, with the page's patience in mind.
 *
 * A third party that has started timing out must not be asked again on the
 * next visitor's first page: one failure closes the circuit for fifteen
 * minutes, which turns an outage into "everyone sees the base currency for a
 * while" instead of "every page takes two seconds longer".
 */
function geo_country_from_service(string $provider): string
{
    $ip = client_ip();

    // A private or empty address tells a lookup service nothing about where
    // the visitor is - it is us, or a proxy that did not pass the real one.
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }

    if ((int) setting('geo_breaker_until', '0') > time()) {
        return '';
    }

    [$url, $plain] = $provider === 'ip_api_com'
        ? ['http://ip-api.com/line/' . rawurlencode($ip) . '?fields=countryCode', true]
        : ['https://ipapi.co/' . rawurlencode($ip) . '/country/', false];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GEO_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => GEO_HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => !$plain,
        CURLOPT_SSL_VERIFYHOST => $plain ? 0 : 2,
        CURLOPT_USERAGENT      => 'OTPFlash/1.0',
    ]);
    $body  = (string) curl_exec($ch);
    $bad   = curl_errno($ch) !== 0 || (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400;
    curl_close($ch);

    if ($bad) {
        set_setting('geo_breaker_until', (string) (time() + GEO_BREAKER_SECONDS));
        log_line('Geo lookup failed on ' . $provider . '; not asking again for '
            . (GEO_BREAKER_SECONDS / 60) . ' minutes.');
        return '';
    }

    $code = strtolower(trim($body));

    return preg_match('/^[a-z]{2}$/', $code) ? $code : '';
}

/**
 * The visitor's country, by whatever means the admin allows.
 *
 * Worked out at most once per request, and in practice once per visitor:
 * whoever calls this writes the answer into a cookie and never comes back.
 */
function geo_country(): string
{
    static $country = null;
    if ($country !== null) {
        return $country;
    }

    $provider = geo_provider();
    if ($provider === 'off' || geo_is_robot()) {
        return $country = '';
    }

    // Always free, so always first - even when the admin has named a service,
    // because a header that is already there beats a call that is not.
    $found = geo_country_from_cdn();

    if ($found === '' && in_array($provider, ['auto', 'ipapi_co', 'ip_api_com'], true)) {
        $found = geo_country_from_service($provider === 'auto' ? 'ipapi_co' : $provider);
    }

    if ($found === '' && in_array($provider, ['auto', 'language'], true)) {
        $found = geo_country_from_language();
    }

    return $country = $found;
}

/** The currency a country spends, or '' when we do not know it. */
function geo_currency_for_country(string $iso2): string
{
    static $map = null;
    $map ??= (require APP_PATH . '/helpers/currencies-data.php')['country'];

    return (string) ($map[strtolower(trim($iso2))] ?? '');
}
