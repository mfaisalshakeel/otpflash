<?php
/** /register - make an account, then carry on where they were going. */
require_once APP_PATH . '/helpers/users.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? 'account'));

if (is_logged_in()) {
    redirect($next);
}
if (setting('registration_open', '1') !== '1') {
    flash('info', 'New accounts are closed for now. You can still buy a number as a guest.');
    redirect('login');
}

$errors = [];
$input  = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $input = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? ''))];

    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        redirect('');                     // a bot filled the field a person never sees
    }
    if (!rate_limit('register', 5, 3600)) {
        $errors['email'] = 'Several accounts were made from here just now. Please wait a while.';
    } else {
        $result = user_register($_POST);
        if ($result['ok']) {
            user_sign_in($result['user']);
            flash('success', 'Your account is ready. Add funds once and every number after is one tap.');
            redirect($next);
        }
        $errors = $result['errors'];
    }
}

view('auth/register', [
    'seo'    => ['title' => 'Create your account', 'canonical' => url('register'), 'robots' => 'noindex, follow'],
    'errors' => $errors,
    'input'  => $input,
    'next'   => $next,
    'google' => google_ready(),
]);
