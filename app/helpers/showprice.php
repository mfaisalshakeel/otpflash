<?php
/**
 * Showing a price in the visitor's own money.
 *
 * **Every price in the database stays in the base currency.** An order is
 * placed, charged, refunded and reported in it, the wallet holds it, and the
 * admin reads it. What this file does is a translation at the last possible
 * moment, for the person reading the page, and nothing else. That line is
 * why money() was left alone: fifty-two of its call sites are order logs,
 * wallet notes and audit lines, and a converted figure in any of those is a
 * lie about what was charged.
 *
 * The conversion happens **in the browser**, not here. The reason is
 * caching: a page rendered in the visitor's currency is a different page per
 * visitor, so a shared cache - Cloudflare's, or any other - has to keep one
 * copy per currency and its hit rate collapses. A page rendered in the base
 * currency with the figure marked up for the browser to rewrite is the *same
 * page for everyone on earth*, cacheable at the edge, while every visitor
 * still sees their own money. It is also why the currency the visitor chose
 * is carried in a cookie and never printed into the HTML - printing it would
 * bake one visitor's choice into the copy the next one is served.
 *
 * So the whole contract is:
 *
 *   price(310)  ->  <span class="pr" data-price="310">Rs 310</span>
 *
 * and app.js turns the text into "$1.15" while leaving data-price alone.
 * Without JavaScript the base price is what shows, which is a true price
 * rather than a broken one - and it is what a crawler indexes, so search
 * results never disagree with the shop.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Cookie the browser keeps its currency in. Readable by script, by design. */
const CURRENCY_COOKIE = 'of_currency';

/** How long the visitor's choice is remembered. */
const CURRENCY_COOKIE_DAYS = 365;

/**
 * A price for a customer to read, in base currency, marked for the browser.
 *
 * Returns HTML, so a view prints it bare - never through e(). Anywhere a
 * plain string is needed instead (a title, an aria-label, an email, JSON)
 * money() is still the right call: those are not converted, and should not be.
 */
function price($amount, string $class = ''): string
{
    $base = round((float) $amount, 2);

    // `data-base`, not `data-price`: the picker's own list links and the
    // options radios already carry `data-price`, and a converter that
    // matched those set the text of a whole row - wiping its icon and name
    // and leaving the price alone on the line. The marker has to be one
    // nothing else in the markup uses.
    return '<span class="pr' . ($class !== '' ? ' ' . e($class) : '') . '"'
         . ' data-base="' . e((string) $base) . '">' . e(money($base)) . '</span>';
}

/**
 * Everything the browser needs to convert, for one currency.
 *
 * `rate` is how many base units one of these is worth, so the browser
 * divides. `step` is what a converted figure is rounded **up** to and
 * `decimals` how many places it really has - a yen price with two decimal
 * places is two digits that do not exist.
 */
function currency_payload(string $code): array
{
    $code     = strtoupper(trim($code));
    $row      = currencies()[$code] ?? null;
    $info     = currency_info($code);
    $baseCode = strtoupper((string) base_currency()['code']);

    return [
        'code'     => $code,
        'symbol'   => (string) ($row['symbol'] ?? $info['symbol'] ?? $code),
        'name'     => (string) ($row['name'] ?? $info['name'] ?? $code),
        'rate'     => $code === $baseCode ? 1.0 : round((float) ($row['rate_to_base'] ?? 0), 8),
        'decimals' => (int) ($info['decimals'] ?? 2),
        'step'     => (float) ($info['step'] ?? 0.01),
        'base'     => $baseCode,
    ];
}

/** What ISO 4217 says about a currency, or nothing if we have never heard of it. */
function currency_info(string $code): array
{
    static $info = null;
    $info ??= (require APP_PATH . '/helpers/currencies-data.php')['info'];

    return $info[strtoupper(trim($code))] ?? [];
}

/**
 * Make sure a currency exists and has a rate, adding it if it does not.
 *
 * Called when a visitor turns up from a country whose money the shop has
 * never sold in. One lookup, one row, and from then on it is simply one of
 * the shop's currencies.
 *
 * Three things keep this from being a hole a visitor can dig:
 *
 *   - only a currency ISO 4217 actually defines is considered, so no amount
 *     of header-forging invents one;
 *   - the row is claimed through a lock, so fifty visitors from one country
 *     arriving at once add it once, not fifty times;
 *   - a currency the rate feed has no rate for is **not added at all**,
 *     because a currency with no rate would price the whole shop at zero.
 *
 * @return bool true when the currency is there and usable afterwards
 */
function currency_ensure(string $code): bool
{
    $code = strtoupper(trim($code));
    $base = strtoupper((string) base_currency()['code']);

    if ($code === $base) {
        return true;
    }
    if (!preg_match('/^[A-Z]{3}$/', $code) || currency_info($code) === []) {
        return false;
    }
    if (setting('currency_auto_add', '1') !== '1') {
        return isset(currencies()[$code]) && currency_rate($code) > 0;
    }

    $existing = currencies()[$code] ?? null;
    if ($existing && (float) $existing['rate_to_base'] > 0) {
        return true;
    }

    require_once APP_PATH . '/helpers/guard.php';

    // Whoever gets the lock does the work; everyone else behind them finds it
    // already done a moment later and simply reads the row.
    $made = with_lock('currency-add-' . $code, static function () use ($code, $existing): bool {
        if (!$existing && isset(currencies(true)[$code])) {
            return currency_rate($code) > 0;
        }

        $rate = currency_fetch_one($code);
        if ($rate <= 0) {
            return false;
        }

        $info = currency_info($code);
        if ($existing) {
            update_row('currencies', ['rate_to_base' => $rate, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $existing['id']]);
        } else {
            try {
                insert_row('currencies', [
                    'code'         => $code,
                    'name'         => (string) $info['name'],
                    'symbol'       => (string) $info['symbol'],
                    'rate_to_base' => $rate,
                    'is_base'      => 0,
                    'is_active'    => 1,
                    'sort_order'   => 50,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            } catch (PDOException $e) {
                // Another process won the race despite the lock - a second
                // web server, or a lock that had expired. Its row is as good
                // as ours would have been.
                if (!is_duplicate_key($e)) {
                    throw $e;
                }
            }
        }

        currencies(true);
        require_once APP_PATH . '/helpers/audit.php';
        audit('currency.added', [
            'entity' => 'currencies', 'severity' => 'info',
            'summary' => $code . ' added automatically at ' . $rate . ' per ' . $code
                       . ', for a visitor whose country spends it',
        ]);
        log_line('Currency ' . $code . ' added automatically at ' . $rate);

        return true;
    }, 30);

    // null means somebody else holds the lock and is adding it right now.
    // Saying no is right: this visitor sees the base currency on this page
    // and their own on the next one.
    return $made === true;
}

/**
 * One currency's rate from the feed, as "how many base units it is worth".
 *
 * The same source and the same inversion refresh_currency_rates() uses, and
 * the same refusal to believe a nonsense number - a rate of zero or one that
 * would make every price in the shop absurd is not stored.
 */
function currency_fetch_one(string $code): float
{
    $base = strtoupper((string) base_currency()['code']);
    $url  = rtrim(CURRENCY_FEED, '/') . '/' . rawurlencode($base);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'OTPFlash/1.0',
    ]);
    $body = (string) curl_exec($ch);
    $bad  = curl_errno($ch) !== 0 || (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400;
    curl_close($ch);

    if ($bad) {
        return 0.0;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['rates'][$code])) {
        return 0.0;
    }
    // The feed answers in whatever base it actually carries; one that quietly
    // fell back to USD would price the shop by a factor of several hundred.
    $answered = strtoupper((string) ($data['base_code'] ?? $data['base'] ?? ''));
    if ($answered !== '' && $answered !== $base) {
        return 0.0;
    }

    $perBase = (float) $data['rates'][$code];

    return $perBase > 0 ? round(1 / $perBase, 8) : 0.0;
}

/** Every currency a visitor may pick, for the switcher. The same for everyone. */
function currency_choices(): array
{
    $out = [];
    foreach (currencies() as $code => $row) {
        if (!($row['is_active'] ?? 1) && !$row['is_base']) {
            continue;
        }
        if (!$row['is_base'] && (float) $row['rate_to_base'] <= 0) {
            continue;   // nothing honest to convert with
        }
        $out[$code] = currency_payload($code);
    }

    return $out;
}

/**
 * The currency this visitor should be shown, worked out from scratch.
 *
 * Only ever called by /api/currency, which the browser asks **once**, when it
 * has no cookie yet. Nothing on a rendered page calls this, so no page waits
 * for it and no cached page carries its answer.
 */
function currency_detect(): array
{
    require_once APP_PATH . '/helpers/geo.php';

    $base = strtoupper((string) base_currency()['code']);

    // Signed in and chose one already: that choice outranks any guess, on
    // every device they sign in on.
    $user = current_user();
    $saved = $user ? strtoupper(trim((string) ($user['currency'] ?? ''))) : '';
    if ($saved !== '' && currency_ensure($saved)) {
        return currency_payload($saved) + ['chosen' => true, 'country' => ''];
    }

    $country = geo_country();
    $wanted  = $country !== '' ? geo_currency_for_country($country) : '';

    if ($wanted !== '' && $wanted !== $base && currency_ensure($wanted)) {
        return currency_payload($wanted) + ['chosen' => false, 'country' => $country];
    }

    return currency_payload($base) + ['chosen' => false, 'country' => $country];
}

/** Remember a signed-in customer's choice, so it follows them. */
function currency_remember_for_user(string $code): void
{
    $user = current_user();
    $code = strtoupper(trim($code));

    if (!$user || !table_has_column('users', 'currency')) {
        return;
    }
    if (!isset(currency_choices()[$code])) {
        return;
    }

    update_row('users', ['currency' => $code, 'updated_at' => date('Y-m-d H:i:s')],
        'id = ?', [(int) $user['id']]);
}

/**
 * Something about the currency setup that will not do what it looks like it
 * does - said at the top of the panel, the way an available update is.
 *
 * Only ever one sentence, and only when it is actionable: a notice that is
 * always there is a notice nobody reads. Returns '' when the setup is
 * coherent, which is the usual case.
 */
function currency_setup_notice(): string
{
    require_once APP_PATH . '/helpers/geo.php';

    if (setting('geo_provider', 'auto') === 'off') {
        return '';     // switched off on purpose is not a problem
    }

    // The combination that silently does nothing: told to read a CDN header,
    // and told there is no CDN. Every visitor falls through to the base
    // currency and it looks like detection is simply broken.
    if (geo_provider() === 'cdn' && setting('geo_behind_cdn', '0') !== '1') {
        return 'Currency detection is set to read a CDN header, but "This site is behind '
             . 'Cloudflare or another CDN" is off - so nothing is ever detected and every '
             . 'visitor sees your base currency.';
    }

    // Nothing to convert into. The switcher hides itself, auto-add is off,
    // and the admin is left wondering where the feature went.
    if (setting('currency_auto_add', '1') !== '1' && count(currency_choices()) < 2) {
        return 'Only one currency has a rate, and adding one automatically is switched off - '
             . 'so prices cannot be shown in anybody else\'s money. Add a currency, or turn '
             . 'that back on.';
    }

    // A rate feed that has been failing: prices would be converted at a stale
    // rate, which is the kind of wrong nobody notices for weeks.
    $updated = (string) setting('currency_rates_updated_at', '');
    if (count(currency_choices()) > 1 && $updated !== ''
        && strtotime($updated) < time() - 7 * 86400) {
        return 'Exchange rates were last updated ' . when($updated) . '. Prices are being shown '
             . 'to visitors at those rates - check that cron is running, or refresh them on '
             . 'the Currencies screen.';
    }

    return '';
}
