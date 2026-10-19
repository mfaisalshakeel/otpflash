<?php
/**
 * A payment gateway telling us, rather than us asking.
 *
 *   POST /hook/{gateway}/{method-id}/{ORDER-CODE}
 *
 * The URL carries the three facts the request has to be read against, and
 * payment_ipn_url() is the one place that builds it - the gateway is handed
 * it when the payment is created, so nothing has to be registered by hand.
 *
 * What arrives here is a claim, not a confirmation. The gateway's own file
 * says whether the call is genuinely from it and genuinely about this order;
 * then the money is read with payment_poll() - the same call the QR page and
 * cron make - and confirm_order_payment() finishes the job. So a webhook is
 * a faster poll, never a second way of deciding: the two cannot disagree,
 * and one arriving while the other is in flight is the race
 * confirm_order_payment() already claims its way out of.
 *
 * Nothing here needs a session or a CSRF token - a gateway has neither. The
 * signature on the body is what authenticates it, which is why a gateway
 * that cannot prove itself gets a plain 404 and no explanation.
 */
require_once APP_PATH . '/helpers/orders.php';
require_once APP_PATH . '/helpers/payments.php';
require_once APP_PATH . '/helpers/notify.php';

// A gateway reads the status code and little else, so the body is for our
// own logs. 200 means "we have this" - anything else and a gateway worth
// using will call again, which is exactly what we want when the failure was
// ours.
$answer = static function (int $status, string $say): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $say;
    exit;
};

// Not found rather than not allowed: an endpoint that answers differently to
// a wrong guess is an endpoint that can be mapped. Same rule as the cron URL.
$vanish = static function (string $why) use ($answer): never {
    log_line('Payment hook refused: ' . $why);
    $answer(404, 'Not found');
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $vanish('not a POST');
}

$driver   = (string) ($params[0] ?? '');
$methodId = (int) ($params[1] ?? 0);
$code     = strtoupper((string) ($params[2] ?? ''));

if ($driver === '' || $methodId <= 0 || $code === '') {
    $vanish('incomplete URL');
}

// A flood of these is somebody else's doing either way, and the gateway's own
// retries are minutes apart. The budget is wide enough that a real retry
// storm still lands.
if (!rate_limit('payment_hook', 300, 600)) {
    $answer(429, 'Too many requests');
}

$method = one('SELECT * FROM payment_methods WHERE id = ?', [$methodId]);

// Switched off since the QR was drawn is not a reason to lose a payment
// somebody has already made, so is_active is not asked. The driver is: a
// method moved to another gateway must not be checked with the old one's
// rules.
if (!$method || (string) $method['driver'] !== $driver) {
    $vanish('no ' . $driver . ' method ' . $methodId);
}

if (!payment_has_ipn($method)) {
    $vanish($driver . ' does not take webhooks');
}

$order = one('SELECT * FROM orders WHERE code = ?', [$code]);
if (!$order) {
    $vanish('no order ' . $code);
}

// Everything the gateway sent, however it sent it. Most post a form; some
// post JSON, and a gateway that does is reading its own body either way.
$input = $_POST;
if (!$input) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $input   = is_array($decoded) ? $decoded : [];
}

$claim = payment_ipn_check($method, $order, $input);

if ($claim['verdict'] === 'reject') {
    $vanish('signature or identifier did not match on order ' . $code
        . ($claim['summary'] !== '' ? ' - ' . $claim['summary'] : ''));
}

// Genuine, and there is nothing to do about it - a payment that failed on
// their side, or a notice about a status we do not act on. Accepted so it is
// not retried, and written down once so the order's own history says what
// was heard.
if ($claim['verdict'] === 'ignore') {
    if ($claim['summary'] !== '') {
        order_log_once((int) $order['id'], $claim['summary']);
    }
    $answer(200, 'OK');
}

// Settled already - by the page polling, by cron, or by this webhook's own
// earlier delivery. Still a 200: the gateway did its part.
if (!in_array($order['status'], ['pending', 'api_error'], true)) {
    $answer(200, 'OK');
}

if ($claim['summary'] !== '') {
    order_log_once((int) $order['id'], $claim['summary']);
}

// Read again: a gateway checking the call may have written down which
// payment it was about, and the poll below has to ask about that one rather
// than about whatever the row said a moment ago.
$order = one('SELECT * FROM orders WHERE id = ?', [(int) $order['id']]) ?: $order;

$result = payment_poll($method, $order);

if ($result['state'] === 'paid') {
    // The loser of the claim is not an error: it means the page or cron got
    // there first, which is the same answer.
    confirm_order_payment($order, $method, $result);
    $answer(200, 'OK');
}

if ($result['state'] === 'failed') {
    order_log_once((int) $order['id'], 'Gateway reported the payment did not go through'
        . ((string) $result['message'] !== '' ? ' - ' . $result['message'] : '') . '.');
    $answer(200, 'OK');
}

// The gateway says it landed and the gateway's own API does not agree yet -
// usually the two sides catching up with each other. A non-200 is what gets
// us called again in a few minutes, and that is the behaviour we want here,
// so this is the one case that does not accept. 503 rather than a 2xx that
// some gateway might read as "handled".
if ((string) $result['summary'] !== '') {
    order_log_once((int) $order['id'], (string) $result['summary']);
}

log_line('Payment hook for ' . $code . ' via ' . $method['name']
    . ' could not confirm yet: ' . ((string) $result['summary'] ?: 'still waiting'));

$answer(503, 'Not confirmed yet');
