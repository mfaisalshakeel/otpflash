<?php
/**
 * Payment gateways.
 *
 * A gateway is one file in app/payments/ that returns a manifest. Dropping a
 * file in registers it - nothing here keeps a list, so adding a gateway never
 * means editing a file that already exists.
 *
 * A manifest looks like this:
 *
 *   return [
 *     'name'    => 'Paytm',
 *     'blurb'   => 'One line for the admin.',
 *     'kind'    => 'manual' | 'redirect',
 *     'fields'  => [                       // what the admin fills in
 *        'merchant_id' => ['label' => 'Merchant ID', 'required' => true],
 *        'secret'      => ['label' => 'Secret', 'type' => 'password'],
 *     ],
 *     'instructions' => fn(array $method, array $order): string => '...',
 *     'start'        => fn(array $method, array $order): array  => ['redirect' => $url],
 *     'verify'       => fn(array $method, array $order, array $input): array
 *                        => ['ok' => true, 'reference' => '...', 'message' => '...'],
 *     'qr'           => fn(array $method, array $order): array
 *                        => ['text' => 'upi://pay?...', 'amount' => '...'],
 *     'poll'         => fn(array $method, array $order): array
 *                        => ['state' => 'paid', 'reference' => '...', 'amount' => 12.0],
 *   ];
 *
 * 'manual' gateways are the bank-transfer kind: show account details, take a
 * transaction id, a human confirms it. 'redirect' gateways send the customer
 * away to pay and are confirmed when they come back.
 *
 * 'qr' and 'poll' are the pair a gateway writes when the payment can be
 * watched rather than typed: 'start' sends the customer to /order/{CODE}/qr,
 * the page draws what 'qr' returns, and 'poll' is asked - by that page and by
 * cron - whether the money has landed. A gateway that has neither is
 * unaffected; nothing here keeps a list of which ones do.
 *
 * 'ipn' is the shortcut: a gateway that can call us when the payment lands
 * declares it, gets a URL of its own from payment_ipn_url(), and /hook/...
 * then does the same poll the page would have done - only without waiting
 * for the next one. It adds nothing a poll cannot find; it only finds it
 * sooner, which is why a gateway without it loses nothing.
 *
 * Only 'name' is required. Anything a gateway leaves out falls back to the
 * manual behaviour, which is why the simplest gateway file is three lines.
 */

/** Every gateway, keyed by its file name. */
function payment_gateways(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }

    $cache = [];

    foreach (glob(APP_PATH . '/payments/*.php') ?: [] as $file) {
        $key = basename($file, '.php');

        // The key ends up in a form value and a database column.
        if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
            continue;
        }

        $manifest = require $file;
        if (!is_array($manifest) || empty($manifest['name'])) {
            continue;
        }

        $cache[$key] = $manifest + [
            'key'    => $key,
            'blurb'  => '',
            'kind'   => 'manual',
            'fields' => [],
            // What the pay button says while this gateway is working. The
            // wait is the gateway's own - one of them is drawing a QR and
            // another is opening a checkout - so the word for it belongs to
            // the gateway rather than to the button.
            'wait'   => 'Just a moment…',
        ];
    }

    ksort($cache);
    return $cache;
}

/** One gateway, or null when the file has been removed. */
function payment_gateway(string $key): ?array
{
    return payment_gateways()[$key] ?? null;
}

/** [key => name] for a select. */
function payment_gateway_options(): array
{
    $options = [];
    foreach (payment_gateways() as $key => $gateway) {
        $options[$key] = $gateway['name'];
    }
    return $options;
}

/** The saved settings for one payment method, as an array. */
function payment_config(array $method): array
{
    $config = json_decode((string) ($method['config'] ?? ''), true);
    return is_array($config) ? $config : [];
}

/**
 * What the customer should be told, for a method that takes payment by hand.
 *
 * A gateway can replace this wholesale; most do not need to.
 */
function payment_instructions(array $method, array $order): string
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    if ($gateway && isset($gateway['instructions']) && is_callable($gateway['instructions'])) {
        return (string) ($gateway['instructions'])($method, $order);
    }

    return (string) ($method['instructions'] ?? '');
}

/**
 * Begin a payment.
 *
 * Returns ['redirect' => url] to send the customer away, or an empty array to
 * stay on the order page and take a transaction id as usual.
 */
function payment_start(array $method, array $order): array
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    if ($gateway && isset($gateway['start']) && is_callable($gateway['start'])) {
        $result = ($gateway['start'])($method, $order);
        return is_array($result) ? $result : [];
    }

    return [];
}

/**
 * Check what the customer submitted.
 *
 * The default is the manual flow: any non-empty reference is accepted here and
 * a human confirms it in the admin. A gateway that can really verify a payment
 * says so by returning ok => true with a reference.
 *
 * @return array{ok: bool, reference: string, message: string, confirmed: bool}
 */
function payment_verify(array $method, array $order, array $input): array
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    if ($gateway && isset($gateway['verify']) && is_callable($gateway['verify'])) {
        $result = ($gateway['verify'])($method, $order, $input);
        return is_array($result) ? $result + [
            'ok' => false, 'reference' => '', 'message' => '', 'confirmed' => false,
        ] : ['ok' => false, 'reference' => '', 'message' => 'The gateway said nothing.', 'confirmed' => false];
    }

    $reference = trim((string) ($input['trx_id'] ?? ''));

    if (setting('require_trx_id', '1') === '1' && mb_strlen($reference) < 4) {
        return ['ok' => false, 'reference' => '', 'confirmed' => false,
                'message' => 'Please enter the transaction id from your payment receipt.'];
    }

    return ['ok' => true, 'reference' => $reference, 'confirmed' => false, 'message' => ''];
}

/**
 * What a gateway wants drawn on the hosted QR page.
 *
 * `text` is what goes into the QR - a UPI intent, a payment URI, whatever the
 * gateway's app reads. `error` instead of it means there is nothing honest to
 * draw, and the page says so rather than showing a QR that collects the wrong
 * amount. An empty array means this gateway has no QR flow at all.
 *
 * @return array{text: string, amount: string, reference: string, caption: string, error: string}
 */
function payment_qr(array $method, array $order): array
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    if (!$gateway || !isset($gateway['qr']) || !is_callable($gateway['qr'])) {
        return [];
    }

    $result = ($gateway['qr'])($method, $order);

    return is_array($result)
        ? $result + ['text' => '', 'amount' => '', 'reference' => '', 'caption' => '', 'error' => '']
        : [];
}

/**
 * Has the money arrived?
 *
 * `state` is 'paid', 'failed' or 'waiting', and anything a gateway does not
 * recognise is 'waiting' - never 'paid'. That is the same rule as
 * map_provider_status(), for the same reason: the cost of waiting too long is
 * a customer asking, and the cost of guessing is delivering for nothing.
 *
 * `message` is said to the customer; `summary` is the line written into the
 * order log, so it may carry figures the customer has no business seeing.
 *
 * @return array{state: string, reference: string, amount: ?float, summary: string, message: string}
 */
function payment_poll(array $method, array $order): array
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));
    $blank   = ['state' => 'waiting', 'reference' => '', 'amount' => null,
                'summary' => '', 'message' => ''];

    if (!$gateway || !isset($gateway['poll']) || !is_callable($gateway['poll'])) {
        return $blank;
    }

    $result = ($gateway['poll'])($method, $order);
    if (!is_array($result)) {
        return $blank;
    }

    $result += $blank;
    if (!in_array($result['state'], ['paid', 'failed', 'waiting'], true)) {
        $result['state'] = 'waiting';
    }

    return $result;
}

/**
 * Where a gateway sends its own word that a payment finished.
 *
 * One URL per method per order, because all three facts are needed before
 * anything can be checked: which gateway is calling, which method's keys to
 * check it against, and which order it is about. A webhook that carried none
 * of them would have to be guessed at from its body, and a payment confirmed
 * by guesswork is not confirmed.
 *
 * It must be reachable from the internet, so a shop on localhost gets a URL
 * that will never be called - which is harmless: polling is what confirms
 * the payment either way.
 */
function payment_ipn_url(array $method, array $order): string
{
    return url('hook/' . (string) ($method['driver'] ?? '') . '/' . (int) $method['id']
        . '/' . rawurlencode((string) $order['code']));
}

/** Does this gateway listen for one? */
function payment_has_ipn(array $method): bool
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    return $gateway !== null && isset($gateway['ipn']) && is_callable($gateway['ipn']);
}

/**
 * Is this webhook really from the gateway, and really about this order?
 *
 * The gateway answers that question and nothing else. It does not say whether
 * the order is paid: the hook goes and reads that with payment_poll(), the
 * same call the page and cron make, so a webhook and a poll can never come to
 * different conclusions about the same payment. The webhook's job is to say
 * "now", not "yes".
 *
 *   'check'  - genuine, and worth going to look
 *   'ignore' - genuine, nothing to do (a status we do not act on)
 *   'reject' - not genuine, or not about this order
 *
 * Anything a gateway does not recognise is 'reject', which answers 404 - the
 * same rule the cron URL follows, so an endpoint nobody can authenticate to
 * cannot be probed for either.
 *
 * @return array{verdict: string, summary: string}
 */
function payment_ipn_check(array $method, array $order, array $input): array
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));
    $blank   = ['verdict' => 'reject', 'summary' => ''];

    if (!$gateway || !isset($gateway['ipn']) || !is_callable($gateway['ipn'])) {
        return $blank;
    }

    $result = ($gateway['ipn'])($method, $order, $input);
    if (!is_array($result)) {
        return $blank;
    }

    $result += $blank;
    if (!in_array($result['verdict'], ['check', 'ignore', 'reject'], true)) {
        $result['verdict'] = 'reject';
    }

    return $result;
}

/** Does this method confirm itself, or does somebody have to? */
function payment_is_watched(array $method): bool
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

    return $gateway !== null && isset($gateway['poll']) && is_callable($gateway['poll']);
}

/**
 * The one method that confirms itself, if there is exactly one.
 *
 * The same test as sole_redirect_method() and for the same reason: with one
 * there is nothing to choose, and with several the choice is the order page's.
 * This is what lets /order/{CODE}/qr be opened from a kept link by an order
 * that does not name a method yet, and lets the check that follows reach the
 * same answer without the page having written anything down.
 */
function sole_watched_method(): ?array
{
    $found = null;

    foreach (all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id') as $method) {
        if (!payment_is_watched($method)) {
            continue;
        }
        if ($found !== null) {
            return null;
        }
        $found = $method;
    }

    return $found;
}

/**
 * The active methods a customer can actually be shown.
 *
 * A manual method with no account number has nowhere to send the money, so
 * listing it offers a dead end. A hosted gateway has no account number by
 * nature and is listed regardless.
 */
function payable_methods(): array
{
    return array_values(array_filter(
        all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id'),
        static function (array $method): bool {
            $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));

            return $gateway !== null
                && ($gateway['kind'] !== 'manual' || trim((string) $method['account_number']) !== '')
                // A method that cannot work out what to charge is not a way
                // to pay. Offering it would be a button that fails at the
                // moment the customer presses it.
                && payment_currency_ready($method)['ok'];
        }
    ));
}

/**
 * What a customer is told when a gateway will not play.
 *
 * Never the gateway's own words: "Invalid api key" is for the admin, and a
 * customer reading it learns nothing they can act on and something about us
 * they should not have to know. The detail goes to the order log and the
 * site log, where the person who can fix it will look.
 */
const PAYMENT_TROUBLE = 'This payment method is having trouble right now. Please choose another '
                      . 'one, or try again in a few minutes.';

/**
 * Write the real reason down, and hand back the one to show.
 *
 * Both halves in one call, because the half that gets forgotten is always
 * the writing down - and then nobody can say why a shop stopped taking money.
 */
function payment_trouble(array $method, array $order, string $detail): string
{
    $detail = trim($detail);

    if ($detail !== '') {
        log_line('Payment start failed on ' . ($method['name'] ?? 'a method') . ' for order '
            . ($order['code'] ?? '?') . ': ' . $detail);

        if (!empty($order['id'])) {
            require_once APP_PATH . '/helpers/orders.php';
            order_log_once((int) $order['id'], $method['name'] . ' could not start the payment: '
                . mb_substr($detail, 0, 300));
        }
    }

    return PAYMENT_TROUBLE;
}

/**
 * What this method collects in.
 *
 * A gateway that settles in one currency and nothing else says so in its
 * manifest, and then it is not the admin's to change - ZurvaPay takes rupees
 * whatever the shop would prefer. Everything else is the method's own
 * setting, and an empty one means the base currency: a bank transfer into
 * your own account converts nothing.
 */
function payment_currency(array $method): string
{
    $gateway = payment_gateway((string) ($method['driver'] ?? 'manual'));
    $fixed   = strtoupper(trim((string) ($gateway['currency'] ?? '')));

    if ($fixed !== '') {
        return $fixed;
    }

    $chosen = strtoupper(trim((string) ($method['currency'] ?? '')));

    return $chosen !== '' ? $chosen : strtoupper((string) base_currency()['code']);
}

/**
 * Can this method price an order at all?
 *
 * One answer for the storefront, which hides a method that cannot, and for
 * the admin screen, which says why and offers to fix it. Without this the
 * two drift and the shop quietly offers a button that cannot take money.
 *
 * @return array{ok: bool, code: string, message: string}
 */
function payment_currency_ready(array $method): array
{
    require_once APP_PATH . '/helpers/currency.php';

    $code = payment_currency($method);
    $base = strtoupper((string) base_currency()['code']);

    if ($code === $base) {
        return ['ok' => true, 'code' => $code, 'message' => ''];
    }

    if (!isset(currencies()[$code])) {
        return ['ok' => false, 'code' => $code,
                'message' => $code . ' is not in your currencies, so there is no rate to turn '
                           . 'your ' . $base . ' prices into it.'];
    }

    if (currency_rate($code) <= 0) {
        return ['ok' => false, 'code' => $code,
                'message' => $code . ' has no exchange rate yet, so there is no honest figure to '
                           . 'ask the customer for.'];
    }

    return ['ok' => true, 'code' => $code, 'message' => ''];
}

/**
 * What to charge for this order, in the currency the method settles in.
 *
 * The one place that conversion happens. Every gateway used to do it for
 * itself, which is the same decision written twice and an admin who cannot
 * see either copy.
 *
 * `rate` is what the figure was worked out with, so the money that comes back
 * can be turned into our own books at the rate it was quoted at rather than
 * at whatever the rate has moved to since.
 *
 * @return array{ok: bool, amount: float, code: string, rate: float, shown: string, message: string}
 */
function payment_amount(array $method, array $order): array
{
    require_once APP_PATH . '/helpers/currency.php';

    $ready = payment_currency_ready($method);
    $code  = $ready['code'];
    $price = (float) ($order['price'] ?? 0);

    if (!$ready['ok']) {
        return ['ok' => false, 'amount' => 0.0, 'code' => $code, 'rate' => 0.0,
                'shown' => '', 'message' => $ready['message']];
    }

    if ($code === strtoupper((string) base_currency()['code'])) {
        return ['ok' => true, 'amount' => round($price, 2), 'code' => $code, 'rate' => 1.0,
                'shown' => money($price), 'message' => ''];
    }

    // rate_to_base says how many of ours one of theirs is worth, so dividing
    // is what turns our price back into theirs.
    $rate   = currency_rate($code);
    $amount = round($price / $rate, 2);

    return ['ok' => true, 'amount' => $amount, 'code' => $code, 'rate' => $rate,
            'shown' => money_in($amount, $code), 'message' => ''];
}

/**
 * The gateway a customer can be sent straight to, when it is the only way to
 * pay at all.
 *
 * Two conditions, and both matter. It has to be a redirect gateway, because
 * one of those takes the payment itself and there is nothing to read first.
 * And it has to be the **only active method**, counting the manual ones: a
 * shop with Paytm and a bank transfer switched on is offering a choice, and
 * skipping past it to the one we happen to be able to open automatically
 * decides for the customer. With anything else on, the order page is where
 * that choice lives.
 */
function sole_redirect_method(): ?array
{
    $methods = all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id');

    if (count($methods) !== 1) {
        return null;
    }

    $gateway = payment_gateway((string) ($methods[0]['driver'] ?? 'manual'));

    return ($gateway['kind'] ?? 'manual') === 'redirect' ? $methods[0] : null;
}
