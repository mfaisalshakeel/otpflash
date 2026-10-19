<?php
/**
 * ZurvaPay, paid by scanning a bank QR.
 *
 * ZurvaPay's Direct API hands back a Raast QR payload for an amount we name,
 * and tells us afterwards whether it was paid. The customer never leaves the
 * shop: the code is drawn on our own page by qr_svg(), any bank or wallet app
 * in Pakistan scans it, and the page watches the status until the money
 * lands.
 *
 * Two keys, both from the ZurvaPay dashboard, and both sent on every call as
 * headers. The API refuses any request carrying a browser Origin header,
 * which is the shape of this integration anyway - nothing here runs in the
 * customer's browser.
 *
 * What makes a payment ours is the `identifier` we choose. ZurvaPay will not
 * take the same one twice, so an order whose QR expired gets the next attempt
 * number - see zurvapay_identifier().
 *
 * Settles in PKR and nothing else, so a shop priced in another currency has
 * its total converted at the rate on record before the QR is asked for.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Where the Direct API lives. One place, so the two calls cannot drift. */
const ZURVAPAY_API = 'https://zurvapay.com/api/v1/';

/**
 * One call to ZurvaPay.
 *
 * Returns the decoded reply with the HTTP status beside it, because this API
 * says as much with the code as with the body: 201 is a QR, 200 is paid, 202
 * is "ask again", 402 is declined.
 *
 * @return array{ok: bool, code: int, body: array, error: string}
 */
function zurvapay_call(array $config, string $path, ?array $payload = null, int $timeout = 30): array
{
    $public = trim((string) ($config['public_key'] ?? ''));
    $secret = trim((string) ($config['secret_key'] ?? ''));

    $ch = curl_init(ZURVAPAY_API . ltrim($path, '/'));
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
            'X-Public-Key: ' . $public,
            'X-Secret-Key: ' . $secret,
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
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'code' => 0, 'body' => [], 'error' => $error];
    }

    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'code' => $code, 'body' => [],
                'error' => 'ZurvaPay sent a reply we could not read: '
                         . mb_substr((string) $raw, 0, 160)];
    }

    return ['ok' => $code < 400, 'code' => $code, 'body' => $body, 'error' => ''];
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
 * The identifier this order's current attempt is paid under.
 *
 * ZurvaPay refuses an identifier it has seen before, and a QR that expired is
 * an attempt that is over. So the order code carries an attempt number, and
 * the number only moves when the last attempt actually ended - an unfinished
 * one is reused, which is what lets the customer reload the page.
 */
function zurvapay_identifier(array $order, int $attempt = 1): string
{
    $code = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $order['code']);

    return $attempt > 1 ? $code . '-' . $attempt : $code;
}

/** What we last knew about this order's ZurvaPay payment. */
function zurvapay_stored(array $order): array
{
    $stored = json_decode((string) ($order['gateway_details'] ?? ''), true);

    return is_array($stored['zurvapay'] ?? null) ? $stored['zurvapay'] : [];
}

/** Keep the identifier we are working under, so the poll asks about it. */
function zurvapay_remember(array $order, array $keep): void
{
    if (!table_has_column('orders', 'gateway_details')) {
        return;
    }

    $stored = json_decode((string) ($order['gateway_details'] ?? ''), true);
    $stored = is_array($stored) ? $stored : [];
    $stored['zurvapay'] = $keep + (array) ($stored['zurvapay'] ?? []);

    update_row('orders', ['gateway_details' => mb_substr(
        (string) json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000)],
        'id = ?', [(int) $order['id']]);
}

return [
    'name'  => 'ZurvaPay (Bank QR)',
    // It settles in rupees and nothing else, so this is not the admin's to
    // choose: payment_amount() converts the order's total into PKR, and a
    // shop with no PKR rate is told to add one rather than shown a QR for a
    // figure nobody worked out.
    'currency' => 'PKR',
    'blurb' => 'Shows a Raast QR for the exact amount. The customer scans it with any bank or '
             . 'wallet app, and the order confirms itself when ZurvaPay reports the payment.',
    'kind'  => 'redirect',
    'wait'  => 'Generating QR code…',

    'fields' => [
        'public_key' => [
            'label'    => 'Public key',
            'required' => true,
            'hint'     => 'From your ZurvaPay dashboard, under Api Key.',
        ],
        'secret_key' => [
            'label'    => 'Secret key',
            'required' => true,
            'hint'     => 'Sent with every request, and what a reply is checked against to prove '
                        . 'it came from ZurvaPay. Never put it anywhere a customer could read it.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        $want = payment_amount($method, $order);

        return $want['ok']
            ? 'Scan the QR with any bank or wallet app and pay ' . $want['shown']
            . '. The order confirms itself the moment ZurvaPay reports the payment - there is '
            . 'nothing to type and nothing to send us.'
            : $want['message'];
    },

    // Our own page, not theirs: the QR is drawn there and the waiting is done
    // there, so the customer never leaves the shop.
    'start' => function (array $method, array $order): array {
        return ['redirect' => url('order/' . $order['code'] . '/qr')];
    },

    /**
     * Ask ZurvaPay for a QR, or hand back the one this order already has.
     *
     * Creating is not free - it is a payment on their side - so an attempt
     * that is still alive is reused, and only a finished one starts another.
     */
    'qr' => function (array $method, array $order): array {
        $config = payment_config($method);

        if (trim((string) ($config['public_key'] ?? '')) === ''
            || trim((string) ($config['secret_key'] ?? '')) === '') {
            return ['error' => 'ZurvaPay is not configured yet - its keys have not been saved.'];
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return ['error' => $want['message']];
        }

        $stored  = zurvapay_stored($order);
        $attempt = max(1, (int) ($stored['attempt'] ?? 1));

        // A code we already hold and that has not expired is the same code.
        if ((string) ($stored['qr'] ?? '') !== ''
            && (int) ($stored['expires_at'] ?? 0) > time() + 20) {
            return [
                'text'      => (string) $stored['qr'],
                'amount'    => $want['shown'],
                'reference' => (string) ($stored['identifier'] ?? ''),
                'caption'   => 'Scan with any bank or wallet app - Raast, JazzCash, EasyPaisa '
                             . 'or your own bank.',
            ];
        }

        // The last one is over, so this is the next attempt.
        if ((string) ($stored['qr'] ?? '') !== '') {
            $attempt++;
        }

        $identifier = zurvapay_identifier($order, $attempt);
        $email      = trim((string) ($order['email'] ?? ''));

        $reply = zurvapay_call($config, 'payments', [
            'identifier'     => $identifier,
            'channel'        => 'dqrc',
            // Where they tell us it landed, instead of us finding out on the
            // next poll. The page keeps polling regardless - this only makes
            // the common case instant, and a shop whose domain ZurvaPay has
            // not approved simply never sees the call.
            'ipn_url'        => payment_ipn_url($method, $order),
            'amount'         => number_format($want['amount'], 2, '.', ''),
            'currency'       => 'PKR',
            'details'        => mb_substr('Order ' . $order['code'], 0, 100),
            'customer_name'  => mb_substr((string) setting('site_name', 'Customer'), 0, 40),
            // Required by the API and often not asked of the customer, so the
            // shop's own address stands in rather than an order failing over
            // a field nobody filled.
            'customer_email' => mb_substr($email !== '' ? $email
                : (string) (setting('support_email', '') ?: 'orders@example.com'), 0, 40),
        ], 40);

        if ($reply['error'] !== '') {
            return ['error' => 'Could not reach ZurvaPay: ' . $reply['error']];
        }

        $payment = is_array($reply['body']['payment'] ?? null) ? $reply['body']['payment'] : [];
        $text    = (string) ($payment['qr']['text'] ?? '');

        // A payment already exists under this identifier - because the page
        // was opened twice, or because the first create is still open on
        // their side. Neither is a failure: the code the customer needs is
        // the one that already exists, so it is fetched rather than refused.
        $clash = (string) ($reply['body']['code'] ?? '');
        if ($text === '' && in_array($clash, ['request_in_progress', 'duplicate_identifier'], true)) {
            $existing = zurvapay_call($config, 'payments/' . rawurlencode($identifier), null, 25);
            $found    = is_array($existing['body']['payment'] ?? null)
                ? $existing['body']['payment'] : [];

            if (strtolower((string) ($found['status'] ?? '')) === 'pending'
                && (string) ($found['qr']['text'] ?? '') !== '') {
                $payment = $found;
                $text    = (string) $found['qr']['text'];
            } elseif ($found) {
                // It exists and is over - paid, failed or expired. The next
                // attempt needs an identifier of its own, which is what a
                // reload will ask for now that this one is written down as
                // finished.
                zurvapay_remember($order, ['attempt' => $attempt, 'qr' => '', 'expires_at' => 0]);
            }
        }

        if ($text === '') {
            $said = trim((string) ($reply['body']['message'] ?? ''));

            return ['error' => $said !== ''
                ? 'ZurvaPay could not create the payment: ' . $said
                : 'ZurvaPay did not return a QR code for this order.'];
        }

        $expiresIn = (int) ($payment['qr']['expires_in'] ?? 1800);

        zurvapay_remember($order, [
            'identifier'  => $identifier,
            'attempt'     => $attempt,
            'payment_trx' => (string) ($payment['payment_trx'] ?? ''),
            'qr'          => $text,
            // Kept as a timestamp of ours rather than their wording, because
            // this is only ever compared with time().
            'expires_at'  => time() + max(60, $expiresIn),
        ]);

        return [
            'text'      => $text,
            'amount'    => $want['shown'],
            'reference' => $identifier,
            'caption'   => 'Scan with any bank or wallet app - Raast, JazzCash, EasyPaisa or '
                         . 'your own bank.',
        ];
    },

    /**
     * Ask ZurvaPay what happened to this order.
     *
     * The signatures are checked before anything is believed, and the amount
     * is checked as well as the status: a success for less than was asked for
     * is somebody paying a different figure into the same QR, and quietly
     * treating that as payment in full is how a shop delivers for nothing.
     */
    'poll' => function (array $method, array $order): array {
        $config = payment_config($method);
        $secret = trim((string) ($config['secret_key'] ?? ''));
        $stored = zurvapay_stored($order);

        // Nothing has been created yet, so there is nothing to ask about.
        $identifier = (string) ($stored['identifier'] ?? '');
        if ($identifier === '' || $secret === '') {
            return ['state' => 'waiting', 'message' => ''];
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return ['state' => 'waiting', 'message' => $want['message']];
        }

        $reply = zurvapay_call($config, 'payments/' . rawurlencode($identifier), null, 12);

        // Unreachable is not unpaid: say so and let the next check decide.
        if ($reply['error'] !== '') {
            return ['state' => 'waiting',
                    'message' => 'Could not reach ZurvaPay: ' . $reply['error'],
                    'summary' => 'ZurvaPay could not be reached: ' . $reply['error']];
        }

        $payment = is_array($reply['body']['payment'] ?? null) ? $reply['body']['payment'] : [];
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
                               . $want['shown'] . ' this order asks for, so it '
                               . 'has been left for us to check.',
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
                'details'   => array_filter($payment, 'is_scalar') + ['gateway' => 'ZurvaPay'],
            ];
        }

        if ($status === 'failed') {
            $reason = trim((string) ($payment['reason'] ?? ''));

            return [
                'state'   => 'failed',
                'message' => $reason !== ''
                    ? 'That payment did not go through: ' . $reason
                    : 'ZurvaPay says that payment did not go through.',
            ];
        }

        // pending, or an identifier they have never seen because the QR has
        // not been scanned yet. Both mean the same thing here: keep waiting.
        return ['state' => 'waiting', 'message' => ''];
    },

    /**
     * ZurvaPay calling us the moment the money lands.
     *
     * It posts the same signed fields the status reply carries, to the
     * ipn_url the payment was created with, and keeps calling for hours
     * until it gets a 200. All this has to decide is whether the call is
     * really theirs and really about this order - /hook/ then reads the
     * status with poll() above, so the figure and the short-payment rule
     * are worked out in exactly one place however the news arrives.
     *
     * The signature is the whole of the authentication: the identifier is
     * in the URL the customer could have seen, but the hash over it is
     * made with the secret only ZurvaPay and we hold.
     */
    'ipn' => function (array $method, array $order, array $input): array {
        $secret = trim((string) (payment_config($method)['secret_key'] ?? ''));
        $stored = zurvapay_stored($order);
        $data   = is_array($input['data'] ?? null) ? $input['data'] : [];

        $identifier = trim((string) ($input['identifier'] ?? ''));
        $status     = strtolower(trim((string) ($input['status'] ?? '')));

        if ($secret === '' || $identifier === '') {
            return ['verdict' => 'reject', 'summary' => 'no secret or no identifier'];
        }

        // About an attempt of this order, and no other. Any attempt of it
        // counts: a customer who paid the first QR after we had already
        // written off that attempt has still paid, and the identifier shape
        // is ours to recognise.
        $base = zurvapay_identifier($order);
        if (!preg_match('/^' . preg_quote($base, '/') . '(-[0-9]+)?$/', $identifier)) {
            return ['verdict' => 'reject',
                    'summary' => 'identifier ' . $identifier . ' belongs to another order'];
        }

        // Hashed over the amount exactly as it arrived, which is why the
        // field is taken raw rather than tidied into a number first.
        $signed = zurvapay_signed([
            'amount'           => (string) ($data['amount'] ?? ''),
            'identifier'       => $identifier,
            'signature'        => (string) ($input['signature'] ?? ''),
            'status_signature' => (string) ($input['status_signature'] ?? ''),
        ], $secret);

        if (!$signed) {
            return ['verdict' => 'reject', 'summary' => 'signature did not match'];
        }

        // Signed, and saying something other than success. Worth writing
        // down - and worth accepting, so they stop calling about it - but
        // not worth a trip to their API.
        if ($status !== 'success') {
            return [
                'verdict' => 'ignore',
                'summary' => 'ZurvaPay reported this payment as '
                           . ($status !== '' ? $status : 'an unnamed status') . '.',
            ];
        }

        // Signed, so this identifier is one ZurvaPay and we agreed on - and
        // the poll that follows asks about whichever one is written down
        // here. So an older attempt being paid redirects the asking to it,
        // rather than the poll looking at an attempt nobody paid.
        if (!hash_equals((string) ($stored['identifier'] ?? ''), $identifier)) {
            zurvapay_remember($order, ['identifier' => $identifier]);
        }

        $trx = trim((string) ($data['payment_trx'] ?? ''));

        return [
            'verdict' => 'check',
            'summary' => 'ZurvaPay called to say the payment landed'
                       . ($trx !== '' ? ' (transaction ' . $trx . ')' : '') . '.',
        ];
    },
];
