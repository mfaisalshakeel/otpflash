<?php
/** Grouped settings, plus the logo and favicon uploads. */
require_once APP_PATH . '/helpers/crud.php';
require_once APP_PATH . '/helpers/upload.php';
require_once APP_PATH . '/helpers/migrate.php';

/** group => [key => definition] */
$GROUPS = [
    'Site' => [
        'site_name'        => ['label' => 'Site name', 'required' => true],
        'site_tagline'     => ['label' => 'Tagline'],
        'currency_symbol'  => ['label' => 'Currency symbol', 'hint' => 'Shown before every price.'],
        'theme_color'      => ['label' => 'Theme colour', 'type' => 'color'],
        'order_prefix'     => ['label' => 'Order code prefix', 'hint' => 'Letters only, e.g. OF gives OF-8F42KD3M.'],
        // A select rather than a box: this number ends up as a LIMIT on every
        // list in the panel, and a typed 0 is a panel that shows nothing.
        'admin_per_page'   => ['label' => 'Rows per page', 'type' => 'select',
                               'hint' => 'How many rows every admin list shows at once - '
                                       . 'orders, customers, the catalogue and the rest.',
                               'options' => ['25' => '25', '30' => '30', '50' => '50',
                                             '100' => '100', '200' => '200']],
    ],
    'Contact' => [
        'whatsapp_number'   => ['label' => 'WhatsApp number', 'hint' => 'With country code, e.g. 923001234567.'],
        'telegram_username' => ['label' => 'Telegram', 'hint' => 'Your username or the whole link - '
                                                               . '@yourshop, yourshop or https://t.me/yourshop.'],
        'support_email'     => ['label' => 'Support email', 'type' => 'email'],
    ],
    // Which button goes where. One row per place on the site, because the
    // answer is genuinely different per place: a bubble that follows every
    // page is not the same decision as the line under an order.
    'Chat buttons' => array_combine(
        array_map(static fn(string $key): string => 'chat_' . $key, array_keys(CHAT_PLACEMENTS)),
        array_map(
            static fn(string $key): array => [
                'label'     => CHAT_PLACEMENTS[$key]['label'],
                'type'      => 'channels',
                'hint'      => CHAT_PLACEMENTS[$key]['hint'],
                'placement' => $key,
            ],
            array_keys(CHAT_PLACEMENTS)
        )
    ),
    'Customers and checkout' => [
        'guest_checkout'    => ['label' => 'Let guests buy numbers without an account', 'type' => 'checkbox',
            'hint' => 'A guest pays for each number and keeps an order code. Rentals and the '
                    . 'wallet always need an account.'],
        'registration_open' => ['label' => 'Let customers create accounts', 'type' => 'checkbox'],
        'google_client_id'     => ['label' => 'Google sign-in: client ID',
            'hint' => 'From Google Cloud Console > APIs & Services > Credentials > OAuth client ID (type: Web '
                    . 'application). Add this as an authorised redirect URI: ' . url('login/google')
                    . ' - and leave both boxes empty to hide "Continue with Google".'],
        'google_client_secret' => ['label' => 'Google sign-in: client secret', 'type' => 'password'],
        'min_deposit'       => ['label' => 'Smallest wallet deposit', 'type' => 'number',
            'hint' => 'In your base currency. Gateways charge per payment, so a floor keeps '
                    . 'a deposit worth what it costs to take.'],
        'ask_whatsapp'      => ['label' => 'Ask guests for a WhatsApp number', 'type' => 'checkbox',
            'hint' => 'Marked optional on the order form. It shows on the order, with a button to '
                    . 'message them, for when something needs asking.'],
        'ask_email'         => ['label' => 'Ask guests for an email address', 'type' => 'checkbox',
            'hint' => 'Marked optional on the order form. Whoever gives one is emailed the receipt, '
                    . 'the number and the code - switch those mails on or off under Notifications. '
                    . 'Signed-in customers are written to at their account address.'],
        'require_trx_id'    => ['label' => 'Require a transaction id before a manual payment can be submitted',
            'type' => 'checkbox'],
    ],
    'Numbers' => [
        'auto_buy_number'       => ['label' => 'Buy the number the moment an order is paid', 'type' => 'checkbox',
            'hint' => 'Off means an admin presses Deliver on each paid order. Wallet purchases are '
                    . 'always delivered at once.'],
        'max_numbers_per_order' => ['label' => 'Numbers one order may go through', 'type' => 'select',
            'options' => ['1' => '1 - no free replacements', '2' => '2', '3' => '3 (recommended)',
                          '4' => '4', '5' => '5'],
            'hint' => 'A number that never gets its SMS is given back to OTPGet (which refunds us) '
                    . 'and the customer can get another, free, up to this many in total.'],
        'number_lifetime'       => ['label' => 'How long a number is held (minutes)', 'type' => 'number',
            'hint' => 'For the SMS servers, which do not say. Premium and Budget numbers carry '
                    . 'their own time; email inboxes are held 30 minutes.'],
        'auto_refund'           => ['label' => 'Refund account holders automatically', 'type' => 'checkbox',
            'hint' => 'When a number expires with no SMS, or OTPGet cannot supply one, the '
                    . 'money goes straight back to the customer\'s wallet.'],
        'loss_guard'            => ['label' => 'Never buy a number for more than it sold for', 'type' => 'checkbox',
            'hint' => 'If OTPGet\'s price is now above what the customer paid, the number is not bought: '
                    . 'an account holder is refunded at once, a guest\'s order waits for you.'],
        'price_check_minutes'   => ['label' => 'Check OTPGet\'s live price when it is older than', 'type' => 'select',
            'options' => ['0' => 'Never - trust the last sync', '5' => '5 minutes', '10' => '10 minutes (recommended)',
                          '30' => '30 minutes', '60' => '1 hour'],
            'hint' => 'Asked once when someone orders and again before the number is bought - one quick request, '
                    . 'shared by everyone buying the same thing for those minutes. A price that went up is shown '
                    . 'to the customer before they pay it.'],
        'hold_before_pay'       => ['label' => 'Get the number before the customer pays', 'type' => 'checkbox',
            'hint' => 'OTPGet only says what a number really costs once it is bought. With this on, the number is '
                    . 'bought when the customer presses Pay and held for them while they pay, so its real price is '
                    . 'known first: a dearer one is offered at its own price (cost plus your profit) for the customer '
                    . 'to agree to, and one nobody pays for goes back to OTPGet, which refunds it.'],
        'hold_minutes'          => ['label' => 'Hold a number for payment for', 'type' => 'select',
            'options' => ['5' => '5 minutes', '10' => '10 minutes (recommended)', '15' => '15 minutes'],
            'hint' => 'The number\'s own time runs while it is held, so a customer who takes the whole window '
                    . 'has that much less to receive the code.'],
    ],
    'Protection' => [
        'rate_sanity_factor' => ['label' => 'Reject an exchange rate that moves more than', 'type' => 'number',
            'hint' => 'Times its stored value, in one update. Real currencies move a few '
                    . 'percent; anything near this is a bad number, and taking it would '
                    . 'reprice the whole catalogue. 5 is sensible.'],
        'audit_keep_days' => ['label' => 'Keep the audit log for (days)', 'type' => 'number',
            'hint' => 'Ordinary entries are pruned after this. Warnings and alerts are kept. '
                    . 'Zero keeps everything.'],
        'log_keep_days'   => ['label' => 'Keep the API, notification and email logs for (days)', 'type' => 'number',
            'hint' => 'Cron trims older rows once a day. Zero keeps everything. Any log can also be '
                    . 'cleared by hand from its own screen or from Maintenance.'],
    ],
    'Mail' => [
        'mail_driver' => ['label' => 'How mail is sent', 'type' => 'select',
            'options' => [
                'off'  => 'Off - send nothing',
                'smtp' => 'SMTP (recommended)',
                'mail' => "PHP mail() - whatever the host provides",
            ],
            'hint' => 'Most shared hosts either block PHP mail() or let it send straight '
                    . 'to spam. SMTP through your own mailbox is what actually arrives.'],
        'mail_from'      => ['label' => 'Send from', 'type' => 'email',
                             'hint' => 'Use an address on your own domain, or the mail is '
                                     . 'treated as forged.'],
        'mail_from_name' => ['label' => 'Send as', 'hint' => 'The name beside the address.'],
        'smtp_host'      => ['label' => 'SMTP host', 'hint' => 'e.g. smtp.gmail.com'],
        'smtp_port'      => ['label' => 'Port', 'type' => 'number',
                             'hint' => '587 for TLS, 465 for SSL, 25 for none.'],
        'smtp_secure'    => ['label' => 'Encryption', 'type' => 'select',
            'options' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None']],
        'smtp_user'      => ['label' => 'Username', 'hint' => 'Usually the full address.'],
        'smtp_pass'      => ['label' => 'Password', 'type' => 'password',
                             'hint' => 'For Gmail and most others this is an app password, '
                                     . 'not your account password.'],
    ],
    'Advanced' => [
        'head_code' => ['label' => 'Head code', 'type' => 'textarea', 'rows' => 6,
                        'hint' => 'Pasted into <head> on the front site. Analytics and pixels go here. '
                                . 'It is output exactly as written, so only paste code you trust.'],
    ],
];

// After an update, new tables and columns are applied from here rather than
// by reinstalling.
if (($params[0] ?? '') === 'migrate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = migrations_run();

    if ($result['errors']) {
        flash('error', 'Some changes failed: ' . implode(' | ', $result['errors']));
    } elseif ($result['applied']) {
        flash('success', 'Applied ' . count($result['applied']) . ' database change(s): '
            . implode(', ', $result['applied']) . '.');
    } else {
        flash('success', 'The database is already up to date.');
    }

    redirect('admin/settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once APP_PATH . '/helpers/audit.php';
    // Read before, so the record says what it used to be and not only what it
    // is now. A setting that quietly changed is the hardest kind to track
    // down, because nothing else in the system looks wrong.
    $settingsBefore = settings_all();

    foreach ($GROUPS as $fields) {
        foreach ($fields as $key => $field) {
            if (($field['type'] ?? 'text') === 'checkbox') {
                set_setting($key, isset($_POST[$key]) ? '1' : '0');
                continue;
            }
            // A set of boxes, and none ticked is a real answer - "no button
            // here" - so this is read whether or not anything was posted.
            // Saved in the order the channels are declared rather than the
            // order the boxes came back in, which the browser does not promise.
            if (($field['type'] ?? '') === 'channels') {
                set_setting($key, implode(',', array_intersect(
                    array_keys(CHAT_CHANNELS),
                    array_map('strval', (array) ($_POST[$key] ?? []))
                )));
                continue;
            }
            // A password is never rendered back into the form, so a blank box
            // means "leave it alone" - saving '' would silently wipe it every
            // time anyone touched an unrelated field on this screen.
            if (($field['type'] ?? '') === 'password' && trim((string) ($_POST[$key] ?? '')) === '') {
                continue;
            }
            if (array_key_exists($key, $_POST)) {
                set_setting($key, trim((string) $_POST[$key]));
            }
        }
    }

    foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $input => $settingKey) {
        if (!empty($_FILES[$input]['name'])) {
            $stored = store_image_upload($input, 'branding', (string) setting($settingKey, ''));
            if ($stored['ok']) {
                set_setting($settingKey, $stored['path']);
            } else {
                flash('error', ucfirst($input) . ': ' . $stored['error']);
            }
        }
        if (isset($_POST['remove_' . $input])) {
            set_setting($settingKey, '');
        }
    }

    audit('setting.changed', [
        'entity'   => 'settings',
        'summary'  => 'Settings saved from the admin panel.',
        'severity' => 'warn',
        'before'   => $settingsBefore,
        'after'    => settings_all(true),
    ]);

    flash('success', 'Settings saved.');
    redirect('admin/settings');
}

view('admin/settings', [
    'title'    => 'Settings',
    'subtitle' => 'Site, checkout, numbers and mail',
    'groups'   => $GROUPS,
    'cronKey'  => (string) cfg('cron_key', ''),
    'pending'  => migrations_pending(),
], 'layouts/admin');
