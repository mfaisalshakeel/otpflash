<?php
/** /reset/{token} - choose a new password from the emailed link. */
require_once APP_PATH . '/helpers/users.php';

$token = (string) ($params[0] ?? '');
$user  = user_by_reset($token);
$error = '';

if (!$user) {
    flash('error', 'That reset link has expired or was already used. Ask for a new one.');
    redirect('forgot');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Use at least 8 characters.';
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        user_set_password((int) $user['id'], $password);
        user_sign_in($user);
        flash('success', 'Your password is changed and you are signed in.');
        redirect('account');
    }
}

view('auth/reset', [
    'seo'   => ['title' => 'Choose a new password', 'robots' => 'noindex, nofollow'],
    'token' => $token,
    'error' => $error,
]);
