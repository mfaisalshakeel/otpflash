<?php
/** Currencies, and the rates that convert provider prices into yours. */
require_once APP_PATH . '/helpers/crud.php';
require_once APP_PATH . '/helpers/currency.php';

// --- refresh rates from the public feed -------------------------------------
// Add one currency and fetch its rate. Answers JSON when asked, because the
// import screen runs this as a step with a progress bar rather than a
// round-trip that loses where the admin was.
if (($params[0] ?? '') === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ignore_user_abort(true);
    @set_time_limit(60);

    $result = currency_add((string) ($_POST['code'] ?? ''));

    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result);
        exit;
    }

    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect((string) ($_POST['back'] ?? 'admin/currencies'));
}

if (($params[0] ?? '') === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = refresh_currency_rates();
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('admin/currencies');
}

// --- make one the base ------------------------------------------------------
//
// Two steps, because there is no undo. The first shows what the change would
// touch and asks for the code to be typed; the second does it. A screen
// rather than a confirm() box: the counts are the point, and a browser with
// no JavaScript must not be able to walk past this one.
if (($params[0] ?? '') === 'base' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once APP_PATH . '/helpers/rebase.php';

    $row = one('SELECT * FROM currencies WHERE id = ?', [(int) ($params[1] ?? 0)]);
    if (!$row) {
        flash('error', 'That currency no longer exists.');
        redirect('admin/currencies');
    }

    view('admin/currency-base', [
        'title'    => 'Change the base currency',
        'subtitle' => 'Currencies',
        'row'      => $row,
        'plan'     => rebase_preview((string) $row['code']),
    ], 'layouts/admin');
}

if (($params[0] ?? '') === 'base' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['id'] ?? 0);
    $row = one('SELECT * FROM currencies WHERE id = ?', [$id]);

    if (!$row) {
        flash('error', 'That currency no longer exists.');
        redirect('admin/currencies');
    }

    // Everything stored is in the base currency and carries no currency of
    // its own, so moving the base rewrites every amount in the database.
    // That is worth typing the code out for - there is no undo.
    require_once APP_PATH . '/helpers/rebase.php';

    $typed = strtoupper(trim((string) ($_POST['confirm'] ?? '')));
    $plan  = rebase_preview((string) $row['code']);

    if (!$plan['ok']) {
        flash('error', $plan['message']);
        redirect('admin/currencies');
    }

    if ($typed !== strtoupper((string) $row['code'])) {
        flash('error', 'Type ' . strtoupper((string) $row['code']) . ' in the box to confirm.');
        redirect('admin/currencies/base/' . (int) $row['id']);
    }

    @set_time_limit(120);
    $result = rebase_currency((string) $row['code']);

    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('admin/currencies');
}

$updatedAt = setting('currency_rates_updated_at', '');

// Counted once for the page rather than once per row. What leans on a
// currency is OTPGet's billing and the payment methods that collect in it.
$methodsPer = [];
foreach (all("SELECT currency, COUNT(*) AS n FROM payment_methods WHERE currency <> '' GROUP BY currency") as $row) {
    $methodsPer[strtoupper((string) $row['currency'])] = (int) $row['n'];
}
$billing = otpget_currency();

crud_handle([
    'table'    => 'currencies',
    'base'     => 'admin/currencies',
    'title'    => 'Currencies',
    'single'   => 'Currency',
    'subtitle' => 'Base currency ' . base_currency()['code']
                . ($updatedAt ? ' - rates updated ' . when($updatedAt) : ' - rates never updated'),
    'order'    => 'is_base DESC, sort_order, code',
    'sortable' => true,
    'search'   => ['code', 'name'],
    'search_hint' => 'Search code or name',
    'toggle'   => 'is_active',
    'empty'    => 'No currencies yet.',
    'actions'  => [['label' => 'Update rates now', 'href' => 'admin/currencies/refresh', 'post' => true]],
    'form_note'=> 'The rate is how much one unit of this currency is worth in your base currency. '
                . 'With PKR as base and the dollar at 280, USD has a rate of 280. '
                . '"Update rates now" fills these in for you.',
    'columns'  => [
        ['label' => 'Currency', 'render' => fn($r) =>
            '<b>' . e($r['code']) . ($r['is_base'] ? ' <span class="chipx">base</span>' : '') . '</b>'
            . '<small class="sub">' . e($r['name']) . '</small>'],
        ['label' => 'Symbol', 'render' => fn($r) => e($r['symbol'] ?: '-')],
        ['label' => 'Rate', 'render' => fn($r) => $r['is_base']
            ? '<span class="muted">-</span>'
            : '<b>' . e(rtrim(rtrim(number_format((float) $r['rate_to_base'], 6), '0'), '.')) . '</b>'
              . '<small class="sub">' . e(base_currency()['code']) . ' per ' . e($r['code']) . '</small>'],
        ['label' => 'Used by', 'render' => function ($r) use ($methodsPer, $billing) {
            $code = strtoupper((string) $r['code']);
            $n    = (int) ($methodsPer[$code] ?? 0);
            $uses = [];
            if ($code === $billing) {
                $uses[] = 'OTPGet billing';
            }
            if ($n) {
                $uses[] = qty_fmt($n) . ' ' . plural('payment method', $n);
            }
            return $uses ? e(implode(', ', $uses)) : '<span class="muted">-</span>';
        }],
        ['label' => 'Updated', 'render' => fn($r) => e(when($r['updated_at'], 'd M, H:i'))],
        ['label' => 'Base', 'render' => function ($r) {
            if ($r['is_base']) {
                return '<span class="st st-completed">base</span>';
            }
            return '<a class="btn btn-ghost btn-sm" href="'
                 . e(url('admin/currencies/base/' . (int) $r['id'])) . '">Make base</a>';
        }],
    ],
    'fields' => [
        'code'   => ['label' => 'Code', 'required' => true, 'rules' => ['unique'],
                     'hint' => 'Three letters, as the rate feed knows it: USD, PKR, EUR.',
                     'save' => fn($v) => strtoupper(trim((string) $v))],
        'name'   => ['label' => 'Name', 'required' => true, 'placeholder' => 'US Dollar'],
        'symbol' => ['label' => 'Symbol', 'placeholder' => '$',
                     'hint' => 'Only the base currency symbol is shown to customers.'],
        'rate_to_base' => ['label' => 'Rate', 'type' => 'number', 'step' => '0.00000001', 'default' => 1,
                           'hint' => 'How much one unit is worth in ' . base_currency()['code'] . '.'],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'is_active'  => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
    ],
    // A rate is a cost: moving one moves every price built on it.
    'after_save' => function () { currencies(true); reprice_offers(); },
    'can_delete' => function (int $id) {
        $row = one('SELECT * FROM currencies WHERE id = ?', [$id]);
        if ($row && $row['is_base']) {
            return 'The base currency cannot be deleted.';
        }
        if (strtoupper((string) ($row['code'] ?? '')) === otpget_currency()) {
            return 'OTPGet bills in this currency, so every cost in the catalogue is converted with it.';
        }
        $used = (int) col('SELECT COUNT(*) FROM payment_methods WHERE currency = ?', [$row['code'] ?? ''], 0);
        return $used > 0
            ? "{$used} payment method(s) collect in this currency. Point them elsewhere first."
            : null;
    },
], $params);
