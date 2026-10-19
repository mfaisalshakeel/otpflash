<?php
/**
 * Payment methods.
 *
 * Each one picks a gateway from app/payments/ and fills in whatever that
 * gateway asks for. Adding a gateway is a new file in that folder; this screen
 * discovers it without being told.
 */
require_once APP_PATH . '/helpers/crud.php';
require_once APP_PATH . '/helpers/payments.php';
require_once APP_PATH . '/helpers/payfields.php';
require_once APP_PATH . '/helpers/upload.php';

$gateways = payment_gateways(true);
$action   = $params[0] ?? 'index';

// --- save, with the gateway's own fields rolled into config -----------------
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $driver = (string) ($_POST['driver'] ?? 'manual');
    $errors = [];

    if (!isset($gateways[$driver])) {
        $driver = 'manual';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'Give this method a name.';
    }

    $config = [];
    foreach ($gateways[$driver]['fields'] as $key => $field) {
        $value = trim((string) ($_POST['cfg_' . $key] ?? ''));

        // An empty password box means "keep what is there".
        if ($value === '' && ($field['type'] ?? 'text') === 'password' && $id > 0) {
            $existing = one('SELECT config FROM payment_methods WHERE id = ?', [$id]);
            $value    = (string) (payment_config($existing ?? [])[$key] ?? '');
        }
        if ($value === '' && !empty($field['required'])) {
            $errors['cfg_' . $key] = ($field['label'] ?? $key) . ' is required for ' . $gateways[$driver]['name'] . '.';
        }
        $config[$key] = $value;
    }

    // Manual methods need somewhere to send the money.
    if ($gateways[$driver]['kind'] === 'manual' && trim((string) ($_POST['account_number'] ?? '')) === '') {
        $errors['account_number'] = 'A manual method needs an account number.';
    }

    // What this method asks the customer. Only for a manual one: a gateway
    // collects its own details on its own page, so asking twice would mean
    // asking for something we then cannot check.
    if ($gateways[$driver]['kind'] === 'manual') {
        $built = [];
        foreach ((array) ($_POST['pf_label'] ?? []) as $index => $label) {
            $field = payfield_clean([
                'label'     => $label,
                'key'       => $_POST['pf_key'][$index] ?? '',
                'type'      => $_POST['pf_type'][$index] ?? 'text',
                'hint'      => $_POST['pf_hint'][$index] ?? '',
                'options'   => $_POST['pf_options'][$index] ?? '',
                'required'  => !empty($_POST['pf_required'][$index]),
                'reference' => (string) ($_POST['pf_reference'] ?? '') === (string) $index,
            ]);
            if ($field === null) {
                continue;        // a row with no label is not a field
            }
            if (isset($built[$field['key']])) {
                $errors['pf'] = 'Two fields ended up with the same name ('
                    . $field['key'] . '). Give them different labels.';
                continue;
            }
            $built[$field['key']] = $field;
        }
        $config['fields'] = array_values($built);
    }

    if ($errors) {
        keep_old($_POST);
        $_SESSION['_errors'] = $errors;
        flash('error', 'Please fix the highlighted fields.');
        redirect('admin/payment-methods/' . ($id ? 'edit/' . $id : 'new'));
    }

    // Kept unless a new one arrives or the box is ticked to clear it, so
    // saving an unrelated field never silently drops the logo.
    $logo = $id > 0 ? (string) col('SELECT logo_path FROM payment_methods WHERE id = ?', [$id], '') : '';

    if (!empty($_FILES['logo']['name'])) {
        $stored = store_image_upload('logo', 'methods', $logo);
        if ($stored['ok']) {
            $logo = $stored['path'];
        } else {
            flash('error', 'Logo: ' . $stored['error']);
        }
    }
    if (isset($_POST['remove_logo'])) {
        $logo = '';
    }

    // What it collects in. A gateway that settles in one currency only is not
    // the admin's to change, so its own answer wins over the box.
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = '';          // empty is the base currency: no conversion
    }

    $data = [
        'name'           => $name,
        'driver'         => $driver,
        'config'         => json_encode($config),
        'short_name'     => trim((string) ($_POST['short_name'] ?? '')),
        'logo_path'      => $logo,
        'account_title'  => trim((string) ($_POST['account_title'] ?? '')),
        'account_number' => trim((string) ($_POST['account_number'] ?? '')),
        'extra_label'    => trim((string) ($_POST['extra_label'] ?? '')),
        'extra_value'    => trim((string) ($_POST['extra_value'] ?? '')),
        'instructions'   => trim((string) ($_POST['instructions'] ?? '')),
        'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
        'is_active'      => isset($_POST['is_active']) ? 1 : 0,
    ];

    // Only where the migration that carries it has run - the panel has to
    // open on a database that is behind the code.
    if (table_has_column('payment_methods', 'currency')) {
        $data['currency'] = $currency;
    }

    if ($id > 0) {
        update_row('payment_methods', $data, 'id = ?', [$id]);
        flash('success', 'Payment method updated.');
    } else {
        insert_row('payment_methods', $data);
        flash('success', 'Payment method created.');
    }
    redirect('admin/payment-methods');
}

// --- the form ---------------------------------------------------------------
if ($action === 'new' || $action === 'edit') {
    $id  = (int) ($params[1] ?? 0);
    $row = ['driver' => 'manual', 'is_active' => 1, 'sort_order' => 0];

    if ($id > 0) {
        $row = one('SELECT * FROM payment_methods WHERE id = ?', [$id]);
        if (!$row) {
            flash('error', 'That payment method no longer exists.');
            redirect('admin/payment-methods');
        }
    }

    view('admin/payment-method-form', [
        'title'    => $id ? 'Edit payment method' : 'New payment method',
        'subtitle' => 'Payment Methods',
        'row'      => $row,
        'id'       => $id,
        'config'   => payment_config($row),
        'gateways' => $gateways,
        'payfields'=> array_values(payfields($row)),
        'pftypes'  => payfield_types(),
        'errors'   => crud_errors(),
    ], 'layouts/admin');
}

// --- delete / toggle / list -------------------------------------------------
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['id'] ?? 0);
    $used = (int) col('SELECT COUNT(*) FROM orders WHERE payment_method_id = ?', [$id], 0);

    if ($used > 0) {
        update_row('payment_methods', ['is_active' => 0], 'id = ?', [$id]);
        flash('warning', "That method is on {$used} order(s), so it was deactivated rather than deleted.");
    } else {
        delete_row('payment_methods', 'id = ?', [$id]);
        flash('success', 'Payment method deleted.');
    }
    redirect('admin/payment-methods');
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    q('UPDATE payment_methods SET is_active = 1 - is_active WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    redirect('admin/payment-methods');
}

view('admin/payment-methods', [
    'title'    => 'Payment Methods',
    'subtitle' => 'What customers can pay with',
    'methods'  => all('SELECT * FROM payment_methods ORDER BY sort_order, id'),
    'gateways' => $gateways,
], 'layouts/admin');
