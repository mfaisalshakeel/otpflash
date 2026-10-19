<?php
/**
 * OTPGet: the connection, what the shop sells, how it is priced, and sync.
 *
 *   GET  /admin/otpget          the screen
 *   POST /admin/otpget/save     connection, products and pricing
 *   POST /admin/otpget/test     ask for the balance, which proves the key
 *   POST /admin/otpget/reprice  rebuild every price from its cost
 *   POST /admin/otpget/plan     JSON: read the lists, return one step per unit
 *   POST /admin/otpget/step     JSON: sync one unit
 *
 * Sync is the update screen's stepper pointed at the catalogue: one country
 * per request, so a shared host's thirty-second limit never cuts it off and
 * the admin watches it fill instead of staring at a spinner.
 */
require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/audit.php';

$action = $params[0] ?? 'index';

$respond = static function (array $payload): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
};

/** The settings this screen owns, and how each is read back from the form. */
$OWNED = [
    'otpget_base_url', 'otpget_currency', 'products', 'sms_servers', 'email_servers',
    'rental_providers', 'email_sites', 'default_markup', 'markup_sms', 'markup_premium',
    'markup_cheap', 'markup_email', 'markup_rental', 'min_price', 'price_rounding',
    'sync_on_cron', 'sync_batch', 'low_balance_threshold',
    'server_label_1', 'server_label_2', 'server_label_3', 'server_label_4', 'server_label_5',
    'server_label_6', 'server_label_premium', 'server_label_cheap',
];

// ------------------------------------------------------------------ save --
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = settings_all();

    $url = rtrim(trim((string) ($_POST['otpget_base_url'] ?? '')), '/');
    if ($url === '' || !preg_match('~^https?://[^\s]+$~i', $url)) {
        $url = 'https://otpget.com/stubs';
    }
    set_setting('otpget_base_url', $url);

    // A key box left empty keeps the key: it is never printed back into
    // the form, so an empty box is "unchanged", not "remove it".
    $key = trim((string) ($_POST['otpget_api_key'] ?? ''));
    if ($key !== '') {
        set_setting('otpget_api_key', $key);
    }
    if (isset($_POST['forget_key'])) {
        set_setting('otpget_api_key', '');
    }

    $currency = strtoupper(trim((string) ($_POST['otpget_currency'] ?? 'PKR')));
    set_setting('otpget_currency', preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'PKR');

    $lists = [
        'products'         => array_keys(PRODUCTS),
        'sms_servers'      => ['1', '2', '3', '4', '5', '6'],
        'email_servers'    => ['1', '2'],
        'rental_providers' => ['1', '2'],
    ];
    foreach ($lists as $name => $allowed) {
        set_setting($name, implode(',', array_values(array_intersect($allowed,
            array_map('strval', (array) ($_POST[$name] ?? []))))));
    }

    set_setting('email_sites', implode(',', array_unique(array_filter(array_map(
        static fn ($s) => strtolower(trim(preg_replace('~^https?://(www\.)?~i', '', $s), " /")),
        preg_split('/[\s,]+/', (string) ($_POST['email_sites'] ?? '')) ?: []
    ), static fn ($s) => (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $s)))));

    foreach (['default_markup', 'min_price', 'low_balance_threshold'] as $name) {
        set_setting($name, (string) max(0, round((float) ($_POST[$name] ?? 0), 2)));
    }
    foreach (array_keys(PRODUCTS) as $product) {
        $value = trim((string) ($_POST['markup_' . $product] ?? ''));
        set_setting('markup_' . $product, $value === '' || !is_numeric($value) ? '' : (string) round((float) $value, 2));
    }
    $rounding = (string) ($_POST['price_rounding'] ?? '1');
    set_setting('price_rounding', in_array($rounding, ['0', '0.5', '1', '5', '10'], true) ? $rounding : '1');

    set_setting('sync_on_cron', isset($_POST['sync_on_cron']) ? '1' : '0');
    set_setting('sync_batch', (string) max(1, min(50, (int) ($_POST['sync_batch'] ?? 8))));

    foreach (['1', '2', '3', '4', '5', '6', 'premium', 'cheap'] as $server) {
        set_setting('server_label_' . $server, mb_substr(trim((string) ($_POST['server_label_' . $server] ?? '')), 0, 40));
    }

    $after = settings_all(true);
    audit('otpget.settings', [
        'entity'   => 'settings', 'severity' => 'warn',
        'summary'  => 'OTPGet settings saved' . ($key !== '' ? ', API key changed' : ''),
        'before'   => array_intersect_key($before, array_flip($OWNED)),
        'after'    => array_intersect_key($after, array_flip($OWNED)),
    ]);

    // What was sold or how it was priced may have moved: prices follow now,
    // and the next sync re-reads the lists rather than trusting old ones.
    $changed = reprice_offers();
    $pricing = ['default_markup', 'min_price', 'price_rounding', 'otpget_currency',
                'markup_sms', 'markup_premium', 'markup_cheap', 'markup_email', 'markup_rental'];
    foreach (['products', 'sms_servers', 'email_servers', 'rental_providers', 'email_sites'] as $name) {
        if (($before[$name] ?? '') !== ($after[$name] ?? '')) {
            @unlink(STORAGE_PATH . '/cache/sync-lists.json');
            break;
        }
    }

    $pricingMoved = false;
    foreach ($pricing as $name) {
        $pricingMoved = $pricingMoved || (($before[$name] ?? '') !== ($after[$name] ?? ''));
    }
    flash('success', 'Saved.' . ($pricingMoved ? ' ' . qty_fmt($changed) . ' price(s) changed.' : ''));

    // A new key is worth proving at once - every order fails behind a bad one.
    if ($key !== '') {
        $result = otpget_balance_refresh();
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'OTPGet accepted the key. Balance: ' . $result['shown'] . '.'
            : 'OTPGet did not accept that: ' . $result['message']);
    }
    redirect('admin/otpget');
}

// ------------------------------------------------------------------ test --
if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = otpget_balance_refresh();
    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? 'Connected. Your OTPGet balance is ' . $result['shown'] . '.'
        : 'Could not connect: ' . $result['message']);
    redirect('admin/otpget');
}

// --------------------------------------------------------------- reprice --
if ($action === 'reprice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $changed = reprice_offers();
    audit('catalogue.repriced', ['entity' => 'offers', 'summary' => $changed . ' price(s) rebuilt from cost']);
    flash('success', qty_fmt($changed) . ' price(s) changed.');
    redirect('admin/otpget');
}

// ------------------------------------------------------------ sync: plan --
if ($action === 'plan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!otpget_configured()) {
        $respond(['ok' => false, 'steps' => [], 'error' => 'Save your OTPGet API key first.']);
    }
    @set_time_limit(120);
    $refresh = sync_lists_refresh();
    $units   = sync_units($refresh['lists']);

    $respond([
        'ok'      => true,
        'carryOn' => true,
        'done'    => 'Done - the catalogue is up to date.',
        'errors'  => $refresh['errors'],
        'steps'   => array_map(static fn ($u) => ['key' => $u['key'], 'label' => $u['label'], 'detail' => ''], $units),
    ]);
}

// ------------------------------------------------------------ sync: step --
if ($action === 'step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ignore_user_abort(true);
    @set_time_limit(60);
    $key    = (string) ($_POST['key'] ?? '');
    $result = sync_unit($key);
    set_setting('catalogue_synced_at', date('Y-m-d H:i:s'));

    $respond([
        'ok'     => $result['ok'],
        'key'    => $key,
        'note'   => $result['ok'] ? $result['count'] . ' offers' : $result['message'],
        'errors' => $result['ok'] ? [] : [$result['message']],
    ]);
}

// ---------------------------------------------------------------- screen --
$counts = [];
foreach (all('SELECT product, COUNT(*) AS n, SUM(CASE WHEN is_active = 1 AND missing = 0 THEN 1 ELSE 0 END) AS live,
                     MIN(CASE WHEN is_active = 1 AND missing = 0 AND price > 0 THEN price END) AS low
                FROM offers GROUP BY product') as $row) {
    $counts[$row['product']] = $row;
}

view('admin/otpget', [
    'title'      => 'OTPGet',
    'subtitle'   => 'Connection, what you sell, pricing and sync',
    'configured' => otpget_configured(),
    'keyHint'    => (static function (): string {
        $key = (string) setting('otpget_api_key', '');
        return $key === '' ? '' : str_repeat('•', 8) . substr($key, -4);
    })(),
    'balance'    => otpget_balance_known(),
    'lastError'  => (string) setting('otpget_last_error', ''),
    'counts'     => $counts,
    'syncedAt'   => (string) setting('catalogue_synced_at', ''),
    'units'      => count(sync_units()),
    'currencies' => array_keys(currencies()),
], 'layouts/admin');
