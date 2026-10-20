<?php
/**
 * Small JSON endpoints for the storefront's script.
 *
 *   GET /api/picker?tab=numbers&service=12&country=3   the picker's lists and options
 *   GET /api/track?code=OF-8F42KD3M                     an order's state, for the tracker
 *
 * Everything here is either public catalogue or what the holder of an order
 * code can already see on the order page - nothing more.
 */
require_once APP_PATH . '/helpers/storefront.php';
require_once APP_PATH . '/helpers/orders.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$answer = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$action = $params[0] ?? '';

// --------------------------------------------------------------- picker ----
if ($action === 'picker') {
    $tab = (string) ($_GET['tab'] ?? 'numbers');
    if (!isset(PICKER_PANES[$tab]) || !isset(store_tabs()[$tab])) {
        $answer(['ok' => false, 'error' => 'That is not on sale.'], 404);
    }

    $serviceId = ctype_digit((string) ($_GET['service'] ?? '')) ? (int) $_GET['service'] : null;
    $countryId = ctype_digit((string) ($_GET['country'] ?? '')) ? (int) $_GET['country'] : null;

    // Private: the last step carries this visitor's wallet balance and a
    // form token, so no shared cache may keep it - but the browser itself
    // may, for the half-minute of clicking back and forth a choice takes.
    header('Cache-Control: private, max-age=30');

    $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
    $state  = picker_state($tab, $serviceId, $countryId, $search);
    $answer([
        'ok'        => true,
        'tab'       => $tab,
        'services'  => $state['services'],
        'services_total' => $state['services_total'],
        'countries' => $state['countries'],
        'options'   => $state['options'],
        // The last step as HTML, from the same partial the page drew, so
        // what buying looks like is defined once.
        'html'      => render('partials/options', [
            'state'    => $state,
            'user'     => current_user(),
            'guestOk'  => setting('guest_checkout', '1') === '1',
            'askEmail' => setting('ask_email', '0') === '1',
        ]),
        'url'       => choice_url($tab, $state['service'], $state['country']),
        'title'     => $state['service'] || $state['country']
            ? trim(($state['service']['name'] ?? '') . ' ' . ($state['country']['name'] ?? '')) : '',
    ]);
}

// ---------------------------------------------------------------- track ----
if ($action === 'track') {
    $code = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? '')));

    if ($code === '') {
        $answer(['ok' => false, 'error' => 'No order code given'], 400);
    }
    if (!rate_limit('api_track', 60, 600)) {
        $answer(['ok' => false, 'error' => 'Too many lookups, please slow down'], 429);
    }

    $order = one('SELECT * FROM orders WHERE code = ?', [$code]);
    if (!$order) {
        $answer(['ok' => false, 'error' => 'We could not find an order with that code. Check it and try again.'], 404);
    }

    $answer([
        'ok'    => true,
        'order' => [
            'code'        => $order['code'],
            'service'     => order_label($order),
            'status'      => $order['status'],
            'status_text' => ORDER_STATUS_WORDS[$order['status']] ?? $order['status'],
            'is_paid'     => $order['paid_at'] !== null,
            'amount'      => money((float) $order['price']),
            'placed_at'   => when($order['created_at']),
            'url'         => url('order/' . $order['code']),
        ],
    ]);
}

$answer(['ok' => false, 'error' => 'Unknown endpoint'], 404);
