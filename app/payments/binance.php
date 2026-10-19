<?php
/**
 * Binance Pay, checked against Binance rather than taken on trust.
 *
 * The customer sends USDT to your Binance Pay id, then gives us the order id
 * Binance shows them for that transfer. We ask Binance for your recent Pay
 * transactions and look for it: the right id, the right currency, and at
 * least the amount the order asks for. Only then is the order paid.
 *
 * That is the whole difference between this and a bank transfer. A typed
 * reference nobody checks is a promise; this one is read back from the
 * account it was paid into.
 *
 * Needs an API key and secret from Binance with permission to read Pay
 * transactions. Nothing here can move money - it only reads a list.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Where the Pay history lives. */
const BINANCE_API = 'https://api.binance.com/sapi/v1/pay/transactions';

/**
 * Ask Binance for recent Pay transactions.
 *
 * Every request is signed: the query string, hashed with the secret, sent
 * back as one more parameter. Binance rejects anything else, which is the
 * point - the key alone is not enough to ask in your name.
 *
 * @return array{ok: bool, rows: array, error: string}
 */
function binance_transactions(array $config, int $days = 7): array
{
    $key    = trim((string) ($config['api_key'] ?? ''));
    $secret = trim((string) ($config['secret_key'] ?? ''));

    if ($key === '' || $secret === '') {
        return ['ok' => false, 'rows' => [], 'error' => 'Binance has no API key saved.'];
    }

    // Binance works in milliseconds, and refuses a window it considers
    // unreasonable - a week is more than enough to find a payment somebody
    // is standing in front of us having just made.
    $now   = time() * 1000;
    $query = http_build_query([
        'timestamp' => $now,
        'startTime' => $now - ($days * 86400 * 1000),
        'endTime'   => $now,
        'recvWindow' => 10000,
    ]);

    $signed = $query . '&signature=' . hash_hmac('sha256', $query, $secret);

    $ch = curl_init(BINANCE_API . '?' . $signed);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Reading a payment over a connection nobody checked is reading a
        // payment anybody in the way can write.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['X-MBX-APIKEY: ' . $key],
    ]);

    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'rows' => [], 'error' => 'Could not reach Binance: ' . $error];
    }

    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'rows' => [],
                'error' => 'Binance sent a reply we could not read: '
                         . mb_substr((string) $raw, 0, 160)];
    }

    // Binance says "success" in a field rather than in the status code, and
    // puts its own complaint in msg.
    if (($body['success'] ?? null) === false || isset($body['msg'])) {
        return ['ok' => false, 'rows' => [],
                'error' => trim((string) ($body['msg'] ?? 'Binance refused the request.'))];
    }

    return ['ok' => true, 'rows' => is_array($body['data'] ?? null) ? $body['data'] : [],
            'error' => ''];
}

/** The transaction with this order id, or null. */
function binance_find(array $rows, string $orderId): ?array
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Compared as strings: these ids are long enough that PHP would
        // otherwise turn them into floats and lose the end of them.
        if (hash_equals((string) ($row['orderId'] ?? ''), $orderId)) {
            return $row;
        }
    }

    return null;
}

return [
    'name'  => 'Binance Pay',
    'blurb' => 'The customer pays in USDT and gives the Binance order id. We read it back '
             . 'from your Binance account and confirm the order ourselves - no screenshots, '
             . 'nothing to approve by hand.',
    'kind'  => 'manual',
    // Binance Pay settles in USDT here, so payment_amount() converts the
    // order's total at the rate on record and a shop with no USDT rate is
    // told to add one rather than quoting a figure nobody worked out.
    'currency' => 'USDT',

    'fields' => [
        'pay_id' => [
            'label'    => 'Your Binance Pay ID',
            'required' => true,
            'hint'     => 'The id or email the customer sends the USDT to. Shown to them on '
                        . 'the payment page.',
        ],
        'api_key' => [
            'label'    => 'API key',
            'required' => true,
            'hint'     => 'From Binance, with permission to read Pay transactions. Reading is '
                        . 'all this ever does - it cannot move money.',
        ],
        'secret_key' => [
            'label'    => 'API secret',
            'type'     => 'password',
            'required' => true,
            'hint'     => 'Used to sign each request. Never sent anywhere but Binance, and '
                        . 'never shown to a customer.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        $config = payment_config($method);
        $want   = payment_amount($method, $order);
        $payId  = trim((string) ($config['pay_id'] ?? ''));

        if (!$want['ok']) {
            return $want['message'];
        }

        // The figure already carries its own symbol, so naming the currency
        // again reads as "USDT 0.01 in USDT".
        return 'Send exactly ' . $want['shown'] . ' on Binance Pay to '
             . ($payId !== '' ? $payId : 'our Binance Pay account')
             . ', then paste the Binance order id below. We check it against our account and '
             . 'the order starts straight away.';
    },

    /**
     * Is this really a payment, for this order, into our account?
     *
     * Four questions, and all four have to answer yes: Binance knows the id,
     * it was paid in the currency we asked for, it is at least the amount the
     * order owes, and no other order has already been paid with it.
     */
    'verify' => function (array $method, array $order, array $input): array {
        $config = payment_config($method);
        $given  = trim((string) ($input['trx_id'] ?? ''));

        $refuse = static fn(string $why): array
            => ['ok' => false, 'reference' => '', 'confirmed' => false, 'message' => $why];

        if ($given === '') {
            return $refuse('Paste the Binance order id from your payment.');
        }

        // Binance order ids are digits. Anything else is a typo or a guess,
        // and asking Binance about it only wastes the customer's time.
        if (!preg_match('/^[0-9]{6,32}$/', $given)) {
            return $refuse('That does not look like a Binance order id - it is a long number '
                         . 'shown on the payment in your Binance app.');
        }

        // The same id twice is the same payment twice. Checked before we ask
        // Binance, because this is the cheap question.
        $usedBy = (string) col(
            'SELECT code FROM orders WHERE trx_id = ? AND code <> ? LIMIT 1',
            [$given, $order['code']], '');

        if ($usedBy !== '') {
            return $refuse('That Binance order id has already been used, on order ' . $usedBy . '.');
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return $refuse($want['message']);
        }

        $history = binance_transactions($config);
        if (!$history['ok']) {
            // Unreachable is not "not paid". The customer is told to try
            // again rather than that their payment does not exist.
            log_line('Binance check failed for order ' . $order['code'] . ': ' . $history['error']);

            return $refuse('We could not reach Binance just now. Please try again in a minute - '
                         . 'your payment is safe either way.');
        }

        $paid = binance_find($history['rows'], $given);
        if ($paid === null) {
            return $refuse('We cannot find a payment with that order id on our Binance account '
                         . 'yet. It can take a minute to appear - try again shortly.');
        }

        $currency = strtoupper(trim((string) ($paid['currency'] ?? '')));
        if ($currency !== 'USDT') {
            return $refuse('That payment was made in ' . ($currency ?: 'another currency')
                         . '. Only USDT can be accepted here.');
        }

        $amount = round((float) ($paid['amount'] ?? 0), 8);
        if ($amount + 0.00000001 < $want['amount']) {
            return $refuse('That payment is ' . money_in($amount, 'USDT') . ', and this order '
                         . 'asks for ' . $want['shown'] . '. Please contact us with the order id.');
        }

        return [
            'ok'        => true,
            'reference' => $given,
            // Read back from the account it was paid into, so there is
            // nothing left for a person to approve.
            'confirmed' => true,
            'message'   => '',
            'amount'    => round($amount * $want['rate'], 2),
        ];
    },
];
