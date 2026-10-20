<?php
/**
 * Notifications and the mail they send.
 *
 * One event list drives three things that used to drift apart: what the admin
 * sees in the bell, what mail goes out, and which switches the Notifications
 * screen offers. An event that is not in `notification_events()` does not
 * exist anywhere, so adding one is a single edit.
 */
require_once APP_PATH . '/helpers/mail.php';

/**
 * Every event the system can raise.
 *
 * `to` says who the message is for - `admin` goes to the notification feed
 * and the admin's address, `customer` goes to the address on the order.
 * `tokens` is what the template may use; it is shown on the edit form, so a
 * token missing from here is a token nobody knows about.
 */
function notification_events(): array
{
    return [
        'order_placed' => [
            'label'  => 'Order placed',
            'to'     => 'customer',
            'when'   => 'As soon as a customer finishes the order form.',
            'tokens' => ['order_code', 'service', 'amount', 'status', 'order_url', 'site_name'],
        ],
        'order_paid' => [
            'label'  => 'Payment confirmed',
            'to'     => 'customer',
            'when'   => 'When the payment is confirmed, by a gateway or by you.',
            'tokens' => ['order_code', 'service', 'amount', 'order_url', 'site_name'],
        ],
        'number_ready' => [
            'label'  => 'Number ready',
            'to'     => 'customer',
            'when'   => 'A purchase is delivered: the number, inbox or rental is theirs. This is the '
                      . 'receipt, so it carries what was paid.',
            'tokens' => ['order_code', 'service', 'number', 'amount', 'order_url', 'site_name'],
        ],
        'otp_received' => [
            'label'  => 'Code received',
            'to'     => 'customer',
            'when'   => 'The first SMS or email code arrives on the number.',
            'tokens' => ['order_code', 'service', 'number', 'code', 'order_url', 'site_name'],
        ],
        'welcome' => [
            'label'  => 'Welcome',
            'to'     => 'customer',
            'when'   => 'Someone creates an account.',
            'tokens' => ['name', 'account_url', 'site_name'],
        ],
        'password_reset' => [
            'label'  => 'Password reset',
            'to'     => 'customer',
            'when'   => 'A customer asks to reset their password.',
            'tokens' => ['name', 'reset_url', 'minutes', 'site_name'],
            'always' => true,   // the only way back into an account
        ],
        'admin_new_order' => [
            'label'  => 'New order',
            'to'     => 'admin',
            'when'   => 'Every time an order is placed.',
            'tokens' => ['order_code', 'service', 'amount', 'admin_url', 'site_name'],
        ],
        'admin_payment_submitted' => [
            'label'  => 'Payment received',
            'to'     => 'admin',
            'when'   => 'A customer says they have paid, or a gateway confirms it.',
            'tokens' => ['order_code', 'amount', 'method', 'reference',
                         'admin_url', 'site_name'],
        ],
        'admin_api_error' => [
            'label'  => 'OTPGet refused an order',
            'to'     => 'admin',
            'when'   => 'OTPGet refuses a purchase for a reason other than stock, and the '
                      . 'order parks in api_error.',
            'tokens' => ['order_code', 'service', 'provider', 'error',
                         'admin_url', 'site_name'],
        ],
        'admin_low_balance' => [
            'label'  => 'OTPGet balance low',
            'to'     => 'admin',
            'when'   => 'The OTPGet balance falls under the threshold on the '
                      . 'Notifications screen.',
            'tokens' => ['provider', 'balance', 'threshold', 'admin_url', 'site_name'],
        ],
        'admin_new_user' => [
            'label'  => 'New account',
            'to'     => 'admin',
            'when'   => 'A customer signs up.',
            'tokens' => ['name', 'email', 'admin_url', 'site_name'],
        ],
        'admin_new_message' => [
            'label'  => 'Contact form message',
            'to'     => 'admin',
            'when'   => 'Someone writes in through the contact form.',
            'tokens' => ['name', 'email', 'subject', 'message', 'admin_url', 'site_name'],
        ],
        'admin_login_code' => [
            'label'  => 'Sign-in code',
            'to'     => 'admin',
            'when'   => 'Two-factor is set to email and you sign in.',
            'tokens' => ['code', 'minutes', 'ip', 'site_name'],
            'always' => true,   // a login code is not a thing to switch off
        ],
    ];
}

/**
 * What a notification looks like: its icon, and the colour that icon carries.
 *
 * One table because two places draw these - the bell's dropdown and the feed
 * - and a row that is red in one and grey in the other is worse than a row
 * with no colour at all. The tone is what the event *means*, not how new it
 * is: money arriving is good news whether or not it has been read, and a
 * provider refusing an order is bad news a week later.
 *
 * An event with no entry gets the bell and no colour, so adding one later
 * draws something sensible before anybody comes back here.
 */
const NOTIFICATION_LOOKS = [
    'admin_new_order'         => ['icon' => 'i-bag',   'tone' => 'info'],
    'admin_payment_submitted' => ['icon' => 'i-card',  'tone' => 'ok'],
    'admin_api_error'         => ['icon' => 'i-alert', 'tone' => 'danger'],
    'admin_low_balance'       => ['icon' => 'i-coin',  'tone' => 'warn'],
    'admin_new_user'          => ['icon' => 'i-user',  'tone' => 'info'],
    'admin_new_message'       => ['icon' => 'i-chat',  'tone' => 'info'],
    'admin_login_code'        => ['icon' => 'i-key',   'tone' => 'muted'],
];

/**
 * @return array{icon: string, tone: string, label: string}
 */
function notification_look(string $event): array
{
    $look = NOTIFICATION_LOOKS[$event] ?? ['icon' => 'i-bell', 'tone' => 'muted'];

    return $look + ['label' => (string) (notification_events()[$event]['label'] ?? 'Notice')];
}

/**
 * The newest notifications, for the bell's dropdown.
 *
 * Read through optional_all() because the header draws this on every admin
 * screen, and the one screen that must open against a database behind the
 * code is the one with the update button on it.
 */
function notifications_latest(int $limit = 8): array
{
    return optional_all('SELECT * FROM notifications ORDER BY id DESC LIMIT ' . max(1, $limit));
}

/** Does this event write a row into the bell? Customer mail never does. */
function notification_in_panel(string $event): bool
{
    $events = notification_events();
    if (($events[$event]['to'] ?? '') !== 'admin') {
        return false;
    }
    return !empty($events[$event]['always'])
        || setting('notify_' . $event . '_panel', '1') === '1';
}

/** Does this event send mail? */
function notification_by_email(string $event): bool
{
    $events = notification_events();
    if (!isset($events[$event]) || !mail_ready()) {
        return false;
    }
    if (!empty($events[$event]['always'])) {
        return true;
    }
    return setting('notify_' . $event . '_mail', notification_mail_default($event)) === '1';
}

/**
 * Whether an event mails before anyone has chosen. Mail to a customer is on
 * until switched off - they gave the address so that we would write - and
 * mail to the admin is opt-in, since the bell already carries it. The rules
 * screen ticks its boxes from here, so it shows what actually happens.
 */
function notification_mail_default(string $event): string
{
    return (notification_events()[$event]['to'] ?? '') === 'customer' ? '1' : '0';
}

/** Where admin mail goes: the address set for it, else the support address. */
function notification_admin_email(): string
{
    foreach ([setting('notify_email', ''), setting('support_email', '')] as $candidate) {
        if (filter_var((string) $candidate, FILTER_VALIDATE_EMAIL)) {
            return (string) $candidate;
        }
    }
    return (string) (col('SELECT email FROM admins ORDER BY id LIMIT 1', [], '') ?: '');
}

/**
 * Raise an event.
 *
 * Nothing here is allowed to break the thing that raised it: an order must
 * still be placed when the mail server is down, so every failure is caught
 * and written to the log instead of thrown.
 *
 * @param array $data  token => value, used by the template and the panel row
 */
function notify(string $event, array $data = []): void
{
    $events = notification_events();
    if (!isset($events[$event])) {
        log_line('notify(): unknown event "' . $event . '"');
        return;
    }
    $definition = $events[$event];
    $data += ['site_name' => (string) setting('site_name', 'OTP Flash')];

    try {
        if (notification_in_panel($event)) {
            insert_row('notifications', [
                'event'      => $event,
                'title'      => mb_substr(notification_title($event, $data), 0, 190),
                'body'       => mb_substr(notification_body($event, $data), 0, 500),
                'link'       => mb_substr((string) ($data['admin_link'] ?? ''), 0, 190),
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if (notification_by_email($event)) {
            $to = $definition['to'] === 'admin'
                ? notification_admin_email()
                : (string) ($data['email'] ?? '');

            if ($to !== '') {
                $rendered = render_email_template($event, $data);
                if ($rendered !== null) {
                    send_mail($to, $rendered['subject'], $rendered['body'], ['event' => $event]);
                }
            }
        }
    } catch (Throwable $e) {
        // The caller is in the middle of placing an order. Say so in the log
        // and let it carry on.
        log_line('notify(' . $event . ') failed: ' . $e->getMessage());
    }
}

/** The one-line headline for the bell. */
function notification_title(string $event, array $data): string
{
    $events = notification_events();
    $label  = $events[$event]['label'] ?? $event;

    return match ($event) {
        'admin_new_order'          => 'New order ' . ($data['order_code'] ?? ''),
        'admin_payment_submitted'  => 'Payment submitted for ' . ($data['order_code'] ?? ''),
        'admin_api_error'          => 'OTPGet refused ' . ($data['order_code'] ?? ''),
        'admin_low_balance'        => ($data['provider'] ?? 'A provider') . ' is low on balance',
        'admin_new_message'        => 'Message from ' . ($data['name'] ?? 'a visitor'),
        default                    => $label,
    };
}

/** The supporting line under it. */
function notification_body(string $event, array $data): string
{
    return match ($event) {
        'admin_new_order' => trim(($data['service'] ?? '') . ' - ' . ($data['amount'] ?? '')),
        'admin_payment_submitted' => trim(($data['amount'] ?? '') . ' by ' . ($data['method'] ?? '')
            . (($data['reference'] ?? '') !== '' ? ' - ref ' . $data['reference'] : '')),
        'admin_api_error'   => (string) ($data['error'] ?? ''),
        'admin_low_balance' => ($data['balance'] ?? '') . ' left, below ' . ($data['threshold'] ?? ''),
        'admin_new_message' => (string) ($data['subject'] ?? $data['message'] ?? ''),
        default             => '',
    };
}

/** Unread count for the bell. Cheap enough to ask for on every admin request. */
function notifications_unread(): int
{
    return (int) col('SELECT COUNT(*) FROM notifications WHERE is_read = 0', [], 0);
}

// ============================================================ templates ====

/**
 * Fill a template in.
 *
 * Returns null when the template row is missing or switched off, which is how
 * an admin turns one message off without turning the whole event off.
 */
function render_email_template(string $event, array $data): ?array
{
    $template = one('SELECT subject, body, is_active FROM email_templates WHERE event = ?', [$event]);
    if (!$template || !$template['is_active']) {
        return null;
    }

    return [
        'subject' => fill_tokens($template['subject'], $data, false),
        'body'    => email_wrap(fill_tokens($template['body'], $data, true)),
    ];
}

/**
 * Replace {token} with its value.
 *
 * Values are escaped when they land in HTML, because a service name or a
 * customer's message is not ours to trust - the same rule the views follow.
 * A token with no value is removed rather than left showing its own braces.
 */
function fill_tokens(string $text, array $data, bool $html): string
{
    return preg_replace_callback('/\{([a-z_]+)\}/', static function ($match) use ($data, $html) {
        $value = (string) ($data[$match[1]] ?? '');
        return $html ? e($value) : $value;
    }, $text);
}

/**
 * Put a body inside the shell every message shares.
 *
 * Inline styles and a table, because mail clients are a decade behind
 * browsers: a stylesheet in the head is stripped by several of them and
 * flexbox is not reliable in any.
 */
function email_wrap(string $html): string
{
    $brand = (string) setting('theme_color', '#6c4df6');
    $brand = preg_match('/^#[0-9a-f]{3,8}$/i', $brand) ? $brand : '#6c4df6';
    $site  = e((string) setting('site_name', 'OTP Flash'));
    $year  = date('Y');

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f2f2f9;">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
      . 'style="background:#f2f2f9;padding:24px 12px;"><tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
      . 'style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;'
      . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
      . '<tr><td style="background:' . e($brand) . ';padding:18px 24px;color:#ffffff;'
      . 'font-size:17px;font-weight:700;">' . $site . '</td></tr>'
      . '<tr><td style="padding:24px;color:#15152a;font-size:15px;line-height:1.6;">'
      . $html
      . '</td></tr>'
      . '<tr><td style="padding:16px 24px;background:#f8f8fc;color:#767690;font-size:12px;">'
      . '&copy; ' . $year . ' ' . $site . '</td></tr>'
      . '</table></td></tr></table></body></html>';
}

// ===================================================== template defaults ====

/**
 * What each message says out of the box.
 *
 * Kept in PHP rather than in the two schema files, because a seed written
 * twice is a seed that drifts: these are long strings full of quotes and
 * newlines, which is exactly the shape that ends up subtly different between
 * the MySQL and the SQLite copy.
 */
function email_template_defaults(): array
{
    return [
        'order_placed' => [
            'subject' => 'Your order {order_code} is in',
            'body'    => "<p>Thanks for your order.</p>\n"
                . "<p><b>{order_code}</b> &mdash; {service}, {amount}.</p>\n"
                . "<p>Keep that code: it is how you come back to your number and your SMS.</p>\n"
                . '<p><a href="{order_url}">Open your order</a></p>',
        ],
        'order_paid' => [
            'subject' => 'Payment confirmed for {order_code}',
            'body'    => "<p>We have your payment for <b>{order_code}</b> ({service}).</p>\n"
                . "<p>Your number is waiting on the order page, and the code will appear there "
                . "the moment the SMS arrives.</p>\n"
                . '<p><a href="{order_url}">Open your order</a></p>',
        ],
        'number_ready' => [
            'subject' => 'Your {service} number is ready',
            'body'    => "<p>Thanks for your purchase. Here it is:</p>\n"
                . "<p style=\"font-size:22px\"><b>{number}</b></p>\n"
                . "<p>{service} &middot; order <b>{order_code}</b> &middot; {amount}</p>\n"
                . "<p>Use it now - the code appears on your order page the moment it arrives, "
                . "and we will email it to you as well.</p>\n"
                . '<p><a href="{order_url}">Open your order</a></p>',
        ],
        'otp_received' => [
            'subject' => 'Your code for {service}: {code}',
            'body'    => "<p>The code for <b>{service}</b> on {number} arrived:</p>\n"
                . "<p style=\"font-size:26px;letter-spacing:4px\"><b>{code}</b></p>\n"
                . '<p><a href="{order_url}">Open order {order_code}</a></p>',
        ],
        'welcome' => [
            'subject' => 'Welcome to {site_name}',
            'body'    => "<p>Hi {name},</p>\n"
                . "<p>Your account is ready. Add funds once and every number after that is one tap, "
                . "with unused numbers refunded to your wallet automatically.</p>\n"
                . '<p><a href="{account_url}">Go to your dashboard</a></p>',
        ],
        'password_reset' => [
            'subject' => 'Reset your {site_name} password',
            'body'    => "<p>Hi {name},</p>\n"
                . "<p>Someone asked to reset the password on your account. If it was you, this "
                . "link works for {minutes} minutes:</p>\n"
                . '<p><a href="{reset_url}">Choose a new password</a></p>'
                . "\n<p>If it was not you, ignore this email - nothing has changed.</p>",
        ],
        'admin_new_order' => [
            'subject' => 'New order {order_code} - {amount}',
            'body'    => "<p><b>{order_code}</b></p>\n"
                . "<p>{service}<br>{amount}</p>\n"
                . '<p><a href="{admin_url}">Open it in the panel</a></p>',
        ],
        'admin_payment_submitted' => [
            'subject' => 'Payment for {order_code}',
            'body'    => "<p><b>{order_code}</b> has a payment.</p>\n"
                . "<p>{amount} by {method}<br>Reference: {reference}</p>\n"
                . '<p><a href="{admin_url}">Open it</a></p>',
        ],
        'admin_api_error' => [
            'subject' => 'OTPGet refused order {order_code}',
            'body'    => "<p><b>{order_code}</b> ({service}) was refused by {provider}.</p>\n"
                . "<p>It said: {error}</p>\n"
                . '<p><a href="{admin_url}">Retry it</a></p>',
        ],
        'admin_low_balance' => [
            'subject' => '{provider} is low on balance',
            'body'    => "<p>{provider} is down to {balance}, below the {threshold} you set.</p>\n"
                . "<p>Numbers will start failing to buy once it runs out. Top it up at otpget.com.</p>\n"
                . '<p><a href="{admin_url}">OTPGet settings</a></p>',
        ],
        'admin_new_user' => [
            'subject' => 'New account: {name}',
            'body'    => "<p><b>{name}</b> &lt;{email}&gt; just signed up.</p>\n"
                . '<p><a href="{admin_url}">See the account</a></p>',
        ],
        'admin_new_message' => [
            'subject' => 'Message from {name}',
            'body'    => "<p><b>{name}</b> &lt;{email}&gt;</p>\n"
                . "<p>{message}</p>\n"
                . '<p><a href="{admin_url}">Reply from the panel</a></p>',
        ],
        'admin_login_code' => [
            'subject' => 'Your {site_name} sign-in code',
            'body'    => "<p>Your sign-in code is <b style=\"font-size:22px;letter-spacing:3px\">{code}</b></p>\n"
                . "<p>It works for {minutes} minutes. The request came from {ip}.</p>\n"
                . "<p>If that was not you, change your password.</p>",
        ],
    ];
}

/**
 * Make sure every event has a row.
 *
 * Called when the templates screen loads rather than seeded by the installer,
 * so adding an event to `notification_events()` is still a single edit - the
 * row appears the next time anyone looks.
 *
 * @return int  how many were created
 */
function email_templates_ensure(): int
{
    $existing = array_column(all('SELECT event FROM email_templates'), 'event', 'event');
    $created  = 0;

    foreach (email_template_defaults() as $event => $default) {
        if (isset($existing[$event]) || !isset(notification_events()[$event])) {
            continue;
        }
        insert_row('email_templates', [
            'event'      => $event,
            'subject'    => $default['subject'],
            'body'       => $default['body'],
            'is_active'  => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $created++;
    }
    return $created;
}
