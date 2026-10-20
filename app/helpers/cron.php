<?php
/**
 * Scheduled work.
 *
 * Every job is a task in one registry, so the admin can list them, see when
 * each last ran and what it did, and run any of them by hand. The CLI script
 * (cron.php) and the URL endpoint (app/controllers/cron.php) both come through
 * here, and both can run everything or one named task.
 *
 * Each run is recorded in cron_runs, which is what the Cron screen shows.
 */

require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/payments.php';
require_once APP_PATH . '/helpers/currency.php';
require_once APP_PATH . '/helpers/guard.php';
require_once APP_PATH . '/helpers/audit.php';

/**
 * The jobs, in the order a full run does them.
 *
 * Each handler returns a one-line summary. Throwing is fine - the runner
 * records the failure and carries on with the next task.
 *
 * Cron is meant to run every minute here, not every five: a number lives
 * for twenty minutes, and a customer who closed the tab is still owed the
 * code - or the refund - inside that window.
 */
function cron_tasks(): array
{
    return [
        'check_payments' => [
            'label'    => 'Check watched payments',
            'detail'   => 'Asks the gateway about orders still awaiting payment on a method '
                        . 'that confirms itself. This is what catches the customer who paid '
                        . 'the QR and then closed the tab.',
            'every'    => 'Every minute',
            'handler'  => 'cron_check_payments',
        ],
        'buy_numbers' => [
            'label'    => 'Deliver paid orders',
            'detail'   => 'Buys the number, rents the rental or credits the deposit for any '
                        . 'order that is paid and still waiting - an order an admin marked '
                        . 'paid by hand lands here.',
            'every'    => 'Every minute',
            'handler'  => 'cron_buy_numbers',
        ],
        'check_numbers' => [
            'label'    => 'Watch live numbers',
            'detail'   => 'Asks OTPGet for the code on every number still waiting, gives back '
                        . 'the ones whose time is up, and refunds account holders whose number '
                        . 'never received an SMS.',
            'every'    => 'Every minute',
            'handler'  => 'cron_check_numbers',
        ],
        'rentals' => [
            'label'    => 'Rental inboxes',
            'detail'   => 'Pulls new SMS into active rentals and closes the ones that ran out.',
            'every'    => 'Every 5 minutes',
            'handler'  => 'cron_rentals',
            'throttle' => 280,
        ],
        'sync_catalogue' => [
            'label'    => 'Sync the OTPGet catalogue',
            'detail'   => 'Reads a few countries per run, oldest first, so prices and stock '
                        . 'stay current without any one run getting long.',
            'every'    => 'Every 10 minutes',
            'handler'  => 'cron_sync_catalogue',
            'enabled'  => 'sync_on_cron',
            'throttle' => 580,
        ],
        'currency_rates' => [
            'label'    => 'Update currency rates',
            'detail'   => 'Pulls fresh exchange rates so OTPGet costs convert correctly, then '
                        . 'reprices the catalogue.',
            'every'    => 'Daily',
            'handler'  => 'cron_currency_rates',
            'enabled'  => 'currency_auto_update',
            'throttle' => 43200,
        ],
        'balances' => [
            'label'    => 'Check the OTPGet balance',
            'detail'   => 'Keeps the figure on the dashboard honest, and warns when it runs low.',
            'every'    => 'Every 15 minutes',
            'handler'  => 'cron_balances',
            'throttle' => 880,
        ],
        'housekeeping' => [
            'label'    => 'Housekeeping',
            'detail'   => 'Clears expired throttle rows, old cron history, abandoned '
                        . 'locks and routine audit entries past their keep-days. '
                        . 'Warnings and alerts in the audit log are never pruned.',
            'every'    => 'Daily',
            'handler'  => 'cron_housekeeping',
            'throttle' => 43200,
        ],
    ];
}

// ===========================================================================
// The tasks
// ===========================================================================

/**
 * Catch the payments the browser was not around to see confirmed.
 *
 * Only orders on a method that can be asked, only while they are still
 * awaiting payment, and only for a day: a QR nobody ever scanned would
 * otherwise be asked about for ever, one outbound request at a time.
 */
function cron_check_payments(): string
{
    $since = date('Y-m-d H:i:s', time() - 86400);

    $waiting = all("SELECT o.* FROM orders o
                     WHERE o.status = 'pending'
                       AND o.payment_method_id IS NOT NULL
                       AND o.created_at >= ?
                  ORDER BY o.created_at DESC LIMIT 25", [$since]);

    $asked = $paid = 0;

    foreach ($waiting as $order) {
        $method = one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1',
            [(int) $order['payment_method_id']]);

        if (!$method || !payment_is_watched($method)) {
            continue;
        }

        $asked++;
        $result = payment_poll($method, $order);

        if ($result['state'] !== 'paid') {
            if (($result['summary'] ?? '') !== '') {
                order_log_once((int) $order['id'], (string) $result['summary']);
            }
            continue;
        }

        if (confirm_order_payment($order, $method, $result)) {
            $paid++;
        }
    }

    return $asked
        ? sprintf('%d asked, %d confirmed', $asked, $paid)
        : 'nothing to check';
}

/**
 * Deliver what is paid and still waiting.
 *
 * Numbers only when the shop buys them automatically; with that off, a paid
 * number order waits for the admin's Buy button on purpose.
 */
function cron_buy_numbers(): string
{
    $waiting = all("SELECT id, product, pay_source FROM orders
                     WHERE status = 'paid' AND paid_at IS NOT NULL
                  ORDER BY paid_at ASC LIMIT 20");

    $done = $failed = 0;
    foreach ($waiting as $order) {
        if (in_array($order['product'], NUMBER_PRODUCTS, true)
            && setting('auto_buy_number', '1') !== '1' && $order['pay_source'] !== 'wallet') {
            continue;
        }
        [$ok] = fulfil_order((int) $order['id']);
        $ok ? $done++ : $failed++;
    }

    return $done + $failed > 0 ? sprintf('%d delivered, %d failed', $done, $failed) : 'nothing waiting';
}

/** Check every number still waiting, and close the ones that are done. */
function cron_check_numbers(): string
{
    $open = all("SELECT o.id FROM orders o
                   JOIN activations a ON a.id = o.activation_id
                  WHERE o.status IN ('processing', 'completed') AND a.status IN ('waiting', 'received')
                    AND a.expires_at > ?
               ORDER BY COALESCE(a.checked_at, a.created_at) ASC LIMIT 60",
        [date('Y-m-d H:i:s', time() - 3600)]);

    foreach ($open as $order) {
        number_check((int) $order['id']);
    }

    // Lapsed by the clock while nobody was asking: the check above only
    // reaches them if they are recent, so anything older is expired here.
    $stuck = all("SELECT o.id FROM orders o
                    JOIN activations a ON a.id = o.activation_id
                   WHERE o.status = 'processing' AND a.status = 'waiting' AND a.expires_at <= ?
                   LIMIT 30", [date('Y-m-d H:i:s', time() - 3600)]);
    foreach ($stuck as $order) {
        number_check((int) $order['id'], true);
    }

    $finished = numbers_finish_due();

    return sprintf('%d checked, %d closed', count($open) + count($stuck), $finished);
}

function cron_rentals(): string
{
    $expired = rentals_expire();
    $new     = 0;
    foreach (all("SELECT id FROM rentals WHERE status = 'active' ORDER BY COALESCE(checked_at, created_at) LIMIT 40")
             as $rental) {
        $new += rental_refresh((int) $rental['id']);
    }
    return sprintf('%d new message(s), %d expired', $new, $expired);
}

function cron_sync_catalogue(): string
{
    if (!otpget_configured()) {
        return 'no OTPGet key yet';
    }
    $result = sync_catalogue_batch(max(1, min(50, (int) setting('sync_batch', '24'))));
    $line   = sprintf('%d unit(s), %d offers', $result['units'], $result['offers']);

    return $result['errors'] ? $line . ' - ' . implode('; ', array_slice($result['errors'], 0, 3)) : $line;
}

function cron_currency_rates(): string
{
    $result = refresh_currency_rates();
    if (!$result['ok']) {
        throw new RuntimeException($result['message']);
    }
    // A rate moving is a cost moving, so the prices follow it.
    return $result['message'] . ', ' . reprice_offers() . ' price(s) changed';
}

function cron_balances(): string
{
    if (!otpget_configured()) {
        return 'no OTPGet key yet';
    }
    $result = otpget_balance_refresh();
    if (!$result['ok']) {
        throw new RuntimeException($result['message']);
    }
    return 'balance ' . $result['shown'];
}

function cron_housekeeping(): string
{
    $limits = q('DELETE FROM rate_limits WHERE created_at < ?',
        [date('Y-m-d H:i:s', time() - 86400)])->rowCount();

    $runs = q('DELETE FROM cron_runs WHERE created_at < ?',
        [date('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();

    // A lock whose holder died is already ignored once it expires; this only
    // stops the table growing. Audit pruning keeps warnings and alerts.
    require_once APP_PATH . '/helpers/audit.php';
    $locks = locks_prune();
    $audit = audit_prune();

    return sprintf('%d throttle rows, %d old cron runs, %d stale lock(s), %d audit entries cleared',
        $limits, $runs, $locks, $audit);
}

// ===========================================================================
// The runner
// ===========================================================================

/** When a task last finished, or null. */
function cron_last_run(string $task): ?array
{
    return one('SELECT * FROM cron_runs WHERE task = ? ORDER BY id DESC LIMIT 1', [$task]);
}

/**
 * Run one task and record it.
 *
 * `$force` ignores both the on/off setting and the throttle, which is what
 * the Run now button in the admin does.
 */
function cron_run_task(string $key, string $source = 'cron', bool $force = false): array
{
    $tasks = cron_tasks();
    if (!isset($tasks[$key])) {
        return ['task' => $key, 'ok' => false, 'summary' => 'Unknown task', 'skipped' => false];
    }

    $task = $tasks[$key];

    if (!$force && isset($task['enabled']) && setting($task['enabled'], '1') !== '1') {
        return ['task' => $key, 'ok' => true, 'summary' => 'turned off in settings', 'skipped' => true];
    }

    // Tasks that do not need to run on every one-minute tick say how long
    // they want between runs.
    if (!$force && isset($task['throttle'])) {
        $last = cron_last_run($key);
        if ($last && (time() - strtotime($last['created_at'])) < $task['throttle']) {
            return ['task' => $key, 'ok' => true, 'summary' => 'not due yet', 'skipped' => true];
        }
    }

    $started = microtime(true);
    $ok      = true;

    try {
        $summary = ($task['handler'])();
    } catch (Throwable $e) {
        $ok      = false;
        $summary = $e->getMessage();
        log_line('Cron task ' . $key . ' failed: ' . $summary);
    }

    $duration = (int) round((microtime(true) - $started) * 1000);

    insert_row('cron_runs', [
        'task'        => $key,
        'source'      => $source,
        'ok'          => $ok ? 1 : 0,
        'summary'     => mb_substr((string) $summary, 0, 500),
        'duration_ms' => $duration,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    return ['task' => $key, 'ok' => $ok, 'summary' => $summary, 'skipped' => false, 'ms' => $duration];
}

/**
 * Run everything, or one named task.
 *
 * Returns a plain-text report, which is what cron emails and what the URL
 * endpoint prints.
 */
function run_cron_tasks(?string $only = null, string $source = 'cron'): string
{
    // New code on a database that has not been migrated: half the columns a
    // job needs are missing, and a job that half-runs against money is worse
    // than one that does not run. Say what is wrong and stop.
    require_once APP_PATH . '/helpers/migrate.php';
    $pending = migrations_pending();
    if ($pending) {
        return '[' . date('c') . '] cron stopped - the database is behind the code. '
            . count($pending) . ' change(s) not applied. '
            . 'Open /admin/update and run it.' . PHP_EOL;
    }

    // One run at a time. A one-minute schedule and a run that takes two
    // minutes overlap, and both would pick up the same 'paid, not yet
    // delivered' orders and both buy them. The URL endpoint makes it likelier still:
    // anyone who knows the key can fire it while the CLI job is mid-flight.
    $output = with_lock('cron:' . ($only ?? 'all'), static function () use ($only, $source) {
        $lines = ['[' . date('c') . '] cron start' . ($only ? ' (' . $only . ' only)' : '')];
        $keys  = $only !== null ? [$only] : array_keys(cron_tasks());

        foreach ($keys as $key) {
            $result = cron_run_task($key, $source, $only !== null);
            $lines[] = sprintf('  %-16s %s%s',
                $key,
                $result['skipped'] ? '- ' : ($result['ok'] ? 'ok ' : 'FAILED '),
                $result['summary']
            );
        }

        $lines[] = '[' . date('c') . '] cron done';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }, 1800);

    if ($output === null) {
        // Not an error: the work is being done, just not by us.
        return '[' . date('c') . '] cron skipped - another run is still going.' . PHP_EOL;
    }

    return $output;
}
