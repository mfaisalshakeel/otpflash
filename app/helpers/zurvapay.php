<?php
/**
 * What ZurvaPay's Direct API does, shared by the channels we sell through it.
 *
 * ZurvaPay is one API with one pair of keys and three channels: `dqrc` is a
 * Raast QR the customer scans, `jazzcash` and `easypaisa` are a push the
 * customer approves in the wallet app. The shop sells them as separate
 * payment methods - separate files in app/payments/, separate rows in the
 * admin, separate credentials - because they are separate things to choose
 * between and an admin may hold keys for one and not the other.
 *
 * Everything the channels agree about lives here, so a signature, an amount
 * test or an attempt number cannot be read one way by one channel and
 * another way by the next. What differs - a QR to draw, a phone number to
 * ask for - stays in the channel's own file.
 *
 * The API refuses any request carrying a browser Origin header, which is the
 * shape of this integration anyway: nothing here runs in the customer's
 * browser.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Where the Direct API lives. One place, so no two calls can drift. */
const ZURVAPAY_LIVE    = 'https://zurvapay.com/api/v1/';
const ZURVAPAY_SANDBOX = 'https://zurvapay.com/sandbox/api/v1/';

/**
 * How long a create may take, per channel.
 *
 * A QR comes back at once. A wallet push does not: ZurvaPay holds the
 * connection open while the customer finds their phone, and asks for 170
 * seconds of patience. We cannot give it - a shared host cuts the request
 * off long before that and the customer is left looking at a dead page - so
 * the wait is bounded here and a create that has not answered by then is
 * treated as pending. The payment still exists under an identifier written
 * down before asking, and the poll that follows is what settles it.
 */
const ZURVAPAY_CREATE_WAIT = ['dqrc' => 40, 'jazzcash' => 22, 'easypaisa' => 22];

/** How long we keep waiting for a payment ZurvaPay has never heard of. */
const ZURVAPAY_UNKNOWN_GRACE = 150;

/** Live or sandbox, as the method was saved. */
function zurvapay_base(array $config): string
{
    return trim((string) ($config['mode'] ?? 'live')) === 'sandbox'
        ? ZURVAPAY_SANDBOX
        : ZURVAPAY_LIVE;
}

/** The two keys and the mode, asked for the same way by every channel. */
function zurvapay_fields(string $channel): array
{
    return [
        'public_key' => [
            'label'    => 'Public key',
            'required' => true,
            'hint'     => 'From your ZurvaPay dashboard, under Api Key. Sandbox keys only work '
                        . 'in sandbox mode, and live keys only in live mode.',
        ],
        'secret_key' => [
            'label'    => 'Secret key',
            'required' => true,
            'hint'     => 'Sent with every request, and what a reply is checked against to prove '
                        . 'it came from ZurvaPay. Never put it anywhere a customer could read it.',
        ],
        'mode' => [
            'label'   => 'Mode',
            'type'    => 'select',
            'default' => 'live',
            'options' => ['live' => 'Live', 'sandbox' => 'Sandbox (test payments)'],
            'hint'    => 'Sandbox takes no real money and needs no approval from ZurvaPay. For '
                       . 'live payments the Direct API has to be enabled on your account, and '
                       . $channel . ' enabled on top of it.',
        ],
    ];
}

/** Has the admin given this method enough to call ZurvaPay with? */
function zurvapay_configured(array $config): bool
{
    return trim((string) ($config['public_key'] ?? '')) !== ''
        && trim((string) ($config['secret_key'] ?? '')) !== '';
}

/**
 * One call to ZurvaPay.
 *
 * Returns the decoded reply with the HTTP status beside it, because this API
 * says as much with the code as with the body: 201 is a QR, 200 is paid, 202
 * is "ask again", 402 is declined.
 *
 * `timeout` is a refusal we survive rather than one we report: a create that
 * runs out of time has still very likely created the payment, which is why
 * the caller writes its identifier down before asking.
 *
 * @return array{ok: bool, code: int, body: array, error: string, timeout: bool}
 */
function zurvapay_call(array $config, string $path, ?array $payload = null, int $timeout = 30): array
{
    $ch = curl_init(zurvapay_base($config) . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 12,
        // A payment status read over a connection nobody checked is one
        // anybody in the way can write.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Public-Key: ' . trim((string) ($config['public_key'] ?? '')),
            'X-Secret-Key: ' . trim((string) ($config['secret_key'] ?? '')),
        ],
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload));
    }

    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    payment_wire($ch, $raw);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'code' => 0, 'body' => [], 'error' => $error,
                'timeout' => in_array($errNo, [CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING,
                                               CURLE_RECV_ERROR, CURLE_PARTIAL_FILE], true)];
    }

    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'code' => $code, 'body' => [], 'timeout' => false,
                'error' => 'ZurvaPay sent a reply we could not read: '
                         . mb_substr((string) $raw, 0, 160)];
    }

    return ['ok' => $code < 400, 'code' => $code, 'body' => $body, 'error' => '', 'timeout' => false];
}

/** The payment out of any ZurvaPay reply, or an empty array. */
function zurvapay_payment(array $reply): array
{
    return is_array($reply['body']['payment'] ?? null) ? $reply['body']['payment'] : [];
}

/** Ask ZurvaPay what became of one payment. */
function zurvapay_status(array $config, string $identifier, int $timeout = 12): array
{
    return zurvapay_call($config, 'payments/' . rawurlencode($identifier), null, $timeout);
}

/**
 * Is this payment really ours, and really paid?
 *
 * ZurvaPay signs the amount and the identifier with the secret only the two
 * of us hold. Without this check a reply is just a string that says
 * "success" - and the whole point of asking is not to take anyone's word for
 * it.
 *
 * The amount is hashed exactly as it arrived: "500.00000000" is what was
 * signed, and any tidying of it breaks the comparison.
 */
function zurvapay_signed(array $payment, string $secret): bool
{
    $amount     = (string) ($payment['amount'] ?? '');
    $identifier = (string) ($payment['identifier'] ?? '');

    if ($amount === '' || $identifier === '' || $secret === '') {
        return false;
    }

    $signature = strtoupper(hash_hmac('sha256', $amount . $identifier, $secret));
    $status    = strtoupper(hash_hmac('sha256', 'success|' . $identifier . '|' . $amount, $secret));

    return hash_equals($signature, strtoupper((string) ($payment['signature'] ?? '')))
        && hash_equals($status, strtoupper((string) ($payment['status_signature'] ?? '')));
}

/**
 * The identifier one attempt at paying this order is made under.
 *
 * ZurvaPay will not take an identifier twice - ever, on the whole account -
 * so every attempt needs its own, and an attempt that ended needs the next
 * number rather than another go at the same one. That is the whole reason
 * the attempt is written down.
 */
function zurvapay_identifier(array $order, int $attempt = 1): string
{
    $code = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $order['code']);

    return $attempt > 1 ? $code . '-' . $attempt : $code;
}

/** Does this identifier belong to any attempt at this order? */
function zurvapay_ours(array $order, string $identifier): bool
{
    $base = zurvapay_identifier($order);

    return $identifier !== ''
        && (bool) preg_match('/^' . preg_quote($base, '/') . '(-[0-9]+)?$/', $identifier);
}

/**
 * Everything the order carries from its gateway, read from the row.
 *
 * From the row and not from the array the caller is holding, because one
 * request writes here more than once - an identifier is reserved before the
 * call and what came back is written after it - and a `$order` loaded before
 * the first write still carries the blob as it was. Merging onto that is how
 * the second write quietly deletes the first.
 */
function zurvapay_blob(array $order): array
{
    $json = empty($order['id'])
        ? (string) ($order['gateway_details'] ?? '')
        : (string) col('SELECT gateway_details FROM orders WHERE id = ?', [(int) $order['id']], '');

    $stored = json_decode($json, true);

    return is_array($stored) ? $stored : [];
}

/** What we last knew about this order's ZurvaPay payment. */
function zurvapay_stored(array $order): array
{
    $stored = zurvapay_blob($order);

    return is_array($stored['zurvapay'] ?? null) ? $stored['zurvapay'] : [];
}

/** Keep what we are working under, so the poll asks about the same payment. */
function zurvapay_remember(array $order, array $keep): void
{
    if (!table_has_column('orders', 'gateway_details')) {
        return;
    }

    $stored = zurvapay_blob($order);
    $stored['zurvapay'] = $keep + (array) ($stored['zurvapay'] ?? []);

    update_row('orders', ['gateway_details' => mb_substr(
        (string) json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000)],
        'id = ?', [(int) $order['id']]);
}

/**
 * The attempt to pay this order on this channel, as it stands.
 *
 * `live` means the attempt written down can still be paid: same channel, not
 * finished, not past its own expiry. Anything else means the next press is a
 * new attempt and needs the next identifier.
 *
 * This is what a failed payment used to get wrong. A declined attempt was
 * still inside its half-hour window, so the next press was handed the same
 * identifier back - and the only thing it could do was read the same refusal
 * again. ZurvaPay is explicit about it: a failed payment is retried with a
 * new identifier or not at all.
 *
 * @return array{attempt: int, next: int, live: bool, record: array}
 */
function zurvapay_attempt(array $order, string $channel): array
{
    $record  = zurvapay_stored($order);
    $attempt = max(1, (int) ($record['attempt'] ?? 1));
    $started = (string) ($record['identifier'] ?? '') !== '';

    $live = $started
        && (string) ($record['channel'] ?? 'dqrc') === $channel
        && !empty($record['live'])
        // A QR carries its own death; a wallet push is live until somebody
        // says otherwise, so an absent expiry is not an expired one.
        && ((int) ($record['expires_at'] ?? 0) === 0
            || (int) $record['expires_at'] > time() + 20);

    return [
        'attempt' => $attempt,
        'next'    => $started ? $attempt + 1 : $attempt,
        'live'    => $live,
        'record'  => $record,
    ];
}

/**
 * This attempt is over: the next one gets an identifier of its own.
 *
 * Called the moment a refusal is believed - by the poll that read it and by
 * the webhook that was told it - so that pressing Pay again is a new payment
 * rather than another look at the old one's refusal.
 */
function zurvapay_finish(array $order, string $why = ''): void
{
    $record = zurvapay_stored($order);
    if (empty($record['live'])) {
        return;
    }

    zurvapay_remember($order, ['live' => false, 'ended' => mb_substr($why, 0, 190)]);
}

/**
 * A Pakistani mobile number as ZurvaPay wants it: 03XXXXXXXXX.
 *
 * `+92`, `92` and `0092` are all written by real people and all mean the
 * same number, so all are accepted and one shape is sent. An empty string
 * back means it is not a mobile number at all.
 */
function zurvapay_phone(string $raw): string
{
    $digits = (string) preg_replace('/\D+/', '', $raw);

    foreach (['0092', '92'] as $prefix) {
        if (str_starts_with($digits, $prefix) && strlen($digits) === strlen($prefix) + 10) {
            $digits = '0' . substr($digits, strlen($prefix));
            break;
        }
    }
    if (strlen($digits) === 10 && $digits[0] === '3') {
        $digits = '0' . $digits;
    }

    return preg_match('/^03[0-9]{9}$/', $digits) === 1 ? $digits : '';
}

/**
 * The create body every channel sends, with the channel's own additions.
 *
 * `details` is what the customer sees on their statement and what ZurvaPay
 * reads for a prohibited purpose, so it says the order code and nothing
 * about what was bought.
 */
function zurvapay_create_body(array $method, array $order, array $want, string $channel,
                              string $identifier, array $extra = []): array
{
    $email = trim((string) ($order['email'] ?? ''));

    // Where they tell us it landed, instead of us finding out on the next
    // poll. The page keeps polling regardless - this only makes the common
    // case instant. A URL nothing outside could call is left off rather than
    // sent: ZurvaPay checks it, and refuses the whole payment over one it
    // does not like (`domain_not_allowed`).
    $hook = payment_ipn_public($method, $order);

    return $extra + ($hook !== '' ? ['ipn_url' => $hook] : []) + [
        'identifier'     => $identifier,
        'channel'        => $channel,
        'amount'         => number_format($want['amount'], 2, '.', ''),
        'currency'       => 'PKR',
        'details'        => mb_substr('Order ' . $order['code'], 0, 100),
        'customer_name'  => mb_substr((string) setting('site_name', 'Customer'), 0, 40),
        // Required by the API and often not asked of the customer, so the
        // shop's own address stands in rather than an order failing over a
        // field nobody filled.
        'customer_email' => mb_substr($email !== '' ? $email
            : (string) (setting('support_email', '') ?: 'orders@example.com'), 0, 40),
    ];
}

/**
 * What a customer is told when ZurvaPay turned the payment down.
 *
 * Never ZurvaPay's own words and never the wallet's: "Insufficient balance"
 * is between the customer and their bank, and a shop reading it back out is
 * repeating something it was told about somebody's money. The real reason
 * goes to the order log, where the admin reads it.
 */
function zurvapay_declined_words(string $channel): string
{
    return $channel === 'dqrc'
        ? 'That payment did not go through. Nothing has been charged - try again, or choose '
        . 'another way to pay.'
        : 'That payment was not completed. Nothing has been charged - check the number and '
        . 'your wallet balance, then try again or choose another way to pay.';
}

/**
 * Has the money landed? One reading, shared by both channels.
 *
 * The signatures are checked before anything is believed, and the amount is
 * checked as well as the status: a success for less than was asked for is
 * somebody paying a different figure into the same payment, and quietly
 * treating that as payment in full is how a shop delivers for nothing.
 *
 * A refusal also ends the attempt here, which is what lets the next press
 * start a fresh identifier instead of re-reading this one's refusal.
 */
function zurvapay_poll_result(array $method, array $order, string $channel): array
{
    $config = payment_config($method);
    $secret = trim((string) ($config['secret_key'] ?? ''));
    $record = zurvapay_stored($order);

    // Nothing has been created yet, so there is nothing to ask about.
    $identifier = (string) ($record['identifier'] ?? '');
    if ($identifier === '' || $secret === '') {
        return ['state' => 'waiting', 'message' => ''];
    }

    // Already read as over, by a poll or by a webhook. ZurvaPay does not
    // un-fail a payment, so asking again every minute for a day is a call
    // that can only hear the same thing. An attempt that merely ran out of
    // time is *not* this: nobody has said it failed, and a code scanned in
    // its last second is still a payment to find.
    if (array_key_exists('live', $record) && empty($record['live'])) {
        return ['state' => 'waiting', 'message' => ''];
    }

    $want = payment_amount($method, $order);
    if (!$want['ok']) {
        return ['state' => 'waiting', 'message' => $want['message']];
    }

    $reply = zurvapay_status($config, $identifier);

    // Unreachable is not unpaid: say so and let the next check decide.
    if ($reply['error'] !== '') {
        return ['state'   => 'waiting',
                'message' => '',
                'summary' => 'ZurvaPay could not be reached: ' . $reply['error']];
    }

    $payment = zurvapay_payment($reply);
    $status  = strtolower(trim((string) ($payment['status'] ?? '')));

    if ($status === 'success') {
        // Signed with the secret only the two of us hold. An unsigned
        // "success" is a string, not a payment.
        if (!zurvapay_signed($payment, $secret)) {
            return [
                'state'   => 'waiting',
                'message' => '',
                'summary' => 'ZurvaPay reported this order paid but the reply was not signed '
                           . 'correctly, so it has been left for us to check.',
            ];
        }

        $paid = round((float) ($payment['amount'] ?? 0), 2);
        if ($paid + 0.01 < $want['amount']) {
            return [
                'state'   => 'waiting',
                'message' => 'ZurvaPay reports ' . money_in($paid, $want['code']) . ' against the '
                           . $want['shown'] . ' this order asks for, so it has been left for us '
                           . 'to check.',
                'summary' => 'ZurvaPay: paid ' . money_in($paid, $want['code']) . ', expected '
                           . $want['shown'],
            ];
        }

        $trx = trim((string) ($payment['payment_trx'] ?? ''));

        return [
            'state'     => 'paid',
            'reference' => $trx !== '' ? $trx : $identifier,
            // Our books are kept in the base currency, so the rupees come
            // back out at the rate the figure was worked out with.
            'amount'    => round($paid * $want['rate'], 2),
            'summary'   => 'ZurvaPay confirmed ' . money_in($paid, $want['code'])
                         . ($trx !== '' ? ', transaction ' . $trx : ''),
            'message'   => 'Payment received. Your order is on its way.',
            // Everything they said about it, kept as it came.
            'details'   => array_filter($payment, 'is_scalar')
                         + ['gateway' => (string) ($method['name'] ?? 'ZurvaPay')],
        ];
    }

    if ($status === 'failed') {
        zurvapay_finish($order, 'ZurvaPay reported it failed');
        $reason = trim((string) ($payment['reason'] ?? ''));

        return [
            'state'   => 'failed',
            'message' => zurvapay_declined_words($channel),
            'summary' => 'ZurvaPay says the payment failed'
                       . ($reason !== '' ? ': ' . $reason : '') . '.',
        ];
    }

    // An identifier ZurvaPay has never heard of. Moments after a create that
    // ran out of time, that is the two sides catching up, so it waits; long
    // after one it means the create never landed, and leaving the customer
    // watching a payment that does not exist is worse than sending them back
    // to choose again.
    if ($status === 'not_found') {
        $age = time() - (int) ($record['started_at'] ?? 0);
        if ($age > ZURVAPAY_UNKNOWN_GRACE) {
            zurvapay_finish($order, 'ZurvaPay never had this payment');

            return [
                'state'   => 'failed',
                'message' => 'That payment was never started. Please try again, or choose '
                           . 'another way to pay.',
                'summary' => 'ZurvaPay has no payment under ' . $identifier . ' after ' . $age
                           . 's, so the attempt is written off.',
            ];
        }
    }

    // pending, or an identifier they have not finished writing down yet.
    // Both mean the same thing here: keep waiting.
    return ['state' => 'waiting', 'message' => ''];
}

/**
 * ZurvaPay calling us the moment the money lands. One reading, both channels.
 *
 * It posts the same signed fields the status reply carries, to the ipn_url
 * the payment was created with, and keeps calling for hours until it gets a
 * 200. All this has to decide is whether the call is really theirs and
 * really about this order - /hook/ then reads the status with the poll
 * above, so the figure and the short-payment rule are worked out in exactly
 * one place however the news arrives.
 *
 * The signature is the whole of the authentication: the identifier is in the
 * URL the customer could have seen, but the hash over it is made with the
 * secret only ZurvaPay and we hold.
 */
function zurvapay_ipn_result(array $method, array $order, array $input, string $channel): array
{
    $secret = trim((string) (payment_config($method)['secret_key'] ?? ''));
    $record = zurvapay_stored($order);
    $data   = is_array($input['data'] ?? null) ? $input['data'] : [];

    $identifier = trim((string) ($input['identifier'] ?? ''));
    $status     = strtolower(trim((string) ($input['status'] ?? '')));

    if ($secret === '' || $identifier === '') {
        return ['verdict' => 'reject', 'summary' => 'no secret or no identifier'];
    }

    // About an attempt of this order, and no other. Any attempt of it
    // counts: a customer who paid the first QR after we had already written
    // off that attempt has still paid, and the identifier shape is ours to
    // recognise.
    if (!zurvapay_ours($order, $identifier)) {
        return ['verdict' => 'reject',
                'summary' => 'identifier ' . $identifier . ' belongs to another order'];
    }

    // Hashed over the amount exactly as it arrived, which is why the field
    // is taken raw rather than tidied into a number first.
    $signed = zurvapay_signed([
        'amount'           => (string) ($data['amount'] ?? ''),
        'identifier'       => $identifier,
        'signature'        => (string) ($input['signature'] ?? ''),
        'status_signature' => (string) ($input['status_signature'] ?? ''),
    ], $secret);

    if (!$signed) {
        return ['verdict' => 'reject', 'summary' => 'signature did not match'];
    }

    // Signed, and saying something other than success. Worth writing down -
    // and worth accepting, so they stop calling about it - but not worth a
    // trip to their API. The attempt ends here too, so the customer's next
    // press is a new payment rather than another look at this refusal.
    if ($status !== 'success') {
        if (hash_equals((string) ($record['identifier'] ?? ''), $identifier)) {
            zurvapay_finish($order, 'ZurvaPay called to say it ' . ($status !== '' ? $status : 'ended'));
        }

        return [
            'verdict' => 'ignore',
            'summary' => 'ZurvaPay reported this payment as '
                       . ($status !== '' ? $status : 'an unnamed status') . '.',
        ];
    }

    // Signed, so this identifier is one ZurvaPay and we agreed on - and the
    // poll that follows asks about whichever one is written down here. So an
    // older attempt being paid redirects the asking to it, rather than the
    // poll looking at an attempt nobody paid.
    //
    // It is marked live either way, and not only when the identifier moved:
    // ZurvaPay has just said this payment landed, and an attempt we had
    // written off is exactly the one the poll would otherwise refuse to go
    // and look at.
    zurvapay_remember($order, ['identifier' => $identifier, 'live' => true,
        'channel' => (string) ($record['channel'] ?? $channel)]);

    $trx = trim((string) ($data['payment_trx'] ?? ''));

    return [
        'verdict' => 'check',
        'summary' => 'ZurvaPay called to say the payment landed'
                   . ($trx !== '' ? ' (transaction ' . $trx . ')' : '') . '.',
    ];
}
