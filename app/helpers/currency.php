<?php
/**
 * Currencies.
 *
 * One currency is the base: every price in the database is stored in it, and
 * every rate says how many of that currency one unit of the other is worth.
 * So with PKR as base and USD at 280, `rate_to_base` for USD is 280.
 *
 * OTPGet bills in its own currency. When the catalogue is synced, its price
 * is converted to base first and the markup is applied after - so a rate
 * moving does not quietly eat the margin.
 */

/**
 * Where rates come from.
 *
 * A parameter rather than a literal inside the function so the checks below
 * it can be exercised against a feed that misbehaves on purpose - a wrong
 * base, a refusal, an inverted number. None of those can be provoked from
 * the real service, and all of them are expensive.
 */
const CURRENCY_FEED = 'https://open.er-api.com/v6/latest';

/** Every currency, keyed by code. Read once per request. */
function currencies(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        try {
            foreach (all('SELECT * FROM currencies ORDER BY sort_order, code') as $row) {
                $cache[strtoupper($row['code'])] = $row;
            }
        } catch (PDOException $e) {
            // Before the currencies migration has run there is simply nothing
            // to convert with, and the panel still has to open so it can be
            // told to run it.
            if (!is_missing_schema($e)) {
                throw $e;
            }
        }
    }
    return $cache;
}

/** The base currency row, or a sensible stand-in if none is marked. */
function base_currency(): array
{
    foreach (currencies() as $currency) {
        if ($currency['is_base']) {
            return $currency;
        }
    }

    $code = strtoupper((string) setting('base_currency', 'PKR'));
    return currencies()[$code] ?? [
        'code'         => $code,
        'name'         => $code,
        'symbol'       => (string) setting('currency_symbol', ''),
        'rate_to_base' => 1,
        'is_base'      => 1,
    ];
}

/** What one unit of $code is worth in the base currency. */
function currency_rate(string $code): float
{
    $code = strtoupper(trim($code));
    if ($code === '' || $code === strtoupper(base_currency()['code'])) {
        return 1.0;
    }

    $rate = (float) (currencies()[$code]['rate_to_base'] ?? 0);
    return $rate > 0 ? $rate : 0.0;
}

/**
 * Convert an amount into the base currency.
 *
 * Returns null when the currency is unknown, because guessing a rate would
 * quietly mis-price everything. Callers decide what to do about it.
 */
function to_base(float $amount, string $fromCode): ?float
{
    $rate = currency_rate($fromCode);
    return $rate > 0 ? $amount * $rate : null;
}

/**
 * Format an amount in a currency that is not necessarily ours.
 *
 * money() always prints the base symbol, which is right for a price and
 * wrong for a provider balance: a provider holding 0.72 USD was being shown
 * as "Rs 0.72", the provider's own number wearing our symbol.
 */
function money_in(float $amount, string $code): string
{
    $code     = strtoupper(trim($code));
    $currency = currencies()[$code] ?? null;
    $symbol   = $currency['symbol'] ?? $code;
    $text     = number_format($amount, 2);
    // A letter symbol needs air before the digits; a glyph like $ does not.
    $gap = preg_match('/\p{L}$/u', (string) $symbol) ? "\u{00A0}" : '';

    return $symbol . $gap . $text;
}

/**
 * Pull fresh rates from a public source.
 *
 * open.er-api.com needs no key and answers with rates *from* the base, so
 * each is inverted to get "what is one unit worth in base".
 *
 * @return array{ok: bool, updated: int, message: string}
 */
function refresh_currency_rates(string $endpoint = CURRENCY_FEED): array
{
    $base = strtoupper(base_currency()['code']);
    $url  = rtrim($endpoint, '/') . '/' . rawurlencode($base);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'OTPFlash/1.0',
    ]);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error !== '') {
        return ['ok' => false, 'updated' => 0, 'message' => 'Could not reach the rate service: ' . $error];
    }
    if ($code >= 400) {
        return ['ok' => false, 'updated' => 0, 'message' => 'Rate service answered HTTP ' . $code];
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data) || empty($data['rates']) || !is_array($data['rates'])) {
        return ['ok' => false, 'updated' => 0, 'message' => 'Rate service sent something we could not read.'];
    }

    // The feed says whether it could answer at all. Without this check an
    // error body with a rates key would be read as data.
    if (isset($data['result']) && $data['result'] !== 'success') {
        return ['ok' => false, 'updated' => 0,
                'message' => 'The rate service refused: ' . (string) ($data['error-type'] ?? $data['result'])];
    }

    // And whether it answered in the currency we asked about. open.er-api.com
    // falls back to USD for a base it does not carry, and the reply looks
    // exactly like a good one - so taking it on trust would store USD rates
    // as if they were PKR and reprice the entire catalogue by a factor of
    // several hundred. This is the single most expensive thing that can go
    // wrong here, and it is one comparison.
    $answered = strtoupper((string) ($data['base_code'] ?? $data['base'] ?? ''));
    if ($answered !== '' && $answered !== $base) {
        require_once APP_PATH . '/helpers/audit.php';
        audit('rate.wrong_base', [
            'severity' => 'alert',
            'summary'  => 'Asked for ' . $base . ' and was answered in ' . $answered
                        . ' - nothing was changed.',
        ]);
        return ['ok' => false, 'updated' => 0,
                'message' => 'The rate service answered in ' . $answered . ', not ' . $base
                           . '. Nothing was changed.'];
    }

    $now      = date('Y-m-d H:i:s');
    $updated  = 0;
    $rejected = [];
    $factor   = max(1.5, (float) setting('rate_sanity_factor', 5));

    require_once APP_PATH . '/helpers/audit.php';

    foreach (currencies(true) as $code => $currency) {
        if ($currency['is_base']) {
            update_row('currencies', ['rate_to_base' => 1, 'updated_at' => $now], 'id = ?', [$currency['id']]);
            continue;
        }

        // The feed gives base -> code; we store code -> base.
        $perBase = (float) ($data['rates'][$code] ?? 0);
        if ($perBase <= 0) {
            continue;
        }
        $fresh = round(1 / $perBase, 8);
        $known = (float) $currency['rate_to_base'];

        // A rate that has multiplied or divided by several times overnight is
        // not a currency moving, it is a bad number - an inversion, a wrong
        // base, a feed glitch. Real money moves a few percent. Refusing it
        // keeps yesterday's rate, which is approximately right; taking it
        // reprices everything, which is precisely wrong.
        if ($known > 0 && ($fresh > $known * $factor || $fresh < $known / $factor)) {
            $rejected[] = $code;
            audit('rate.refused', [
                'entity' => 'currencies', 'entity_id' => (int) $currency['id'],
                'severity' => 'alert',
                'summary' => $code . ' came back as ' . $fresh . ' against a stored '
                           . $known . ' - more than ' . $factor . "x, so it was ignored.",
                'before'  => ['rate_to_base' => $known],
                'after'   => ['rate_to_base' => $fresh],
            ]);
            continue;
        }

        update_row('currencies', [
            'rate_to_base' => $fresh,
            'updated_at'   => $now,
        ], 'id = ?', [$currency['id']]);
        $updated++;

        if ($known > 0 && abs($fresh - $known) / $known > 0.02) {
            audit('rate.changed', [
                'entity' => 'currencies', 'entity_id' => (int) $currency['id'],
                'summary' => $code . ' ' . $known . ' -> ' . $fresh . ' per ' . $base,
                'before'  => ['rate_to_base' => $known],
                'after'   => ['rate_to_base' => $fresh],
            ]);
        }
    }

    set_setting('currency_rates_updated_at', $now);
    currencies(true);

    if ($updated) {
        $message = 'Updated ' . $updated . ' rate' . ($updated === 1 ? '' : 's')
                 . ' against ' . $base . '.';
    } elseif ($rejected) {
        // Not "nothing for your currencies": there was something, and it was
        // refused. Saying the wrong one sends the admin looking in Currencies
        // for a currency that is already there.
        $message = 'Nothing was changed.';
    } else {
        $message = 'The rate service had nothing for the currencies you have added.';
    }

    if ($rejected) {
        $message .= ' Ignored a wild jump in ' . implode(', ', $rejected)
                 . ' - the old rate was kept. Check the audit log.';
    }

    return ['ok' => true, 'updated' => $updated, 'rejected' => $rejected, 'message' => $message];
}

/**
 * The currencies a provider is likely to bill in.
 *
 * Only here so an added currency arrives with a name and a symbol rather than
 * three bare letters. Anything not listed still works - it is named after its
 * own code until somebody edits it.
 */
/** Stablecoins worth a dollar, which is the only reason they exist. */
const DOLLAR_STABLECOINS = ['USDT', 'USDC', 'BUSD'];

const KNOWN_CURRENCIES = [
    // The stablecoins a gateway settles in. They arrive with a name and a
    // symbol like anything else, so the currencies screen does not show
    // four bare letters.
    'USDT' => ['Tether USD', 'USDT '], 'USDC' => ['USD Coin', 'USDC '],
    'BUSD' => ['Binance USD', 'BUSD '],
    'USD' => ['US Dollar', '$'],          'EUR' => ['Euro', '\u{20AC}'],
    'GBP' => ['British Pound', '\u{A3}'],   'PKR' => ['Pakistani Rupee', 'Rs '],
    'INR' => ['Indian Rupee', '\u{20B9}'],  'BDT' => ['Bangladeshi Taka', '\u{9F3}'],
    'NGN' => ['Nigerian Naira', '\u{20A6}'],'TRY' => ['Turkish Lira', '\u{20BA}'],
    'BRL' => ['Brazilian Real', 'R$'],    'IDR' => ['Indonesian Rupiah', 'Rp'],
    'PHP' => ['Philippine Peso', '\u{20B1}'],'EGP' => ['Egyptian Pound', 'E\u{A3}'],
    'SAR' => ['Saudi Riyal', 'SR'],       'AED' => ['UAE Dirham', 'AED '],
    'RUB' => ['Russian Ruble', '\u{20BD}'], 'CNY' => ['Chinese Yuan', '\u{A5}'],
    'CAD' => ['Canadian Dollar', 'C$'],   'AUD' => ['Australian Dollar', 'A$'],
];

/**
 * Add a currency and go and get its rate.
 *
 * Used where a provider turns out to bill in something we have never heard
 * of: there is nothing useful to show until the rate exists, so the panel
 * offers to fetch it rather than quietly pricing everything wrong.
 *
 * @return array{ok: bool, message: string, rate: float}
 */
function currency_add(string $code): array
{
    $code = strtoupper(trim($code));

    // Three letters for money a country prints, four or five for the coins a
    // gateway settles in - USDT and BUSD are currencies to this shop in every
    // way that matters here: a price is converted into them and a payment
    // arrives in them.
    if (!preg_match('/^[A-Z]{3,5}$/', $code)) {
        return ['ok' => false, 'rate' => 0.0,
                'message' => 'A currency code is three to five letters, like USD or USDT.'];
    }

    if (isset(currencies(true)[$code])) {
        return ['ok' => true, 'message' => $code . ' is already in your currencies.',
                'rate' => currency_rate($code)];
    }

    [$name, $symbol] = KNOWN_CURRENCIES[$code] ?? [$code, $code . ' '];

    insert_row('currencies', [
        'code'         => $code,
        'name'         => $name,
        'symbol'       => $symbol,
        'rate_to_base' => 0,
        'is_base'      => 0,
        'is_active'    => 1,
        'sort_order'   => (int) col('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM currencies', [], 1),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    currencies(true);

    $refresh = refresh_currency_rates();
    $rate    = currency_rate($code);

    // The rate feed deals in money countries print, so it has never heard of
    // USDT. A dollar stablecoin is worth a dollar - that is the whole of what
    // it is for - so it takes the dollar's rate rather than sitting at zero
    // and quietly switching off the gateway that settles in it. An admin who
    // disagrees can type their own on the Currencies screen.
    if ($rate <= 0 && in_array($code, DOLLAR_STABLECOINS, true)) {
        $dollar = currency_rate('USD');
        if ($dollar > 0) {
            update_row('currencies',
                ['rate_to_base' => $dollar, 'updated_at' => date('Y-m-d H:i:s')],
                'code = ?', [$code]);
            currencies(true);
            $rate = $dollar;

            return ['ok' => true, 'rate' => $rate,
                    'message' => $code . ' was added at the US dollar rate, which is what it is '
                               . 'pegged to. Change it under Currencies if you price it '
                               . 'differently.'];
        }
    }

    if ($rate <= 0) {
        return [
            'ok'      => false,
            'rate'    => 0.0,
            'message' => $code . ' was added, but no rate came back'
                       . ($refresh['ok'] ? '' : ': ' . $refresh['message'])
                       . '. Set it by hand under Currencies.',
        ];
    }

    return [
        'ok'      => true,
        'rate'    => $rate,
        'message' => $code . ' added at ' . rtrim(rtrim(number_format($rate, 4), '0'), '.')
                   . ' ' . base_currency()['code'] . ' per ' . $code . '.',
    ];
}
