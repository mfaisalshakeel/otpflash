<?php
/**
 * Heleket - crypto payments.
 *
 * Heleket creates an invoice over its API and hosts the payment page, so the
 * customer is redirected there and the amount is never typed by hand. The
 * signature is an MD5 of the base64 payload plus your API key, which is what
 * their docs call for.
 *
 * Needs a merchant id and an API key from the Heleket dashboard.
 */
return [
    'name'  => 'Heleket (crypto)',
    'blurb' => 'Creates a Heleket invoice and sends the customer to it. '
             . 'The amount comes from the order, so there is nothing to mistype.',
    'kind'  => 'redirect',

    'fields' => [
        'merchant_id' => [
            'label'    => 'Merchant ID',
            'required' => true,
        ],
        'api_key' => [
            'label'    => 'API key',
            'type'     => 'password',
            'required' => true,
            'hint'     => 'Kept in the database and only ever sent to Heleket.',
        ],
        'currency' => [
            'label'       => 'Invoice currency',
            'default'     => 'USD',
            'hint'        => 'The currency Heleket should bill in, e.g. USD.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        return 'You will be taken to Heleket to pay with crypto. '
             . 'The invoice is made for order ' . $order['code']
             . ', so there is nothing to type.';
    },

    'start' => function (array $method, array $order): array {
        $config   = payment_config($method);
        $merchant = trim((string) ($config['merchant_id'] ?? ''));
        $key      = trim((string) ($config['api_key'] ?? ''));

        if ($merchant === '' || $key === '') {
            return ['error' => 'Heleket is not configured yet.'];
        }

        $payload = [
            'amount'      => number_format((float) $order['price'], 2, '.', ''),
            'currency'    => strtoupper((string) ($config['currency'] ?? 'USD')),
            'order_id'    => $order['code'],
            'url_return'  => url('order/' . $order['code']),
            'url_success' => url('order/' . $order['code']),
        ];

        $body      = json_encode($payload);
        $signature = md5(base64_encode((string) $body) . $key);

        $ch = curl_init('https://api.heleket.com/v1/payment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'merchant: ' . $merchant,
                'sign: ' . $signature,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['error' => 'Could not reach Heleket: ' . $error];
        }

        $data = json_decode((string) $response, true);
        $link = $data['result']['url'] ?? null;

        return $link
            ? ['redirect' => (string) $link, 'reference' => (string) ($data['result']['uuid'] ?? '')]
            : ['error' => 'Heleket did not return a payment link.'];
    },

    'verify' => function (array $method, array $order, array $input): array {
        $reference = trim((string) ($input['trx_id'] ?? ''));

        if ($reference === '') {
            return ['ok' => false, 'reference' => '', 'confirmed' => false,
                    'message' => 'Enter the invoice id Heleket showed you.'];
        }

        return ['ok' => true, 'reference' => $reference, 'confirmed' => false, 'message' => ''];
    },
];
