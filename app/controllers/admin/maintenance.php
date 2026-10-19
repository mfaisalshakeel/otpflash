<?php
/**
 * Maintenance: emptying things out.
 *
 * Every action here is irreversible, so each one is typed out in full rather
 * than clicked through, and each writes an audit entry before it runs - the
 * record of a wipe is the one thing a wipe must not take with it.
 *
 * Clearing the catalogue does not damage order history. `orders.offer_id`,
 * `service_id` and `country_id` are ON DELETE SET NULL, and every order
 * carries its own names, price, cost and what OTPGet needs to buy it again,
 * so a finished order still reads correctly after its catalogue is gone.
 */
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/payfields.php';

/**
 * What each button does, in the order the screen lists them.
 *
 * `tables` are emptied in the order given, so a child table goes before the
 * parent it points at - SQLite enforces foreign keys here and MySQL always
 * has.
 */
$JOBS = [
    'catalogue' => [
        'label'   => 'Clear the catalogue',
        'blurb'   => 'Deletes every offer synced from OTPGet. Services, countries and their SEO '
                   . 'text stay, and the next sync puts the offers back at today\'s prices. '
                   . 'Orders keep what they were placed with.',
        'button'  => 'Delete all offers',
        'tables'  => ['offers'],
        'counted' => 'offers',
    ],
    'orders' => [
        'label'   => 'Clear orders',
        'blurb'   => 'Deletes every order, the numbers bought for it, its log and any payment '
                   . 'screenshots uploaded with it. For clearing out test orders before you go '
                   . 'live. Rentals and wallets are left alone.',
        'button'  => 'Delete all orders',
        'tables'  => ['activations', 'order_logs', 'orders'],
        'counted' => 'orders',
        'files'   => true,
    ],
    'fresh' => [
        'label'   => 'Start fresh',
        'blurb'   => 'Everything above, plus customers and their wallets, rentals, contact '
                   . 'messages, notifications, the email log and the cron history. Keeps your '
                   . 'admin account, settings, services, countries, payment methods, currencies, '
                   . 'pages, blog and FAQs - and keeps the audit log, which is where this wipe is '
                   . 'recorded.',
        'button'  => 'Empty it out',
        'tables'  => ['rental_messages', 'rentals', 'wallet_transactions', 'activations',
                      'order_logs', 'orders', 'users', 'offers',
                      'messages', 'notifications', 'email_log', 'cron_runs',
                      'rate_limits', 'locks'],
        'counted' => 'orders',
        'files'   => true,
    ],
];

// --------------------------------------------------------------- run it ----
if (($params[0] ?? '') === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key   = (string) ($_POST['job'] ?? '');
    $typed = strtoupper(trim((string) ($_POST['confirm'] ?? '')));
    $job   = $JOBS[$key] ?? null;

    if (!$job) {
        flash('error', 'Unknown action.');
        redirect('admin/maintenance');
    }

    if ($typed !== 'DELETE') {
        flash('error', 'Type DELETE in the box to confirm. Nothing was changed.');
        redirect('admin/maintenance');
    }

    // Counted and recorded before anything goes, so the entry says what was
    // there rather than what is left.
    $counts = [];
    foreach ($job['tables'] as $table) {
        if (table_exists($table)) {
            $counts[$table] = (int) col("SELECT COUNT(*) FROM `{$table}`", [], 0);
        }
    }

    audit('maintenance.' . $key, [
        'entity'   => 'maintenance',
        'severity' => 'alert',
        'summary'  => $job['label'] . ': '
                    . implode(', ', array_map(
                        static fn($t, $n) => qty_fmt($n) . ' ' . str_replace('_', ' ', (string) $t),
                        array_keys($counts), $counts)),
        'before'   => $counts,
    ]);

    // The screenshots are files, not rows, and nothing else points at them
    // once their orders are gone.
    if (!empty($job['files'])) {
        $store = payfield_store();
        foreach (glob($store . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitignore') {
                @unlink($file);
            }
        }
    }

    $deleted = 0;
    foreach ($job['tables'] as $table) {
        if (!table_exists($table)) {
            continue;
        }
        $deleted += q("DELETE FROM `{$table}`")->rowCount();
    }

    flash('success', $job['label'] . ' done - ' . qty_fmt($deleted) . ' row(s) deleted.');
    redirect('admin/maintenance');
}

// ----------------------------------------------------------------- show ----
$rows = [];
foreach ($JOBS as $key => $job) {
    $counts = [];
    foreach ($job['tables'] as $table) {
        if (table_exists($table)) {
            $counts[$table] = (int) col("SELECT COUNT(*) FROM `{$table}`", [], 0);
        }
    }
    $rows[$key] = $job + ['counts' => $counts, 'total' => array_sum($counts)];
}

view('admin/maintenance', [
    'title'    => 'Maintenance',
    'subtitle' => 'Emptying things out',
    'jobs'     => $rows,
], 'layouts/admin');
