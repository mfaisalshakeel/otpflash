<?php
/**
 * The catalogue: what OTPGet sells, under our names and at our prices.
 *
 * Three jobs live here because each one leans on the other two:
 *
 *   names    OTPGet's servers each spell a country and a service their own
 *            way ("USA", "United States of America"; "Gmail", "Google").
 *            country_for_name() and service_for_name() put every spelling
 *            on one row, so a customer sees one Pakistan with one flag and
 *            every server's price under it.
 *   prices   cost is what OTPGet charges, in its currency; price is ours,
 *            in the base currency, rebuilt from cost by offer_price() -
 *            the one place the markup is applied.
 *   sync     the catalogue is read one unit at a time (one country on one
 *            server, one email site, one rental country), because a shared
 *            host cuts a request off at thirty seconds and the whole of
 *            OTPGet is hundreds of calls. The admin's Sync button and cron
 *            run the same units.
 */

require_once APP_PATH . '/helpers/OtpGet.php';
require_once APP_PATH . '/helpers/currency.php';

/**
 * Every product OTPGet sells, in the order the shop offers them.
 *
 * `tab` is where it is sold on the storefront: the three number APIs share
 * one picker, because to a customer they are the same thing - a number for
 * WhatsApp in Pakistan - at different prices.
 */
const PRODUCTS = [
    'sms'     => ['name' => 'Virtual numbers', 'option' => 'Server',  'tab' => 'numbers',
                  'blurb' => 'OTPGet\'s main SMS API: six servers, 150+ countries, thousands of apps.'],
    'premium' => ['name' => 'Premium numbers', 'option' => 'Premium', 'tab' => 'numbers',
                  'blurb' => 'Higher success rate; the code can arrive by SMS or by a voice call.'],
    'cheap'   => ['name' => 'Budget numbers',  'option' => 'Budget',  'tab' => 'numbers',
                  'blurb' => 'Fixed number ranges at the lowest price, held for 20 minutes.'],
    'email'   => ['name' => 'Email OTP',       'option' => 'Email',   'tab' => 'email',
                  'blurb' => 'Temporary inboxes on Gmail, Outlook and more, for email verification codes.'],
    'rental'  => ['name' => 'Rental numbers',  'option' => 'Rental',  'tab' => 'rental',
                  'blurb' => 'A number kept for days or months, with an inbox for every SMS it receives.'],
];

/** The storefront tabs, and what each one is called. */
const STORE_TABS = [
    'numbers' => ['label' => 'Virtual Numbers', 'icon' => 'i-phone'],
    'email'   => ['label' => 'Email OTP',       'icon' => 'i-mail'],
    'rental'  => ['label' => 'Rent a Number',   'icon' => 'i-calendar'],
];

/** The rental lengths OTPGet sells, in hours, and what a person calls them. */
const RENTAL_DURATIONS = [
    12   => '12 hours',
    24   => '1 day',
    168  => '1 week',
    720  => '1 month',
    4320 => '6 months',
    8640 => '1 year',
];

/** A rental length in words: the named ones by name, anything else in days or hours. */
function rental_duration_words(int $hours): string
{
    if (isset(RENTAL_DURATIONS[$hours])) {
        return RENTAL_DURATIONS[$hours];
    }
    if ($hours >= 24 && $hours % 24 === 0) {
        return ($hours / 24) . ' days';
    }
    return $hours . ' hours';
}

/** OTPGet, as the admin set it up. */
function otpget(): OtpGet
{
    static $api = null;
    return $api ??= new OtpGet(
        (string) setting('otpget_base_url', 'https://otpget.com/stubs'),
        trim((string) setting('otpget_api_key', '')),
        strtoupper(trim((string) setting('otpget_currency', 'PKR')))
    );
}

/** What OTPGet bills us in. Every cost column is in this. */
function otpget_currency(): string
{
    return strtoupper(trim((string) setting('otpget_currency', 'PKR'))) ?: 'PKR';
}

/** Is there a key at all? Without one there is nothing to sell. */
function otpget_configured(): bool
{
    return trim((string) setting('otpget_api_key', '')) !== '';
}

// ===========================================================================
// What is switched on
// ===========================================================================

/** The products the admin sells, in PRODUCTS order. */
function products_enabled(): array
{
    $chosen = array_map('trim', explode(',', (string) setting('products', 'sms,premium,cheap,email,rental')));
    return array_values(array_intersect(array_keys(PRODUCTS), $chosen));
}

function product_enabled(string $product): bool
{
    return in_array($product, products_enabled(), true);
}

/** The storefront tabs that have at least one product switched on. */
function store_tabs(): array
{
    $tabs = [];
    foreach (products_enabled() as $product) {
        $tab = PRODUCTS[$product]['tab'];
        $tabs[$tab] = STORE_TABS[$tab];
    }
    return array_intersect_key(STORE_TABS, $tabs);
}

/** Products sold under one tab, of those switched on. */
function tab_products(string $tab): array
{
    return array_values(array_filter(products_enabled(),
        static fn (string $product): bool => PRODUCTS[$product]['tab'] === $tab));
}

/**
 * Which servers of a product are on.
 *
 *   sms     the SMS API's types, 1-6
 *   email   1 and 2
 *   rental  providers 1 and 2
 *
 * Premium and Cheap have no server choice of their own, so they answer an
 * empty list, which offer_sellable_sql() reads as "no filter".
 */
function product_servers(string $product): array
{
    $key = match ($product) {
        'sms'    => 'sms_servers',
        'email'  => 'email_servers',
        'rental' => 'rental_providers',
        default  => null,
    };
    if ($key === null) {
        return [];
    }

    $default = $product === 'sms' ? '1,2,3,4,5,6' : '1,2';
    $raw     = array_map('trim', explode(',', (string) setting($key, $default)));
    return array_values(array_filter($raw, static fn ($v) => $v !== '' && ctype_digit($v)));
}

/** The markup on one product, falling back to the shop-wide one. */
function product_markup(string $product): float
{
    $own = trim((string) setting('markup_' . $product, ''));
    return $own !== '' && is_numeric($own) ? (float) $own : (float) setting('default_markup', '30');
}

/**
 * The WHERE clause for "a customer may buy this offer", for alias $o.
 *
 * Written once because the picker, the landing pages, the sitemap and the
 * order form all ask it, and an offer the picker hides but the order form
 * accepts is a price nobody meant to sell at.
 *
 * @return array{0: string, 1: array}
 */
function offer_sellable_sql(string $o = 'o', ?array $products = null): array
{
    $products ??= products_enabled();
    if (!$products) {
        return ['1 = 0', []];
    }

    $parts  = [];
    $params = [];
    foreach ($products as $product) {
        $servers = product_servers($product);
        if (in_array($product, ['sms', 'email', 'rental'], true)) {
            if (!$servers) {
                continue;
            }
            $parts[] = "({$o}.product = ? AND {$o}.server IN ("
                . implode(',', array_fill(0, count($servers), '?')) . '))';
            $params  = array_merge($params, [$product], $servers);
        } else {
            $parts[]  = "{$o}.product = ?";
            $params[] = $product;
        }
    }
    if (!$parts) {
        return ['1 = 0', []];
    }

    return ["{$o}.is_active = 1 AND {$o}.missing = 0 AND {$o}.price > 0 AND (" . implode(' OR ', $parts) . ')',
            $params];
}

// ===========================================================================
// Names
// ===========================================================================

/**
 * A name reduced to what two spellings of it have in common.
 *
 * "Côte d'Ivoire (virtual)" and "cote divoire" both come out as
 * "cote divoire": accents gone, brackets gone, the word "virtual" gone,
 * punctuation turned into single spaces.
 */
function name_key(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $name);
    $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if (is_string($plain) && $plain !== '') {
        $name = strtolower($plain);
    }
    $name = str_replace(['&', '+'], [' and ', ' plus '], $name);
    $name = preg_replace("/['`\"]/", '', $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    $name = preg_replace('/\b(virtual|numbers?|sms|otp)\b/', ' ', $name);

    return trim(preg_replace('/\s+/', ' ', $name));
}

/** Every known country, keyed by ISO code. */
function country_catalogue(): array
{
    static $data = null;
    return $data ??= require APP_PATH . '/helpers/countries-data.php';
}

/** Every known service, keyed by slug. */
function service_catalogue(): array
{
    static $data = null;
    return $data ??= require APP_PATH . '/helpers/services-data.php';
}

/** [name_key => iso] over every name and alias we know. */
function country_name_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (country_catalogue() as $iso => $country) {
        $index[name_key($country['name'])] = $iso;
        foreach ($country['aliases'] as $alias) {
            $index[name_key($alias)] = $iso;
        }
    }
    return $index;
}

/** [name_key => slug] over every service name and alias we know. */
function service_name_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (service_catalogue() as $slug => $service) {
        $index[name_key($service['name'])] = $slug;
        $index[name_key($slug)] = $slug;
        foreach ($service['aliases'] as $alias) {
            $index[name_key($alias)] = $slug;
        }
    }
    return $index;
}

/** The ISO code for a country name, or '' when it is not one we know. */
function country_iso_for(string $name): string
{
    $key = name_key($name);
    if ($key === '') {
        return '';
    }
    if (strlen($key) === 2 && isset(country_catalogue()[$key])) {
        return $key;
    }
    return country_name_index()[$key] ?? '';
}

/**
 * The countries row for a name, made on first sight.
 *
 * A name we cannot place still gets a row - a plain one with no flag -
 * because a country the shop cannot draw is still a country OTPGet sells,
 * and leaving it out is a sale nobody can make. The admin can give it a flag
 * on the Countries screen.
 */
function country_for_name(string $name, string $iso = ''): ?int
{
    static $seen = [];

    $iso = strtolower(trim($iso)) ?: country_iso_for($name);
    $known = $iso !== '' ? (country_catalogue()[$iso] ?? null) : null;
    $label = $known['name'] ?? trim(preg_replace('/\s+/', ' ', $name));
    if ($label === '') {
        return null;
    }
    $slug = slugify($label);

    if (isset($seen[$slug])) {
        return $seen[$slug];
    }

    $id = col('SELECT id FROM countries WHERE slug = ?', [$slug]);
    if (!$id && $iso !== '') {
        // Renamed by an admin: the flag is still the same country.
        $id = col('SELECT id FROM countries WHERE iso2 = ? ORDER BY id LIMIT 1', [$iso]);
    }
    if (!$id) {
        try {
            $id = insert_row('countries', [
                'slug'       => $slug,
                'name'       => mb_substr($label, 0, 120),
                'iso2'       => $iso,
                'dial_code'  => (string) ($known['dial'] ?? ''),
                'is_popular' => !empty($known['popular']) ? 1 : 0,
                'sort_order' => 0,
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // Two syncs naming the same new country at once: the other won.
            require_once APP_PATH . '/helpers/guard.php';
            if (!is_duplicate_key($e)) {
                throw $e;
            }
            $id = col('SELECT id FROM countries WHERE slug = ?', [$slug]);
        }
    }

    return $seen[$slug] = $id ? (int) $id : null;
}

/** The slug of a known service for a name, or '' when it is new to us. */
function service_slug_for(string $name): string
{
    $key = name_key($name);
    if ($key === '') {
        return '';
    }
    $index = service_name_index();
    if (isset($index[$key])) {
        return $index[$key];
    }

    // "Facebook Messenger", "Amazon Prime": a short name that leads with a
    // brand we know is that brand. Only short ones, and only whole words -
    // "Google" inside a ten-word description is not a claim about Google.
    $words = explode(' ', $key);
    if (count($words) <= 3) {
        for ($take = count($words) - 1; $take >= 1; $take--) {
            $head = implode(' ', array_slice($words, 0, $take));
            if (isset($index[$head]) && strlen($head) >= 3) {
                return $index[$head];
            }
        }
    }
    return '';
}

/** The services row for a name, made on first sight. Same bargain as countries. */
function service_for_name(string $name): ?int
{
    static $seen = [];

    $slug  = service_slug_for($name);
    $known = $slug !== '' ? service_catalogue()[$slug] : null;
    $label = $known['name'] ?? trim(preg_replace('/\s+/', ' ', $name));
    if ($label === '') {
        return null;
    }
    if ($slug === '') {
        $slug = slugify($label);
    }

    if (isset($seen[$slug])) {
        return $seen[$slug];
    }

    $id = col('SELECT id FROM services WHERE slug = ?', [$slug]);
    if (!$id) {
        try {
            $id = insert_row('services', [
                'slug'        => $slug,
                'name'        => mb_substr($label, 0, 120),
                'icon'        => (string) ($known['icon'] ?? ''),
                'color'       => (string) ($known['color'] ?? service_color_for($label)),
                'site_domain' => '',
                'is_popular'  => !empty($known['popular']) ? 1 : 0,
                'sort_order'  => 0,
                'is_active'   => 1,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            require_once APP_PATH . '/helpers/guard.php';
            if (!is_duplicate_key($e)) {
                throw $e;
            }
            $id = col('SELECT id FROM services WHERE slug = ?', [$slug]);
        }
    }

    return $seen[$slug] = $id ? (int) $id : null;
}

/** The service a website belongs to, for email server 2's "site". */
function service_for_site(string $site): ?int
{
    $site = strtolower(trim(preg_replace('~^https?://(www\.)?~i', '', $site), '/ '));
    if ($site === '') {
        return null;
    }
    $own = col('SELECT id FROM services WHERE site_domain = ?', [$site]);
    if ($own) {
        return (int) $own;
    }
    // instagram.com -> instagram; twitter.com -> X, through the alias.
    $head = explode('.', $site)[0];
    return service_for_name($head);
}

/**
 * A steady colour for a service with no brand colour of its own.
 *
 * Picked from the name, so "Acme" is the same colour on every page and
 * after every sync, rather than a new one each time it is drawn.
 */
function service_color_for(string $name): string
{
    $palette = ['#6c4df6', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#ec4899',
                '#8b5cf6', '#14b8a6', '#f97316', '#3b82f6', '#84cc16', '#a855f7'];
    return $palette[crc32(strtolower($name)) % count($palette)];
}

/**
 * Split a cheap server's name - "Guinea WhatsApp" - into its country and
 * its service.
 *
 * Cheap numbers are sold as named ranges rather than as a country and a
 * service, but the name almost always says both, and splitting it is what
 * lets them sit in the same picker as everything else. Every split point is
 * tried and the first that reads as a known country on the left is taken.
 *
 * @return array{country: string, service: string}
 */
function split_range_name(string $name): array
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    for ($i = min(4, count($words) - 1); $i >= 1; $i--) {
        $left  = implode(' ', array_slice($words, 0, $i));
        $right = implode(' ', array_slice($words, $i));
        if (country_iso_for($left) !== '') {
            return ['country' => $left, 'service' => $right];
        }
    }
    // Service first, country last: "WhatsApp Guinea".
    for ($i = 1; $i < count($words); $i++) {
        $left  = implode(' ', array_slice($words, 0, $i));
        $right = implode(' ', array_slice($words, $i));
        if (country_iso_for($right) !== '') {
            return ['country' => $right, 'service' => $left];
        }
    }
    return ['country' => '', 'service' => trim($name)];
}

// ===========================================================================
// Prices
// ===========================================================================

/**
 * Our price for something that costs us $cost in $currency.
 *
 * Returns 0 when there is no rate for $currency: a price worked out from a
 * guessed rate is worse than no price, and 0 is what offer_sellable_sql()
 * reads as "not for sale".
 */
function offer_price(string $product, float $cost, string $currency = ''): float
{
    $base = to_base($cost, $currency !== '' ? $currency : otpget_currency());
    if ($base === null || $base <= 0) {
        return 0.0;
    }

    $price = $base * (1 + product_markup($product) / 100);
    $price = max($price, (float) setting('min_price', '0'));

    // Rounded up, never down: rounding is a presentation choice and must not
    // be the thing that turns a thin margin into a loss.
    $step = (float) setting('price_rounding', '1');
    if ($step > 0) {
        $price = ceil(round($price / $step, 6)) * $step;
    }

    return round($price, 2);
}

/**
 * Rebuild every price from its cost.
 *
 * Run after the markup, the rounding or an exchange rate changes. Only rows
 * whose price actually moves are written, so running it twice is cheap.
 *
 * @return int how many prices changed
 */
function reprice_offers(): int
{
    $changed = 0;
    foreach (all('SELECT id, product, cost, cost_currency, price FROM offers') as $offer) {
        $price = offer_price($offer['product'], (float) $offer['cost'], (string) $offer['cost_currency']);
        if (abs($price - (float) $offer['price']) >= 0.005) {
            q('UPDATE offers SET price = ? WHERE id = ?', [$price, $offer['id']]);
            $changed++;
        }
    }
    return $changed;
}

/** What the customer pays for an offer: the admin's pin if there is one. */
function offer_sale_price(array $offer): float
{
    return $offer['price_override'] !== null && $offer['price_override'] !== ''
        ? round((float) $offer['price_override'], 2)
        : round((float) $offer['price'], 2);
}

/** "Server 2", "Premium", "Gmail.com", "1 month" - what an option is called. */
function offer_option_label(array $offer): string
{
    return match ((string) $offer['product']) {
        'sms'     => trim((string) setting('server_label_' . $offer['server'], '')) ?: 'Server ' . $offer['server'],
        'premium' => trim((string) setting('server_label_premium', '')) ?: 'Premium · SMS or call',
        'cheap'   => trim((string) setting('server_label_cheap', '')) ?: 'Budget',
        'email'   => (string) $offer['provider_country'] . ' · Server ' . $offer['server'],
        'rental'  => rental_duration_words((int) $offer['provider_service'])
                     . ' · Server ' . $offer['server'],
        default   => (string) $offer['product'],
    };
}

// ===========================================================================
// Sync
// ===========================================================================

/** Read a cached JSON file from storage/cache, or null when stale or absent. */
function catalogue_cache_read(string $name, int $maxAgeSeconds = 0): ?array
{
    $file = STORAGE_PATH . '/cache/' . $name . '.json';
    if (!is_file($file) || ($maxAgeSeconds > 0 && filemtime($file) < time() - $maxAgeSeconds)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function catalogue_cache_write(string $name, array $data): void
{
    @file_put_contents(STORAGE_PATH . '/cache/' . $name . '.json', json_encode($data), LOCK_EX);
}

/**
 * Fetch every list that decides which units exist, and keep them.
 *
 * About ten calls whatever the size of the catalogue - one per SMS server,
 * one for Premium, one per rental provider, one for the email domains - so
 * it is safe to do inside one request. A list that fails keeps its last
 * good copy, so one unreachable server does not empty the shop.
 *
 * @return array{lists: array, errors: string[]}
 */
function sync_lists_refresh(): array
{
    $api    = otpget();
    $lists  = catalogue_cache_read('sync-lists') ?? [];
    $errors = [];

    if (product_enabled('sms')) {
        $servers = $api->smsServers();
        $active  = isset($servers['error'])
            ? product_servers('sms')
            : array_column(array_filter($servers['servers'], static fn ($s) => $s['active']), 'id');

        foreach (product_servers('sms') as $type) {
            if (!in_array($type, $active, true)) {
                $lists['sms'][$type] = [];
                continue;
            }
            $countries = $api->smsCountries((int) $type);
            if (isset($countries['error'])) {
                $errors[] = 'Server ' . $type . ': ' . $countries['message'];
                continue;
            }
            $lists['sms'][$type] = $countries;
        }
    }

    if (product_enabled('premium')) {
        $countries = $api->premiumCountries();
        if (isset($countries['error'])) {
            $errors[] = 'Premium: ' . $countries['message'];
        } else {
            $lists['premium'] = $countries;
        }
    }

    if (product_enabled('email') && in_array('1', product_servers('email'), true)) {
        $domains = $api->emailDomains();
        if (isset($domains['error'])) {
            $errors[] = 'Email server 1: ' . $domains['message'];
        } else {
            $lists['email1'] = array_values(array_filter(array_map(
                static fn ($d) => strtolower(trim((string) ($d['domain'] ?? ''))), $domains['domains'])));
        }
    }

    if (product_enabled('rental')) {
        foreach (product_servers('rental') as $provider) {
            $reply = $api->rentalCountries((int) $provider);
            if (isset($reply['error'])) {
                $errors[] = 'Rental server ' . $provider . ': ' . $reply['message'];
                continue;
            }
            $rows = [];
            foreach ((array) ($reply['countries'] ?? []) as $row) {
                if (is_array($row) && !empty($row['country_code'])) {
                    $rows[] = ['code' => strtolower((string) $row['country_code']),
                               'name' => (string) ($row['name'] ?? $row['country_code'])];
                }
            }
            $lists['rental'][$provider] = $rows;
        }
    }

    $lists['at'] = time();
    catalogue_cache_write('sync-lists', $lists);
    retire_vanished_units($lists);

    return ['lists' => $lists, 'errors' => $errors];
}

/**
 * Hide offers whose country or server dropped out of the lists.
 *
 * A unit that is no longer listed is never synced again, so nothing else
 * would ever notice that its offers are gone.
 */
function retire_vanished_units(array $lists): void
{
    foreach ((array) ($lists['sms'] ?? []) as $type => $countries) {
        $ids = array_column($countries, 'id');
        retire_offers_except('sms', (string) $type, $ids);
    }
    if (isset($lists['premium'])) {
        retire_offers_except('premium', '', array_column($lists['premium'], 'id'));
    }
    if (isset($lists['email1'])) {
        retire_offers_except('email', '1', $lists['email1']);
    }
    foreach ((array) ($lists['rental'] ?? []) as $provider => $countries) {
        retire_offers_except('rental', (string) $provider, array_column($countries, 'code'));
    }
}

/** Mark missing every offer of a product/server outside the given provider countries. */
function retire_offers_except(string $product, string $server, array $keep): void
{
    $sql    = 'UPDATE offers SET missing = 1 WHERE product = ? AND server = ? AND missing = 0';
    $params = [$product, $server];
    if ($keep) {
        $sql   .= ' AND provider_country NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
        $params = array_merge($params, array_map('strval', $keep));
    }
    q($sql, $params);
}

/**
 * Every unit the catalogue is made of, from the cached lists.
 *
 * @return array<int, array{key: string, label: string}>
 */
function sync_units(?array $lists = null): array
{
    $lists ??= catalogue_cache_read('sync-lists') ?? [];
    $units = [];

    if (product_enabled('sms')) {
        foreach (product_servers('sms') as $type) {
            foreach ((array) ($lists['sms'][$type] ?? []) as $country) {
                $units[] = ['key' => 'sms:' . $type . ':' . $country['id'],
                            'label' => 'Server ' . $type . ' - ' . $country['name']];
            }
        }
    }
    if (product_enabled('premium')) {
        foreach ((array) ($lists['premium'] ?? []) as $country) {
            $units[] = ['key' => 'premium::' . $country['id'], 'label' => 'Premium - ' . $country['name']];
        }
    }
    if (product_enabled('cheap')) {
        $units[] = ['key' => 'cheap::', 'label' => 'Budget numbers'];
    }
    if (product_enabled('email')) {
        if (in_array('1', product_servers('email'), true)) {
            foreach ((array) ($lists['email1'] ?? []) as $domain) {
                $units[] = ['key' => 'email:1:' . $domain, 'label' => 'Email - ' . $domain];
            }
        }
        if (in_array('2', product_servers('email'), true)) {
            foreach (email_sites() as $site) {
                $units[] = ['key' => 'email:2:' . $site, 'label' => 'Email for ' . $site];
            }
        }
    }
    if (product_enabled('rental')) {
        foreach (product_servers('rental') as $provider) {
            foreach ((array) ($lists['rental'][$provider] ?? []) as $country) {
                $units[] = ['key' => 'rental:' . $provider . ':' . $country['code'],
                            'label' => 'Rental ' . $provider . ' - ' . $country['name']];
            }
        }
    }

    return $units;
}

/** The websites email server 2 is asked about. */
function email_sites(): array
{
    $raw = preg_split('/[\s,]+/', strtolower((string) setting('email_sites', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($raw as $site) {
        $site = trim(preg_replace('~^https?://(www\.)?~', '', $site), '/');
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $site)) {
            $out[$site] = $site;
        }
    }
    return array_values($out);
}

/**
 * Read one unit from OTPGet and bring its offers up to date.
 *
 * @return array{ok: bool, count: int, message: string}
 */
function sync_unit(string $key): array
{
    [$product, $server, $where] = array_pad(explode(':', $key, 3), 3, '');
    $api   = otpget();
    $lists = catalogue_cache_read('sync-lists') ?? [];
    $rows  = [];

    switch ($product) {
        case 'sms':
            $countryName = '';
            foreach ((array) ($lists['sms'][$server] ?? []) as $country) {
                if ((string) $country['id'] === $where) {
                    $countryName = $country['name'];
                }
            }
            $reply = $api->smsServices($where, (int) $server);
            if (isset($reply['error'])) {
                return ['ok' => false, 'count' => 0, 'message' => $reply['message']];
            }
            $countryId = $countryName !== '' ? country_for_name($countryName) : null;
            foreach ($reply as $service) {
                $rows[] = ['provider_service' => $service['id'], 'provider_name' => $service['name'],
                           'cost' => $service['price'], 'country_id' => $countryId,
                           'service_id' => service_for_name($service['name'])];
            }
            break;

        case 'premium':
            $countryName = '';
            foreach ((array) ($lists['premium'] ?? []) as $country) {
                if ((string) $country['id'] === $where) {
                    $countryName = $country['name'];
                }
            }
            $reply = $api->premiumServices($where);
            if (isset($reply['error'])) {
                return ['ok' => false, 'count' => 0, 'message' => $reply['message']];
            }
            $countryId = $countryName !== '' ? country_for_name($countryName) : null;
            foreach ($reply as $service) {
                $rows[] = ['provider_service' => $service['id'], 'provider_name' => $service['name'],
                           'cost' => $service['price'], 'country_id' => $countryId,
                           'service_id' => service_for_name($service['name'])];
            }
            break;

        case 'cheap':
            $reply = $api->cheapServers();
            if (isset($reply['error'])) {
                return ['ok' => false, 'count' => 0, 'message' => $reply['message']];
            }
            // One unit covers every group, so each row carries its own group
            // in `server` and they are stored one group at a time.
            $byGroup = [];
            foreach ($reply as $range) {
                $parts = split_range_name($range['name']);
                $byGroup[$range['type']][] = [
                    'provider_country' => $range['id'], 'provider_service' => '',
                    'provider_name' => $range['name'], 'cost' => $range['price'],
                    'country_id' => $parts['country'] !== '' ? country_for_name($parts['country']) : null,
                    'service_id' => $parts['service'] !== '' ? service_for_name($parts['service']) : null,
                ];
            }
            $count = 0;
            foreach ($byGroup as $group => $groupRows) {
                $count += offers_store('cheap', (string) $group, null, $groupRows);
            }
            q("UPDATE offers SET missing = 1 WHERE product = 'cheap' AND server NOT IN ("
                . implode(',', array_fill(0, max(1, count($byGroup)), '?')) . ')',
                $byGroup ? array_map('strval', array_keys($byGroup)) : ['-']);
            sync_mark($key);
            return ['ok' => true, 'count' => $count, 'message' => ''];

        case 'email':
            $reply = $api->emailServices((int) $server, $where);
            if (isset($reply['error'])) {
                return ['ok' => false, 'count' => 0, 'message' => $reply['message']];
            }
            if ($server === '1') {
                // Services on one domain: the domain is the "country".
                foreach ((array) ($reply['services'] ?? []) as $service) {
                    if (!is_array($service) || empty($service['service_code'])) {
                        continue;
                    }
                    $rows[] = ['provider_service' => (string) $service['service_code'],
                               'provider_name' => (string) ($service['service_name'] ?? $service['service_code']),
                               'cost' => (float) ($service['price'] ?? 0),
                               'stock' => isset($service['count']) ? (int) $service['count'] : null,
                               'country_id' => null,
                               'service_id' => service_for_name((string) ($service['service_name'] ?? $service['service_code']))];
                }
                $count = offers_store('email', '1', $where, $rows);
                sync_mark($key);
                return ['ok' => true, 'count' => $count, 'message' => ''];
            }

            // Server 2 answers the other way round - domains for one site -
            // so every domain is its own "country" and the unit is the site.
            $serviceId = service_for_site($where);
            $seen      = [];
            $count     = 0;
            foreach ((array) ($reply['domains'] ?? []) as $domain) {
                if (!is_array($domain) || empty($domain['domain'])) {
                    continue;
                }
                $name   = strtolower((string) $domain['domain']);
                $seen[] = $name;
                $count += offers_store('email', '2', $name, [[
                    'provider_service' => $where, 'provider_name' => $name . ' for ' . $where,
                    'cost' => (float) ($domain['price'] ?? 0),
                    'stock' => isset($domain['count']) ? (int) $domain['count'] : null,
                    'country_id' => null, 'service_id' => $serviceId,
                ]], false);
            }
            $sql    = "UPDATE offers SET missing = 1 WHERE product = 'email' AND server = '2' AND provider_service = ?";
            $params = [$where];
            if ($seen) {
                $sql   .= ' AND provider_country NOT IN (' . implode(',', array_fill(0, count($seen), '?')) . ')';
                $params = array_merge($params, $seen);
            }
            q($sql, $params);
            sync_mark($key);
            return ['ok' => true, 'count' => $count, 'message' => ''];

        case 'rental':
            $reply = $api->rentalPricing($where, (int) $server);
            if (isset($reply['error'])) {
                // "No pricing" is an answer: the country has nothing to rent.
                if ($reply['error'] === 'STATUS_404') {
                    offers_store('rental', $server, $where, []);
                    sync_mark($key);
                    return ['ok' => true, 'count' => 0, 'message' => ''];
                }
                return ['ok' => false, 'count' => 0, 'message' => $reply['message']];
            }
            $countryName = '';
            foreach ((array) ($lists['rental'][$server] ?? []) as $country) {
                if ($country['code'] === $where) {
                    $countryName = $country['name'];
                }
            }
            $countryId = country_for_name($countryName !== '' ? $countryName : $where, $where);
            foreach ((array) ($reply['pricing'] ?? []) as $price) {
                if (!is_array($price) || empty($price['duration_hours'])) {
                    continue;
                }
                $rows[] = ['provider_service' => (string) (int) $price['duration_hours'],
                           'provider_name' => trim(($price['country_name'] ?? $countryName) . ' '
                               . rental_duration_words((int) $price['duration_hours'])),
                           'cost' => (float) ($price['price'] ?? 0),
                           'country_id' => $countryId, 'service_id' => null];
            }
            break;

        default:
            return ['ok' => false, 'count' => 0, 'message' => 'Unknown sync step ' . $key . '.'];
    }

    $count = offers_store($product, $server, $where, $rows);
    sync_mark($key);
    return ['ok' => true, 'count' => $count, 'message' => ''];
}

/**
 * Write one unit's offers, and retire the ones it no longer lists.
 *
 * Matched on (product, server, provider_country, provider_service), which
 * is what OTPGet's own ids make unique. The cost and the price are refreshed
 * every time; whether the admin switched an offer off, and any price they
 * pinned, are theirs and are never touched.
 *
 * @param ?string $country  the unit's provider_country, or null when each row carries its own
 * @param bool    $retire   false when the caller retires the rest itself
 * @return int how many offers the unit holds now
 */
function offers_store(string $product, string $server, ?string $country, array $rows, bool $retire = true): int
{
    $now      = date('Y-m-d H:i:s');
    $currency = otpget_currency();
    $kept     = [];

    foreach ($rows as $row) {
        $providerCountry = (string) ($row['provider_country'] ?? $country ?? '');
        $providerService = (string) ($row['provider_service'] ?? '');
        $cost            = round((float) $row['cost'], 4);

        $fields = [
            'provider_name' => mb_substr((string) ($row['provider_name'] ?? ''), 0, 190),
            'country_id'    => $row['country_id'] ?? null,
            'service_id'    => $row['service_id'] ?? null,
            'cost'          => $cost,
            'cost_currency' => $currency,
            'price'         => offer_price($product, $cost, $currency),
            'stock'         => $row['stock'] ?? null,
            'missing'       => 0,
            'synced_at'     => $now,
        ];

        $id = col('SELECT id FROM offers WHERE product = ? AND server = ? AND provider_country = ?
                     AND provider_service = ?', [$product, $server, $providerCountry, $providerService]);

        if ($id) {
            update_row('offers', $fields, 'id = ?', [$id]);
        } else {
            $id = insert_row('offers', $fields + [
                'product'          => $product,
                'server'           => $server,
                'provider_country' => $providerCountry,
                'provider_service' => $providerService,
                'is_active'        => 1,
                'created_at'       => $now,
            ]);
        }
        $kept[] = (int) $id;
    }

    if ($retire && $country !== null) {
        $sql    = 'UPDATE offers SET missing = 1 WHERE product = ? AND server = ? AND provider_country = ?';
        $params = [$product, $server, $country];
        if ($kept) {
            $sql   .= ' AND id NOT IN (' . implode(',', array_fill(0, count($kept), '?')) . ')';
            $params = array_merge($params, $kept);
        }
        q($sql, $params);
    }

    return count($kept);
}

/** Remember when a unit was last read, for cron's oldest-first order. */
function sync_mark(string $key): void
{
    $state = catalogue_cache_read('sync-state') ?? [];
    $state[$key] = time();
    catalogue_cache_write('sync-state', $state);
}

/**
 * The cron half of syncing: a few of the stalest units per run.
 *
 * The lists are refreshed when they are six hours old, and then the units
 * nobody has read for longest go first - so a big catalogue is walked end to
 * end over a few runs and then kept fresh, without any one run getting long.
 *
 * @return array{units: int, offers: int, errors: string[]}
 */
function sync_catalogue_batch(int $limit): array
{
    $errors = [];
    $lists  = catalogue_cache_read('sync-lists', 6 * 3600);
    if ($lists === null) {
        $refresh = sync_lists_refresh();
        $lists   = $refresh['lists'];
        $errors  = $refresh['errors'];
    }

    $state = catalogue_cache_read('sync-state') ?? [];
    $units = sync_units($lists);
    usort($units, static fn ($a, $b) => ($state[$a['key']] ?? 0) <=> ($state[$b['key']] ?? 0));

    $done   = 0;
    $offers = 0;
    foreach (array_slice($units, 0, max(1, $limit)) as $unit) {
        // Only units that are actually due. A shop with twenty units and a
        // batch of eight should not re-read the whole of OTPGet every minute.
        if (($state[$unit['key']] ?? 0) > time() - 3 * 3600) {
            break;
        }
        $result = sync_unit($unit['key']);
        $done++;
        $offers += $result['count'];
        if (!$result['ok']) {
            $errors[] = $unit['label'] . ': ' . $result['message'];
        }
    }

    if ($done > 0) {
        set_setting('catalogue_synced_at', date('Y-m-d H:i:s'));
    }

    return ['units' => $done, 'offers' => $offers, 'errors' => $errors];
}

// ===========================================================================
// What the storefront shows
// ===========================================================================

/**
 * Services on sale under one tab, each with its cheapest price.
 *
 * @return array<int, array> rows of services plus `from_price`, `countries`, `options`
 */
function store_services(string $tab, ?int $countryId = null): array
{
    [$sellable, $params] = offer_sellable_sql('o', tab_products($tab));

    $sql = "SELECT s.id, s.slug, s.name, s.icon, s.color, s.is_popular, s.sort_order,
                   MIN(COALESCE(o.price_override, o.price)) AS from_price,
                   COUNT(DISTINCT o.country_id) AS countries,
                   COUNT(*) AS options
              FROM offers o
              JOIN services s ON s.id = o.service_id AND s.is_active = 1
              LEFT JOIN countries c ON c.id = o.country_id
             WHERE {$sellable}
               AND (o.country_id IS NULL OR c.is_active = 1)";
    if ($countryId !== null) {
        $sql     .= ' AND o.country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' GROUP BY s.id, s.slug, s.name, s.icon, s.color, s.is_popular, s.sort_order
              ORDER BY s.is_popular DESC, s.sort_order, s.name';

    return all($sql, $params);
}

/**
 * Countries on sale under one tab - for one service, or for any.
 *
 * @return array<int, array> rows of countries plus `from_price`, `options`
 */
function store_countries(string $tab, ?int $serviceId = null): array
{
    [$sellable, $params] = offer_sellable_sql('o', tab_products($tab));

    $sql = "SELECT c.id, c.slug, c.name, c.iso2, c.dial_code, c.is_popular, c.sort_order,
                   MIN(COALESCE(o.price_override, o.price)) AS from_price,
                   COUNT(*) AS options
              FROM offers o
              JOIN countries c ON c.id = o.country_id AND c.is_active = 1
              LEFT JOIN services s ON s.id = o.service_id
             WHERE {$sellable}
               AND (o.service_id IS NULL OR s.is_active = 1)";
    if ($serviceId !== null) {
        $sql     .= ' AND o.service_id = ?';
        $params[] = $serviceId;
    }
    $sql .= ' GROUP BY c.id, c.slug, c.name, c.iso2, c.dial_code, c.is_popular, c.sort_order
              ORDER BY c.is_popular DESC, c.sort_order, c.name';

    return all($sql, $params);
}

/**
 * The options for one choice, cheapest first.
 *
 * Numbers: one service in one country, across every server and product.
 * Email: one service, across every domain. Rental: one country, across
 * every length and server.
 */
function store_options(string $tab, ?int $serviceId, ?int $countryId): array
{
    [$sellable, $params] = offer_sellable_sql('o', tab_products($tab));

    $sql = "SELECT o.* FROM offers o WHERE {$sellable}";
    if ($serviceId !== null) {
        $sql     .= ' AND o.service_id = ?';
        $params[] = $serviceId;
    }
    if ($countryId !== null) {
        $sql     .= ' AND o.country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY COALESCE(o.price_override, o.price), o.product, o.server LIMIT 60';

    $rows = all($sql, $params);
    foreach ($rows as &$row) {
        $row['sale_price'] = offer_sale_price($row);
        $row['label']      = offer_option_label($row);
    }
    unset($row);

    if ($tab === 'rental') {
        usort($rows, static fn ($a, $b) =>
            [(int) $a['provider_service'], $a['sale_price']] <=> [(int) $b['provider_service'], $b['sale_price']]);
    }

    return $rows;
}

/** One offer a customer may buy, with its names, or null. */
function sellable_offer(int $offerId): ?array
{
    [$sellable, $params] = offer_sellable_sql('o');

    $offer = one(
        "SELECT o.*, s.name AS service_name, s.slug AS service_slug, s.is_active AS service_active,
                c.name AS country_name, c.iso2 AS country_iso, c.is_active AS country_active
           FROM offers o
           LEFT JOIN services s ON s.id = o.service_id
           LEFT JOIN countries c ON c.id = o.country_id
          WHERE o.id = ? AND {$sellable}",
        array_merge([$offerId], $params)
    );

    if (!$offer
        || ($offer['service_id'] !== null && !$offer['service_active'])
        || ($offer['country_id'] !== null && !$offer['country_active'])) {
        return null;
    }

    $offer['sale_price'] = offer_sale_price($offer);
    $offer['label']      = offer_option_label($offer);
    return $offer;
}

/** The words an order is known by: "WhatsApp · Pakistan", "Instagram · gmail.com". */
function offer_title(array $offer): string
{
    $service = trim((string) ($offer['service_name'] ?? '')) ?: trim((string) $offer['provider_name']);

    return match ((string) $offer['product']) {
        'email'  => $service . ' · ' . $offer['provider_country'],
        'rental' => 'Rental · ' . ($offer['country_name'] ?? $offer['provider_country'])
                    . ' · ' . rental_duration_words((int) $offer['provider_service']),
        default  => $service . ($offer['country_name'] ? ' · ' . $offer['country_name'] : ''),
    };
}

// ===========================================================================
// Drawing names
// ===========================================================================

/** The flag image for an ISO code, or the plain globe. */
function flag_url(?string $iso): string
{
    $iso = strtolower(trim((string) $iso));
    return asset('assets/flags/' . ($iso !== '' && is_file(BASE_PATH . '/assets/flags/' . $iso . '.svg')
        ? $iso : 'xx') . '.svg');
}

/** The markup for a service's icon: the brand glyph, or its letter. */
function service_icon_html(array $service, string $class = 'svc-ico'): string
{
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($service['color'] ?? ''))
        ? $service['color'] : service_color_for((string) ($service['name'] ?? '?'));
    $icon  = (string) ($service['icon'] ?? '');

    if ($icon !== '' && preg_match('/^[a-z0-9]+$/', $icon)) {
        return '<span class="' . e($class) . '" style="--c:' . e($color) . '" aria-hidden="true">'
             . '<svg><use href="' . e(asset('assets/brands/brands.svg')) . '#b-' . e($icon) . '"></use></svg></span>';
    }

    $letter = mb_strtoupper(mb_substr(trim((string) ($service['name'] ?? '?')), 0, 1)) ?: '?';
    return '<span class="' . e($class) . ' is-letter" style="--c:' . e($color) . '" aria-hidden="true">'
         . e($letter) . '</span>';
}

// ===========================================================================
// The OTPGet wallet
// ===========================================================================

/**
 * Ask OTPGet for our balance and keep it.
 *
 * Warns once when it crosses the threshold on the way down - the run after
 * that says nothing new, and a cron that mails the same warning every
 * fifteen minutes is a cron the admin turns off.
 *
 * @return array{ok: bool, balance: float, shown: string, message: string}
 */
function otpget_balance_refresh(): array
{
    $reply = otpget()->balance();
    if (isset($reply['error'])) {
        set_setting('otpget_last_error', mb_substr((string) $reply['message'], 0, 300));
        return ['ok' => false, 'balance' => 0.0, 'shown' => '', 'message' => (string) $reply['message']];
    }

    $before = (float) setting('otpget_balance', '0');
    $now    = (float) $reply['balance'];
    set_setting('otpget_balance', (string) $now);
    set_setting('otpget_balance_at', date('Y-m-d H:i:s'));
    set_setting('otpget_last_error', '');

    $threshold = (float) setting('low_balance_threshold', '0');
    $nowBase   = to_base($now, otpget_currency());
    $wasBase   = to_base($before, otpget_currency());
    if ($threshold > 0 && $nowBase !== null && $nowBase < $threshold && ($wasBase === null || $wasBase >= $threshold)) {
        notify('admin_low_balance', [
            'provider'   => 'OTPGet',
            'balance'    => money_in($now, otpget_currency()),
            'threshold'  => money($threshold),
            'admin_url'  => url('admin/otpget'),
            'admin_link' => 'admin/otpget',
        ]);
    }

    return ['ok' => true, 'balance' => $now, 'shown' => money_in($now, otpget_currency()), 'message' => ''];
}

/** The last balance we read, for the sidebar and the dashboard. */
function otpget_balance_known(): array
{
    $amount = (float) setting('otpget_balance', '0');
    $code   = otpget_currency();
    $base   = to_base($amount, $code);

    return [
        'amount' => $amount,
        'code'   => $code,
        'own'    => money_in($amount, $code),
        'base'   => $base,
        'at'     => (string) setting('otpget_balance_at', ''),
    ];
}
