<?php
/** /login - customers sign in here. The admin has its own door at /admin/login. */
require_once APP_PATH . '/helpers/users.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? $_SESSION['_after_user_login'] ?? 'account'));

if (is_logged_in()) {
    redirect($next);
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email  = trim((string) ($_POST['email'] ?? ''));
    $result = user_attempt($email, (string) ($_POST['password'] ?? ''));

    if ($result['ok']) {
        unset($_SESSION['_after_user_login']);
        user_sign_in($result['user']);
        flash('success', 'Welcome back, ' . $result['user']['name'] . '.');
        redirect($next);
    }
    $error = $result['error'];
}

view('auth/login', [
    'seo'   => ['title' => 'Sign in', 'canonical' => url('login'), 'robots' => 'noindex, follow'],
    'error' => $error,
    'email' => $email,
    'next'  => $next,
]);
