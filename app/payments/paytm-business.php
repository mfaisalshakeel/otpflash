<?php
/**
 * Paytm Business, paid by scanning a UPI QR.
 *
 * Paytm Business collects into a UPI id, so there is nothing to type: the
 * customer is shown a QR built from the UPI intent - payee, amount and our
 * own order code in the `tr` field - and pays it in whichever UPI app is on
 * their phone.
 *
 * That `tr` value is what ties the money back to the order. Paytm records it
 * as its own ORDERID, so the order-status endpoint can be asked about exactly
 * that id and the answer needs no matching by hand. A success for the full
 * amount marks the order paid by itself; anything else leaves the order where
 * it is with what Paytm said written into its log, because the alternative is
 * finishing an order nobody paid for.
 *
 * Needs the MID and the business UPI id, both from the Paytm dashboard. There
 * is no merchant key here and nothing is signed: this reads a status, it never
 * asks Paytm to move money.
 */
require_once APP_PATH . '/helpers/currency.php';

/**
 * Where the status of one order is read from.
 *
 * A variable rather than a literal inside the closures so the two that need
 * it cannot drift apart.
 */
$statusEndpoint = 'https://securegw.paytm.in/order/status';

/**
 * The order code with nothing in it a UPI `tr` field might refuse.
 *
 * Codes read GK-8F42KD; the letters and digits alone are still unique, and
 * both the QR and the status lookup use this same spelling, or the lookup is
 * about an id Paytm never saw.
 */
$reference = static fn (array $order): string
    => (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $order['code']);

return [
    'name'  => 'Paytm Business (UPI QR)',
    // UPI settles in Indian rupees and nothing else. payment_amount() turns
    // the order's total into INR, and a shop with no INR rate is told to add
    // one rather than shown a QR for a figure nobody worked out.
    'currency' => 'INR',
    'blurb' => 'Shows a UPI QR for the exact amount and watches Paytm for the payment. '
             . 'Confirms the order itself when the money lands - no transaction id to type.',
    'kind'  => 'redirect',
    'wait'  => 'Generating QR code…',

    'fields' => [
        'merchant_id' => [
            'label'    => 'Merchant ID (MID)',
            'required' => true,
            'hint'     => 'From your Paytm Business dashboard. This is what the payment is '
                        . 'looked up against, so a wrong one means nothing ever confirms.',
        ],
        'upi_id' => [
            'label'       => 'Business UPI ID',
            'required'    => true,
            'placeholder' => 'yourbusiness@paytm',
            'hint'        => 'The UPI id the money goes to. It is printed into the QR.',
        ],
        'payee_name' => [
            'label'       => 'Name shown in the UPI app',
            'placeholder' => 'Your shop name',
            'hint'        => 'Left empty, the site name is used.',
        ],
    ],

    'instructions' => function (array $method, array $order) : string {
        $want = payment_amount($method, $order);

        return $want['ok']
            ? 'Scan the Paytm QR with any UPI app and pay ' . $want['shown']
            . '. The order confirms itself the moment Paytm reports the payment - there is '
            . 'nothing to type and nothing to send us.'
            : $want['message'];
    },

    // Our own page, not Paytm's: the QR is drawn there and the waiting is done
    // there, so the customer never leaves the shop.
    'start' => function (array $method, array $order): array {
        return ['redirect' => url('order/' . $order['code'] . '/qr')];
    },

    /**
     * What the hosted QR page draws.
     *
     * `tn` is left out on purpose - several UPI apps refuse a note with
     * punctuation in it, and the order code is already in `tr`.
     */
    'qr' => function (array $method, array $order) use ($reference): array {
        $config = payment_config($method);
        $upi    = trim((string) ($config['upi_id'] ?? ''));

        if ($upi === '') {
            return ['error' => 'Paytm is not configured yet - no UPI id was saved.'];
        }

        // A UPI id is name@handle out of a narrow alphabet. Checked because
        // this one string ends up inside a URI the page hands to a phone, and
        // because a mistyped one would otherwise collect nothing in silence.
        if (!preg_match('/^[A-Za-z0-9.\-_]{2,60}@[A-Za-z0-9.\-]{2,40}$/', $upi)) {
            return ['error' => 'The saved Paytm UPI id does not look like one '
                             . '(it should read something like yourbusiness@paytm).'];
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return ['error' => $want['message']];
        }

        // A long shop name is the only part of this that can grow, and the QR
        // encoder tops out at 271 bytes, so it is the part that gets clipped.
        $payee = trim((string) ($config['payee_name'] ?? ''));
        if ($payee === '') {
            $payee = (string) setting('site_name', 'OTP Flash');
        }

        // Built by hand rather than with http_build_query: that escapes the @
        // in the UPI id to %40, and a handful of UPI apps take the parameter
        // literally and look for a payee nobody has. Only the shop name can
        // hold a space or an ampersand, so only it is encoded; the rest are
        // already down to digits, letters and the @.
        $query = [
            'pa' => $upi,
            'pn' => rawurlencode(mb_substr($payee, 0, 24)),
            'am' => number_format($want['amount'], 2, '.', ''),
            'cu' => 'INR',
            'tr' => $reference($order),
        ];

        // `mc` is nominally a merchant category code, and what goes in it here
        // is the MID - which is what Paytm Business QR integrations in the
        // wild send and what the money is attributed by. Drop this line if a
        // UPI app ever refuses the intent over it: nothing else depends on it,
        // because the payee is `pa` and the order is matched on `tr`.
        $merchant = preg_replace('/[^A-Za-z0-9]/', '', (string) ($config['merchant_id'] ?? ''));
        if ($merchant !== '') {
            $query['mc'] = $merchant;
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return [
            'text'      => 'upi://pay?' . implode('&', $pairs),
            'amount'    => $want['shown'],
            'reference' => $reference($order),
            'caption'   => 'Scan with any UPI app - Paytm, PhonePe, Google Pay or your bank app.',
        ];
    },

    /**
     * Ask Paytm what happened to this order.
     *
     * The amount is checked as well as the status: a success for less than was
     * asked for is somebody paying a different figure into the same QR, and
     * quietly treating that as payment in full is how a shop delivers for
     * nothing. It is left for a person, with both figures in the order log.
     */
    'poll' => function (array $method, array $order) use ($reference, $statusEndpoint): array {
        $config   = payment_config($method);
        $merchant = trim((string) ($config['merchant_id'] ?? ''));

        if ($merchant === '') {
            return ['state' => 'waiting',
                    'message' => 'Paytm has no merchant id saved, so nothing can be looked up.'];
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return ['state' => 'waiting', 'message' => $want['message']];
        }

        $url = $statusEndpoint . '?' . http_build_query([
            'JsonData' => json_encode(['MID' => $merchant, 'ORDERID' => $reference($order)]),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Verification stays on. A payment status read over a connection
            // nobody checked is a payment status anybody in the way can write.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Paytm's status endpoint is a browser-facing one, and a bare
            // library user agent is the sort of thing an edge in front of it
            // drops. Shaped like a browser for the same reason every other
            // shop integrating this one sends one.
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                    . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                    . 'Chrome/120.0.0.0 Safari/537.36',
        ]);

        $body  = curl_exec($ch);
        $errNo = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Unreachable is not unpaid: say so and let the next check decide.
        if ($errNo !== 0) {
            return ['state' => 'waiting', 'message' => 'Could not reach Paytm: ' . $error,
                    'summary' => 'Paytm could not be reached: ' . $error];
        }
        if ($code >= 400) {
            return ['state' => 'waiting', 'message' => 'Paytm returned HTTP ' . $code . '.',
                    'summary' => 'Paytm returned HTTP ' . $code . ' to the status request.'];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return ['state' => 'waiting',
                    'message' => 'Paytm sent a reply we could not read: '
                               . mb_substr((string) $body, 0, 180),
                    'summary' => 'Paytm sent a reply we could not read: '
                               . mb_substr((string) $body, 0, 180)];
        }

        $status = strtoupper(trim((string) ($data['STATUS'] ?? '')));
        $paid   = round((float) ($data['TXNAMOUNT'] ?? 0), 2);
        $txn    = trim((string) ($data['TXNID'] ?? ''));

        // What Paytm said, kept whole. Only strings and numbers, and only the
        // fields it actually sent: this is written onto the order and read by
        // a person months later, so it should be Paytm's own words rather
        // than our summary of them.
        $details = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $details[(string) $key] = (string) $value;
            }
        }
        $details['ASKED_ORDERID'] = $reference($order);
        $details['ASKED_AMOUNT']  = number_format($want['amount'], 2, '.', '');

        if ($status === 'TXN_SUCCESS') {
            if ($paid + 0.01 < $want['amount']) {
                return [
                    'state'   => 'waiting',
                    'details' => $details,
                    'message' => 'Paytm reports ' . money_in($paid, $want['code']) . ' against the '
                               . $want['shown'] . ' this order asks for, so it '
                               . 'has been left for us to check.',
                    'summary' => 'Paytm: paid ' . money_in($paid, $want['code']) . ', expected '
                               . $want['shown']
                               . ($txn !== '' ? ', transaction ' . $txn : ''),
                ];
            }

            return [
                'state'     => 'paid',
                'reference' => $txn !== '' ? $txn : $reference($order),
                'details'   => $details,
                // Our books are kept in the base currency, so the rupees come
                // back out at the rate the figure was worked out with.
                'amount'    => round($paid * $want['rate'], 2),
                'summary'   => 'Paytm confirmed ' . money_in($paid, $want['code'])
                             . ($txn !== '' ? ', transaction ' . $txn : ''),
                'message'   => 'Payment received. Your order is on its way.',
            ];
        }

        // Paytm says TXN_FAILURE for an order id it has never heard of as well
        // as for one that genuinely failed, and until somebody scans the QR
        // ours is always the first of those - Paytm only learns the id when
        // money moves against it. RESPCODE 334 is "Invalid Order Id", which
        // is that case said plainly; and a payment that really failed carries
        // the transaction it failed as. So the two are told apart by the
        // shape of the reply rather than by a timer, and a QR nobody has
        // picked up yet keeps waiting instead of closing the page on a
        // customer who is still reaching for their phone.
        if ($status === 'TXN_FAILURE') {
            if (trim((string) ($data['RESPCODE'] ?? '')) === '334' || $txn === '') {
                return ['state' => 'waiting', 'message' => ''];
            }

            return [
                'state'   => 'failed',
                'message' => trim((string) ($data['RESPMSG'] ?? '')) !== ''
                    ? (string) $data['RESPMSG']
                    : 'Paytm says that payment did not go through.',
            ];
        }

        // No STATUS at all is Paytm complaining about the question rather than
        // answering it - a wrong MID reads exactly like this. Said once into
        // the order log, because otherwise a shop that will never confirm
        // anything looks identical to a shop nobody has paid yet.
        if ($status === '' && trim((string) ($data['RESPMSG'] ?? '')) !== '') {
            return [
                'state'   => 'waiting',
                'message' => '',
                'summary' => 'Paytm would not answer about this order: ' . $data['RESPMSG']
                           . ' (code ' . (string) ($data['RESPCODE'] ?? '?') . '). '
                           . 'Check the merchant id on the payment method.',
            ];
        }

        // PENDING, or an id Paytm has never seen because nobody has scanned
        // the QR yet. Both mean the same thing here: keep waiting.
        return ['state' => 'waiting', 'message' => ''];
    },
];
