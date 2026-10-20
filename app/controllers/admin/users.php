<?php
/**
 * Customers: the list, one customer, and the things an admin does to one.
 *
 *   GET  /admin/users          the list
 *   GET  /admin/users/7        one customer: wallet, orders, rentals
 *   POST /admin/users/action   adjust the wallet, block, unblock, send a reset
 *
 * The wallet is only ever moved through wallet_credit()/wallet_debit(), so an
 * adjustment from here is a statement line like any other and the customer
 * sees it, with the note, in their own portal.
 */
require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/users.php';
require_once APP_PATH . '/helpers/paging.php';
require_once APP_PATH . '/helpers/stats.php';

$action = $params[0] ?? 'index';

// --------------------------------------------------------------- actions --
if ($action === 'action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['id'] ?? 0);
    $what = (string) ($_POST['do'] ?? '');
    $user = one('SELECT * FROM users WHERE id = ?', [$id]);

    if (!$user) {
        flash('error', 'That customer no longer exists.');
        redirect('admin/users');
    }

    switch ($what) {
        case 'adjust':
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $note   = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 190);
            $way    = (string) ($_POST['way'] ?? 'credit');

            if ($amount <= 0) {
                flash('error', 'Enter an amount above zero.');
                break;
            }
            // The note is what the customer reads on their statement, so an
            // adjustment nobody explained is one they will write in about.
            if ($note === '') {
                flash('error', 'Add a note - the customer sees it on their statement.');
                break;
            }

            $adminId = (int) (admin_user()['id'] ?? 0) ?: null;
            if ($way === 'debit') {
                if (!wallet_debit($id, $amount, 'adjust', null, $note, $adminId)) {
                    flash('error', 'The balance is ' . money((float) $user['balance']) . ' - it cannot go below zero.');
                    break;
                }
            } else {
                wallet_credit($id, $amount, 'adjust', null, $note, $adminId);
            }

            audit('wallet.adjust', [
                'entity'   => 'users', 'entity_id' => $id, 'severity' => 'warn',
                'summary'  => ($way === 'debit' ? 'Took ' : 'Added ') . money($amount)
                              . ($way === 'debit' ? ' from ' : ' to ') . $user['email'] . ': ' . $note,
            ]);
            flash('success', ($way === 'debit' ? 'Taken ' : 'Added ') . money($amount) . '.');
            break;

        case 'block':
        case 'unblock':
            $status = $what === 'block' ? 'blocked' : 'active';
            update_row('users', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit_row('user.' . $what, 'users', $id, $user, [
                'severity' => 'warn',
                'summary'  => $user['email'] . ($what === 'block' ? ' blocked' : ' unblocked'),
            ]);
            flash('success', $what === 'block'
                ? 'Blocked. They are signed out at their next click and cannot sign back in.'
                : 'Unblocked.');
            break;

        // For a customer who has lost the inbox their sign-in code goes to.
        case 'two_factor_off':
            if (table_has_column('users', 'two_factor')) {
                update_row('users', ['two_factor' => 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                audit_row('user.two_factor', 'users', $id, $user, [
                    'severity' => 'warn',
                    'summary'  => 'Two-step sign-in turned off for ' . $user['email'] . ' by an admin',
                ]);
                flash('success', 'Two-step sign-in is off for this account; their password alone signs them in.');
            }
            break;

        case 'reset':
            user_send_reset((string) $user['email']);
            audit('user.reset_sent', ['entity' => 'users', 'entity_id' => $id,
                'summary' => 'Reset link sent to ' . $user['email']]);
            flash('success', 'A reset link is on its way to ' . $user['email'] . '.');
            break;

        case 'save':
            $name  = trim((string) ($_POST['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A name and a working email are both needed.');
                break;
            }
            if (col('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
                flash('error', 'Another account already uses ' . $email . '.');
                break;
            }
            update_row('users', ['name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => $phone,
                'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit_row('user.edit', 'users', $id, $user, ['summary' => 'Details of ' . $email . ' edited']);
            flash('success', 'Saved.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
    redirect('admin/users/' . $id);
}

// ---------------------------------------------------------------- detail --
if (ctype_digit((string) $action)) {
    $id   = (int) $action;
    $user = one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user) {
        flash('error', 'That customer no longer exists.');
        redirect('admin/users');
    }

    $earning = earning_statuses();
    $marks   = implode(', ', array_fill(0, count($earning), '?'));
    $summary = one(
        "SELECT COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN product <> 'deposit' THEN price ELSE 0 END), 0) AS spent,
                COALESCE(SUM(CASE WHEN product <> 'deposit' THEN price - cost ELSE 0 END), 0) AS profit
           FROM orders WHERE user_id = ? AND status IN ($marks)",
        array_merge([$id], $earning)
    );

    view('admin/user', [
        'title'     => $user['name'],
        'subtitle'  => 'Customers',
        'user'      => $user,
        'summary'   => $summary,
        'deposited' => (float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = ? AND type = 'deposit'", [$id], 0),
        'orders'    => all('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$id]),
        'rentals'   => all('SELECT * FROM rentals WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]),
        'statement' => wallet_statement($id, 25),
    ], 'layouts/admin');
}

// ------------------------------------------------------------------ list --
$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'sort'   => (string) ($_GET['sort'] ?? ''),
];

$where = [];
$binds = [];
if ($filters['q'] !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?' . (ctype_digit($filters['q']) ? ' OR u.id = ?' : '') . ')';
    array_push($binds, '%' . $filters['q'] . '%', '%' . $filters['q'] . '%', '%' . $filters['q'] . '%');
    if (ctype_digit($filters['q'])) {
        $binds[] = (int) $filters['q'];
    }
}
if (in_array($filters['status'], ['active', 'blocked'], true)) {
    $where[] = 'u.status = ?';
    $binds[] = $filters['status'];
}
if ($filters['status'] === 'funded') {
    $where[] = 'u.balance > 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$order = [
    'balance' => 'u.balance DESC, u.id DESC',
    'seen'    => 'u.last_login_at DESC, u.id DESC',
    'orders'  => 'orders DESC, u.id DESC',
][$filters['sort']] ?? 'u.id DESC';

$paging = paging((int) col("SELECT COUNT(*) FROM users u $whereSql", $binds, 0));
$users  = all(
    "SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.product <> 'deposit') AS orders
       FROM users u $whereSql
   ORDER BY $order
      LIMIT {$paging['per']} OFFSET {$paging['offset']}",
    $binds
);

view('admin/users', [
    'title'    => 'Customers',
    'subtitle' => qty_fmt($paging['total']) . ' ' . plural('account', $paging['total'])
                  . ' · ' . money((float) col('SELECT COALESCE(SUM(balance), 0) FROM users', [], 0)) . ' held in wallets',
    'users'    => $users,
    'filters'  => $filters,
    'paging'   => $paging,
], 'layouts/admin');
