<?php
/**
 * Paytm, as a merchant collect link.
 *
 * Paytm's hosted checkout needs a server-to-server transaction token, which
 * means a merchant account with the Payment Gateway product enabled. Rather
 * than half-implement that, this sends the customer to the collect link Paytm
 * gives you in the dashboard and takes the order id back as the reference -
 * the same shape as the manual flow, with the paying step handled by Paytm.
 *
 * Fill in the merchant id and your collect link; the customer is redirected
 * with the amount and order code attached.
 */
return [
    'name'  => 'Paytm',
    'blurb' => 'Send the customer to your Paytm collect link with the amount and order '
             . 'code filled in, then confirm the transaction id they bring back.',
    'kind'  => 'redirect',

    'fields' => [
        'merchant_id' => [
            'label'    => 'Merchant ID (MID)',
            'required' => true,
            'hint'     => 'From your Paytm dashboard.',
        ],
        'collect_url' => [
            'label'       => 'Collect / payment link',
            'required'    => true,
            'placeholder' => 'https://paytm.me/xxxxxxx',
            'hint'        => 'The link Paytm gives you. The amount and order code are appended.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        return 'You will be taken to Paytm to pay ' . money($order['price']) . '. '
             . 'When you are done, come back here and enter the Paytm transaction id '
             . 'so we can match the payment to order ' . $order['code'] . '.';
    },

    'start' => function (array $method, array $order): array {
        $config = payment_config($method);
        $url    = trim((string) ($config['collect_url'] ?? ''));

        if ($url === '') {
            return [];
        }

        return ['redirect' => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([
            'amount' => number_format((float) $order['price'], 2, '.', ''),
            'note'   => $order['code'],
        ])];
    },

    'verify' => function (array $method, array $order, array $input): array {
        $reference = trim((string) ($input['trx_id'] ?? ''));

        // Paytm order ids are alphanumeric and never this short.
        if (mb_strlen($reference) < 6) {
            return ['ok' => false, 'reference' => '', 'confirmed' => false,
                    'message' => 'Enter the Paytm transaction id from your receipt.'];
        }

        // No server-to-server check without the Payment Gateway API, so this
        // still ends with a person confirming it in the admin.
        return ['ok' => true, 'reference' => $reference, 'confirmed' => false, 'message' => ''];
    },
];
