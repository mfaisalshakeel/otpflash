<?php
/**
 * JazzCash, through ZurvaPay's Direct API (their `jazzcash` channel).
 *
 * The same API and the same pair of keys as the Bank QR method next door,
 * and a different thing to buy with: there is no code to scan. The customer
 * gives their JazzCash number, ZurvaPay pushes the payment to that wallet,
 * and the customer approves it in the JazzCash app. So the shop asks for one
 * field, starts the payment, and then watches - exactly as the QR method
 * watches a code nobody has scanned yet.
 *
 * It is its own file, and so its own payment method with its own credentials,
 * because an admin may be approved for one channel and not the other and
 * because "JazzCash" and "Bank QR" are two different answers to "how would
 * you like to pay". Everything the two channels agree about - the call, the
 * signatures, the attempt numbering, what a refusal means - is in
 * helpers/zurvapay.php, so neither can drift from the other.
 *
 * ZurvaPay holds the create request open while the customer finds their
 * phone, and asks for 170 seconds of patience. A shared host does not have
 * 170 seconds. So the wait is cut short (ZURVAPAY_CREATE_WAIT) and a create
 * that has not answered is treated as pending: the identifier was written
 * down before the call, and the watch page's poll is what settles it.
 *
 * Settles in PKR and nothing else, so a shop priced in another currency has
 * its total converted at the rate on record before the push is sent.
 */
require_once APP_PATH . '/helpers/zurvapay.php';

/** The channel this file sells. Written once so no call can disagree. */
const ZURVAPAY_JAZZCASH = 'jazzcash';

/**
 * Send the payment to the customer's wallet, and say where to wait.
 *
 * Returns what a gateway's `start` returns, plus `ask_error` for the one
 * thing the customer can actually fix: the number they typed. That is said
 * in their own words, under the field, because PAYMENT_TROUBLE ("choose
 * another method") is the wrong advice for a mistyped digit.
 */
function zurvapay_jazzcash_begin(array $method, array $order, array $input, int $tries = 2): array
{
    $config = payment_config($method);

    if (!zurvapay_configured($config)) {
        return ['error' => 'ZurvaPay JazzCash is not configured yet - its keys have not been saved.'];
    }

    $want = payment_amount($method, $order);
    if (!$want['ok']) {
        return ['error' => $want['message']];
    }

    // The number is the customer's to get right, and the only thing here
    // they can. An attempt already under way is allowed to keep the number
    // it was started with, so a reload does not ask for it again.
    $state  = zurvapay_attempt($order, ZURVAPAY_JAZZCASH);
    $phone  = zurvapay_phone((string) (payment_ask_value($method, $input, 'phone')
        ?: ($state['live'] ? (string) ($state['record']['phone'] ?? '') : '')));

    if ($phone === '') {
        return ['ask_error' => 'Enter the JazzCash mobile number to charge, as 03XX XXXXXXX.',
                'fields'    => ['phone' => 'This does not look like a mobile number.']];
    }

    // Still live on this channel: the push has already gone to that phone,
    // so the customer is sent to watch it rather than charged a second time.
    if ($state['live']) {
        return ['redirect' => url('order/' . $order['code'] . '/qr')];
    }

    $attempt    = $state['next'];
    $identifier = zurvapay_identifier($order, $attempt);

    // Written down before the call, because a create that times out has very
    // likely created the payment and the poll has to know what to ask about.
    zurvapay_remember($order, [
        'channel'    => ZURVAPAY_JAZZCASH,
        'identifier' => $identifier,
        'attempt'    => $attempt,
        'phone'      => $phone,
        'started_at'  => time(),
        'live'        => true,
        'qr'          => '',
        'expires_at'  => 0,
        'payment_trx' => '',
    ]);

    $reply = zurvapay_call($config, 'payments',
        zurvapay_create_body($method, $order, $want, ZURVAPAY_JAZZCASH, $identifier,
            ['customer_phone' => $phone]),
        ZURVAPAY_CREATE_WAIT[ZURVAPAY_JAZZCASH]);

    // Out of time is not a refusal: ZurvaPay was still waiting for the
    // customer to approve it. The watch page takes over from here.
    if ($reply['error'] !== '' && $reply['timeout']) {
        return ['redirect' => url('order/' . $order['code'] . '/qr')];
    }
    if ($reply['error'] !== '') {
        zurvapay_finish($order, 'the payment could not be created');
        return ['error' => 'Could not reach ZurvaPay: ' . $reply['error']];
    }

    $payment = zurvapay_payment($reply);
    $status  = strtolower(trim((string) ($payment['status'] ?? '')));
    $said    = trim((string) ($reply['body']['message'] ?? ''));
    $code    = (string) ($reply['body']['code'] ?? '');

    // That identifier has been used before. If what exists is still payable
    // the customer watches it; otherwise the next number is the answer, and
    // trying once more is cheaper than telling them to press the button
    // again themselves.
    if (in_array($code, ['duplicate_identifier', 'request_in_progress'], true)) {
        if ($status === 'pending') {
            return ['redirect' => url('order/' . $order['code'] . '/qr')];
        }
        zurvapay_finish($order, 'that identifier was already used up');

        return $tries > 1
            ? zurvapay_jazzcash_begin($method, $order, $input, $tries - 1)
            : ['error' => 'ZurvaPay would not take a new payment for this order: ' . $said];
    }

    // Approved in the app while the request was still open, or still waiting
    // for the customer: both are watched rather than believed here.
    // confirm_order_payment() is the only place a yes becomes a paid order,
    // and the watch page's first poll is what reaches it.
    if ($status === 'success' || $status === 'pending' || $reply['code'] === 202) {
        return ['redirect' => url('order/' . $order['code'] . '/qr')];
    }

    // Declined by the wallet. The attempt is over, so the next press is a new
    // payment under a new identifier - which is the only way ZurvaPay will
    // take a retry. The customer is told in our words and left where the
    // methods are, with the wallet's own reason in the order log.
    if ($status === 'failed' || $reply['code'] === 402 || $code === 'payment_failed') {
        zurvapay_finish($order, 'JazzCash declined it' . ($said !== '' ? ': ' . $said : ''));
        payment_trouble($method, $order, 'JazzCash declined the payment'
            . ($said !== '' ? ': ' . $said : '') . ' (' . $phone . ')');

        return ['ask_error' => zurvapay_declined_words(ZURVAPAY_JAZZCASH)];
    }

    zurvapay_finish($order, 'JazzCash would not start the payment');

    return ['error' => $said !== ''
        ? 'ZurvaPay could not start the JazzCash payment: ' . $said
        : 'ZurvaPay did not start the JazzCash payment for this order.'];
}

return [
    'name' => 'JazzCash',
    // It settles in rupees and nothing else, so this is not the admin's to
    // choose: payment_amount() converts the order's total into PKR, and a
    // shop with no PKR rate is told to add one rather than shown a figure
    // nobody worked out.
    'currency' => 'PKR',
    'blurb' => 'Charges a JazzCash wallet through ZurvaPay. The customer gives their JazzCash '
             . 'number, approves the payment in the JazzCash app, and the order confirms itself. '
             . 'Needs its own ZurvaPay keys with the jazzcash channel enabled.',
    'kind' => 'redirect',
    'wait' => 'Sending the request to JazzCash…',

    'fields' => zurvapay_fields('JazzCash'),

    // The one thing the gateway needs from the customer before it can start.
    // Asked on the pay step, beside the method they just picked, so the
    // number is given once and the payment begins on the same press.
    'ask' => [
        'phone' => [
            'label'       => 'JazzCash mobile number',
            'type'        => 'tel',
            'required'    => true,
            'placeholder' => '03XX XXXXXXX',
            'hint'        => 'The number your JazzCash account is registered on. You will get a '
                           . 'request in the JazzCash app to approve.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        $want = payment_amount($method, $order);

        return $want['ok']
            ? 'Enter your JazzCash number and press Pay. Approve the ' . $want['shown']
            . ' request in your JazzCash app - the order confirms itself the moment ZurvaPay '
            . 'reports the payment, so there is nothing to type and nothing to send us.'
            : $want['message'];
    },

    'start' => function (array $method, array $order, array $input = []): array {
        return zurvapay_jazzcash_begin($method, $order, $input);
    },

    /**
     * There is nothing to draw: the request is already on the customer's
     * phone. `await` is how a gateway says that - the watch page then shows
     * what to do instead of a code, and polls exactly as it does for a QR.
     */
    'qr' => function (array $method, array $order): array {
        $want   = payment_amount($method, $order);
        $state  = zurvapay_attempt($order, ZURVAPAY_JAZZCASH);
        $record = $state['record'];

        if (!$state['live']) {
            return ['error' => 'There is no JazzCash request waiting on this order any more.'];
        }

        $phone = (string) ($record['phone'] ?? '');

        return [
            'await'     => true,
            'text'      => '',
            'amount'    => $want['ok'] ? $want['shown'] : money((float) $order['price']),
            'reference' => (string) ($record['identifier'] ?? ''),
            'title'     => 'Approve it in JazzCash',
            'caption'   => $phone !== ''
                ? 'We have sent a payment request to ' . $phone . '. Open your JazzCash app and '
                . 'approve it - enter your MPIN if it asks.'
                : 'Open your JazzCash app and approve the payment request - enter your MPIN if '
                . 'it asks.',
            'steps'     => [
                'Open the <b>JazzCash app</b> on the phone you gave us.',
                'Find the payment request - it arrives as a notification, or under '
                . '<i>My Approvals</i> / <i>Pending</i>.',
                'Approve it and enter your <b>MPIN</b>. Nothing to type here.',
            ],
        ];
    },

    // Both of these are the same reading for every ZurvaPay channel, so they
    // are written once in the helper. A QR that goes wrong and a wallet push
    // that goes wrong must not be judged by two different sets of rules.
    'poll' => function (array $method, array $order): array {
        return zurvapay_poll_result($method, $order, ZURVAPAY_JAZZCASH);
    },

    'ipn' => function (array $method, array $order, array $input): array {
        return zurvapay_ipn_result($method, $order, $input, ZURVAPAY_JAZZCASH);
    },
];
