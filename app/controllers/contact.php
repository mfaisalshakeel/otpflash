<?php
/** Contact form. Saves to the messages table, which the admin reads. */

$sent   = false;
$errors = [];
$input  = ['name' => '', 'email' => '', 'whatsapp' => '', 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (array_keys($input) as $key) {
        $input[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    // Hidden field: a person never sees it, a bot fills it in.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        flash('success', 'Thank you, your message has been sent.');
        redirect('contact');
    }

    if (!rate_limit('contact', 5, 900)) {
        $errors['body'] = 'You have sent several messages already. Please wait a few minutes.';
    }

    if ($input['name'] === '' || mb_strlen($input['name']) > 120) {
        $errors['name'] = 'Please tell us your name.';
    }
    if ($input['email'] !== '' && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That email address does not look right.';
    }
    if ($input['email'] === '' && $input['whatsapp'] === '') {
        $errors['email'] = 'Give us an email or a WhatsApp number so we can reply.';
    }
    // The same fifteen digits the order form allows, and for the same reason:
    // a half-typed number looks like a way to reach somebody and is not. The
    // field stops at fifteen as it is typed, so this is the browser being
    // taken out of the decision rather than a second rule.
    $digits = preg_replace('/\D/', '', $input['whatsapp']);
    if ($input['whatsapp'] !== '' && (strlen($digits) < 10 || strlen($digits) > 15)) {
        $errors['whatsapp'] = 'That WhatsApp number does not look right.';
    }
    if (mb_strlen($input['body']) < 5) {
        $errors['body'] = 'Please write your message.';
    }
    if (mb_strlen($input['body']) > 4000) {
        $errors['body'] = 'That message is too long.';
    }

    if (!$errors) {
        $messageId = insert_row('messages', [
            'name'       => $input['name'],
            'email'      => $input['email'],
            'whatsapp'   => $input['whatsapp'],
            'body'       => $input['body'],
            'ip'         => client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        require_once APP_PATH . '/helpers/notify.php';
        notify('admin_new_message', [
            'name'       => $input['name'],
            'email'      => $input['email'],
            'subject'    => excerpt($input['body'], 60),
            'message'    => $input['body'],
            'admin_url'  => url('admin/messages'),
            'admin_link' => 'admin/messages',
        ]);
        flash('success', 'Thank you, your message has been sent. We usually reply within a few hours.');
        redirect('contact');
    }
}

view('contact', [
    'seo'              => [
        'title'       => 'Contact us',
        'description' => 'Questions about a number, a code or a payment? Get in touch with '
                       . setting('site_name', 'us') . ' - we usually reply within minutes.',
        'canonical'   => url('contact'),
    ],
    'canonical'        => url('contact'),
    'errors'           => $errors,
    'input'            => $input,
]);
