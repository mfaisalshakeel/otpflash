<?php
/**
 * PayPal, taking the payment on PayPal's own pages.
 *
 * An order is created over PayPal's API, the customer is sent to PayPal to
 * approve it, and they come back here. Then we ask PayPal what happened:
 * approved but not yet taken is captured, and only a captured order counts
 * as paid. Nothing is believed because the customer came back - they can
 * come back from anywhere.
 *
 * The PayPal order id is kept on our order, so the asking afterwards is
 * about exactly the payment we started. Cron asks the same question for the
 * customer who approved it and then closed the tab.
 *
 * Needs a REST app's client id and secret from the PayPal developer
 * dashboard. Sandbox credentials work against the sandbox switch.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Live and sandbox, so the two cannot be mixed up by hand. */
const PAYPAL_HOSTS = [
    'live'    => 'https://api-m.paypal.com',
    'sandbox' => 'https://api-m.sandbox.paypal.com',
];

/** Which PayPal this method talks to. */
function paypal_host(array $config): string
{
    $mode = ($config['mode'] ?? 'live') === 'sandbox' ? 'sandbox' : 'live';

    return PAYPAL_HOSTS[$mode];
}

/**
 * An access token, good for a few hours.
 *
 * Asked for per request rather than cached: a token in the database is a
 * token to keep safe and expire correctly, and this call is cheap next to
 * the redirect it precedes.
 *
 * @return array{ok: bool, token: string, error: string}
 */
function paypal_token(array $config): array
{
    $client = trim((string) ($config['client_id'] ?? ''));
    $secret = trim((string) ($config['secret'] ?? ''));

    if ($client === '' || $secret === '') {
        return ['ok' => false, 'token' => '', 'error' => 'PayPal has no client id or secret saved.'];
    }

    $ch = curl_init(paypal_host($config) . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => $client . ':' . $secret,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $error = curl_error($ch);
    payment_wire($ch, $raw);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'token' => '', 'error' => 'Could not reach PayPal: ' . $error];
    }

    $body  = json_decode((string) $raw, true);
    $token = (string) ($body['access_token'] ?? '');

    return $token !== ''
        ? ['ok' => true, 'token' => $token, 'error' => '']
        : ['ok' => false, 'token' => '',
           'error' => trim((string) ($body['error_description'] ?? 'PayPal refused those keys.'))];
}

/**
 * One call to the REST API with a token already in hand.
 *
 * @return array{ok: bool, code: int, body: array, error: string}
 */
function paypal_call(array $config, string $token, string $path, ?array $payload = null): array
{
    $ch = curl_init(paypal_host($config) . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
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
        return ['ok' => false, 'code' => 0, 'body' => [], 'error' => $error];
    }

    $body = json_decode((string) $raw, true);

    return ['ok' => $code < 400, 'code' => $code,
            'body' => is_array($body) ? $body : [], 'error' => ''];
}

/** The PayPal order id we started for this order of ours. */
function paypal_stored(array $order): array
{
    $stored = json_decode((string) ($order['gateway_details'] ?? ''), true);

    return is_array($stored['paypal'] ?? null) ? $stored['paypal'] : [];
}

/** Remember it, so the asking afterwards is about the right payment. */
function paypal_remember(array $order, array $keep): void
{
    if (!table_has_column('orders', 'gateway_details')) {
        return;
    }

    $stored = json_decode((string) ($order['gateway_details'] ?? ''), true);
    $stored = is_array($stored) ? $stored : [];
    $stored['paypal'] = $keep + (array) ($stored['paypal'] ?? []);

    update_row('orders', ['gateway_details' => mb_substr(
        (string) json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000)],
        'id = ?', [(int) $order['id']]);
}

return [
    'name'  => 'PayPal',
    'blurb' => 'Sends the customer to PayPal to pay, then reads the result back from PayPal '
             . 'and confirms the order itself.',
    'kind'  => 'redirect',
    'wait'  => 'Opening PayPal…',
    // PayPal is asked for a currency it settles in; the admin picks which on
    // the method, and payment_amount() converts the order's total into it.
    'currencies' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD'],

    'fields' => [
        'client_id' => [
            'label'    => 'Client ID',
            'required' => true,
            'hint'     => 'From a REST app in your PayPal developer dashboard.',
        ],
        'secret' => [
            'label'    => 'Secret',
            'type'     => 'password',
            'required' => true,
            'hint'     => 'Kept in the database and only ever sent to PayPal.',
        ],
        'mode' => [
            'label'   => 'Which PayPal',
            'type'    => 'select',
            'options' => ['live' => 'Live - real payments', 'sandbox' => 'Sandbox - for testing'],
            'default' => 'live',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        $want = payment_amount($method, $order);

        return $want['ok']
            ? 'You will be taken to PayPal to pay ' . $want['shown'] . '. The order confirms '
            . 'itself as soon as PayPal reports the payment.'
            : $want['message'];
    },

    /**
     * Create the PayPal order and hand back where to send the customer.
     *
     * The return address is this order's own page: coming back is not what
     * makes it paid - poll() asking PayPal is - but it is where the customer
     * should land either way.
     */
    'start' => function (array $method, array $order): array {
        $config = payment_config($method);
        $want   = payment_amount($method, $order);

        if (!$want['ok']) {
            return ['error' => $want['message']];
        }

        $auth = paypal_token($config);
        if (!$auth['ok']) {
            return ['error' => $auth['error']];
        }

        $reply = paypal_call($config, $auth['token'], '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // Our own code on their record, so a payment can be traced
                // back from the PayPal dashboard without a lookup table.
                'reference_id' => (string) $order['code'],
                'custom_id'    => (string) $order['code'],
                'description'  => mb_substr('Order ' . $order['code'], 0, 127),
                'amount' => [
                    'currency_code' => $want['code'],
                    'value'         => number_format($want['amount'], 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name'  => mb_substr((string) setting('site_name', 'Shop'), 0, 127),
                'user_action' => 'PAY_NOW',
                'return_url'  => url('order/' . $order['code']),
                'cancel_url'  => url('order/' . $order['code']),
            ],
        ]);

        $paypalId = (string) ($reply['body']['id'] ?? '');
        $approve  = '';

        foreach ((array) ($reply['body']['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'approve') {
                $approve = (string) ($link['href'] ?? '');
                break;
            }
        }

        if ($reply['error'] !== '') {
            return ['error' => 'Could not reach PayPal: ' . $reply['error']];
        }

        if ($paypalId === '' || $approve === '') {
            return ['error' => trim((string) ($reply['body']['message'] ?? ''))
                ?: 'PayPal would not start that payment.'];
        }

        paypal_remember($order, ['id' => $paypalId, 'currency' => $want['code'],
                                 'amount' => number_format($want['amount'], 2, '.', '')]);

        return ['redirect' => $approve];
    },

    /**
     * Ask PayPal what happened, and finish the job if it is waiting on us.
     *
     * An approved order is one the customer said yes to and nobody has taken
     * the money for yet - so this takes it. Anything else is read, not acted
     * on: only COMPLETED is a payment.
     */
    'poll' => function (array $method, array $order): array {
        $config = payment_config($method);
        $stored = paypal_stored($order);
        $id     = (string) ($stored['id'] ?? '');

        if ($id === '') {
            return ['state' => 'waiting', 'message' => ''];
        }

        $auth = paypal_token($config);
        if (!$auth['ok']) {
            return ['state' => 'waiting', 'message' => '', 'summary' => $auth['error']];
        }

        $look = paypal_call($config, $auth['token'], '/v2/checkout/orders/' . rawurlencode($id));
        if ($look['error'] !== '') {
            return ['state' => 'waiting', 'message' => '',
                    'summary' => 'PayPal could not be reached: ' . $look['error']];
        }

        $status = strtoupper((string) ($look['body']['status'] ?? ''));

        // Said yes but not yet taken. Capturing is the half that is ours.
        if ($status === 'APPROVED') {
            $taken = paypal_call($config, $auth['token'],
                '/v2/checkout/orders/' . rawurlencode($id) . '/capture', []);

            $status = strtoupper((string) ($taken['body']['status'] ?? $status));
            if ($taken['error'] !== '' || $status !== 'COMPLETED') {
                return ['state' => 'waiting', 'message' => '',
                        'summary' => 'PayPal would not complete that payment: '
                                   . trim((string) ($taken['body']['message'] ?? $taken['error']))];
            }

            $look = $taken;
        }

        if ($status !== 'COMPLETED') {
            // VOIDED is the one that is over; the rest are still in flight.
            return $status === 'VOIDED'
                ? ['state' => 'failed', 'message' => 'That PayPal payment was cancelled.']
                : ['state' => 'waiting', 'message' => ''];
        }

        $want    = payment_amount($method, $order);
        $capture = $look['body']['purchase_units'][0]['payments']['captures'][0] ?? [];
        $paid    = round((float) ($capture['amount']['value'] ?? 0), 2);
        $code    = strtoupper((string) ($capture['amount']['currency_code'] ?? $want['code']));

        if ($want['ok'] && $code === $want['code'] && $paid + 0.01 < $want['amount']) {
            return [
                'state'   => 'waiting',
                'message' => 'PayPal reports ' . money_in($paid, $code) . ' against the '
                           . $want['shown'] . ' this order asks for, so it has been left for '
                           . 'us to check.',
                'summary' => 'PayPal: paid ' . money_in($paid, $code) . ', expected ' . $want['shown'],
            ];
        }

        $reference = (string) ($capture['id'] ?? $id);

        return [
            'state'     => 'paid',
            'reference' => $reference,
            'amount'    => $want['ok'] ? round($paid * $want['rate'], 2) : null,
            'summary'   => 'PayPal confirmed ' . money_in($paid, $code) . ', capture ' . $reference,
            'message'   => 'Payment received. Your order is on its way.',
            'details'   => ['gateway' => 'PayPal', 'order_id' => $id, 'capture_id' => $reference,
                            'amount' => (string) $paid, 'currency' => $code, 'status' => $status],
        ];
    },
];
