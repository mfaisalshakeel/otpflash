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

/**
 * Status-check refusals that mean OTPGet has closed the number itself.
 *
 * A number that OTPGet cancels on its side - or no longer has on this
 * account - will never receive a code, so the order is treated as one whose
 * number ran out: refunded to a wallet, or a free replacement for a guest.
 */
const NUMBER_CLOSED_UPSTREAM = ['NO_ACTIVATION', 'STATUS_CANCEL', 'ACCESS_CANCEL', 'BAD_STATUS',
    'WRONG_ACTIVATION_ID', 'STATUS_404'];

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

    // Bought while the customer was paying: that number is handed over
    // rather than a second one bought.
    if ($order && number_hold_take($order)) {
        return [true, 'Your number is ready.'];
    }

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

    $bought = number_purchase($orderId, $target);
    if (($bought['error'] ?? '') === 'EXCEPTION') {
        $release($orderId);
        order_log($orderId, 'Buying the number failed: ' . $bought['message']);
        return [false, 'Buying the number failed. Please try again.'];
    }
    if (isset($bought['error'])) {
        if (number_out_of_stock($bought['error'])
            && number_switch_offer($order, $tried, 'it had no numbers (' . $bought['error'] . ')')) {
            return number_buy($orderId, array_merge($tried, [(int) $order['offer_id']]));
        }
        return number_failed($order, $bought['error'], $bought['message']);
    }

    $number   = $bought['number'];
    $orderRef = $bought['ref'];
    $costBase = $bought['cost_base'];
    offer_learn_cost($order, $target, $bought['cost']);

    // The order is paid, so its price cannot be asked again. A number whose
    // real price leaves less than the minimum profit is not handed over: it
    // joins the cancel queue (OTPGet refunds it) and another server is tried,
    // or the order is refused the way any other loss is.
    if (setting('loss_guard', '1') === '1'
        && $costBase + (float) setting('min_profit', '0') > (float) $order['price'] + 0.009) {
        $activationId = activation_insert($orderId, $target, $bought, 'queued');
        order_recost($orderId);
        order_log($orderId, 'OTPGet charged ' . money($costBase) . ' for ' . $number . ', which leaves less than '
            . 'the minimum profit at the ' . money((float) $order['price']) . ' paid. It goes back to OTPGet.');
        audit('order.loss_avoided', ['entity' => 'orders', 'entity_id' => $orderId, 'severity' => 'warn',
            'summary' => $order['code'] . ': OTPGet charged ' . money($costBase) . ', sold for '
                       . money((float) $order['price']) . ' - number given back']);
        if (number_switch_offer($order, $tried, 'its real price was ' . money($costBase))) {
            return number_buy($orderId, array_merge($tried, [(int) $order['offer_id']]));
        }
        return number_failed($order, 'PRICE_UP', 'OTPGet charged ' . money($costBase) . ', more than this order '
            . 'can carry at ' . money((float) $order['price']) . '. The number was given back.');
    }

    $activationId = activation_insert($orderId, $target, $bought, 'waiting');

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

/**
 * Ask OTPGet for one number (or inbox) for $target. Nothing is written.
 *
 * @return array ['error' => code, 'message' => words], with EXCEPTION for a
 *               request that never got an answer; or
 *               ['number', 'ref', 'cost', 'cost_base', 'validity']
 */
function number_purchase(int $orderId, array $target): array
{
    $api = otpget()->about($orderId);
    try {
        $reply = $target['product'] === 'email'
            ? $api->buyEmail((int) $target['server'], $target['provider_country'], $target['provider_service'])
            : $api->buy($target['product'], $target['server'], $target['provider_country'], $target['provider_service']);
    } catch (Throwable $e) {
        return ['error' => 'EXCEPTION', 'message' => $e->getMessage()];
    }
    if (isset($reply['error'])) {
        return ['error' => (string) $reply['error'], 'message' => (string) $reply['message']];
    }

    $cost = isset($reply['price']) && $reply['price'] !== null && (float) $reply['price'] > 0
        ? (float) $reply['price'] : (float) $target['cost'];
    return [
        'number'    => (string) ($reply['phone'] ?? $reply['email'] ?? ''),
        'ref'       => (string) ($reply['order_id'] ?? $reply['email_id'] ?? ''),
        'cost'      => $cost,
        'cost_base' => round((float) (to_base($cost, $target['cost_currency']) ?? 0), 2),
        'validity'  => (int) ($reply['validity'] ?? 0) ?: number_lifetime($target['product']) * 60,
    ];
}

/** Write down a number OTPGet sold us, in the state it starts in. */
function activation_insert(int $orderId, array $target, array $bought, string $status): int
{
    return insert_row('activations', [
        'order_id'          => $orderId,
        'product'           => $target['product'],
        'server'            => $target['server'],
        'provider_order_id' => mb_substr($bought['ref'], 0, 80),
        'number'            => mb_substr($bought['number'], 0, 190),
        'status'            => $status,
        'code'              => '',
        'messages'          => '[]',
        'cost'              => $bought['cost'],
        'cost_currency'     => $target['cost_currency'],
        'cost_base'         => $bought['cost_base'],
        'refunded'          => 0,
        'expires_at'        => date('Y-m-d H:i:s', time() + $bought['validity']),
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
}

/**
 * OTPGet says what it charged only once it has charged it. If that is not
 * what the offer thought, the offer learns it, so the next customer is shown
 * enough.
 */
function offer_learn_cost(array $order, array $target, float $cost): void
{
    if (empty($order['offer_id']) || abs($cost - (float) $target['cost']) <= 0.0001) {
        return;
    }
    update_row('offers', [
        'cost'      => $cost,
        'price'     => offer_price($target['product'], $cost, $target['cost_currency']),
        'synced_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $order['offer_id']]);
}

/**
 * The least an order may sell for, now that OTPGet has said what the number
 * really costs.
 *
 * The whole price rule - markup, minimum profit, minimum price, rounded up -
 * applied to the real cost, so a number that turned out dearer than its
 * listing is offered at a price that still carries the full profit. A price
 * the admin pinned stands, but never below cost plus the minimum profit.
 * Zero with the loss guard off: the admin has chosen to sell regardless.
 */
function number_price_needed(array $order, array $target, float $cost): float
{
    if (setting('loss_guard', '1') !== '1') {
        return 0.0;
    }
    $pin = !empty($order['offer_id'])
        ? col('SELECT price_override FROM offers WHERE id = ?', [(int) $order['offer_id']], null)
        : null;
    if ($pin === null || $pin === '') {
        return offer_price($target['product'], $cost, $target['cost_currency']);
    }
    $floor = (float) (to_base($cost, $target['cost_currency']) ?? 0) + (float) setting('min_profit', '0');
    $step  = (float) setting('price_rounding', '1');
    $floor = $step > 0 ? ceil(round($floor / $step, 6)) * $step : $floor;
    return round(max((float) $pin, $floor), 2);
}

/** Is a number held for payment on this order switched on and possible? */
function number_hold_enabled(string $product, string $pay): bool
{
    return in_array($product, NUMBER_PRODUCTS, true)
        && setting('hold_before_pay', '1') === '1'
        && ($pay === 'wallet' || setting('auto_buy_number', '1') === '1')
        && table_has_column('orders', 'hold_until');
}

/**
 * Get the number for an order before it is paid for, and hold it.
 *
 * OTPGet's listings - getPrice included - give the figure it shows, not the
 * one it charges, and there is no ceiling to send with a purchase: the real
 * price is known only once the number has been bought. So the number is
 * bought first, kept out of sight until the money arrives (number_buy() then
 * hands it over), and given back - OTPGet refunds it - if nobody pays within
 * `hold_minutes` (numbers_release_due()).
 *
 * When the real price leaves less than the full profit at the price the
 * customer was shown, the number is still held and the order carries the
 * price it would take (`quote_price`), for the customer to agree to or turn
 * down. Nothing is ever charged at the old price.
 *
 * @param int[] $tried offers already tried
 * @return array{state: string, message: string, price?: float}
 *   'held' - take the payment; 'quote' - ask about the new price first;
 *   'none' - nothing could be had, and the order has been closed
 */
function number_hold(int $orderId, array $tried = []): array
{
    $now = date('Y-m-d H:i:s');
    if (!claim('orders', ['sending_at' => $now],
            "id = ? AND status = 'pending' AND paid_at IS NULL AND activation_id IS NULL
               AND product IN ('sms', 'premium', 'cheap', 'email')
               AND (sending_at IS NULL OR sending_at < ?)",
            [$orderId, date('Y-m-d H:i:s', time() - 120)])) {
        return ['state' => 'none', 'message' => 'A number is being fetched for this order right now.'];
    }
    $order  = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $target = $order ? order_purchase_target($order) : null;

    $close = static function (string $why) use ($orderId, $now): void {
        update_row('orders', ['status' => 'cancelled', 'sending_at' => null, 'updated_at' => $now],
            'id = ?', [$orderId]);
        order_log($orderId, 'Closed before payment: ' . $why);
    };

    if (!$order || !$target) {
        $close('the order no longer says what to buy.');
        return ['state' => 'none', 'message' => number_trouble('NO_SERVICE')];
    }

    $bought = number_purchase($orderId, $target);
    if (isset($bought['error'])) {
        if (number_out_of_stock($bought['error'])
            && number_switch_offer($order, $tried, 'it had no numbers (' . $bought['error'] . ')')) {
            q('UPDATE orders SET sending_at = NULL WHERE id = ?', [$orderId]);
            return number_hold($orderId, array_merge($tried, [(int) $order['offer_id']]));
        }
        $close('OTPGet would not sell a number (' . $bought['error'] . ': ' . $bought['message'] . ').');
        number_refusal_notify($order, $bought['error'], $bought['message']);
        return ['state' => 'none', 'message' => number_trouble($bought['error'] === 'EXCEPTION' ? '' : $bought['error'])];
    }

    $activationId = activation_insert($orderId, $target, $bought, 'held');
    $window = max(120, min(max(5, min(15, (int) setting('hold_minutes', '10'))) * 60, $bought['validity'] - 180));
    $needed = number_price_needed($order, $target, $bought['cost']);
    $quote  = $needed > (float) $order['price'] + 0.009 ? $needed : null;

    update_row('orders', [
        'activation_id' => $activationId,
        'hold_until'    => date('Y-m-d H:i:s', time() + $window),
        'quote_price'   => $quote,
        'sending_at'    => null,
        'updated_at'    => $now,
    ], 'id = ?', [$orderId]);
    order_recost($orderId);
    offer_learn_cost($order, $target, $bought['cost']);

    order_log($orderId, 'Number ' . $bought['number'] . ' held for payment until ' . date('H:i', time() + $window)
        . ' (OTPGet order ' . $bought['ref'] . ', cost ' . money($bought['cost_base']) . ').'
        . ($quote !== null ? ' Its real price needs ' . money($quote) . ' to keep the full profit, not the '
            . money((float) $order['price']) . ' shown, so the customer is asked first.' : ''));
    audit('order.hold', ['entity' => 'orders', 'entity_id' => $orderId,
        'severity' => $quote !== null ? 'warn' : 'info',
        'summary'  => $order['code'] . ' holds ' . $bought['number'] . ' at ' . money($bought['cost_base'])
                    . ($quote !== null ? ', re-priced ' . money((float) $order['price']) . ' -> ' . money($quote) : '')]);

    if ($quote !== null) {
        return ['state' => 'quote', 'price' => $quote,
                'message' => 'This number costs ' . money($quote) . ' right now.'];
    }
    return ['state' => 'held', 'message' => ''];
}

/**
 * The money arrived: hand over the number held for this order.
 *
 * Claimed from 'held', so the cancel queue and the payment arriving in the
 * same second cannot both have it. A hold whose new price the customer never
 * agreed to, or whose time is nearly gone, is let go instead and the order
 * buys afresh like any other.
 */
function number_hold_take(array $order): bool
{
    $activation = order_activation($order);
    if (!$activation || $activation['status'] !== 'held') {
        return false;
    }
    $orderId = (int) $order['id'];
    if (($order['quote_price'] ?? null) !== null || strtotime((string) $activation['expires_at']) < time() + 90) {
        number_hold_drop($order, ($order['quote_price'] ?? null) !== null
            ? 'paid without agreeing to its new price' : 'too little of its time was left');
        return false;
    }
    if (!claim('activations', ['status' => 'waiting'], "id = ? AND status = 'held'", [(int) $activation['id']])) {
        return false;
    }

    update_row('orders', [
        'status'      => 'processing',
        'number'      => mb_substr((string) $activation['number'], 0, 190),
        'attempts'    => (int) $order['attempts'] + 1,
        'otp_code'    => '',
        'api_error'   => '',
        'hold_until'  => null,
        'sending_at'  => null,
        'updated_at'  => date('Y-m-d H:i:s'),
    ], 'id = ?', [$orderId]);
    order_recost($orderId);

    order_log($orderId, 'Paid - held number ' . $activation['number'] . ' handed over (cost '
        . money((float) $activation['cost_base']) . ', sold for ' . money((float) $order['price']) . ').');
    audit('order.number', ['entity' => 'orders', 'entity_id' => $orderId,
        'summary' => $order['code'] . ' got held ' . $activation['number'] . ' at '
                   . money((float) $activation['cost_base']) . ', sold for ' . money((float) $order['price'])]);
    if ((int) $order['attempts'] === 0) {
        number_ready_notice($order, (string) $activation['number']);
    }
    return true;
}

/**
 * Put an order's held number in the cancel queue: nobody paid for it, or the
 * customer turned its price down. The order forgets it, so a later payment
 * buys afresh.
 */
function number_hold_drop(array $order, string $why): bool
{
    $activation = order_activation($order);
    if (!$activation || $activation['status'] !== 'held') {
        return false;
    }
    if (!claim('activations', ['status' => 'queued'], "id = ? AND status = 'held'", [(int) $activation['id']])) {
        return false;
    }
    q('UPDATE orders SET activation_id = NULL, hold_until = NULL, quote_price = NULL, updated_at = ? WHERE id = ?',
        [date('Y-m-d H:i:s'), (int) $order['id']]);
    order_log((int) $order['id'], 'Held number ' . $activation['number'] . ' goes back to OTPGet: ' . $why . '.');
    return true;
}

/**
 * The customer agreed to the price their held number really costs.
 *
 * Only while the hold lasts and only once: the price becomes the order's, so
 * whatever takes the payment next charges that.
 */
function number_quote_accept(array $order): bool
{
    if (($order['quote_price'] ?? null) === null) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    if (!claim('orders', ['price' => round((float) $order['quote_price'], 2), 'quote_price' => null, 'updated_at' => $now],
            "id = ? AND status = 'pending' AND paid_at IS NULL AND quote_price IS NOT NULL AND hold_until > ?",
            [(int) $order['id'], $now])) {
        return false;
    }
    order_log((int) $order['id'], 'The customer agreed to ' . money((float) $order['quote_price'])
        . ' (it was ' . money((float) $order['price']) . ').');
    return true;
}

/**
 * The cancel queue: give back the numbers nobody is going to use.
 *
 * Holds that ran out unpaid, or whose order was closed, join it first. A
 * queued number is handed back once OTPGet allows it - two minutes after
 * buying, on the SMS servers - and OTPGet refunds it; until then it waits for
 * the next run. Cron calls this, and so does each new order a few at a time,
 * so a shop without cron still gives its holds back.
 *
 * @return int numbers given back
 */
function numbers_release_due(int $limit = 20): int
{
    if (!table_has_column('orders', 'hold_until')) {
        return 0;
    }
    $now = date('Y-m-d H:i:s');

    foreach (all("SELECT o.* FROM orders o JOIN activations a ON a.id = o.activation_id
                   WHERE a.status = 'held'
                     AND ((o.status = 'pending' AND (o.hold_until IS NULL OR o.hold_until < ?))
                          OR o.status IN ('cancelled', 'refunded'))
                   LIMIT " . max(1, $limit), [$now]) as $order) {
        number_hold_drop($order, $order['status'] === 'pending' ? 'it was not paid for in time' : 'the order was closed');
    }
    // A request that died between buying and writing the hold leaves a held
    // number no order points at; past any hold's length it can only be queued.
    q("UPDATE activations SET status = 'queued' WHERE status = 'held' AND created_at < ?
         AND id NOT IN (SELECT activation_id FROM orders WHERE activation_id IS NOT NULL AND status IN ('pending', 'paid'))",
        [date('Y-m-d H:i:s', time() - 1800)]);

    $released = 0;
    foreach (all("SELECT * FROM activations WHERE status = 'queued' ORDER BY id LIMIT " . max(1, $limit * 2)) as $activation) {
        if (time() - strtotime((string) $activation['created_at']) < number_cancel_after((string) $activation['product'])) {
            continue;
        }
        if (!claim('activations', ['checked_at' => $now], "id = ? AND status = 'queued' AND (checked_at IS NULL OR checked_at < ?)",
                [(int) $activation['id'], date('Y-m-d H:i:s', time() - 20)])) {
            continue;
        }
        if (number_queue_release($activation)) {
            $released++;
        }
        if ($released >= $limit) {
            break;
        }
    }
    return $released;
}

/** Hand one queued number back to OTPGet and write down what came of it. */
function number_queue_release(array $activation): bool
{
    $orderId = (int) $activation['order_id'];
    $api     = otpget()->about($orderId);
    $ref     = (string) $activation['provider_order_id'];
    $reply   = $activation['product'] === 'email' ? $api->cancelEmail($ref) : $api->cancel((string) $activation['product'], $ref);

    $close = static function (string $status, bool $refunded, string $code = '') use ($activation, $orderId): bool {
        if (!claim('activations', ['status' => $status, 'refunded' => $refunded ? 1 : 0, 'code' => $code,
                'closed_at' => date('Y-m-d H:i:s')], "id = ? AND status = 'queued'", [(int) $activation['id']])) {
            return false;
        }
        order_recost($orderId);
        if ($code !== '') {
            // Nobody was shown this number, so a code on it is somebody else's
            // luck and our cost: worth an admin's eye.
            order_log($orderId, 'Queued number ' . $activation['number'] . ' received a code before it could be '
                . 'given back; OTPGet keeps its cost.');
            audit('order.hold_lost', ['entity' => 'orders', 'entity_id' => $orderId, 'severity' => 'warn',
                'summary' => 'A held number got a code before release: ' . money((float) $activation['cost_base']) . ' spent']);
        } else {
            order_log($orderId, 'Number ' . $activation['number'] . ' given back to OTPGet'
                . ($refunded ? ', which refunded its cost.' : '.'));
        }
        return true;
    };

    if (!isset($reply['error'])) {
        if (($reply['code'] ?? '') !== '') {
            $close('finished', false, (string) $reply['code']);
            return false;
        }
        return $close('expired', true);
    }
    if ($reply['error'] === 'EARLY_CANCEL_DENIED') {
        return false;
    }
    if (in_array($reply['error'], array_merge(['STATUS_ALREADY_CHANGED'], NUMBER_CLOSED_UPSTREAM), true)) {
        $status = $activation['product'] === 'email' ? $api->emailCode($ref) : $api->status((string) $activation['product'], $ref);
        if (($status['state'] ?? '') === 'received' && ($status['code'] ?? '') !== '') {
            $close('finished', false, (string) $status['code']);
            return false;
        }
        return $close('expired', true);
    }
    // OTPGet closes and refunds a number with no code once its time is out,
    // so one well past it that still will not answer is written off as given back.
    if (strtotime((string) $activation['expires_at']) < time() - 600) {
        return $close('expired', true);
    }
    order_log_once($orderId, 'Could not give the held number back yet: ' . $reply['message']);
    return false;
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

    number_refusal_notify($order, $code, $message);

    // Refunded whenever no code ever arrived - the first number or a
    // replacement, it is the same customer holding nothing for their money.
    if (!empty($order['user_id']) && (string) ($order['otp_code'] ?? '') === '' && setting('auto_refund', '1') === '1') {
        $fresh = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        refund_order_to_wallet($fresh ?: $order, number_refund_note($code), ['api_error']);
    }

    return [false, number_trouble($code)];
}

/**
 * Tell the admin OTPGet refused a purchase. Out of stock is the customer's
 * problem to route around; anything else is the shop's to fix.
 */
function number_refusal_notify(array $order, string $code, string $message): void
{
    if (in_array($code, ['NO_NUMBERS', 'BAD_COUNTRY', 'BAD_SERVICE', 'NO_SERVICE'], true)) {
        return;
    }
    notify('admin_api_error', [
        'order_code' => $order['code'],
        'service'    => order_label($order),
        'provider'   => 'OTPGet',
        'error'      => $message,
        'admin_url'  => url('admin/orders/' . (int) $order['id']),
        'admin_link' => 'admin/orders/' . (int) $order['id'],
    ]);
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
 * server of the same one. Up to three candidates, best-graded and then
 * cheapest first (offer_quality()), each
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
                  ORDER BY o.price, o.id LIMIT 8",
        array_merge([(int) $order['service_id'], (int) $order['country_id']], $params, $exclude));
    // A customer who paid for a number that delivers should not be moved to
    // one that may not: better-graded servers are tried first, then price.
    $rank = ['best' => 0, 'standard' => 1, 'budget' => 2];
    usort($rows, static fn ($a, $b) =>
        [$rank[offer_quality($a)] ?? 1, (float) $a['price']] <=> [$rank[offer_quality($b)] ?? 1, (float) $b['price']]);
    $rows = array_slice($rows, 0, 3);

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
        // OTPGet no longer knows the number, or says it is closed: it was
        // cancelled on their side (a banned number, an operator problem, an
        // order closed by hand there). That is an answer, not a hiccup, so it
        // is acted on now rather than when the number's time runs out.
        if ($activation['status'] === 'waiting' && in_array($reply['error'], NUMBER_CLOSED_UPSTREAM, true)) {
            order_log((int) $order['id'], 'OTPGet closed number ' . $activation['number'] . ' on its side ('
                . $reply['error'] . ') before a code arrived.');
            audit('order.closed_upstream', ['entity' => 'orders', 'entity_id' => $orderId, 'severity' => 'warn',
                'summary' => $order['code'] . ': OTPGet closed the number (' . $reply['error'] . ')']);
            number_lapsed($order, $activation, true, true);
            return;
        }
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
        // Closed at OTPGet without a code - it lapsed or was cancelled there,
        // and either way OTPGet refunded us: the customer is refunded or
        // offered a fresh number, the same as a number that ran out.
        if (strtotime((string) $activation['expires_at']) > time() + 30) {
            order_log((int) $order['id'], 'OTPGet cancelled number ' . $activation['number'] . ' before its time was up.');
        }
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
    // A number held for an unpaid order is not the customer's yet: until the
    // money arrives the page draws only its shape.
    $hold = $activation && $activation['status'] === 'held' ? $activation : null;
    if ($hold || ($activation && $activation['status'] === 'queued')) {
        $activation = null;
    }
    $release    = number_can_release($order, $activation);
    $messages   = $activation ? (json_decode((string) ($activation['messages'] ?? '[]'), true) ?: []) : [];
    $attempts   = (int) $order['attempts'];
    $dial       = (string) col('SELECT dial_code FROM countries WHERE id = ?', [(int) ($order['country_id'] ?? 0)], '');
    $number     = (string) ($activation['number'] ?? ($hold ? '' : $order['number'] ?? ''));
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
        'held'          => $hold !== null,
        'hold_left'     => $hold && !empty($order['hold_until']) ? max(0, strtotime((string) $order['hold_until']) - time()) : 0,
        'quote'         => $hold && ($order['quote_price'] ?? null) !== null ? (float) $order['quote_price'] : null,
        'error'         => $order['status'] === 'api_error'
                             ? number_trouble((string) strtok((string) $order['api_error'], ':')) : '',
    ];
}
