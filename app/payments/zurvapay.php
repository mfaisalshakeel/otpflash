<?php
/**
 * ZurvaPay, paid by scanning a bank QR (their `dqrc` channel).
 *
 * ZurvaPay's Direct API hands back a Raast QR payload for an amount we name,
 * and tells us afterwards whether it was paid. The customer never leaves the
 * shop: the code is drawn on our own page by qr_svg(), any bank or wallet app
 * in Pakistan scans it, and the page watches the status until the money
 * lands.
 *
 * The same API also pushes a payment to a JazzCash wallet, which is sold as
 * its own payment method - app/payments/zurvapay-jazzcash.php - with its own
 * credentials. Everything the two agree about is in helpers/zurvapay.php;
 * what is here is only what a QR needs.
 *
 * What makes a payment ours is the `identifier` we choose. ZurvaPay will not
 * take the same one twice, so each attempt gets its own - see
 * zurvapay_attempt() for when an attempt is over.
 *
 * Settles in PKR and nothing else, so a shop priced in another currency has
 * its total converted at the rate on record before the QR is asked for.
 */
require_once APP_PATH . '/helpers/zurvapay.php';

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

    'fields' => zurvapay_fields('Bank QR (DQRC)'),

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

        if (!zurvapay_configured($config)) {
            return ['error' => 'ZurvaPay is not configured yet - its keys have not been saved.'];
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return ['error' => $want['message']];
        }

        $caption = 'Scan with any bank or wallet app - Raast, JazzCash, EasyPaisa or your own bank.';
        $state   = zurvapay_attempt($order, 'dqrc');
        $record  = $state['record'];

        // A code we already hold, on a payment that can still be paid, is
        // the same code: the customer reloaded the page.
        if ($state['live'] && (string) ($record['qr'] ?? '') !== '') {
            return [
                'text'      => (string) $record['qr'],
                'amount'    => $want['shown'],
                'reference' => (string) ($record['identifier'] ?? ''),
                'caption'   => $caption,
            ];
        }

        $attempt    = $state['next'];
        $identifier = zurvapay_identifier($order, $attempt);

        // Written down before the call, because a create that times out has
        // very likely created the payment and the poll has to know what to
        // ask about.
        zurvapay_remember($order, [
            'channel'    => 'dqrc',
            'identifier' => $identifier,
            'attempt'    => $attempt,
            'started_at'  => time(),
            'live'        => true,
            'qr'          => '',
            'expires_at'  => 0,
            // The last attempt's reference goes with it, or the blob an
            // admin reads names a payment this attempt never made.
            'payment_trx' => '',
        ]);

        $reply = zurvapay_call($config, 'payments',
            zurvapay_create_body($method, $order, $want, 'dqrc', $identifier),
            ZURVAPAY_CREATE_WAIT['dqrc']);

        if ($reply['error'] !== '') {
            zurvapay_finish($order, 'the QR could not be created');
            return ['error' => 'Could not reach ZurvaPay: ' . $reply['error']];
        }

        $payment = zurvapay_payment($reply);
        $text    = (string) ($payment['qr']['text'] ?? '');

        // A payment already exists under this identifier - because the page
        // was opened twice, or because the first create is still open on
        // their side. Neither is a failure: the code the customer needs is
        // the one that already exists, so it is fetched rather than refused.
        $clash = (string) ($reply['body']['code'] ?? '');
        if ($text === '' && in_array($clash, ['request_in_progress', 'duplicate_identifier'], true)) {
            $found = zurvapay_payment(zurvapay_status($config, $identifier, 25));

            if (strtolower((string) ($found['status'] ?? '')) === 'pending'
                && (string) ($found['qr']['text'] ?? '') !== '') {
                $payment = $found;
                $text    = (string) $found['qr']['text'];
            } elseif ($found) {
                // It exists and is over - paid, failed or expired. The next
                // attempt needs an identifier of its own, which is what a
                // reload will ask for now that this one is written off.
                zurvapay_finish($order, 'that identifier was already used up');
            }
        }

        if ($text === '') {
            zurvapay_finish($order, 'no QR came back');
            $said = trim((string) ($reply['body']['message'] ?? ''));

            return ['error' => $said !== ''
                ? 'ZurvaPay could not create the payment: ' . $said
                : 'ZurvaPay did not return a QR code for this order.'];
        }

        $expiresIn = (int) ($payment['qr']['expires_in'] ?? 1800);

        zurvapay_remember($order, [
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
            'caption'   => $caption,
            // What the page counts down to, so the clock on screen is the
            // gateway's own window rather than a guess written into a view.
            'expires_in' => max(60, $expiresIn),
        ];
    },

    // Both of these are the same reading for every ZurvaPay channel, so they
    // are written once in the helper. A QR that goes wrong and a wallet push
    // that goes wrong must not be judged by two different sets of rules.
    'poll' => function (array $method, array $order): array {
        return zurvapay_poll_result($method, $order, 'dqrc');
    },

    'ipn' => function (array $method, array $order, array $input): array {
        return zurvapay_ipn_result($method, $order, $input, 'dqrc');
    },
];
