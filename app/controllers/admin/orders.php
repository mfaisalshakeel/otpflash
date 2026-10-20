<?php
/** Orders: the list, one order's detail, and every action an admin can take. */
require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/guard.php';
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/payfields.php';

$action = $params[0] ?? 'index';

// --- a payment screenshot --------------------------------------------------
// Served through here rather than from a public folder: these are kept under
// storage/, which .htaccess denies, because a payment screenshot carries a
// bank balance and a person's name and a guessable URL would hand that to
// anyone who tried one. Reaching this line already means a signed-in admin -
// _middleware.php saw to that.
if ($action === 'proof') {
    $order = one('SELECT payment_details FROM orders WHERE id = ?', [(int) ($params[1] ?? 0)]);
    $key   = (string) ($params[2] ?? '');
    $answer = payfields_stored($order ?? [])[$key] ?? null;

    if (!$answer || ($answer['type'] ?? '') !== 'image') {
        http_response_code(404);
        exit;
    }
    $path = payfield_image_path((string) $answer['value']);
    if ($path === null) {
        http_response_code(404);
        exit;
    }

    $info = @getimagesize($path);
    header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($path);
    exit;
}

// --------------------------------------------------------------- actions --
if ($action === 'action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['id'] ?? 0);
    $what  = (string) ($_POST['do'] ?? '');
    $order = one('SELECT * FROM orders WHERE id = ?', [$id]);

    if (!$order) {
        flash('error', 'That order no longer exists.');
        redirect('admin/orders');
    }

    switch ($what) {
        case 'mark_paid':
            // Conditional on the status it is moving away from, so two admins
            // on the same order cannot both mark it paid and both buy a number.
            $tookIt = claim('orders', [
                'status'      => 'paid',
                'paid_at'     => $order['paid_at'] ?: date('Y-m-d H:i:s'),
                'paid_amount' => $order['paid_amount'] ?? $order['price'],
                'updated_at'  => date('Y-m-d H:i:s'),
            ], "id = ? AND status IN ('pending', 'cancelled')", [$id]);

            if (!$tookIt) {
                flash('error', 'That order is already paid - somebody got there first.');
                redirect('admin/orders/' . $id);
            }

            order_log($id, 'Payment confirmed by admin.');
            audit_row('order.paid', 'orders', $id, $order,
                ['summary' => $order['code'] . ' marked paid by hand', 'severity' => 'warn']);

            $paidName = (string) col('SELECT name FROM payment_methods WHERE id = ?',
                [(int) $order['payment_method_id']], 'admin');
            order_paid_notices($order, $paidName, (string) $order['trx_id']);

            [$ok, $message] = fulfil_order($id);
            flash($ok ? 'success' : 'warning', 'Marked as paid. ' . $message);
            break;

        case 'buy':
            [$ok, $message] = fulfil_order($id);
            flash($ok ? 'success' : 'error', $message);
            break;

        case 'check':
            number_check($id, true);
            if ($order['product'] === 'rental' || $order['product'] === 'renewal') {
                $rentalId = (int) (order_meta($order)['rental_id'] ?? 0);
                if ($rentalId) {
                    rental_refresh($rentalId, true);
                }
            }
            flash('success', 'Asked OTPGet. The order below shows what it said.');
            break;

        case 'change':
            [$ok, $message] = number_change($id);
            flash($ok ? 'success' : 'error', $message);
            break;

        case 'release':
            // Give the number back without buying another: the admin is
            // closing this order, by refund or by a conversation.
            $activation = order_activation($order);
            $can = number_can_release($order, $activation);
            if (!$can['ok']) {
                flash('error', $can['reason']);
                break;
            }
            [$released, $why] = number_release($order, $activation);
            if ($released) {
                number_lapsed($order, $activation, true, false);
                flash('success', 'The number was given back to OTPGet and its cost refunded to us.');
            } else {
                flash('error', $why);
            }
            break;

        case 'refund_wallet':
            if (empty($order['user_id'])) {
                flash('error', 'A guest order has no wallet. Refund the payment by hand, then mark it refunded.');
                break;
            }
            if ($order['paid_at'] === null) {
                flash('error', 'Nothing was paid on this order.');
                break;
            }
            $ok = refund_order_to_wallet($order, 'Refunded by admin',
                ['paid', 'processing', 'completed', 'expired', 'api_error']);
            flash($ok ? 'success' : 'error', $ok ? 'Refunded to the customer\'s wallet.' : 'Already refunded.');
            break;

        case 'mark_refunded':
            update_row('orders', ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            order_log($id, 'Marked refunded by admin (money returned outside the shop).');
            audit_row('order.refunded', 'orders', $id, $order,
                ['summary' => $order['code'] . ' marked refunded by hand', 'severity' => 'warn']);
            flash('success', 'Marked as refunded.');
            break;

        case 'cancel':
            if ($order['paid_at'] !== null) {
                flash('error', 'This order is paid for. Refund it instead of cancelling it.');
                break;
            }
            update_row('orders', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?', [$id]);
            order_log($id, 'Order cancelled by admin.');
            flash('success', 'Order cancelled.');
            break;

        case 'progress':
            $want = (string) ($_POST['status'] ?? '');
            if (!in_array($want, ORDER_STATUSES, true)) {
                flash('error', 'That is not an order status.');
                break;
            }
            $fields = ['status' => $want, 'updated_at' => date('Y-m-d H:i:s')];
            $code   = trim((string) ($_POST['otp_code'] ?? ''));
            if ($code !== '') {
                $fields['otp_code'] = mb_substr($code, 0, 190);
            }
            if ($want === 'paid' && !$order['paid_at']) {
                $fields['paid_at'] = date('Y-m-d H:i:s');
            }
            update_row('orders', $fields, 'id = ?', [$id]);

            $said = $want !== $order['status'] ? $order['status'] . ' to ' . $want : 'status unchanged';
            if ($code !== '') {
                $said .= ', code set to ' . $code;
            }
            order_log($id, 'Set by admin: ' . $said . '.');
            audit_row('order.progress', 'orders', $id, $order,
                ['severity' => 'warn', 'summary' => $order['code'] . ': ' . $said]);
            flash('success', 'Saved - ' . $said . '.');
            break;

        case 'note':
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($note === '') {
                flash('error', 'The note was empty.');
                break;
            }
            order_log($id, 'Note: ' . $note);
            flash('success', 'Note added.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    // Back where the button was pressed. The same actions are offered on the
    // list and on the order, and an action from the list that lands on the
    // order loses the page, the filters and the admin's place in a hundred
    // rows they were working through.
    $back = (string) ($_POST['back'] ?? '');
    redirect($back !== '' ? 'admin/orders?' . $back : 'admin/orders/' . $id);
}

// ---------------------------------------------------------------- detail --
if (ctype_digit((string) $action)) {
    $id    = (int) $action;
    $order = one(
        'SELECT o.*, pm.name AS payment_name, u.name AS user_name, u.email AS user_email,
                u.balance AS user_balance
           FROM orders o
           LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
           LEFT JOIN users u            ON u.id  = o.user_id
          WHERE o.id = ?',
        [$id]
    );

    if (!$order) {
        flash('error', 'That order no longer exists.');
        redirect('admin/orders');
    }

    view('admin/order', [
        'title'    => 'Order ' . $order['code'],
        'subtitle' => 'Orders',
        'order'       => $order,
        'state'       => number_state($order),
        'activations' => all('SELECT * FROM activations WHERE order_id = ? ORDER BY id DESC', [$id]),
        'rental'      => !empty(order_meta($order)['rental_id'])
                           ? one('SELECT * FROM rentals WHERE id = ?', [(int) order_meta($order)['rental_id']]) : null,
        'logs'        => all('SELECT * FROM order_logs WHERE order_id = ? ORDER BY created_at DESC, id DESC', [$id]),
        // optional_all: the table arrives with a migration, and an order has
        // to open on a database that has not run it yet.
        'calls'       => optional_all('SELECT * FROM order_calls WHERE order_id = ? ORDER BY id DESC LIMIT 200', [$id]),
    ], 'layouts/admin');
}

// ------------------------------------------------------------------ list --
$filters = [
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'status'      => (string) ($_GET['status'] ?? ''),
    'product'     => (string) ($_GET['product'] ?? ''),
    'user'        => (string) ($_GET['user'] ?? ''),
    'date'        => (string) ($_GET['date'] ?? ''),
];

$where = [];
$binds = [];

if ($filters['q'] !== '') {
    // Whatever the gateway reported is searched as well, so a bank reference
    // off a statement finds its order without anybody knowing which field it
    // was in - and the number itself, which is what a customer quotes.
    $columns = ['o.code', 'o.number', 'o.email', 'o.whatsapp', 'o.trx_id', 'o.label', 'o.gateway_details'];

    $clause = '(' . implode(' LIKE ? OR ', $columns) . ' LIKE ?';
    foreach ($columns as $unused) {
        $binds[] = '%' . $filters['q'] . '%';
    }

    // A bare number is the row's own id as well. Customers were shown that
    // for a while and some of them wrote it down, so an order quoted as "24"
    // still has to be findable - the code is what they are shown now.
    if (ctype_digit($filters['q'])) {
        $clause .= ' OR o.id = ?';
        $binds[] = (int) $filters['q'];
    }

    $where[] = $clause . ')';
}
if ($filters['status'] !== '') {
    $where[] = 'o.status = ?';
    $binds[] = $filters['status'];
}
if ($filters['product'] !== '') {
    $where[] = 'o.product = ?';
    $binds[] = $filters['product'];
}
if ($filters['user'] !== '' && ctype_digit($filters['user'])) {
    $where[] = 'o.user_id = ?';
    $binds[] = (int) $filters['user'];
}
if ($filters['date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
    $where[] = 'DATE(o.created_at) = ?';
    $binds[] = $filters['date'];
}

// The page length is the admin's setting now, not a number written here.
require_once APP_PATH . '/helpers/paging.php';

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total    = (int) col("SELECT COUNT(*) FROM orders o{$whereSql}", $binds, 0);

$pages   = paging($total);
$page    = $pages['page'];
$perPage = $pages['per'];
$offset  = $pages['offset'];

$orders = all(
    "SELECT o.*, u.email AS user_email
       FROM orders o
       LEFT JOIN users u ON u.id = o.user_id
       {$whereSql}
   ORDER BY o.created_at DESC, o.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $binds
);

view('admin/orders', [
    'title'     => 'Orders',
    'subtitle'  => 'All customer orders',
    'orders'    => $orders,
    'filters'   => $filters,
    'products'  => array_map(static fn ($p) => $p['name'], PRODUCTS)
                     + ['renewal' => 'Rental renewals', 'deposit' => 'Wallet deposits'],
    'total'     => $total,
    'paging'    => $pages,
], 'layouts/admin');
