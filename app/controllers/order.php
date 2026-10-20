<?php
/**
 * Placing an order, paying for it, and watching the number.
 *
 *   POST /order                 create an order (JSON for fetch, redirect without JS)
 *   GET  /order/{CODE}          payment, then the live number and its code
 *   POST /order/{CODE}/pay      submit a manual payment's transaction id
 *   POST /order/{CODE}/start    hand off to a gateway
 *   GET  /order/{CODE}/qr       the hosted QR page
 *   POST /order/{CODE}/check    has the money landed?
 *   POST /order/{CODE}/status   has the code landed? (the number page polls this)
 *   POST /order/{CODE}/change   swap the number for a free replacement
 *   POST /order/{CODE}/cancel   give it back and refund the wallet (account holders)
 *   POST /order/{CODE}/retry    try again after OTPGet had nothing, or the number lapsed
 *
 * Nothing the browser sends about money is trusted: the price is read from
 * the offer row every time.
 */
require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/payments.php';
require_once APP_PATH . '/helpers/notify.php';
require_once APP_PATH . '/helpers/guard.php';
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/payfields.php';

$code = $params[0] ?? null;

// ============================================================== create ====
if ($code === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    // $field names the input the message is about, where one owns it. The
    // browser then writes it under that input instead of in a banner.
    $fail = static function (string $message, ?string $field = null, array $extra = []) use ($wantsJson) {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_filter([
                'ok'    => false,
                'error' => $message,
                'field' => $field,
            ], static fn ($value) => $value !== null) + $extra);
            exit;
        }
        flash('error', $message);
        redirect($_SERVER['HTTP_REFERER'] ?? '');
    };

    // A bot filling the hidden field looks exactly like success to the bot.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $fail('Something went wrong. Please try again.');
    }

    if (!rate_limit('place_order', 20, 600)) {
        $fail('That is a lot of orders in a short time. Please wait a few minutes.');
    }

    $user  = current_user();
    $pay   = ($_POST['pay'] ?? 'gateway') === 'wallet' ? 'wallet' : 'gateway';
    $email = trim((string) ($_POST['email'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $offer = sellable_offer((int) ($_POST['offer_id'] ?? 0));

    if (!$offer) {
        $fail('That option has just sold out or changed. Please pick again.');
    }

    // A rental is an inbox somebody comes back to for weeks, so it belongs
    // to an account; and a shop may choose to sell only to account holders.
    if (!$user && ($offer['product'] === 'rental' || setting('guest_checkout', '1') !== '1')) {
        $fail('Please sign in first - it takes ten seconds.', null, ['login' => url('login')]);
    }

    if ($pay === 'wallet' && !$user) {
        $pay = 'gateway';
    }
    // A wallet that cannot cover it is passed over rather than refused: the
    // customer pressed Pay, and paying directly is what they meant.
    if ($pay === 'wallet' && (float) $user['balance'] + 0.001 < offer_sale_price($offer) && payable_methods()) {
        $pay = 'gateway';
    }

    // An order nobody can pay for is not an order.
    if ($pay === 'gateway' && !payable_methods()) {
        $fail('Card and wallet payments are switched off for a moment. '
            . ($user ? 'Pay from your wallet, or try again shortly.' : 'Please try again shortly.'));
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('That email address does not look right. Leave it blank if you would rather not give one.', 'email');
    }
    // Digits with an optional + and the spaces people type; a country code is
    // what makes it something WhatsApp can open, so 7 to 15 digits.
    $digits = preg_replace('/\D/', '', $whatsapp);
    if ($whatsapp !== '' && (!preg_match('/^\+?[\d\s()-]+$/', $whatsapp) || strlen($digits) < 7 || strlen($digits) > 15)) {
        $fail('That WhatsApp number does not look right - include the country code, e.g. +92 300 1234567. '
            . 'Or leave it blank.', 'whatsapp');
    }
    $whatsapp = $whatsapp !== '' ? (str_starts_with($whatsapp, '+') ? '+' : '') . $digits : '';

    // The price the customer was shown, against OTPGet's price now. A cost
    // the sync last saw hours ago is read again first (offer_refresh), and a
    // price that went up is shown before it is charged - never after.
    $shown = offer_sale_price($offer);
    $fresh = offer_refresh($offer);
    if (!$fresh) {
        require_once APP_PATH . '/helpers/storefront.php';
        $fail('That option has just sold out. Please pick another.', null, ['repick' => picker_options_html($offer)]);
    }
    $offer = $fresh;
    $price = offer_sale_price($offer);
    if ($price > $shown + 0.009) {
        require_once APP_PATH . '/helpers/storefront.php';
        $fail('The price went up to ' . money($price) . ' a moment ago (it was ' . money($shown) . '). '
            . 'Tap the button again to buy at the new price.', null, ['repick' => picker_options_html($offer)]);
    }

    // Checked before the order exists, so a wallet that is short leaves no
    // stray unpaid order behind - just the way to top it up.
    if ($pay === 'wallet' && (float) $user['balance'] + 0.001 < $price) {
        $fail('Your wallet has ' . money((float) $user['balance']) . ' and this costs ' . money($price)
            . '. Add funds, or pay another way.', null, ['topup' => url('account/wallet?amount=' . ceil($price - (float) $user['balance']))]);
    }

    [$orderId, $orderCode] = place_offer_order($offer, $user, $email, $whatsapp);
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);

    if ($pay === 'wallet') {
        $paid = pay_order_from_wallet($order, $user);
        if (!$paid['ok']) {
            $fail($paid['message']);
        }
        $next = url('order/' . $orderCode);
        if ($offer['product'] === 'rental') {
            $rentalId = (int) (order_meta(one('SELECT * FROM orders WHERE id = ?', [$orderId]) ?: [])['rental_id'] ?? 0);
            if ($rentalId) {
                $next = url('account/rentals/' . $rentalId);
            }
        }
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'code' => $orderCode, 'redirect' => $next]);
            exit;
        }
        redirect($next);
    }

    if ($order['email'] !== '') {
        notify('order_placed', [
            'order_code' => $orderCode,
            'service'    => order_label($order),
            'amount'     => money($price),
            'status'     => 'Awaiting payment',
            'order_url'  => url('order/' . $orderCode),
            'email'      => $order['email'],
        ]);
    }

    if ($wantsJson) {
        // Where exactly one gateway takes the payment itself there is nothing
        // to choose, so the payment is begun here and the answer carries the
        // page it begins on - one hop from the button to the thing being paid.
        $sole = sole_redirect_method();
        $next = url('order/' . $orderCode);
        $mode = 'page';

        if ($sole) {
            $started = payment_start($sole, $order);
            if (!empty($started['redirect'])) {
                update_row('orders', ['payment_method_id' => (int) $sole['id'],
                    'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
                order_log($orderId, 'Sent to ' . $sole['name'] . ' to pay.');
                $next = (string) $started['redirect'];
                $mode = 'redirect';
            } elseif (!empty($started['error'])) {
                order_log($orderId, 'Could not start ' . $sole['name'] . ': ' . $started['error']);
            }
        }

        // With a choice to make, the choosing happens in the box the customer
        // is already in: the same partial the order page renders.
        $payStep = $mode === 'page' ? render('partials/pay', [
            'order'     => $order,
            'methods'   => payable_methods(),
            'gateways'  => payment_gateways(),
            'chat'      => chat_channels('order'),
            'payErrors' => [],
        ]) : '';

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => true,
            'code'     => $orderCode,
            'amount'   => money($price),
            'label'    => order_label($order),
            'payment'  => ['mode' => $mode, 'name' => $sole['name'] ?? ''],
            'pay'      => $payStep,
            'redirect' => $next,
        ]);
        exit;
    }
    redirect('order/' . $orderCode);
}

if ($code === null) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

// ============================================================ one order ====
// The code is the only thing guarding this page, and the page shows a live
// number and its code. A real customer reloads a handful of times and the
// page's own polling goes through /status, which has its own budget; this
// stops anyone walking the code space looking for them.
if (($params[1] ?? '') === '' && !rate_limit('view_order', 80, 600)) {
    http_response_code(429);
    view('_404', ['title' => 'Too many requests', 'meta_robots' => 'noindex, nofollow']);
}

$order = order_full($code);

if (!$order) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

// ------------------------------------------------------------- payment ----
if (($params[1] ?? '') === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Answered where the customer is standing. This step is shown inside the
    // order modal as often as it is shown on the order page, and sending them
    // to another page to read one sentence loses the box they were working
    // in. Without the script the same failures flash and redirect, which is
    // what they always did.
    $asJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $answer = static function (array $payload, string $flash, string $type = 'error') use (
        $asJson, $order
    ): never {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload + ['ok' => false, 'error' => $flash]);
            exit;
        }
        if ($flash !== '') {
            flash($type, $flash);
        }
        redirect((string) ($payload['redirect'] ?? 'order/' . $order['code']));
    };

    if (!rate_limit('submit_payment', 12, 600)) {
        $answer([], 'Too many attempts. Please wait a few minutes.');
    }

    if ($order['status'] !== 'pending') {
        $answer(['ok' => true, 'redirect' => url('order/' . $order['code'])],
            'This order is already paid for.', 'warning');
    }

    $methodId = (int) ($_POST['payment_method_id'] ?? 0);
    $trx      = trim((string) ($_POST['trx_id'] ?? ''));
    $amount   = (float) str_replace(',', '', (string) ($_POST['paid_amount'] ?? 0));

    $method = one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1', [$methodId]);
    if (!$method) {
        $answer([], 'Please choose a payment method.');
    }

    // Same rule as /start: hidden on the way in, and refused here too.
    if (!payment_currency_ready($method)['ok']) {
        $answer([], 'That payment method is not available right now. Please choose another one.');
    }

    // What this method asks for is read from the method, never from the form:
    // a posted field nobody defined does not exist, and a required one cannot
    // be skipped by removing it from the markup.
    $collected = payfields_collect($method, $_POST, $_FILES, (string) $order['code']);

    if (!$collected['ok']) {
        keep_old($_POST);
        $_SESSION['_pay_errors'] = $collected['errors'];
        // The field names come back as well, so the box that is wrong can be
        // the one that says so rather than a sentence above all of them.
        $answer(['fields' => $collected['errors']],
            'Please check the payment details: ' . implode(' ', $collected['errors']));
    }

    // The gateway decides what counts as a valid submission. The manual one
    // just wants a reference; a hosted one may check for real.
    $check = payment_verify($method, $order,
        ['trx_id' => $collected['reference'] !== '' ? $collected['reference'] : $trx,
         'paid_amount' => $amount]);

    if (!$check['ok']) {
        $answer([], $check['message'] !== ''
            ? $check['message']
            : 'That payment could not be accepted.');
    }

    $reference = mb_substr((string) $check['reference'], 0, 120);

    $fields = [
        'payment_method_id' => (int) $method['id'],
        'trx_id'            => $reference,
        'paid_amount'       => $amount > 0 ? $amount : null,
        'payment_details'   => $collected['answers']
                                 ? json_encode($collected['answers'],
                                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                 : null,
        'updated_at'        => date('Y-m-d H:i:s'),
    ];

    // A gateway that really verified the money can mark the order paid itself.
    if (!empty($check['confirmed'])) {
        $fields['status']  = 'paid';
        $fields['paid_at'] = date('Y-m-d H:i:s');
    }

    // Only while it is still waiting. A customer who submits the form twice -
    // or leaves it open and comes back after an admin has confirmed - must not
    // reopen a paid order or overwrite the reference that was accepted.
    $recorded = claim('orders', $fields,
        "id = ? AND status = 'pending'", [$order['id']]);

    if (!$recorded) {
        $answer(['ok' => true, 'redirect' => url('order/' . $order['code'])],
            'We already have your payment for this order.', 'success');
    }

    order_log((int) $order['id'], 'Customer submitted payment via ' . $method['name']
        . ($reference !== '' ? ' - reference ' . $reference : '') . '.');
    audit('order.payment_submitted', [
        'entity' => 'orders', 'entity_id' => (int) $order['id'], 'severity' => 'warn',
        'summary' => $order['code'] . ': ' . money($amount > 0 ? $amount : (float) $order['price'])
                   . ' via ' . $method['name']
                   . ($reference !== '' ? ', reference ' . $reference : ''),
        'after'   => ['status' => $fields['status'] ?? $order['status'], 'trx_id' => $reference],
    ]);

    notify('admin_payment_submitted', [
        'order_code' => $order['code'],
        'amount'     => money($amount > 0 ? $amount : (float) $order['price']),
        'method'     => $method['name'],
        'reference'  => $reference !== '' ? $reference : 'none given',
        'admin_url'  => url('admin/orders/' . $order['id']),
        'admin_link' => 'admin/orders/' . $order['id'],
    ]);

    if (!empty($check['confirmed'])) {
        order_log((int) $order['id'], 'Payment confirmed by ' . $method['name'] . '.');
        fulfil_order((int) $order['id']);
        $answer(['ok' => true, 'redirect' => url('order/' . $order['code'])],
            'Payment confirmed. Your number is ready.', 'success');
    }

    $answer(['ok' => true, 'redirect' => url('order/' . $order['code'])],
        'Thank you. We are confirming your payment now - this usually takes a few minutes.',
        'success');
}

// --- hand off to a hosted gateway -------------------------------------------
if (($params[1] ?? '') === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $asJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $answer = static function (array $payload, string $flash = '') use ($asJson, $order): never {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            exit;
        }
        if ($flash !== '') {
            flash('error', $flash);
        }
        redirect(!empty($payload['redirect']) && empty($payload['ok'])
            ? 'order/' . $order['code']
            : (string) ($payload['redirect'] ?? 'order/' . $order['code']));
    };

    $method = one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1',
        [(int) ($_POST['payment_method_id'] ?? 0)]);

    if (!$method) {
        $answer(['ok' => false, 'error' => 'Please choose a payment method.'],
            'Please choose a payment method.');
    }

    // A method that cannot work out what to charge is refused here as well as
    // hidden on the way in: the id came off a form, and a form is a thing
    // people can edit.
    if (!payment_currency_ready($method)['ok']) {
        $answer(['ok' => false, 'error' => 'That payment method is not available right now. '
                                         . 'Please choose another one.'],
            'That payment method is not available right now. Please choose another one.');
    }

    $start = payment_start($method, $order);

    if (!empty($start['error'])) {
        $told = payment_trouble($method, $order, (string) $start['error']);
        $answer(['ok' => false, 'error' => $told], $told);
    }

    // The gateway is happy to begin, but what it sends the customer to is our
    // own QR page - and that page is where it actually asks the gateway for
    // something to draw. Asking here instead means a refusal arrives while
    // the customer is still in the box they ordered from, rather than as a
    // page that loads only to bounce them back.
    if (!empty($start['redirect']) && str_contains((string) $start['redirect'], '/qr')) {
        $drawing = payment_qr($method, $order);

        if (($drawing['error'] ?? '') !== '' || trim((string) ($drawing['text'] ?? '')) === '') {
            $told = payment_trouble($method, $order, (string) ($drawing['error'] ?? 'no code came back'));
            $answer(['ok' => false, 'error' => $told], $told);
        }
    }

    if (!empty($start['redirect'])) {
        update_row('orders', ['payment_method_id' => (int) $method['id']], 'id = ?', [$order['id']]);
        order_log((int) $order['id'], 'Sent to ' . $method['name'] . ' to pay.');
        $answer(['ok' => true, 'redirect' => (string) $start['redirect']]);
    }

    // Nothing to redirect to: the order page takes a reference as usual.
    $answer(['ok' => true, 'redirect' => url('order/' . $order['code'])]);
}

// --- the hosted QR page -----------------------------------------------------
// A gateway that can be watched rather than typed sends the customer here
// instead of away: the QR is ours, the waiting is ours, and the order marks
// itself paid the moment the gateway says the money landed.
if (($params[1] ?? '') === 'qr') {
    require_once APP_PATH . '/helpers/qr.php';

    // Nothing to scan for an order that is already settled, and a live QR on
    // one is an invitation to pay for it twice.
    if ($order['status'] !== 'pending') {
        redirect('order/' . $order['code']);
    }

    $method = $order['payment_method_id']
        ? one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1',
            [(int) $order['payment_method_id']])
        : null;

    // Opened from a kept link rather than reached through /start, so the order
    // does not name a method yet. Nothing is written down to fix that: the
    // check below answers the same question the same way, and a GET that
    // changes an order is a GET a reload can surprise somebody with.
    $method ??= sole_watched_method();

    $drawing = $method ? payment_qr($method, $order) : [];

    // Reached straight from a kept link, or the code has since expired and
    // the gateway will not make another. The customer gets the same sentence
    // they would have got in the modal, and the reason is written down.
    if (!$drawing || $drawing['text'] === '' || $drawing['error'] !== '') {
        flash('error', $method
            ? payment_trouble($method, $order, (string) ($drawing['error'] ?? 'no code came back'))
            : 'That payment method cannot show a QR code. Please pay from this page instead.');
        redirect('order/' . $order['code']);
    }

    // The encoder stops at 271 bytes. Nothing we build comes close, but a
    // gateway's own text is a gateway's own business, so a refusal lands here
    // rather than as a 500 on a page the customer is trying to pay from.
    try {
        $svg = qr_svg($drawing['text'], 230, 'QR code to pay order ' . $order['code']);
    } catch (RuntimeException $e) {
        log_line('QR for order ' . $order['code'] . ' could not be drawn: ' . $e->getMessage());
        flash('error', 'The payment QR could not be drawn. Please pay from this page instead.');
        redirect('order/' . $order['code']);
    }

    echo render('order-qr', [
        'title'       => 'Pay for order ' . $order['code'],
        'meta_robots' => 'noindex, nofollow',
        'order'       => $order,
        'method'      => $method,
        'qr'          => $drawing,
        'svg'         => $svg,
    ]);
    exit;
}

// --- has the money landed? --------------------------------------------------
// Asked by the QR page every few seconds, and by cron for the customer who
// paid and closed the tab. Both answers come from the gateway, never from the
// browser: this endpoint takes no input beyond which order it is about.
if (($params[1] ?? '') === 'check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $asJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    // PHP holds an exclusive lock on the session file for the whole request,
    // and this one spends most of its life waiting on a gateway. Every other
    // request from the same browser - the next poll, the page itself, any
    // link they press - queues behind it, which reads as the whole site
    // hanging rather than as one slow call. The token has been checked and
    // nothing below writes to the session, so the lock goes now.
    //
    // The path without the script still flashes, so it keeps its session.
    if ($asJson) {
        session_write_close();
    }
    $answer = static function (array $payload) use ($asJson, $order): never {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            exit;
        }
        if (($payload['message'] ?? '') !== '') {
            flash($payload['state'] === 'paid' ? 'success' : 'warning', (string) $payload['message']);
        }
        redirect($payload['state'] === 'paid'
            ? 'order/' . $order['code']
            : 'order/' . $order['code'] . '/qr');
    };

    // A poll is one outbound request to the gateway, so the budget is the
    // page's own pace with room to spare - not something to be ground on.
    if (!rate_limit('check_payment', 200, 600)) {
        $answer(['state' => 'waiting', 'message' => 'Checking too often. Please wait a moment.']);
    }

    // Settled while the page was watching, by an admin or by the last poll.
    // Paid and beyond is the good news the page is waiting for; cancelled or
    // refunded is news too, and it is not "keep waiting".
    if ($order['status'] !== 'pending') {
        $answer(in_array($order['status'], ['cancelled', 'refunded'], true)
            ? ['state' => 'failed', 'message' => 'This order is no longer open.']
            : ['state' => 'paid', 'message' => '', 'redirect' => url('order/' . $order['code'])]);
    }

    $method = $order['payment_method_id']
        ? one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1',
            [(int) $order['payment_method_id']])
        : null;

    $method ??= sole_watched_method();

    if (!$method) {
        $answer(['state' => 'waiting', 'message' => 'This order has no payment method to check.']);
    }

    $result = payment_poll($method, $order);

    // Worth recording the first time it is seen and worthless every time
    // after: a short payment or a gateway that cannot be reached.
    if ($result['state'] !== 'paid' && ($result['summary'] ?? '') !== '') {
        order_log_once((int) $order['id'], (string) $result['summary']);
    }

    if ($result['state'] === 'paid') {
        // Not checked: whoever loses the claim is still being told the truth,
        // which is that this order is paid for.
        confirm_order_payment($order, $method, $result);

        $answer([
            'state'    => 'paid',
            'message'  => $result['message'] !== '' ? $result['message'] : 'Payment received.',
            'redirect' => url('order/' . $order['code']),
        ]);
    }

    $answer(['state' => $result['state'], 'message' => (string) $result['message']]);
}

// --------------------------------------------- the number, while it lives --
// Polled by the number page every few seconds, one request at a time, and
// it is what asks OTPGet - throttled per number inside number_check(), so
// however many tabs are open OTPGet hears from us every few seconds at most.
if (in_array($params[1] ?? '', ['status', 'change', 'cancel', 'retry'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do     = (string) $params[1];
    $asJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    // The session file is locked for the whole request and a status check
    // spends most of its life waiting on OTPGet; nothing below writes the
    // session on the script's path, so the lock goes now.
    if ($asJson) {
        session_write_close();
    }
    $answer = static function (bool $ok, string $message = '') use ($asJson, $order): never {
        $fresh = one('SELECT * FROM orders WHERE id = ?', [(int) $order['id']]) ?: $order;
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'message' => $message, 'state' => number_state($fresh)]);
            exit;
        }
        if ($message !== '') {
            flash($ok ? 'success' : 'error', $message);
        }
        redirect('order/' . $order['code']);
    };

    if (!rate_limit('order_' . $do, $do === 'status' ? 400 : 20, 600)) {
        $answer(false, 'Too many requests. Please wait a moment.');
    }

    if ($do === 'status') {
        number_check((int) $order['id']);
        $answer(true);
    }

    if ($do === 'change') {
        [$ok, $message] = number_change((int) $order['id']);
        $answer($ok, $ok ? 'Here is a new number.' : $message);
    }

    if ($do === 'retry') {
        $state = number_state($order);
        if (!$state['can_retry']) {
            $answer(false, 'This order cannot take another number.');
        }
        [$ok, $message] = number_buy((int) $order['id']);
        $answer($ok, $ok ? 'Here is your number.' : $message);
    }

    // cancel: only the account the order belongs to, because the refund
    // goes to a wallet and the wallet is theirs.
    $user = current_user();
    if (!$user || (int) $user['id'] !== (int) ($order['user_id'] ?? 0)) {
        $answer(false, 'Sign in to the account that placed this order to cancel it.');
    }
    [$ok, $message] = number_cancel((int) $order['id']);
    $answer($ok, $message);
}

// ---------------------------------------------------------------- view ----
// A paid rental lives in the portal, with its inbox.
if ($order['product'] === 'rental' && $order['status'] === 'completed') {
    $rentalId = (int) (order_meta($order)['rental_id'] ?? 0);
    $user     = current_user();
    if ($rentalId && $user && (int) $user['id'] === (int) $order['user_id']) {
        redirect('account/rentals/' . $rentalId);
    }
}

$numberState = in_array($order['product'], NUMBER_PRODUCTS, true) ? number_state($order) : null;

view('order', [
    'seo'         => ['title' => 'Order ' . $order['code'], 'robots' => 'noindex, nofollow'],
    'order'       => $order,
    'state'       => $numberState,
    'owner'       => ($u = current_user()) && (int) $u['id'] === (int) ($order['user_id'] ?? 0),
    'methods'     => payable_methods(),
    'gateways'    => payment_gateways(),
    // A gateway that takes the payment on its own pages sends the customer
    // back here, and coming back is not what makes an order paid - asking is.
    'checkOnLoad' => (static function () use ($order): bool {
        if ($order['status'] !== 'pending' || !$order['payment_method_id']) {
            return false;
        }
        $method = one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1',
            [(int) $order['payment_method_id']]);
        return $method !== null && payment_is_watched($method);
    })(),
    'payErrors'   => (static function (): array {
        $errors = $_SESSION['_pay_errors'] ?? [];
        unset($_SESSION['_pay_errors']);
        return is_array($errors) ? $errors : [];
    })(),
]);
