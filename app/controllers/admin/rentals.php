<?php
/**
 * Rentals: every number a customer has rented, and its inbox.
 *
 *   GET  /admin/rentals          the list
 *   GET  /admin/rentals/7        one rental and every SMS it received
 *   POST /admin/rentals/action   check the inbox now, cancel, close, extend
 *
 * Cancelling goes through rental_cancel(), the customer's own button, so
 * OTPGet's rules (server 2, twenty minutes, no SMS yet) are checked the same
 * way whoever presses it. Closing and extending are ours alone: they change
 * our record, not OTPGet's, for the conversation that happens around one.
 */
require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/paging.php';

$action = $params[0] ?? 'index';

// --------------------------------------------------------------- actions --
if ($action === 'action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $what   = (string) ($_POST['do'] ?? '');
    $rental = one('SELECT * FROM rentals WHERE id = ?', [$id]);

    if (!$rental) {
        flash('error', 'That rental no longer exists.');
        redirect('admin/rentals');
    }

    switch ($what) {
        case 'check':
            $new = rental_refresh($id, true);
            flash('success', $new ? qty_fmt($new) . ' new message(s).' : 'Asked OTPGet - nothing new.');
            break;

        case 'cancel':
            [$ok, $message] = rental_cancel($rental);
            if ($ok) {
                audit_row('rental.cancelled', 'rentals', $id, $rental,
                    ['severity' => 'warn', 'summary' => 'Rental ' . $rental['number'] . ' cancelled by admin']);
            }
            flash($ok ? 'success' : 'error', $ok ? 'Cancelled, and the customer was refunded to their wallet.' : $message);
            break;

        case 'close':
            update_row('rentals', ['status' => 'ended', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit_row('rental.closed', 'rentals', $id, $rental,
                ['severity' => 'warn', 'summary' => 'Rental ' . $rental['number'] . ' closed by admin']);
            flash('success', 'Closed. The customer no longer sees an open inbox for it.');
            break;

        case 'extend':
            $hours = max(1, min(24 * 90, (int) ($_POST['hours'] ?? 0)));
            $from  = max(time(), strtotime((string) $rental['expires_at']) ?: time());
            update_row('rentals', [
                'expires_at' => date('Y-m-d H:i:s', $from + $hours * 3600),
                'status'     => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);
            audit_row('rental.extended', 'rentals', $id, $rental,
                ['severity' => 'warn', 'summary' => 'Rental ' . $rental['number'] . ' extended by ' . $hours . 'h on our side']);
            flash('success', 'Extended on our side. OTPGet\'s own expiry is unchanged - renew there too if the number must keep working.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
    redirect('admin/rentals/' . $id);
}

// ---------------------------------------------------------------- detail --
if (ctype_digit((string) $action)) {
    $id     = (int) $action;
    $rental = one('SELECT r.*, u.name AS user_name, u.email AS user_email, o.code AS order_code
                     FROM rentals r
                     LEFT JOIN users u  ON u.id = r.user_id
                     LEFT JOIN orders o ON o.id = r.order_id
                    WHERE r.id = ?', [$id]);
    if (!$rental) {
        flash('error', 'That rental no longer exists.');
        redirect('admin/rentals');
    }

    require_once APP_PATH . '/helpers/paging.php';
    $inbox = paging((int) col('SELECT COUNT(*) FROM rental_messages WHERE rental_id = ?', [$id], 0), 50);
    view('admin/rental', [
        'title'    => 'Rental ' . ($rental['number'] !== '' ? $rental['number'] : '#' . $id),
        'subtitle' => 'Rentals',
        'rental'   => $rental,
        'messages' => all('SELECT * FROM rental_messages WHERE rental_id = ? ORDER BY id DESC LIMIT '
                          . $inbox['per'] . ' OFFSET ' . $inbox['offset'], [$id]),
        'inbox'    => $inbox,
        // Matched with what follows the id too, so rental 5 does not collect 50's renewals.
        'renewals' => all("SELECT * FROM orders WHERE product = 'renewal' AND (meta LIKE ? OR meta LIKE ?) ORDER BY id DESC",
                          ['%"rental_id":' . $id . ',%', '%"rental_id":' . $id . '}%']),
    ], 'layouts/admin');
}

// ------------------------------------------------------------------ list --
$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'user'   => (string) ($_GET['user'] ?? ''),
];

$where = [];
$binds = [];
if ($filters['q'] !== '') {
    $where[] = '(r.number LIKE ? OR r.country_name LIKE ? OR u.email LIKE ? OR r.provider_order_id LIKE ?)';
    array_push($binds, ...array_fill(0, 4, '%' . $filters['q'] . '%'));
}
if (in_array($filters['status'], ['active', 'expired', 'cancelled', 'ended'], true)) {
    $where[] = 'r.status = ?';
    $binds[] = $filters['status'];
}
if (ctype_digit($filters['user'])) {
    $where[] = 'r.user_id = ?';
    $binds[] = (int) $filters['user'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from     = 'FROM rentals r LEFT JOIN users u ON u.id = r.user_id';

$paging  = paging((int) col("SELECT COUNT(*) $from $whereSql", $binds, 0));
$rentals = all(
    "SELECT r.*, u.email AS user_email,
            (SELECT COUNT(*) FROM rental_messages m WHERE m.rental_id = r.id) AS messages
       $from $whereSql
   ORDER BY r.id DESC
      LIMIT {$paging['per']} OFFSET {$paging['offset']}",
    $binds
);

view('admin/rentals', [
    'title'    => 'Rentals',
    'subtitle' => qty_fmt((int) col("SELECT COUNT(*) FROM rentals WHERE status = 'active'", [], 0)) . ' active now',
    'rentals'  => $rentals,
    'filters'  => $filters,
    'paging'   => $paging,
], 'layouts/admin');
