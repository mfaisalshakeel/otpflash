<?php
/**
 * BharatPe, checked against BharatPe rather than taken on trust.
 *
 * The customer pays your BharatPe QR from any UPI app and gives us the UTR -
 * the bank reference every UPI payment gets. We ask BharatPe for the last
 * couple of days of QR payments and look for it. Right reference, status
 * SUCCESS, at least the amount owed, and not already used on another order:
 * only then is the order paid.
 *
 * A UTR arrives written a dozen ways - with spaces, with a bank's own prefix,
 * with leading zeros - so both ends are reduced to their digits before they
 * are compared, which is what the shop this came from learned to do.
 *
 * Needs the merchant id and the API token from your BharatPe dashboard.
 */
require_once APP_PATH . '/helpers/currency.php';

/** Where the merchant's QR payments are listed. */
const BHARATPE_API = 'https://payments-tesseract.bharatpe.in/api/v1/merchant/transactions';

/**
 * A UTR as a number, however it was written.
 *
 * Banks print the same reference with spaces, with letters in front and with
 * leading zeros; BharatPe returns its own spelling. Comparing what people
 * type to what an API returns only works once both are down to digits.
 */
function bharatpe_utr(string $written): string
{
    return ltrim((string) preg_replace('/\D/', '', $written), '0');
}

/**
 * Recent QR payments on this merchant account.
 *
 * @return array{ok: bool, rows: array, error: string}
 */
function bharatpe_transactions(array $config, int $days = 2): array
{
    $merchant = trim((string) ($config['merchant_id'] ?? ''));
    $token    = trim((string) ($config['token'] ?? ''));

    if ($merchant === '' || $token === '') {
        return ['ok' => false, 'rows' => [], 'error' => 'BharatPe has no merchant id or token saved.'];
    }

    $url = BHARATPE_API . '?' . http_build_query([
        'module'     => 'PAYMENT_QR',
        'merchantId' => $merchant,
        'sDate'      => date('Y-m-d', strtotime('-' . max(1, $days) . ' days')),
        'eDate'      => date('Y-m-d'),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'token: ' . $token,
            'Accept: application/json',
        ],
    ]);

    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $error = curl_error($ch);
    payment_wire($ch, $raw);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'rows' => [], 'error' => 'Could not reach BharatPe: ' . $error];
    }

    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'rows' => [],
                'error' => 'BharatPe sent a reply we could not read: '
                         . mb_substr((string) $raw, 0, 160)];
    }

    $rows = $body['data']['transactions'] ?? null;

    return is_array($rows)
        ? ['ok' => true, 'rows' => $rows, 'error' => '']
        : ['ok' => false, 'rows' => [],
           'error' => trim((string) ($body['message'] ?? 'BharatPe listed no transactions.'))];
}

return [
    'name'  => 'BharatPe (UPI QR)',
    'blurb' => 'The customer pays your BharatPe QR and gives the UTR. We read it back from '
             . 'your BharatPe account and confirm the order ourselves.',
    'kind'  => 'manual',
    // BharatPe collects in Indian rupees, so payment_amount() converts the
    // order's total and a shop with no INR rate is told to add one.
    'currency' => 'INR',

    'fields' => [
        'merchant_id' => [
            'label'    => 'Merchant ID',
            'required' => true,
            'hint'     => 'From your BharatPe dashboard. This is the account the payments are '
                        . 'looked for in.',
        ],
        'token' => [
            'label'    => 'API token',
            'type'     => 'password',
            'required' => true,
            'hint'     => 'Sent with every request and never shown to a customer.',
        ],
    ],

    'instructions' => function (array $method, array $order): string {
        $want = payment_amount($method, $order);

        return $want['ok']
            ? 'Pay ' . $want['shown'] . ' to our BharatPe QR from any UPI app, then paste the '
            . 'UTR from your payment below. We check it against our account and the order '
            . 'starts straight away.'
            : $want['message'];
    },

    /**
     * Is this really a payment, for this order, into our account?
     *
     * The same four questions Binance is asked, in the same order and for the
     * same reasons - a reference nobody checks is a promise, not a payment.
     */
    'verify' => function (array $method, array $order, array $input): array {
        $config = payment_config($method);
        $given  = bharatpe_utr((string) ($input['trx_id'] ?? ''));

        $refuse = static fn(string $why): array
            => ['ok' => false, 'reference' => '', 'confirmed' => false, 'message' => $why];

        if ($given === '') {
            return $refuse('Paste the UTR from your payment - it is the reference number your '
                         . 'bank or UPI app shows after it goes through.');
        }

        if (strlen($given) < 6) {
            return $refuse('That is too short to be a UTR. It is the long reference number on '
                         . 'your payment receipt.');
        }

        // The same reference twice is the same payment twice.
        $usedBy = (string) col(
            'SELECT code FROM orders WHERE trx_id = ? AND code <> ? LIMIT 1',
            [$given, $order['code']], '');

        if ($usedBy !== '') {
            return $refuse('That UTR has already been used, on order ' . $usedBy . '.');
        }

        $want = payment_amount($method, $order);
        if (!$want['ok']) {
            return $refuse($want['message']);
        }

        $history = bharatpe_transactions($config);
        if (!$history['ok']) {
            log_line('BharatPe check failed for order ' . $order['code'] . ': ' . $history['error']);

            return $refuse('We could not reach BharatPe just now. Please try again in a minute - '
                         . 'your payment is safe either way.');
        }

        $paid = null;
        foreach ($history['rows'] as $row) {
            if (is_array($row) && bharatpe_utr((string) ($row['bankReferenceNo'] ?? '')) === $given) {
                $paid = $row;
                break;
            }
        }

        if ($paid === null) {
            return $refuse('We cannot find a payment with that UTR on our BharatPe account yet. '
                         . 'It can take a minute to appear - try again shortly.');
        }

        if (strtoupper(trim((string) ($paid['status'] ?? ''))) !== 'SUCCESS') {
            return $refuse('BharatPe shows that payment as '
                         . strtolower(trim((string) ($paid['status'] ?? 'unfinished')))
                         . ', not completed.');
        }

        $amount = round((float) ($paid['amount'] ?? 0), 2);
        if ($amount + 0.01 < $want['amount']) {
            return $refuse('That payment is ' . money_in($amount, 'INR') . ', and this order asks '
                         . 'for ' . $want['shown'] . '. Please contact us with the UTR.');
        }

        return [
            'ok'        => true,
            'reference' => $given,
            'confirmed' => true,
            'message'   => '',
            'amount'    => round($amount * $want['rate'], 2),
        ];
    },
];
