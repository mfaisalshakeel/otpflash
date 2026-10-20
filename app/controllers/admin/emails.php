<?php
/**
 * Email templates, and the record of what was sent.
 *
 *   /admin/emails             the template list
 *   /admin/emails/edit/{id}   one template
 *   /admin/emails/log         what went out, and what failed
 *   /admin/emails/test        send one to yourself
 */
require_once APP_PATH . '/helpers/notify.php';

$action = $params[0] ?? '';
$events = notification_events();

// A template row appears the first time anyone looks, so adding an event to
// notification_events() stays a single edit.
$created = email_templates_ensure();

if ($action === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string) ($_POST['to'] ?? '')) ?: notification_admin_email();

    if (!mail_ready()) {
        flash('error', 'Fill the mail server in on Settings first, and set the driver to '
            . 'something other than Off.');
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'That is not an address we can send to.');
    } else {
        $result = send_mail($to, 'Test from ' . setting('site_name', 'OTP Flash'),
            email_wrap('<p>This is a test.</p><p>If you are reading it, the mail settings '
                . 'work and the panel can reach you.</p>'),
            ['event' => 'test']);

        if ($result['ok']) {
            flash('success', 'Sent to ' . $to . '. If nothing arrives, look in spam, then '
                . 'at the log below.');
        } else {
            flash('error', 'Could not send: ' . $result['error']);
        }
    }
    redirect('admin/emails');
}

if ($action === 'log') {
    require_once APP_PATH . '/helpers/paging.php';
    $paging = paging((int) col('SELECT COUNT(*) FROM email_log', [], 0));
    view('admin/email-log', [
        'title'    => 'Email log',
        'subtitle' => 'What was sent, and what failed',
        'rows'     => all('SELECT * FROM email_log ORDER BY id DESC LIMIT ' . $paging['per'] . ' OFFSET ' . $paging['offset']),
        'events'   => $events,
        'paging'   => $paging,
    ], 'layouts/admin');
    return;
}

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $event    = (string) ($_POST['event'] ?? '');
    $defaults = email_template_defaults();

    if (!isset($defaults[$event])) {
        flash('error', 'No default exists for that message.');
    } else {
        update_row('email_templates', [
            'subject'    => $defaults[$event]['subject'],
            'body'       => $defaults[$event]['body'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'event = ?', [$event]);
        flash('success', 'Put back the wording it shipped with.');
    }
    redirect('admin/emails');
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    q('UPDATE email_templates SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
       WHERE id = ?', [$id]);
    redirect('admin/emails');
}

if ($action === 'edit') {
    $id       = (int) ($params[1] ?? 0);
    $template = one('SELECT * FROM email_templates WHERE id = ?', [$id]);

    if (!$template || !isset($events[$template['event']])) {
        flash('error', 'That message does not exist.');
        redirect('admin/emails');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('error', 'A message needs a subject and a body.');
            keep_old($_POST);
        } else {
            update_row('email_templates', [
                'subject'    => $subject,
                'body'       => $body,
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);
            flash('success', 'Saved.');
            redirect('admin/emails');
        }
    }

    view('admin/email-form', [
        'title'    => $events[$template['event']]['label'],
        'subtitle' => 'The message sent when this happens',
        'template' => $template,
        'event'    => $events[$template['event']],
        'preview'  => email_wrap(fill_tokens($template['body'],
                        email_sample_tokens($template['event']), true)),
    ], 'layouts/admin');
    return;
}

if ($created) {
    flash('success', 'Added ' . $created . ' message(s) that had no template yet.');
}

view('admin/emails', [
    'title'     => 'Email templates',
    'subtitle'  => 'What each message says',
    'rows'      => all('SELECT * FROM email_templates ORDER BY id'),
    'events'    => $events,
    'mailReady' => mail_ready(),
    'adminMail' => notification_admin_email(),
    'failed'    => (int) col("SELECT COUNT(*) FROM email_log WHERE status = 'failed'", [], 0),
], 'layouts/admin');


/**
 * Stand-in values for the preview.
 *
 * Every token the event offers gets one, so a preview never shows an empty
 * gap where the admin is trying to judge the wording.
 */
function email_sample_tokens(string $event): array
{
    $samples = [
        'order_code'  => 'OF-8F42KD3M',
        'service'     => 'WhatsApp · Pakistan',
        'amount'      => money(120),
        'status'      => 'Awaiting payment',
        'order_url'   => url('order/OF-8F42KD3M'),
        'admin_url'   => url('admin/orders'),
        'account_url' => url('account'),
        'reset_url'   => url('reset/sample-token'),
        'site_name'   => (string) setting('site_name', 'OTP Flash'),
        'number'      => '+923001234567',
        'method'      => 'Easypaisa',
        'reference'   => '884120055',
        'provider'    => 'OTPGet',
        'error'       => 'The OTPGet wallet is too low to buy this number.',
        'balance'     => money(120),
        'threshold'   => money(500),
        'name'        => 'Sana',
        'email'       => 'sana@example.com',
        'subject'     => 'Question about delivery',
        'message'     => 'How long does an order usually take?',
        'code'        => '481920',
        'minutes'     => '10',
        'ip'          => '203.0.113.9',
    ];

    $tokens = [];
    foreach (notification_events()[$event]['tokens'] ?? [] as $token) {
        $tokens[$token] = $samples[$token] ?? ('{' . $token . '}');
    }
    return $tokens;
}
