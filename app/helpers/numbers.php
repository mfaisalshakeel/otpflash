<?php
/**
 * A number's life, from the moment it is paid for to the moment its code is
 * read - or it lapses without one.
 *
 *   number_buy()     buys a number (or an email inbox) for a paid order
 *   number_check()   asks whether the code has arrived; called by the order
 *                    page every few seconds and by cron for closed tabs
 *   number_change()  gives the number back and buys another
 *   number_cancel()  gives it back and refunds the wallet
 *
 * Each activation is one number. An order can go through several, and every
 * one keeps what it cost and whether OTPGet refunded that cost, which is
 * what makes the profit on an order honest when a number had to be swapped.
 *
 * Every step that spends or moves money claims its row first, because the
 * page polling in two tabs and cron arriving in the same second is not a
 * rare event here - it is what happens to every order whose customer leaves
 * the page open.
 */

/** Products whose order is fulfilled by a number (or an inbox). */
const NUMBER_PRODUCTS = ['sms', 'premium', 'cheap', 'email'];

/** How long a number is held when OTPGet does not say, in minutes. */
function number_lifetime(string $product): int
{
    if ($product === 'email') {
        return 30;      // OTPGet cancels an email order with no code after 30 minutes
    }
    return max(5, min(60, (int) setting('number_lifetime', '20')));
}

/** How long after buying a number it may be given back, in seconds. */
function number_cancel_after(string $product): int
{
    // The SMS API refuses a cancel inside two minutes; the others allow it
    // at once. A little margin, so the button never lights up early.
    return $product === 'sms' ? 125 : 5;
}

/** The most numbers one order may go through. */
function number_attempts_max(): int
{
    return max(1, min(10, (int) setting('max_numbers_per_order', '3')));
}

/** The live activation of an order, or null. */
function order_activation(array $order): ?array
{
    if (empty($order['activation_id'])) {
        return null;
    }
    return one('SELECT * FROM activations WHERE id = ? AND order_id = ?',
        [(int) $order['activation_id'], (int) $order['id']]);
}

/** Recount an order's cost from the numbers that were really paid for. */
function order_recost(int $orderId): void
{
    $cost = (float) col('SELECT COALESCE(SUM(cost_base), 0) FROM activations WHERE order_id = ? AND refunded = 0',
        [$orderId], 0);
    q('UPDATE orders SET cost = ? WHERE id = ?', [round($cost, 2), $orderId]);
}

/**
 * What OTPGet needs to buy this order's number again.
 *
 * The offer row first, because a sync keeps its ids current; the copy the
 * order took when it was placed if the offer has since been deleted.
 */
function order_purchase_target(array $order): ?array
{
    $offer = !empty($order['offer_id'])
        ? one('SELECT * FROM offers WHERE id = ?', [(int) $order['offer_id']])
        : null;
    $meta = order_meta($order);

    $target = [
        'product'          => (string) ($offer['product'] ?? $meta['product'] ?? $order['product']),
        'server'           => (string) ($offer['server'] ?? $meta['server'] ?? $order['server']),
        'provider_country' => (string) ($offer['provider_country'] ?? $meta['provider_country'] ?? ''),
        'provider_service' => (string) ($offer['provider_service'] ?? $meta['provider_service'] ?? ''),
        'cost'             => (float) ($offer['cost'] ?? $meta['cost'] ?? 0),
        'cost_currency'    => (string) ($offer['cost_currency'] ?? $meta['cost_currency'] ?? otpget_currency()),
    ];

    if ($target['provider_country'] === '' && $target['product'] !== 'cheap') {
        return null;
    }
    return $target;
}

/**
 * Buy a number for a paid order.
 *
 * From 'paid' for the first number, and from 'api_error' or 'expired' for a
 * retry or a replacement - never past the attempt limit, and never twice at
 * once: the claim on `sending_at` is what keeps a double-click from buying
 * two numbers. A claim older than two minutes is a request that died
 * mid-purchase, and is let go.
 *
 * When the chosen server has no stock, or its cost has risen past what the
 * order sold for, the same app in the same country is tried on another
 * server that still makes the minimum profit at the price already paid
 * (number_stand_in) - the customer bought "a WhatsApp number for Pakistan",
 * not "server 3". Only when none will do is the order failed.
 *
 * @param int[] $tried offers already tried for this purchase
 * @return array{0: bool, 1: string}  [ok, what happened]
 */
function number_buy(int $orderId, array $tried = []): array
{
    $now   = date('Y-m-d H:i:s');
    $stale = date('Y-m-d H:i:s', time() - 120);

    $mine = claim('orders', ['sending_at' => $now],
        "id = ? AND status IN ('paid', 'api_error', 'expired') AND paid_at IS NOT NULL
           AND product IN ('sms', 'premium', 'cheap', 'email') AND attempts < ?
           AND (sending_at IS NULL OR sending_at < ?)",
        [$orderId, number_attempts_max(), $stale]);

    if (!$mine) {
        $order = one('SELECT status, attempts, sending_at, paid_at FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            return [false, 'Order not found.'];
        }
        if ($order['paid_at'] === null) {
            return [false, 'This order is not paid for yet.'];
        }
        if ((int) $order['attempts'] >= number_attempts_max()) {
            return [false, 'This order has used all ' . number_attempts_max() . ' of its numbers.'];
        }
        if (!in_array($order['status'], ['paid', 'api_error', 'expired'], true)) {
            return [false, 'This order already has a number.'];
        }
        return [false, 'A number is being fetched right now. Give it a moment.'];
    }

    $release = static function (int $id): void {
        q('UPDATE orders SET sending_at = NULL WHERE id = ?', [$id]);
    };

    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);

    // Never buy at a loss without being asked to - judged on OTPGet's cost
    // as it is now, since a manual payment can be confirmed an hour after
    // the order was placed. This also refreshes the offer the target reads.
    $loss   = $order ? order_loss_refusal($order) : null;
    $target = $order ? order_purchase_target($order) : null;
    if (!$order || !$target) {
        $release($orderId);
        return [false, 'This order no longer says what to buy.'];
    }
    if ($loss !== null) {
        if (number_switch_offer($order, $tried, 'its cost rose past the price paid')) {
            return number_buy($orderId, array_merge($tried, [(int) $order['offer_id']]));
        }
        return number_failed($order, 'PRICE_UP', $loss . ' Its price is corrected for new orders; '
            . 'refund this one, or switch the loss guard off and retry it.');
    }

    $api = otpget()->about($orderId);
    try {
        $reply = $target['product'] === 'email'
            ? $api->buyEmail((int) $target['server'], $target['provider_country'], $target['provider_service'])
            : $api->buy($target['product'], $target['server'], $target['provider_country'], $target['provider_service']);
    } catch (Throwable $e) {
        $release($orderId);
        order_log($orderId, 'Buying the number failed: ' . $e->getMessage());
        return [false, 'Buying the number failed. Please try again.'];
    }

    if (isset($reply['error'])) {
        if (number_out_of_stock((string) $reply['error'])
            && number_switch_offer($order, $tried, 'it had no numbers (' . $reply['error'] . ')')) {
            return number_buy($orderId, array_merge($tried, [(int) $order['offer_id']]));
        }
        return number_failed($order, (string) $reply['error'], (string) $reply['message']);
    }

    $number   = (string) ($reply['phone'] ?? $reply['email'] ?? '');
    $orderRef = (string) ($reply['order_id'] ?? $reply['email_id'] ?? '');
    $cost     = isset($reply['price']) && $reply['price'] !== null && (float) $reply['price'] > 0
        ? (float) $reply['price'] : $target['cost'];
    $costBase = round((float) (to_base($cost, $target['cost_currency']) ?? 0), 2);
    $validity = (int) ($reply['validity'] ?? 0) ?: number_lifetime($target['product']) * 60;

    $activationId = insert_row('activations', [
        'order_id'          => $orderId,
        'product'           => $target['product'],
        'server'            => $target['server'],
        'provider_order_id' => mb_substr($orderRef, 0, 80),
        'number'            => mb_substr($number, 0, 190),
        'status'            => 'waiting',
        'code'              => '',
        'messages'          => '[]',
        'cost'              => $cost,
        'cost_currency'     => $target['cost_currency'],
        'cost_base'         => $costBase,
        'refunded'          => 0,
        'expires_at'        => date('Y-m-d H:i:s', time() + $validity),
        'created_at'        => $now,
    ]);

    update_row('orders', [
        'status'        => 'processing',
        'number'        => mb_substr($number, 0, 190),
        'activation_id' => $activationId,
        'attempts'      => (int) $order['attempts'] + 1,
        'otp_code'      => '',
        'api_error'     => '',
        'sending_at'    => null,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$orderId]);
    order_recost($orderId);

    // OTPGet says what it charged only now. If that is not what the offer
    // thought, the offer learns it, so the next customer pays enough - and a
    // number that did cost more than it sold for is said out loud.
    if (!empty($order['offer_id']) && abs($cost - (float) $target['cost']) > 0.0001) {
        update_row('offers', [
            'cost'      => $cost,
            'price'     => offer_price($target['product'], $cost, $target['cost_currency']),
            'synced_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $order['offer_id']]);
    }
    if ($costBase > (float) $order['price'] + 0.009) {
        order_log($orderId, 'OTPGet charged ' . money($costBase) . ' for this number, more than the '
            . money((float) $order['price']) . ' it sold for. The price has been corrected for the next customer.');
        audit('order.loss', ['entity' => 'orders', 'entity_id' => $orderId, 'severity' => 'warn',
            'summary' => $order['code'] . ' cost ' . money($costBase) . ', sold for ' . money((float) $order['price'])]);
    }

    order_log($orderId, ($order['attempts'] > 0 ? 'Replacement' : 'Number') . ' ' . $number
        . ' bought from OTPGet (' . $target['product'] . ($target['server'] !== '' ? ' ' . $target['server'] : '')
        . ', order ' . $orderRef . ', cost ' . money($costBase) . ').');
    audit('order.number', [
        'entity' => 'orders', 'entity_id' => $orderId,
        'summary' => $order['code'] . ' got ' . $number . ' at ' . money($costBase)
                   . ', sold for ' . money((float) $order['price']),
    ]);

    // The receipt goes out with the first number only: a free replacement is
    // the same purchase, and is on the order page the customer already has.
    if ((int) $order['attempts'] === 0) {
        number_ready_notice($order, $number);
    }

    return [true, 'Number ' . $number . ' is ready.'];
}

/** Email the customer what they bought, if they gave us somewhere to send it. */
function number_ready_notice(array $order, string $number, string $link = ''): void
{
    $email = order_email($order);
    if ($email === '') {
        return;
    }
    notify('number_ready', [
        'order_code' => $order['code'],
        'service'    => order_label($order),
        'number'     => $number,
        'amount'     => money((float) $order['price']),
        'order_url'  => $link !== '' ? $link : url('order/' . $order['code']),
        'email'      => $email,
    ]);
}

/**
 * OTPGet refused the purchase. Park the order with its words.
 *
 * A wallet customer whose order cannot be filled - no code has arrived on
 * any number it had - is refunded on the spot rather than left looking at a
 * paid order with nothing in it. A guest keeps the order and the Try again
 * button, and the admin is told either way.
 */
function number_failed(array $order, string $code, string $message): array
{
    $orderId = (int) $order['id'];

    update_row('orders', [
        'status'     => 'api_error',
        'api_error'  => mb_substr($code . ': ' . $message, 0, 500),
        'sending_at' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$orderId]);

    order_log($orderId, 'OTPGet refused the number: ' . $message);
    log_line('Order ' . $order['code'] . ' number refused: ' . $code . ' ' . $message);
    audit('order.api_error', [
        'entity' => 'orders', 'entity_id' => $orderId, 'severity' => 'warn',
        'summary' => $order['code'] . ' refused by OTPGet: ' . $code,
    ]);

    // Out of stock is the customer's problem to route around; anything else
    // is the shop's to fix, and is worth a notification.
    if (!in_array($code, ['NO_NUMBERS', 'BAD_COUNTRY', 'BAD_SERVICE', 'NO_SERVICE'], true)) {
        notify('admin_api_error', [
            'order_code' => $order['code'],
            'service'    => order_label($order),
            'provider'   => 'OTPGet',
            'error'      => $message,
            'admin_url'  => url('admin/orders/' . $orderId),
            'admin_link' => 'admin/orders/' . $orderId,
        ]);
    }

    // Refunded whenever no code ever arrived - the first number or a
    // replacement, it is the same customer holding nothing for their money.
    if (!empty($order['user_id']) && (string) ($order['otp_code'] ?? '') === '' && setting('auto_refund', '1') === '1') {
        $fresh = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        refund_order_to_wallet($fresh ?: $order, number_refund_note($code), ['api_error']);
    }

    return [false, number_trouble($code)];
}

/** A refusal that means "none of these left", which another server may still have. */
function number_out_of_stock(string $code): bool
{
    return in_array($code, ['NO_NUMBERS', 'NO_SERVICE', 'BAD_SERVICE', 'BAD_COUNTRY', 'STATUS_404'], true);
}

/**
 * Another offer for the same app and country that can fill this order at
 * the price it was sold for, today's cost checked first.
 *
 * Numbers only: an inbox or a rental is a different thing, not another
 * server of the same one. Up to three candidates, cheapest first, each
 * re-read from OTPGet if its cost is old - a stand-in bought at a loss is
 * the very thing the loss guard exists to stop.
 */
function number_stand_in(array $order, array $exclude): ?array
{
    if (empty($order['service_id']) || empty($order['country_id'])
        || !in_array((string) $order['product'], ['sms', 'premium', 'cheap'], true)) {
        return null;
    }
    $products = array_values(array_intersect(['sms', 'premium', 'cheap'], products_enabled()));
    [$sellable, $params] = offer_sellable_sql('o', $products);
    $exclude = array_values(array_unique(array_map('intval', $exclude)));
    $skip    = $exclude ? ' AND o.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')' : '';

    $rows = all("SELECT o.* FROM offers o
                  WHERE o.service_id = ? AND o.country_id = ? AND {$sellable}{$skip}
                  ORDER BY o.price, o.id LIMIT 3",
        array_merge([(int) $order['service_id'], (int) $order['country_id']], $params, $exclude));

    $guarded = setting('loss_guard', '1') === '1';
    foreach ($rows as $row) {
        $fresh = offer_refresh($row);
        if (!$fresh) {
            continue;
        }
        if ($guarded && offer_cost_floor($fresh) > (float) $order['price'] + 0.009) {
            continue;
        }
        return $fresh;
    }
    return null;
}

/**
 * Point the order at a stand-in offer, if there is one.
 *
 * The order keeps its price - the customer paid what they were shown - and
 * its label; what changes is which server it is bought from, which is ours
 * to choose. The log says why, for the admin reading it later.
 */
function number_switch_offer(array $order, array $tried, string $why): bool
{
    if (count($tried) >= 3) {
        return false;
    }
    $standIn = number_stand_in($order, array_merge($tried, [(int) $order['offer_id']]));
    if (!$standIn) {
        return false;
    }
    $meta = order_meta($order);
    $meta = array_merge($meta, [
        'product'          => $standIn['product'],
        'server'           => $standIn['server'],
        'provider_country' => $standIn['provider_country'],
        'provider_service' => $standIn['provider_service'],
        'provider_name'    => $standIn['provider_name'],
        'option'           => offer_option_label($standIn),
        'cost'             => (float) $standIn['cost'],
        'cost_currency'    => $standIn['cost_currency'],
    ]);
    update_row('orders', [
        'offer_id'   => (int) $standIn['id'],
        'product'    => (string) $standIn['product'],
        'server'     => (string) $standIn['server'],
        'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sending_at' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $order['id']]);
    order_log((int) $order['id'], 'Switched to ' . offer_option_label($standIn) . ' (' . $standIn['product']
        . ' ' . $standIn['server'] . ') because the chosen option ' . $why . '.');
    return true;
}

/**
 * The line on the customer's wallet statement when a number could not be
 * had. The customer reads it, so it says what happened to them - never whose
 * stock it was or what our own account looked like.
 */
function number_refund_note(string $code): string
{
    return match (true) {
        in_array($code, ['NO_NUMBERS', 'NO_SERVICE', 'BAD_SERVICE', 'BAD_COUNTRY', 'STATUS_404'], true)
            => 'No number was available',
        $code === 'PRICE_UP' => 'The price changed before the number was bought',
        default              => 'The number could not be supplied',
    };
}

/** What a customer is told when a number could not be had. Never OTPGet's own words. */
function number_trouble(string $code): string
{
    return match (true) {
        in_array($code, ['NO_NUMBERS', 'NO_SERVICE', 'BAD_SERVICE', 'BAD_COUNTRY', 'STATUS_404'], true)
            => 'No numbers are available for this right now. Try again in a minute, or pick another country or server.',
        $code === 'PRICE_UP'
            => 'The price for this went up a moment ago, so we did not buy it. Account holders are refunded '
               . 'to their wallet at once; otherwise we have been told and will sort it out shortly.',
        default
            => 'We could not get a number just now. Please try again in a minute.',
    };
}

/**
 * Has the code arrived? Ask OTPGet, at most every few seconds per number.
 *
 * The throttle is a claim on `checked_at`, so ten open tabs and cron cost
 * OTPGet one request every four seconds between them, and every one of them
 * still gets the latest answer from the row.
 */
function number_check(int $orderId, bool $force = false): void
{
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order || !in_array($order['status'], ['processing', 'completed'], true)) {
        return;
    }
    $activation = order_activation($order);
    if (!$activation || !in_array($activation['status'], ['waiting', 'received'], true)) {
        return;
    }

    // A code already in hand only needs looking at again while the number is
    // still held - some apps send a second code, and it is worth showing.
    if ($activation['status'] === 'received' && strtotime((string) $activation['expires_at']) < time()) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    if (!claim('activations', ['checked_at' => $now],
            'id = ? AND (checked_at IS NULL OR checked_at < ?)',
            [(int) $activation['id'], date('Y-m-d H:i:s', time() - ($force ? 1 : 4))])) {
        return;
    }

    $api   = otpget()->about($orderId);
    $reply = $activation['product'] === 'email'
        ? $api->emailCode((string) $activation['provider_order_id'])
        : $api->status((string) $activation['product'], (string) $activation['provider_order_id']);

    if (isset($reply['error'])) {
        order_log_once($orderId, 'Checking the SMS failed: ' . $reply['message']);
        if (strtotime((string) $activation['expires_at']) < time() - 600 && $activation['status'] === 'waiting') {
            // Ten minutes past its time and OTPGet will not even say: let it go.
            number_lapsed($order, $activation, false, true);
        }
        return;
    }

    if ($reply['state'] === 'received' && $reply['code'] !== '') {
        number_received($order, $activation, $reply['code']);
        return;
    }

    if ($reply['state'] === 'cancelled') {
        if ($activation['status'] === 'received') {
            q("UPDATE activations SET status = 'finished', closed_at = ? WHERE id = ?", [$now, $activation['id']]);
            return;
        }
        // Closed at OTPGet without a code - it lapsed there, and lapsing
        // there is refunded there.
        number_lapsed($order, $activation, true, true);
        return;
    }

    if ($activation['status'] === 'waiting' && strtotime((string) $activation['expires_at']) <= time()) {
        number_expire($order, $activation);
    }
}

/** A code arrived. Keep every one, show the newest. */
function number_received(array $order, array $activation, string $code): void
{
    $code = mb_substr(trim($code), 0, 190);
    if ($code === '' || $code === $activation['code']) {
        return;
    }

    $now      = date('Y-m-d H:i:s');
    $messages = json_decode((string) ($activation['messages'] ?? '[]'), true) ?: [];
    $messages[] = ['code' => $code, 'at' => $now];

    update_row('activations', [
        'status'      => 'received',
        'code'        => $code,
        'messages'    => json_encode($messages, JSON_UNESCAPED_UNICODE),
        'received_at' => $activation['received_at'] ?? $now,
    ], 'id = ?', [(int) $activation['id']]);

    $first = $order['status'] !== 'completed';
    update_row('orders', [
        'status'       => 'completed',
        'otp_code'     => $code,
        'completed_at' => $order['completed_at'] ?? $now,
        'updated_at'   => $now,
    ], 'id = ?', [(int) $order['id']]);

    order_log((int) $order['id'], ($first ? 'Code received: ' : 'Another code received: ') . $code . '.');

    $email = order_email($order);
    if ($first && $email !== '') {
        notify('otp_received', [
            'order_code' => $order['code'],
            'service'    => order_label($order),
            'number'     => (string) $order['number'],
            'code'       => $code,
            'order_url'  => url('order/' . $order['code']),
            'email'      => $email,
        ]);
    }
}

/**
 * The number's time is up with no code: give it back to OTPGet.
 *
 * Their answer decides what we know. ACCESS_READY is a refund. "Already
 * changed" means somebody got there first - possibly the code itself - so
 * the status is read once more before anything is decided. Anything else is
 * left for the next check rather than guessed at.
 */
function number_expire(array $order, array $activation): void
{
    $api   = otpget()->about((int) $order['id']);
    $reply = $activation['product'] === 'email'
        ? $api->cancelEmail((string) $activation['provider_order_id'])
        : $api->cancel((string) $activation['product'], (string) $activation['provider_order_id']);

    if (!isset($reply['error'])) {
        if (($reply['code'] ?? '') !== '') {
            number_received($order, $activation, (string) $reply['code']);
            return;
        }
        number_lapsed($order, $activation, true, true);
        return;
    }

    if (in_array($reply['error'], ['STATUS_ALREADY_CHANGED', 'BAD_STATUS', 'NO_ACTIVATION'], true)) {
        $status = $activation['product'] === 'email'
            ? $api->emailCode((string) $activation['provider_order_id'])
            : $api->status((string) $activation['product'], (string) $activation['provider_order_id']);
        if (($status['state'] ?? '') === 'received' && ($status['code'] ?? '') !== '') {
            number_received($order, $activation, (string) $status['code']);
            return;
        }
        number_lapsed($order, $activation, ($status['state'] ?? '') === 'cancelled', true);
        return;
    }

    order_log_once((int) $order['id'], 'Could not give the number back yet: ' . $reply['message']);
}

/**
 * A number is finished without a code.
 *
 * @param bool $refunded    OTPGet gave its cost back
 * @param bool $autoRefund  refund an account holder's wallet now; false when
 *                          the caller is about to buy a replacement instead
 */
function number_lapsed(array $order, array $activation, bool $refunded, bool $autoRefund): void
{
    $now = date('Y-m-d H:i:s');
    if (!claim('activations', ['status' => 'expired', 'closed_at' => $now, 'refunded' => $refunded ? 1 : 0],
            "id = ? AND status = 'waiting'", [(int) $activation['id']])) {
        return;
    }

    claim('orders', ['status' => 'expired', 'updated_at' => $now],
        "id = ? AND status = 'processing'", [(int) $order['id']]);
    order_recost((int) $order['id']);

    order_log((int) $order['id'], 'Number ' . $activation['number'] . ' expired with no SMS'
        . ($refunded ? ' - OTPGet refunded its cost.' : '.'));

    if ($autoRefund && !empty($order['user_id']) && setting('auto_refund', '1') === '1') {
        $fresh = one('SELECT * FROM orders WHERE id = ?', [(int) $order['id']]);
        if ($fresh) {
            refund_order_to_wallet($fresh, 'No SMS arrived on ' . $activation['number'], ['expired']);
        }
    }
}

/**
 * Can this order's number be given back now? And if not, how long until it can?
 *
 * @return array{ok: bool, wait: int, reason: string}
 */
function number_can_release(array $order, ?array $activation): array
{
    if (!$activation || $activation['status'] !== 'waiting' || $order['status'] !== 'processing') {
        return ['ok' => false, 'wait' => 0, 'reason' => 'There is no number waiting for a code.'];
    }
    $age  = time() - strtotime((string) $activation['created_at']);
    $wait = number_cancel_after((string) $activation['product']) - $age;
    if ($wait > 0) {
        return ['ok' => false, 'wait' => $wait,
                'reason' => sprintf('This number can be changed in %d:%02d.', intdiv($wait, 60), $wait % 60)];
    }
    return ['ok' => true, 'wait' => 0, 'reason' => ''];
}

/** Give the current number back to OTPGet. */
function number_release(array $order, array $activation): array
{
    $api   = otpget()->about((int) $order['id']);
    $reply = $activation['product'] === 'email'
        ? $api->cancelEmail((string) $activation['provider_order_id'])
        : $api->cancel((string) $activation['product'], (string) $activation['provider_order_id']);

    if (isset($reply['error'])) {
        if ($reply['error'] === 'EARLY_CANCEL_DENIED') {
            return [false, 'Too early to change this number. Please wait a minute and try again.'];
        }
        // Already closed or the code landed first: read what really happened.
        number_check((int) $order['id'], true);
        return [false, 'This number could not be given back - it may have just received its code.'];
    }
    if (($reply['code'] ?? '') !== '') {
        number_received($order, $activation, (string) $reply['code']);
        return [false, 'Your code arrived just now.'];
    }
    return [true, ''];
}

/**
 * Swap the number for a new one, free.
 *
 * @return array{0: bool, 1: string}
 */
function number_change(int $orderId): array
{
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) {
        return [false, 'Order not found.'];
    }
    if ((int) $order['attempts'] >= number_attempts_max()) {
        return [false, 'This order has used all of its ' . number_attempts_max() . ' numbers.'];
    }

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
    order_log($orderId, 'The customer asked for a different number.');

    return number_buy($orderId);
}

/**
 * Give the number back and refund the wallet. Account holders only - a
 * guest's money went through a gateway, so a guest changes the number
 * instead.
 *
 * @return array{0: bool, 1: string}
 */
function number_cancel(int $orderId): array
{
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order || empty($order['user_id'])) {
        return [false, 'Only an order on an account can be cancelled for a refund.'];
    }

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
    $fresh = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    refund_order_to_wallet($fresh ?: $order, 'Cancelled by the customer', ['expired']);

    return [true, 'Cancelled. ' . money((float) ($order['paid_amount'] ?? $order['price'])) . ' is back in your wallet.'];
}

/**
 * Close numbers that have had their code and run out their time.
 *
 * OTPGet would close them itself; saying so is the polite end of the
 * protocol, and it stops the next status read reporting a number we have
 * finished with.
 */
function numbers_finish_due(int $limit = 50): int
{
    $done = 0;
    foreach (all("SELECT * FROM activations WHERE status = 'received' AND expires_at < ? ORDER BY id LIMIT "
            . max(1, $limit), [date('Y-m-d H:i:s')]) as $activation) {
        if ($activation['product'] !== 'email') {
            otpget()->about((int) $activation['order_id'])->finish((string) $activation['product'], (string) $activation['provider_order_id']);
        }
        q("UPDATE activations SET status = 'finished', closed_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $activation['id']]);
        $done++;
    }
    return $done;
}

/**
 * Everything the order page needs to draw the number, in one shape.
 *
 * The page renders it once and the script asks for it again every few
 * seconds, so both read the same function and cannot disagree about what
 * the customer may press.
 */
function number_state(array $order): array
{
    $activation = order_activation($order);
    $release    = number_can_release($order, $activation);
    $messages   = $activation ? (json_decode((string) ($activation['messages'] ?? '[]'), true) ?: []) : [];
    $attempts   = (int) $order['attempts'];
    $dial       = (string) col('SELECT dial_code FROM countries WHERE id = ?', [(int) ($order['country_id'] ?? 0)], '');
    $number     = (string) ($activation['number'] ?? $order['number'] ?? '');
    $digits     = ltrim($number, '+');
    $local      = $dial !== '' && str_starts_with($digits, $dial) ? substr($digits, strlen($dial)) : $digits;
    $isEmail    = ($order['product'] ?? '') === 'email';

    return [
        'status'        => (string) $order['status'],
        'words'         => ORDER_STATUS_WORDS[$order['status']] ?? $order['status'],
        'kind'          => $isEmail ? 'email' : 'phone',
        'number'        => $isEmail ? $number : ($number !== '' ? '+' . $digits : ''),
        'local'         => $isEmail ? $number : $local,
        'dial'          => $dial,
        'code'          => (string) ($activation['code'] ?? $order['otp_code'] ?? ''),
        'codes'         => array_values(array_map(static fn ($m) => ['code' => (string) $m['code'],
                               'at' => date('H:i', strtotime((string) $m['at']))], $messages)),
        'seconds_left'  => $activation && in_array($activation['status'], ['waiting', 'received'], true)
                             ? max(0, strtotime((string) $activation['expires_at']) - time()) : 0,
        'release_in'    => $release['wait'],
        'can_change'    => $release['ok'] && $attempts < number_attempts_max(),
        'can_cancel'    => $release['ok'] && !empty($order['user_id']),
        'can_retry'     => in_array($order['status'], ['api_error', 'expired'], true)
                             && $order['paid_at'] !== null && $attempts < number_attempts_max(),
        'attempts'      => $attempts,
        'attempts_max'  => number_attempts_max(),
        'error'         => $order['status'] === 'api_error'
                             ? number_trouble((string) strtok((string) $order['api_error'], ':')) : '',
    ];
}
