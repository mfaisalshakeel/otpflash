<?php
/**
 * Changing the base currency after the shop already has prices in it.
 *
 * Every money column in the database is stored in the base currency and
 * carries no currency of its own, so moving the base means rewriting all of
 * them. That is why it used to be refused outright once anything was priced.
 *
 * The conversion is a single ratio. If one unit of the new base is worth R of
 * the old one, then every stored amount divides by R and every exchange rate
 * does too. Nothing needs re-fetching and nothing needs OTPGet: an offer's
 * price is `cost x rate(OTPGet's currency) x markup`, and dividing that by R
 * gives exactly what it would be rebuilt as against the new base, because the
 * rate divides by R as well.
 *
 * `offers.cost` and `activations.cost` are left alone - they are in OTPGet's
 * own currency, not ours, and so is the OTPGet balance.
 */

require_once APP_PATH . '/helpers/currency.php';
require_once APP_PATH . '/helpers/audit.php';

/**
 * Every money column this has to rewrite, as [table, columns].
 *
 * Written out rather than discovered, because a column holding a number is
 * not the same as a column holding one of our amounts - `markup_percent` and
 * `min_qty` are numbers too, and converting them would be nonsense.
 */
const REBASE_MONEY = [
    'offers'              => ['price', 'price_override'],
    'orders'              => ['price', 'cost', 'paid_amount'],
    'activations'         => ['cost_base'],
    'users'               => ['balance'],
    'wallet_transactions' => ['amount', 'balance_after'],
    'rentals'             => ['price', 'cost'],
];

/** Settings whose value is an amount in the base currency. */
const REBASE_MONEY_SETTINGS = ['low_balance_threshold', 'min_price', 'min_deposit'];

/**
 * What changing the base to $code would touch, without touching it.
 *
 * @return array{ok: bool, message: string, from: string, to: string, rate: float, counts: array}
 */
function rebase_preview(string $code): array
{
    $code = strtoupper(trim($code));
    $from = strtoupper((string) base_currency()['code']);

    $blank = ['ok' => false, 'from' => $from, 'to' => $code, 'rate' => 0.0, 'counts' => []];

    if ($code === '' || !isset(currencies()[$code])) {
        return $blank + ['message' => 'That currency is not in your list.'];
    }
    if ($code === $from) {
        return $blank + ['message' => $code . ' is already your base currency.'];
    }

    // How many of the old base one unit of the new one is worth. Without it
    // there is no honest conversion, so this is the one case that refuses.
    $rate = currency_rate($code);
    if ($rate <= 0) {
        return $blank + ['message' => $code . ' has no exchange rate yet, so there is nothing to '
                                    . 'convert with. Add a rate on this screen first.'];
    }

    $counts = [];
    foreach (REBASE_MONEY as $table => $columns) {
        if (!table_exists($table)) {
            continue;
        }
        $counts[$table] = (int) col("SELECT COUNT(*) FROM `{$table}`", [], 0);
    }
    $counts['currencies'] = count(currencies());

    return ['ok' => true, 'message' => '', 'from' => $from, 'to' => $code,
            'rate' => $rate, 'counts' => $counts];
}

/**
 * Move the base currency, converting everything stored in it.
 *
 * All of it or none of it: a half-converted catalogue sells at numbers that
 * mean nothing, so the whole pass is one transaction.
 *
 * @return array{ok: bool, message: string}
 */
function rebase_currency(string $code): array
{
    $plan = rebase_preview($code);
    if (!$plan['ok']) {
        return ['ok' => false, 'message' => $plan['message']];
    }

    $rate = $plan['rate'];
    $from = $plan['from'];
    $to   = $plan['to'];
    $row  = currencies()[$to];

    $before = ['base_currency' => $from, 'currency_symbol' => setting('currency_symbol', '')];

    db()->beginTransaction();

    try {
        // The stored amounts.
        foreach (REBASE_MONEY as $table => $columns) {
            if (!table_exists($table)) {
                continue;
            }
            $sets = [];
            foreach ($columns as $column) {
                if (table_has_column($table, $column)) {
                    $sets[] = "`{$column}` = ROUND(`{$column}` / ?, 2)";
                }
            }
            if ($sets) {
                q("UPDATE `{$table}` SET " . implode(', ', $sets),
                    array_fill(0, count($sets), $rate));
            }
        }

        // Every rate is "how much of the base", so every rate moves with it.
        q('UPDATE currencies SET rate_to_base = ROUND(rate_to_base / ?, 8)', [$rate]);
        q('UPDATE currencies SET is_base = 0');
        update_row('currencies',
            ['is_base' => 1, 'rate_to_base' => 1, 'is_active' => 1], 'id = ?', [$row['id']]);

        foreach (REBASE_MONEY_SETTINGS as $key) {
            $value = (float) setting($key, 0);
            if ($value > 0) {
                set_setting($key, (string) round($value / $rate, 2));
            }
        }

        set_setting('base_currency', $to);
        set_setting('currency_symbol', (string) ($row['symbol'] ?? $to . ' '));

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        log_line('Base currency change failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Nothing was changed - the conversion failed partway: '
                                          . $e->getMessage()];
    }

    currencies(true);
    settings_all(true);

    audit('currency.rebased', [
        'entity'   => 'currencies',
        'severity' => 'alert',
        'summary'  => 'Base currency changed from ' . $from . ' to ' . $to
                    . ' at ' . rtrim(rtrim(number_format($rate, 6), '0'), '.') . ' ' . $from
                    . ' per ' . $to . '; every stored amount was converted',
        'before'   => $before,
        'after'    => ['base_currency' => $to, 'currency_symbol' => setting('currency_symbol', '')],
    ]);

    return ['ok' => true, 'message' => 'Base currency is now ' . $to . '. Every price, wallet and '
                                     . 'order amount was converted at '
                                     . rtrim(rtrim(number_format($rate, 6), '0'), '.')
                                     . ' ' . $from . ' per ' . $to . '.'];
}
