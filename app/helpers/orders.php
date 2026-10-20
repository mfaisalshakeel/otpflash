<?php
/**
 * Order lifecycle: the code, the log, and what happens the moment an order
 * is paid for. The work after that belongs to whatever was bought - a number
 * (numbers.php), a rental (rentals.php) or money into the wallet (wallet.php)
 * - and fulfil_order() is the one switch that hands it over.
 */

require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/notify.php';
require_once APP_PATH . '/helpers/guard.php';
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/wallet.php';
require_once APP_PATH . '/helpers/numbers.php';
require_once APP_PATH . '/helpers/rentals.php';

/**
 * Every status an order can be in, in the order it travels through them.
 *
 *   pending     placed, not paid yet
 *   paid        paid, nothing bought yet (or waiting for an admin to buy)
 *   processing  a number is live and waiting for its SMS
 *   completed   the code arrived - or the rental is running, or the deposit landed
 *   expired     the number lapsed with no code; a replacement can still be had
 *   cancelled   closed before it was paid for
 *   refunded    the money went back - to the wallet, or by hand
 *   api_error   OTPGet refused the purchase; kept with its words, to retry
 */
const ORDER_STATUSES = ['pending', 'paid', 'processing', 'completed',
                        'expired', 'cancelled', 'refunded', 'api_error'];

/** What each status reads as, to a customer. */
const ORDER_STATUS_WORDS = [
    'pending'    => 'Awaiting payment',
    'paid'       => 'Getting your number',
    'processing' => 'Waiting for SMS',
    'completed'  => 'Completed',
    'expired'    => 'No SMS - number expired',
    'cancelled'  => 'Cancelled',
    'refunded'   => 'Refunded',
    'api_error'  => 'Needs attention',
];

/** Unique order code, e.g. OF-8F42KD3M. */
function new_order_code(): string
{
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper(setting('order_prefix', 'OF'))) ?: 'OF';

    // Eight characters, not six: this code is all that guards a page showing
    // somebody's verification code, so the space it hides in has to be big
    // enough that walking it is not worth anyone's time. `orders.code` is
    // unique, and insert_order_code() is what survives losing a collision.
    return $prefix . '-' . random_code(8);
}

/**
 * Insert an order, retrying the code if another request took it first.
 *
 * @return array{0:int, 1:string}  [order id, the code it ended up with]
 */
function insert_order_code(array $fields): array
{
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $fields['code'] = new_order_code();
        try {
            return [insert_row('orders', $fields), $fields['code']];
        } catch (PDOException $e) {
            if (!is_duplicate_key($e)) {
                throw $e;
            }
        }
    }

    $fields['code'] = preg_replace('/-.*$/', '', $fields['code'])
        . '-' . strtoupper(bin2hex(random_bytes(6)));

    return [insert_row('orders', $fields), $fields['code']];
}

function order_log(int $orderId, string $message): void
{
    insert_row('order_logs', [
        'order_id'   => $orderId,
        'message'    => mb_substr($message, 0, 500),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Log a line unless that exact line is already on the order.
 *
 * For anything written by something that repeats - a page checking every
 * few seconds has something worth recording the first time it sees it and
 * nothing the hundredth.
 */
function order_log_once(int $orderId, string $message): void
{
    $message = mb_substr($message, 0, 500);

    if (col('SELECT 1 FROM order_logs WHERE order_id = ? AND message = ?',
            [$orderId, $message], null)) {
        return;
    }

    order_log($orderId, $message);
}

/** The JSON an order carries about what exactly it bought. */
function order_meta(array $order): array
{
    $meta = json_decode((string) ($order['meta'] ?? ''), true);
    return is_array($meta) ? $meta : [];
}

/**
 * Record that a gateway confirmed the money for an order, and act on it.
 *
 * The one place a gateway's yes turns into a paid order - the QR page, the
 * webhook and cron all come here. The status is claimed, not checked and then
 * written: two tabs polling and cron arriving together would otherwise both
 * mark it paid and both buy a number.
 *
 * @param  array $result  a payment_poll() reply with state 'paid'
 * @return bool   true only for the caller that actually marked it paid
 */
function confirm_order_payment(array $order, array $method, array $result): bool
{
    $id        = (int) $order['id'];
    $now       = date('Y-m-d H:i:s');
    $reference = mb_substr(trim((string) ($result['reference'] ?? '')), 0, 120);
    $amount    = $result['amount'] ?? null;

    $fields = [
        'status'            => 'paid',
        'paid_at'           => $now,
        'paid_amount'       => $amount !== null ? round((float) $amount, 2) : (float) $order['price'],
        'payment_method_id' => (int) $method['id'],
        'pay_source'        => 'gateway',
        'trx_id'            => $reference,
        'updated_at'        => $now,
    ];

    // Everything the gateway told us about this payment, kept as it came:
    // trx_id is one field out of it, and the rest is what answers "which
    // payment was this?" a month later.
    $details = $result['details'] ?? null;
    if (is_array($details) && $details) {
        $fields['gateway_details'] = mb_substr(
            (string) json_encode($details + ['method' => $method['name'], 'at' => $now],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000);
    }

    if (!claim('orders', $fields, "id = ? AND status IN ('pending', 'api_error') AND paid_at IS NULL", [$id])) {
        return false;
    }

    $summary = trim((string) ($result['summary'] ?? ''));

    order_log($id, 'Payment confirmed by ' . $method['name']
        . ($summary !== '' ? ' - ' . $summary : '') . '.');
    audit_row('order.paid', 'orders', $id, $order, [
        'severity' => 'warn',
        'summary'  => $order['code'] . ' confirmed by ' . $method['name']
                    . ($summary !== '' ? ' - ' . $summary : ''),
    ]);

    order_paid_notices($order, $method['name'], $reference);
    fulfil_order($id);

    return true;
}

/** Tell the admin, and the customer where they gave an address. */
function order_paid_notices(array $order, string $methodName, string $reference): void
{
    $tokens = [
        'order_code' => $order['code'],
        'service'    => order_label($order),
        'amount'     => money((float) $order['price']),
        'method'     => $methodName,
        'reference'  => $reference !== '' ? $reference : 'none given',
        'order_url'  => url('order/' . $order['code']),
        'admin_url'  => url('admin/orders/' . $order['id']),
        'admin_link' => 'admin/orders/' . $order['id'],
        'email'      => order_email($order),
    ];

    notify('admin_payment_submitted', $tokens);
    if ($tokens['email'] !== '') {
        notify('order_paid', $tokens);
    }
}

/**
 * Keep one exchange with OTPGet or a payment gateway on its order.
 *
 * The admin's question after anything goes wrong is "what did they actually
 * say?", and the order log only holds our reading of it. This holds the
 * request and the reply as they were, secrets masked. A poll that hears the
 * same thing again only bumps `repeats` and `last_at` - a number waiting for
 * its SMS is asked every four seconds, and three hundred identical rows would
 * bury the one that matters.
 *
 * Never throws: on a database that has not run its migration yet, or a full
 * disk, the sale goes on and the line goes to the log file instead.
 *
 * @param array{source?: string, party?: string, action?: string, ok?: bool, summary?: string,
 *              request?: string, response?: string, status?: int, ms?: int} $call
 */
function order_call(int $orderId, array $call): void
{
    // The client has read the reply it reported a moment ago and found a
    // refusal in it: the last row is put right, not a second one written.
    if (!empty($call['amend'])) {
        try {
            // Two statements: MySQL will not UPDATE a table it is selecting from.
            $lastId = (int) col('SELECT MAX(id) FROM order_calls WHERE order_id = ?', [$orderId], 0);
            q('UPDATE order_calls SET ok = 0, summary = ? WHERE id = ?',
                [mb_substr((string) ($call['summary'] ?? ''), 0, 500), $lastId]);
        } catch (Throwable $e) {
            log_line('order_call amend (' . $orderId . '): ' . $e->getMessage());
        }
        return;
    }

    $call += ['source' => '', 'party' => '', 'action' => '', 'ok' => false, 'summary' => '',
              'request' => '', 'response' => '', 'status' => 0, 'ms' => 0];
    $response = mb_substr(call_mask(trim((string) $call['response'])), 0, 6000);
    $summary  = trim((string) $call['summary']) !== ''
        ? (string) $call['summary']
        : mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($response))), 0, 200);
    $now = date('Y-m-d H:i:s');

    try {
        // The same reply to the same question is the same answer, whatever
        // was later made of it - so the raw reply is what is compared.
        $last = one('SELECT id, source, action, response FROM order_calls
                      WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        if ($last && $last['source'] === $call['source'] && $last['action'] === $call['action']
            && (string) $last['response'] === $response) {
            q('UPDATE order_calls SET repeats = repeats + 1, last_at = ?, ms = ? WHERE id = ?',
                [$now, (int) $call['ms'], (int) $last['id']]);
            return;
        }
        insert_row('order_calls', [
            'order_id'    => $orderId,
            'source'      => mb_substr((string) $call['source'], 0, 20),
            'party'       => mb_substr((string) $call['party'], 0, 120),
            'action'      => mb_substr((string) $call['action'], 0, 60),
            'ok'          => $call['ok'] ? 1 : 0,
            'summary'     => mb_substr($summary, 0, 500),
            'request'     => mb_substr(call_mask((string) $call['request']), 0, 2000),
            'response'    => $response,
            'http_status' => (int) $call['status'],
            'ms'          => (int) $call['ms'],
            'repeats'     => 1,
            'created_at'  => $now,
            'last_at'     => $now,
        ]);
    } catch (Throwable $e) {
        log_line('order_call(' . $orderId . ', ' . $call['source'] . ' ' . $call['action'] . '): '
            . mb_substr($summary, 0, 200) . ' [not stored: ' . $e->getMessage() . ']');
    }
}

/**
 * Take the secrets out of a request or a reply before it is kept.
 *
 * Keys, tokens, signatures and passwords, in a query string or in JSON. What
 * is left is still enough to see what was asked and what came back, which
 * is the point; a key in a support screenshot is not.
 */
function call_mask(string $text): string
{
    $secret = '(?:api_?key|apikey|key|secret[a-z_]*|[a-z_]*token|sign(?:ature)?|password|pass|mid|merchant_?key|checksum[a-z_]*)';
    $text = (string) preg_replace('/([?&]' . $secret . '=)[^&\s"]*/i', '$1***', $text);
    $text = (string) preg_replace('/("' . $secret . '"\s*:\s*")[^"]*"/i', '$1***"', $text);
    return $text;
}

/** Where a customer can be written to about an order: theirs, or their account's. */
function order_email(array $order): string
{
    $email = trim((string) ($order['email'] ?? ''));
    if ($email === '' && !empty($order['user_id'])) {
        $email = (string) col('SELECT email FROM users WHERE id = ?', [(int) $order['user_id']], '');
    }
    return $email;
}

/**
 * Deliver what a paid order bought.
 *
 * Safe to call more than once and from anywhere: each branch claims its own
 * work, so a second caller finds nothing left to do.
 *
 * @return array{0: bool, 1: string}  [ok, what happened]
 */
function fulfil_order(int $orderId): array
{
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) {
        return [false, 'Order not found.'];
    }

    return match ((string) $order['product']) {
        'deposit' => wallet_deposit_complete($order),
        'rental'  => rental_start($orderId),
        'renewal' => rental_renew_from_order($orderId),
        default   => setting('auto_buy_number', '1') === '1' || $order['pay_source'] === 'wallet'
                        ? number_buy($orderId)
                        : [true, 'Waiting for an admin to buy the number.'],
    };
}

/**
 * Pay for an order out of the customer's wallet, and deliver it.
 *
 * The debit and the status change are one claim each and the debit goes
 * first, so the worst a crash between them can leave is money taken and an
 * order still pending - which the refund below puts right, rather than an
 * order delivered and never paid for.
 *
 * @return array{ok: bool, message: string}
 */
function pay_order_from_wallet(array $order, array $user): array
{
    $price = round((float) $order['price'], 2);

    if (!wallet_debit((int) $user['id'], $price, 'purchase', (int) $order['id'], order_label($order))) {
        return ['ok' => false, 'message' => 'Your wallet balance is too low for this. Add funds, or pay '
                                           . 'with another method.'];
    }

    $now = date('Y-m-d H:i:s');
    $took = claim('orders', [
        'status'      => 'paid',
        'pay_source'  => 'wallet',
        'paid_at'     => $now,
        'paid_amount' => $price,
        'trx_id'      => 'WALLET',
        'updated_at'  => $now,
    ], "id = ? AND status = 'pending' AND user_id = ?", [(int) $order['id'], (int) $user['id']]);

    if (!$took) {
        wallet_credit((int) $user['id'], $price, 'refund', (int) $order['id'], 'Payment not needed');
        return ['ok' => false, 'message' => 'This order is already paid for.'];
    }

    order_log((int) $order['id'], 'Paid ' . money($price) . ' from the wallet.');
    fulfil_order((int) $order['id']);

    return ['ok' => true, 'message' => ''];
}

/**
 * Give an order's money back to its owner's wallet.
 *
 * Claimed, so it happens once however many things ask for it - cron finding
 * the number expired and the customer pressing Cancel can arrive together.
 * Only an order with an owner can be refunded this way; a guest's money went
 * through a gateway and goes back by hand.
 */
function refund_order_to_wallet(array $order, string $why, array $fromStatuses = ['expired', 'api_error', 'paid', 'processing']): bool
{
    if (empty($order['user_id']) || $order['product'] === 'deposit') {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($fromStatuses), '?'));
    $took = claim('orders', ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')],
        "id = ? AND status IN ({$placeholders})", array_merge([(int) $order['id']], $fromStatuses));
    if (!$took) {
        return false;
    }

    $amount = round((float) ($order['paid_amount'] ?? $order['price']), 2);
    wallet_credit((int) $order['user_id'], $amount, 'refund', (int) $order['id'], $why);
    order_log((int) $order['id'], 'Refunded ' . money($amount) . ' to the wallet: ' . $why);
    audit('order.refunded', ['entity' => 'orders', 'entity_id' => (int) $order['id'],
        'summary' => $order['code'] . ' refunded ' . money($amount) . ' to wallet - ' . $why]);

    return true;
}

/**
 * Cancel an order that has not delivered, and settle its money.
 *
 * The one way an order is cancelled - by its customer or by an admin - so
 * the rules cannot differ between the two:
 *
 *  - nothing is cancelled once a code has arrived: the customer has what
 *    they paid for;
 *  - an unpaid order is simply closed;
 *  - a live number is given back to OTPGet first (which refunds us), and
 *    only inside OTPGet's own rules - the SMS servers refuse it for two
 *    minutes, so the reason comes back instead;
 *  - the money goes to the wallet of the account the order belongs to. A
 *    guest has no wallet, so a guest's order is closed with nothing
 *    refunded - the customer was told that before pressing, and an admin
 *    can still return the money by hand.
 *
 * @return array{0: bool, 1: string}
 */
function order_cancel(array $order, bool $refund, string $by): array
{
    $id = (int) $order['id'];
    if ((string) $order['otp_code'] !== '') {
        return [false, 'A code has already arrived on this order, so there is nothing to cancel.'];
    }
    if (!in_array((string) $order['product'], NUMBER_PRODUCTS, true) && $order['status'] !== 'pending') {
        return [false, 'Only numbers and inboxes are cancelled here.'];
    }
    $now = date('Y-m-d H:i:s');

    if ($order['status'] === 'pending') {
        if (!claim('orders', ['status' => 'cancelled', 'updated_at' => $now], "id = ? AND status = 'pending'", [$id])) {
            return [false, 'This order has just changed. Refresh the page.'];
        }
        order_log($id, 'Cancelled by ' . $by . ' before it was paid.');
        return [true, 'Order cancelled. Nothing was charged.'];
    }

    if ($order['status'] === 'processing') {
        $activation = order_activation($order);
        $can = number_can_release($order, $activation);
        if (!$can['ok']) {
            return [false, $can['reason']];
        }
        [$released, $why] = number_release($order, $activation);
        if (!$released) {
            return [false, $why];
        }
        number_lapsed($order, $activation, true, false);
    } elseif (!in_array($order['status'], ['paid', 'api_error', 'expired'], true)) {
        return [false, 'This order can no longer be cancelled.'];
    } elseif (!empty($order['sending_at']) && strtotime((string) $order['sending_at']) > time() - 120) {
        // A purchase is in flight: cancelling under it could pay for a
        // number the customer never sees.
        return [false, 'A number is being fetched for this order right now. Try again in a moment.'];
    }

    $fresh  = one('SELECT * FROM orders WHERE id = ?', [$id]) ?: $order;
    $amount = money((float) ($fresh['paid_amount'] ?? $fresh['price']));
    if ($refund && !empty($fresh['user_id'])
        && refund_order_to_wallet($fresh, 'Cancelled by ' . $by, ['expired', 'paid', 'api_error'])) {
        return [true, 'Cancelled. ' . $amount . ' is back in your wallet.'];
    }
    if (!claim('orders', ['status' => 'cancelled', 'updated_at' => $now],
            "id = ? AND status IN ('expired', 'paid', 'api_error') AND (sending_at IS NULL OR sending_at < ?)",
            [$id, date('Y-m-d H:i:s', time() - 120)])) {
        return [false, 'This order has just changed. Refresh the page.'];
    }
    order_log($id, 'Cancelled by ' . $by . ' with no refund (' . $amount . ' paid).');
    return [true, 'Cancelled. ' . $amount . ' was not refunded.'];
}

/**
 * What the Cancel button can offer this visitor, for the order page to draw.
 *
 * `refund` is true for the account the order belongs to - and for a signed-in
 * customer looking at a guest order this browser placed, which is moved into
 * their account the moment they press it. Anyone else holding the code may
 * still cancel a guest order, without a refund, having been told so.
 *
 * @return array{show: bool, wait: int, refund: bool, claim: bool, others: bool, amount: string, next: string}
 */
function order_cancel_choice(array $order, ?array $user): array
{
    $none = ['show' => false, 'wait' => 0, 'refund' => false, 'claim' => false, 'others' => false,
             'amount' => '', 'next' => ''];
    if ((string) $order['otp_code'] !== '' || !in_array((string) $order['product'], NUMBER_PRODUCTS, true)
        || !in_array($order['status'], ['processing', 'paid', 'api_error', 'expired'], true)) {
        return $none;
    }
    $wait = 0;
    if ($order['status'] === 'processing') {
        $release = number_can_release($order, order_activation($order));
        if (!$release['ok'] && $release['wait'] <= 0) {
            return $none;
        }
        $wait = $release['wait'];
    }
    $owner = $user && (int) $user['id'] === (int) ($order['user_id'] ?? 0);
    $claim = $user && empty($order['user_id']) && in_array((string) $order['code'], browser_orders(), true);
    return [
        'show'   => true,
        'wait'   => $wait,
        'refund' => $owner || $claim,
        'claim'  => $claim,
        // Somebody else's account order: its refund is theirs to ask for.
        'others' => !empty($order['user_id']) && !$owner,
        'amount' => money((float) ($order['paid_amount'] ?? $order['price'])),
        'next'   => 'order/' . $order['code'] . '?cancel=1',
    ];
}

/** The order an id or a code names, with what the pages need joined on. */
function order_full(string $code): ?array
{
    return one(
        'SELECT o.*, pm.name AS payment_name, s.icon AS service_icon, s.color AS service_color,
                s.slug AS service_slug
           FROM orders o
           LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
           LEFT JOIN services s         ON s.id  = o.service_id
          WHERE o.code = ?',
        [strtoupper($code)]
    );
}

/**
 * Write the order for one offer, at the offer's price.
 *
 * The price is read from the offer here and nowhere else - nothing the
 * browser sent about money is looked at. What OTPGet needs to buy it is
 * copied into `meta` as well, so the order can still be filled if the offer
 * row is deleted by the next sync.
 *
 * @return array{0: int, 1: string}  [order id, code]
 */
function place_offer_order(array $offer, ?array $user, string $email = '', string $whatsapp = ''): array
{
    $label = offer_title($offer);
    $price = offer_sale_price($offer);

    [$orderId, $code] = insert_order_code([
        'user_id'      => $user['id'] ?? null,
        'offer_id'     => (int) $offer['id'],
        'product'      => (string) $offer['product'],
        'server'       => (string) $offer['server'],
        'country_id'   => $offer['country_id'],
        'service_id'   => $offer['service_id'],
        'service_name' => mb_substr((string) ($offer['service_name'] ?? $offer['provider_name']), 0, 120),
        'country_name' => mb_substr((string) ($offer['country_name'] ?? ''), 0, 120),
        'country_iso'  => (string) ($offer['country_iso'] ?? ''),
        'label'        => mb_substr($label, 0, 190),
        'email'        => $email !== '' ? $email : (string) ($user['email'] ?? ''),
        'whatsapp'     => $whatsapp,
        'price'        => $price,
        'cost'         => round((float) (to_base((float) $offer['cost'], (string) $offer['cost_currency']) ?? 0), 2),
        'status'       => 'pending',
        'meta'         => json_encode([
            'product'          => $offer['product'],
            'server'           => $offer['server'],
            'provider_country' => $offer['provider_country'],
            'provider_service' => $offer['provider_service'],
            'provider_name'    => $offer['provider_name'],
            'option'           => $offer['label'] ?? offer_option_label($offer),
            'cost'             => (float) $offer['cost'],
            'cost_currency'    => $offer['cost_currency'],
            'country_name'     => $offer['country_name'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip'           => client_ip(),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    order_log($orderId, 'Order placed' . ($user ? ' by ' . $user['email'] : ' as a guest') . ': '
        . $label . ' (' . ($offer['label'] ?? '') . ') at ' . money($price) . '.');
    audit('order.placed', [
        'entity' => 'orders', 'entity_id' => $orderId,
        'summary' => $code . ': ' . $label . ' at ' . money($price),
    ]);

    notify('admin_new_order', [
        'order_code' => $code,
        'service'    => $label,
        'amount'     => money($price),
        'admin_url'  => url('admin/orders/' . $orderId),
        'admin_link' => 'admin/orders/' . $orderId,
    ]);

    return [$orderId, $code];
}
