<?php
/** /forgot - ask for a reset link. The answer is the same whether or not the email has an account. */
require_once APP_PATH . '/helpers/users.php';

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (rate_limit('forgot', 5, 900)) {
        user_send_reset((string) ($_POST['email'] ?? ''));
    }
    $sent = true;
}

view('auth/forgot', [
    'seo'      => ['title' => 'Reset your password', 'robots' => 'noindex, follow'],
    'sent'     => $sent,
    'mailOn'   => setting('mail_driver', 'off') !== 'off',
]);
