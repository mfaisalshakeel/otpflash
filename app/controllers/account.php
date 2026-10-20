<?php
/**
 * The customer portal.
 *
 *   /account                         dashboard: balance, live numbers, recent orders
 *   /account/orders                  every order
 *   /account/rentals                 every rental
 *   /account/rentals/7               one rental and its inbox
 *   POST /account/rentals/7/inbox    JSON: new messages since (the inbox polls this)
 *   POST /account/rentals/7/renew    extend it
 *   POST /account/rentals/7/cancel   hand it back inside twenty minutes
 *   /account/wallet                  balance, statement, add funds
 *   POST /account/wallet             start a deposit
 *   /account/profile                 name, phone, email, password
 *
 * Everything here is the signed-in customer's own and nobody else's: every
 * query is narrowed to their id, so an id in the address that belongs to
 * somebody else reads as not found rather than as forbidden.
 */
require_once APP_PATH . '/helpers/orders.php';

/** SMS on one page of a rental's inbox; a long rental collects hundreds. */
const RENTAL_INBOX_PAGE = 30;
require_once APP_PATH . '/helpers/payments.php';
require_once APP_PATH . '/helpers/users.php';
require_once APP_PATH . '/helpers/paging.php';

require_login();
$user    = current_user();
$userId  = (int) $user['id'];
$section = $params[0] ?? '';

$page = static function (string $view, string $title, array $data = []) use ($user, $section): never {
    view('account/' . $view, $data + [
        'seo'     => ['title' => $title, 'robots' => 'noindex, nofollow'],
        'user'    => current_user(true) ?? $user,
        'section' => $section === '' ? 'dashboard' : $section,
        'body_class' => 'is-portal',
    ]);
};

// ============================================================= dashboard ==
if ($section === '') {
    $live = all("SELECT o.*, s.icon AS service_icon, s.color AS service_color
                   FROM orders o LEFT JOIN services s ON s.id = o.service_id
                  WHERE o.user_id = ? AND o.status IN ('paid', 'processing')
                     OR (o.user_id = ? AND o.status = 'completed' AND o.completed_at >= ? AND o.product IN ('sms','premium','cheap','email'))
               ORDER BY o.created_at DESC LIMIT 6",
        [$userId, $userId, date('Y-m-d H:i:s', time() - 1800)]);

    $page('dashboard', 'My account', [
        'live'    => $live,
        'recent'  => all('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 8', [$userId]),
        // Active and not yet run out: cron closes an expired one within
        // minutes, but the dashboard should not call it active meanwhile.
        'rentals' => all("SELECT * FROM rentals WHERE user_id = ? AND status = 'active' AND expires_at > ?
                           ORDER BY expires_at LIMIT 4", [$userId, date('Y-m-d H:i:s')]),
        'stats'   => [
            'numbers'  => (int) col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND product IN ('sms','premium','cheap','email') AND paid_at IS NOT NULL", [$userId], 0),
            'codes'    => (int) col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'completed' AND otp_code <> ''", [$userId], 0),
            'spent'    => (float) col("SELECT COALESCE(SUM(-amount), 0) FROM wallet_transactions WHERE user_id = ? AND amount < 0", [$userId], 0)
                        - (float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = ? AND type = 'refund'", [$userId], 0)
                        + (float) col("SELECT COALESCE(SUM(price), 0) FROM orders WHERE user_id = ? AND pay_source = 'gateway' AND product <> 'deposit' AND paid_at IS NOT NULL AND status <> 'refunded'", [$userId], 0),
            'refunded' => (float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = ? AND type = 'refund'", [$userId], 0),
        ],
    ]);
}

// ================================================================ orders ==
if ($section === 'orders') {
    $status = (string) ($_GET['status'] ?? '');
    $where  = 'user_id = ?' . (in_array($status, ORDER_STATUSES, true) ? ' AND status = ?' : '') . " AND product <> 'deposit'";
    $binds  = in_array($status, ORDER_STATUSES, true) ? [$userId, $status] : [$userId];
    $pages  = paging((int) col("SELECT COUNT(*) FROM orders WHERE {$where}", $binds, 0), 20);

    $page('orders', 'My orders', [
        'orders' => all("SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT {$pages['per']} OFFSET {$pages['offset']}", $binds),
        'paging' => $pages,
        'status' => $status,
    ]);
}

// =============================================================== rentals ==
if ($section === 'rentals') {
    $rentalId = (int) ($params[1] ?? 0);

    if ($rentalId === 0) {
        $page('rentals', 'My rentals', [
            'rentals' => all('SELECT r.*, (SELECT COUNT(*) FROM rental_messages m WHERE m.rental_id = r.id) AS messages
                                FROM rentals r WHERE r.user_id = ? ORDER BY r.status = \'active\' DESC, r.expires_at DESC', [$userId]),
        ]);
    }

    $rental = one('SELECT * FROM rentals WHERE id = ? AND user_id = ?', [$rentalId, $userId]);
    if (!$rental) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
    $do = $params[2] ?? '';

    // The inbox asks every few seconds, through the same throttle cron uses.
    if ($do === 'inbox' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        session_write_close();
        rental_refresh($rentalId);
        // The newest page only: the live inbox is the first page, and the
        // count is of everything so a new SMS is noticed wherever it lands.
        $messages = all('SELECT * FROM rental_messages WHERE rental_id = ? ORDER BY received_at DESC, id DESC LIMIT '
            . RENTAL_INBOX_PAGE, [$rentalId]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true,
            'count' => (int) col('SELECT COUNT(*) FROM rental_messages WHERE rental_id = ?', [$rentalId], 0),
            'html' => render('account/_inbox', ['messages' => $messages])]);
        exit;
    }

    if ($do === 'renew' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $choice = null;
        foreach (rental_renew_options($rental) as $option) {
            if ((int) $option['id'] === (int) ($_POST['offer_id'] ?? 0)) {
                $choice = $option;
            }
        }
        if (!$choice || $rental['status'] !== 'active') {
            flash('error', 'That length is not on offer for this number right now.');
            redirect('account/rentals/' . $rentalId);
        }

        $hours = (int) $choice['provider_service'];
        [$orderId, $code] = insert_order_code([
            'user_id'      => $userId,
            'offer_id'     => (int) $choice['id'],
            'product'      => 'renewal',
            'server'       => (string) $rental['provider'],
            'country_id'   => $choice['country_id'],
            'country_name' => (string) $rental['country_name'],
            'label'        => 'Renew ' . $rental['number'] . ' · ' . $choice['label'],
            'email'        => (string) $user['email'],
            'number'       => (string) $rental['number'],
            'price'        => (float) $choice['sale_price'],
            'cost'         => round((float) (to_base((float) $choice['cost'], (string) $choice['cost_currency']) ?? 0), 2),
            'status'       => 'pending',
            'meta'         => json_encode(['rental_id' => $rentalId, 'hours' => $hours, 'option' => $choice['label'],
                                           'product' => 'rental', 'server' => $rental['provider'],
                                           'provider_country' => $rental['country_code'],
                                           'provider_service' => (string) $hours,
                                           'cost' => (float) $choice['cost'], 'cost_currency' => $choice['cost_currency']]),
            'ip'           => client_ip(),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        order_log($orderId, 'Renewal of rental #' . $rentalId . ' for ' . $choice['label'] . ' placed.');

        if (($_POST['pay'] ?? 'wallet') === 'wallet') {
            $paid = pay_order_from_wallet(one('SELECT * FROM orders WHERE id = ?', [$orderId]), current_user(true));
            if ($paid['ok']) {
                $fresh = one('SELECT status FROM orders WHERE id = ?', [$orderId]);
                flash($fresh['status'] === 'completed' ? 'success' : 'error', $fresh['status'] === 'completed'
                    ? 'Renewed for ' . $choice['label'] . '.' : 'The renewal could not be applied; your wallet was refunded.');
                redirect('account/rentals/' . $rentalId);
            }
            flash('error', $paid['message']);
        }
        redirect('order/' . $code);
    }

    if ($do === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        [$ok, $message] = rental_cancel($rental);
        flash($ok ? 'success' : 'error', $message);
        redirect('account/rentals/' . $rentalId);
    }

    rental_refresh($rentalId);
    $rental = one('SELECT * FROM rentals WHERE id = ?', [$rentalId]);

    require_once APP_PATH . '/helpers/paging.php';
    $inboxPaging = paging((int) col('SELECT COUNT(*) FROM rental_messages WHERE rental_id = ?', [$rentalId], 0),
        RENTAL_INBOX_PAGE);
    $page('rental', 'Rental ' . $rental['number'], [
        'rental'   => $rental,
        'messages' => all('SELECT * FROM rental_messages WHERE rental_id = ? ORDER BY received_at DESC, id DESC LIMIT '
                          . $inboxPaging['per'] . ' OFFSET ' . $inboxPaging['offset'], [$rentalId]),
        'paging'   => $inboxPaging,
        'options'  => $rental['status'] === 'active' ? rental_renew_options($rental) : [],
        'iso'      => strtolower((string) $rental['country_code']),
        'canCancel'=> (string) $rental['provider'] === '2' && $rental['status'] === 'active'
                       && time() - strtotime((string) $rental['created_at']) < 20 * 60,
    ]);
}

// ================================================================ wallet ==
if ($section === 'wallet') {
    $min = max(1, (float) setting('min_deposit', '100'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $amount = round((float) str_replace(',', '', (string) ($_POST['amount'] ?? '0')), 2);

        if (!payable_methods()) {
            flash('error', 'Deposits are switched off for a moment. Please try again shortly.');
            redirect('account/wallet');
        }
        if ($amount < $min || $amount > 1000000) {
            flash('error', 'Add at least ' . money($min) . '.');
            redirect('account/wallet');
        }
        if (!rate_limit('deposit', 10, 600)) {
            flash('error', 'Several deposits were started just now. Please finish one of them first.');
            redirect('account/wallet');
        }

        [$orderId, $code] = insert_order_code([
            'user_id'    => $userId,
            'product'    => 'deposit',
            'label'      => 'Wallet top-up',
            'email'      => (string) $user['email'],
            'price'      => $amount,
            'cost'       => 0,
            'status'     => 'pending',
            'ip'         => client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        order_log($orderId, 'Deposit of ' . money($amount) . ' started.');
        redirect('order/' . $code);
    }

    $pages = paging((int) col('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?', [$userId], 0), 25);
    $page('wallet', 'My wallet', [
        'lines'    => wallet_statement($userId, $pages['per'], $pages['offset']),
        'paging'   => $pages,
        'min'      => $min,
        'suggest'  => max($min, (float) ($_GET['amount'] ?? 0)),
        'pending'  => all("SELECT * FROM orders WHERE user_id = ? AND product = 'deposit' AND status = 'pending'
                            AND created_at >= ? ORDER BY created_at DESC LIMIT 5",
                          [$userId, date('Y-m-d H:i:s', time() - 3 * 86400)]),
        'canPay'   => (bool) payable_methods(),
        // Three totals under the balance, one query each by type so the
        // figures cannot borrow from one another.
        'totals'   => [
            'Added'    => (float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
                                        WHERE user_id = ? AND type = 'deposit'", [$userId], 0),
            'Spent'    => -(float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
                                         WHERE user_id = ? AND type IN ('purchase', 'rental', 'renewal')", [$userId], 0),
            'Refunded' => (float) col("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
                                        WHERE user_id = ? AND type = 'refund'", [$userId], 0),
        ],
    ]);
}

// =============================================================== profile ==
if ($section === 'profile') {
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $what = (string) ($_POST['form'] ?? 'details');

        if ($what === 'details') {
            $name  = trim((string) ($_POST['name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['name'] = 'Tell us what to call you.';
            }
            if ($phone !== '' && !preg_match('/^\+?[\d\s-]{7,20}$/', $phone)) {
                $errors['phone'] = 'That number does not look right.';
            }
            if (!$errors) {
                update_row('users', ['name' => $name, 'phone' => $phone, 'updated_at' => date('Y-m-d H:i:s')],
                    'id = ?', [$userId]);
                flash('success', 'Saved.');
                redirect('account/profile');
            }
        }

        if ($what === 'password') {
            $check = one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
            $new   = (string) ($_POST['password'] ?? '');
            if (!password_verify((string) ($_POST['current'] ?? ''), (string) $check['password_hash'])) {
                $errors['current'] = 'That is not your current password.';
            } elseif (strlen($new) < 8) {
                $errors['password'] = 'Use at least 8 characters.';
            } elseif ($new !== (string) ($_POST['password_confirm'] ?? '')) {
                $errors['password_confirm'] = 'The two passwords do not match.';
            }
            if (!$errors) {
                user_set_password($userId, $new);
                session_regenerate_id(true);
                flash('success', 'Your password is changed.');
                redirect('account/profile');
            }
        }
    }

    $page('profile', 'My profile', ['errors' => $errors]);
}

require CONTROLLER_PATH . '/_404.php';
