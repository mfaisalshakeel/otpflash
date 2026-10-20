<?php
/**
 * Rental numbers: kept for days or months, with an inbox.
 *
 * A rental is bought through an order like anything else - so it is paid
 * the same ways and shows up in the same lists - and then lives on as a
 * `rentals` row that the customer's portal reads. Rentals need an account:
 * an inbox somebody comes back to for a month has to belong to somebody,
 * and an order code is a poor key to a month of somebody's SMS.
 */

/**
 * Rent the number a paid order bought.
 *
 * @return array{0: bool, 1: string}
 */
function rental_start(int $orderId): array
{
    $now   = date('Y-m-d H:i:s');
    $stale = date('Y-m-d H:i:s', time() - 120);

    if (!claim('orders', ['sending_at' => $now],
            "id = ? AND product = 'rental' AND status IN ('paid', 'api_error') AND paid_at IS NOT NULL
               AND (sending_at IS NULL OR sending_at < ?)", [$orderId, $stale])) {
        return [false, 'This rental is already being set up.'];
    }

    $order  = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $target = $order ? order_purchase_target($order) : null;
    if (!$order || !$target || empty($order['user_id'])) {
        q('UPDATE orders SET sending_at = NULL WHERE id = ?', [$orderId]);
        return [false, 'This rental order is missing what it needs.'];
    }

    $hours = (int) $target['provider_service'];
    $loss  = order_loss_refusal($order);
    $reply = $loss !== null
        ? ['error' => 'PRICE_UP', 'message' => $loss]
        : otpget()->about($orderId)->rentNumber($target['provider_country'], (int) $target['server'], $hours);

    if (isset($reply['error']) || empty($reply['phone'])) {
        $message = (string) ($reply['message'] ?? 'OTPGet did not return a number.');
        update_row('orders', ['status' => 'api_error', 'api_error' => mb_substr($message, 0, 500),
            'sending_at' => null, 'updated_at' => $now], 'id = ?', [$orderId]);
        order_log($orderId, 'OTPGet refused the rental: ' . $message);
        notify('admin_api_error', ['order_code' => $order['code'], 'service' => order_label($order),
            'provider' => 'OTPGet', 'error' => $message,
            'admin_url' => url('admin/orders/' . $orderId), 'admin_link' => 'admin/orders/' . $orderId]);
        if (setting('auto_refund', '1') === '1') {
            $fresh = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            refund_order_to_wallet($fresh ?: $order, 'The rental number could not be supplied', ['api_error']);
        }
        return [false, 'We could not rent that number right now. '
            . (setting('auto_refund', '1') === '1' ? 'Your payment is back in your wallet.' : 'We will be in touch.')];
    }

    $cost     = (float) ($reply['price_paid'] ?? $target['cost']);
    $costBase = round((float) (to_base($cost, $target['cost_currency']) ?? 0), 2);
    $meta     = order_meta($order);
    $expires  = !empty($reply['expires']) && strtotime((string) $reply['expires'])
        ? date('Y-m-d H:i:s', strtotime((string) $reply['expires']))
        : date('Y-m-d H:i:s', time() + $hours * 3600);

    $rentalId = insert_row('rentals', [
        'order_id'           => $orderId,
        'user_id'            => (int) $order['user_id'],
        'provider'           => $target['server'],
        'provider_order_id'  => mb_substr((string) ($reply['order_id'] ?? ''), 0, 80),
        'provider_rental_id' => '',
        'number'             => mb_substr((string) $reply['phone'], 0, 40),
        'country_code'       => $target['provider_country'],
        'country_name'       => (string) ($order['country_name'] ?: ($meta['country_name'] ?? '')),
        'duration_hours'     => $hours,
        'price'              => (float) $order['price'],
        'cost'               => $costBase,
        'status'             => 'active',
        'expires_at'         => $expires,
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);

    update_row('orders', [
        'status'       => 'completed',
        'number'       => mb_substr((string) $reply['phone'], 0, 190),
        'cost'         => $costBase,
        'api_error'    => '',
        'sending_at'   => null,
        'completed_at' => $now,
        'updated_at'   => $now,
        'meta'         => json_encode($meta + ['rental_id' => $rentalId], JSON_UNESCAPED_UNICODE),
    ], 'id = ?', [$orderId]);

    order_log($orderId, 'Rented ' . $reply['phone'] . ' until ' . $expires . ' (OTPGet order '
        . ($reply['order_id'] ?? '?') . ', cost ' . money($costBase) . ').');
    rental_find_provider_id($rentalId);
    number_ready_notice($order, (string) $reply['phone'], url('account/rentals/' . $rentalId));

    return [true, 'Your rental number is ready.'];
}

/**
 * Learn OTPGet's own id for a rental, which renewing and cancelling need.
 *
 * rentNumber answers with an order id and not this one, so it is looked up
 * in the account's rental list by that order id. Asked again whenever it is
 * needed and still missing.
 */
function rental_find_provider_id(int $rentalId): string
{
    $rental = one('SELECT * FROM rentals WHERE id = ?', [$rentalId]);
    if (!$rental) {
        return '';
    }
    if ($rental['provider_rental_id'] !== '') {
        return (string) $rental['provider_rental_id'];
    }

    $reply = rental_api($rental)->myRentals();
    foreach ((array) ($reply['rentals'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['order_id'] ?? '') === (string) $rental['provider_order_id']) {
            $id = (string) ($row['id'] ?? '');
            q('UPDATE rentals SET provider_rental_id = ? WHERE id = ?', [$id, $rentalId]);
            return $id;
        }
    }
    return '';
}

/**
 * Pull new SMS for a rental into its inbox.
 *
 * Throttled to one OTPGet call every ten seconds per rental, by a claim on
 * `checked_at`, so an inbox left open in two tabs is one caller.
 *
 * @return int how many messages are new
 */
function rental_refresh(int $rentalId, bool $force = false): int
{
    $rental = one('SELECT * FROM rentals WHERE id = ?', [$rentalId]);
    if (!$rental || $rental['status'] !== 'active') {
        return 0;
    }
    if (strtotime((string) $rental['expires_at']) < time()) {
        q("UPDATE rentals SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'active'",
            [date('Y-m-d H:i:s'), $rentalId]);
        return 0;
    }

    if (!claim('rentals', ['checked_at' => date('Y-m-d H:i:s')],
            'id = ? AND (checked_at IS NULL OR checked_at < ?)',
            [$rentalId, date('Y-m-d H:i:s', time() - ($force ? 2 : 10))])) {
        return 0;
    }

    $reply = rental_api($rental)->rentalSms((string) $rental['provider_order_id']);
    if (isset($reply['error'])) {
        return 0;
    }

    $new = 0;
    foreach ((array) ($reply['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $at   = strtotime((string) ($message['timestamp'] ?? '')) ?: time();
        $body = (string) ($message['text'] ?? '');
        $from = (string) ($message['from'] ?? '');
        $fingerprint = hash('sha256', date('Y-m-d H:i:s', $at) . '|' . $from . '|' . $body);

        if (col('SELECT 1 FROM rental_messages WHERE rental_id = ? AND fingerprint = ?', [$rentalId, $fingerprint])) {
            continue;
        }
        try {
            insert_row('rental_messages', [
                'rental_id'   => $rentalId,
                'fingerprint' => $fingerprint,
                'sender'      => mb_substr($from, 0, 120),
                'body'        => mb_substr($body, 0, 2000),
                'code'        => mb_substr((string) ($message['code'] ?? ''), 0, 60),
                'received_at' => date('Y-m-d H:i:s', $at),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $new++;
        } catch (PDOException $e) {
            // The other tab stored it first.
            if (!is_duplicate_key($e)) {
                throw $e;
            }
        }
    }

    return $new;
}

/**
 * The lengths a rental can be renewed for, and at what price.
 *
 * Read from the same offers the shop sells new rentals from, so a renewal
 * costs what renting it fresh would.
 */
function rental_renew_options(array $rental): array
{
    [$sellable, $params] = offer_sellable_sql('o', ['rental']);

    $rows = all("SELECT o.* FROM offers o WHERE {$sellable} AND o.server = ? AND o.provider_country = ?
                  ORDER BY o.provider_service + 0",
        array_merge($params, [(string) $rental['provider'], (string) $rental['country_code']]));

    foreach ($rows as &$row) {
        $row['sale_price'] = offer_sale_price($row);
        $row['label']      = rental_duration_words((int) $row['provider_service']);
    }
    return $rows;
}

/**
 * A paid renewal order extends its rental.
 *
 * @return array{0: bool, 1: string}
 */
function rental_renew_from_order(int $orderId): array
{
    $now = date('Y-m-d H:i:s');
    if (!claim('orders', ['sending_at' => $now],
            "id = ? AND product = 'renewal' AND status IN ('paid', 'api_error') AND paid_at IS NOT NULL
               AND (sending_at IS NULL OR sending_at < ?)", [$orderId, date('Y-m-d H:i:s', time() - 120)])) {
        return [false, 'This renewal is already being applied.'];
    }

    $order  = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $meta   = $order ? order_meta($order) : [];
    $rental = !empty($meta['rental_id']) ? one('SELECT * FROM rentals WHERE id = ?', [(int) $meta['rental_id']]) : null;
    $hours  = (int) ($meta['hours'] ?? 0);

    $providerId = $rental ? rental_find_provider_id((int) $rental['id']) : '';
    $loss  = $order ? order_loss_refusal($order) : null;
    $reply = match (true) {
        $loss !== null                   => ['error' => 'PRICE_UP', 'message' => $loss],
        $providerId !== '' && $hours > 0 => otpget()->about($orderId)->renewRental($providerId, $hours),
        default                          => ['error' => 'MISSING', 'message' => 'OTPGet has not told us this rental\'s id yet.'],
    };

    if (isset($reply['error'])) {
        update_row('orders', ['status' => 'api_error', 'api_error' => mb_substr((string) $reply['message'], 0, 500),
            'sending_at' => null, 'updated_at' => $now], 'id = ?', [$orderId]);
        order_log($orderId, 'OTPGet refused the renewal: ' . $reply['message']);
        if (setting('auto_refund', '1') === '1') {
            $fresh = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            refund_order_to_wallet($fresh ?: $order, 'The rental could not be renewed', ['api_error']);
        }
        return [false, 'We could not renew that rental right now.'];
    }

    $expires = !empty($reply['new_expiry']) && strtotime((string) $reply['new_expiry'])
        ? date('Y-m-d H:i:s', strtotime((string) $reply['new_expiry']))
        : date('Y-m-d H:i:s', max(time(), strtotime((string) $rental['expires_at'])) + $hours * 3600);

    update_row('rentals', ['expires_at' => $expires, 'status' => 'active', 'updated_at' => $now,
        'duration_hours' => (int) $rental['duration_hours'] + $hours], 'id = ?', [(int) $rental['id']]);

    $costBase = round((float) (to_base((float) ($reply['price_paid'] ?? 0), otpget_currency()) ?? 0), 2);
    update_row('orders', ['status' => 'completed', 'cost' => $costBase, 'sending_at' => null,
        'completed_at' => $now, 'updated_at' => $now, 'number' => (string) $rental['number']],
        'id = ?', [$orderId]);
    order_log($orderId, 'Renewed ' . $rental['number'] . ' until ' . $expires . '.');

    return [true, 'Renewed until ' . date('d M Y, H:i', strtotime($expires)) . '.'];
}

/** The OTPGet client, with what it says about this rental kept on the order that bought it. */
function rental_api(array $rental): OtpGet
{
    return !empty($rental['order_id']) ? otpget()->about((int) $rental['order_id']) : otpget();
}

/**
 * Hand a rental back for a refund. Server 2 only, within twenty minutes, and
 * only while nothing has arrived - OTPGet's rules, checked here first so the
 * customer is told why before anything is asked.
 *
 * @return array{0: bool, 1: string}
 */
function rental_cancel(array $rental): array
{
    if ((string) $rental['provider'] !== '2') {
        return [false, 'Only Server 2 rentals can be cancelled.'];
    }
    if (time() - strtotime((string) $rental['created_at']) > 20 * 60) {
        return [false, 'A rental can only be cancelled within 20 minutes of renting it.'];
    }
    if (col('SELECT 1 FROM rental_messages WHERE rental_id = ?', [(int) $rental['id']])) {
        return [false, 'This number has already received an SMS, so it cannot be cancelled.'];
    }

    $providerId = rental_find_provider_id((int) $rental['id']);
    $reply = $providerId !== '' ? rental_api($rental)->cancelRental($providerId)
                                : ['error' => 'MISSING', 'message' => 'OTPGet has not told us this rental\'s id yet.'];
    if (isset($reply['error'])) {
        // Their words go to the log; the customer gets ours.
        log_line('Rental #' . $rental['id'] . ' cancel refused: ' . $reply['error'] . ' ' . $reply['message']);
        return [false, 'This rental could not be cancelled right now. Please try again in a minute.'];
    }

    if (!claim('rentals', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
            "id = ? AND status = 'active'", [(int) $rental['id']])) {
        return [false, 'This rental is already closed.'];
    }

    $order = $rental['order_id'] ? one('SELECT * FROM orders WHERE id = ?', [(int) $rental['order_id']]) : null;
    if ($order) {
        refund_order_to_wallet($order, 'Rental cancelled', ['completed']);
    }
    return [true, 'Cancelled, and the money is back in your wallet.'];
}

/** Close the rentals whose time is up. */
function rentals_expire(): int
{
    return q("UPDATE rentals SET status = 'expired', updated_at = ? WHERE status = 'active' AND expires_at < ?",
        [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')])->rowCount();
}
