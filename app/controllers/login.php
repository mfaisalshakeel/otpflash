<?php
/**
 * Customers sign in here. The admin has its own door at /admin/login.
 *
 *   /login           email and password
 *   /login/code      the emailed code, for an account that asks for one
 *   /login/google    off to Google, and back from it
 */
require_once APP_PATH . '/helpers/users.php';

$step = $params[0] ?? '';
$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? $_SESSION['_after_user_login'] ?? 'account'));

if (is_logged_in()) {
    redirect($next);
}

$finish = static function (array $user, string $next): never {
    unset($_SESSION['_after_user_login']);
    user_sign_in($user);
    flash('success', 'Welcome back, ' . $user['name'] . '.');
    redirect($next);
};

// ---------------------------------------------------------------- Google --
if ($step === 'google') {
    if (!google_ready()) {
        redirect('login');
    }
    if (!isset($_GET['code']) && !isset($_GET['error'])) {
        if (!rate_limit('google_start', 20, 600)) {
            flash('error', 'Too many tries. Please wait a few minutes.');
            redirect('login');
        }
        redirect(google_auth_url($next));
    }
    if (isset($_GET['error'])) {
        // They pressed Cancel on Google's screen: nothing went wrong.
        unset($_SESSION['_google']);
        redirect('login');
    }
    $back = google_finish((string) $_GET['code'], (string) ($_GET['state'] ?? ''));
    if (!$back['ok']) {
        flash('error', $back['error']);
        redirect('login');
    }
    $account = user_from_google($back['profile']);
    if (!$account['ok']) {
        flash('error', $account['error']);
        redirect('login');
    }
    // Google has just proved the person owns the address the sign-in code
    // would go to, so it does not ask for that code again.
    unset($_SESSION['_after_user_login']);
    user_sign_in($account['user']);
    flash('success', $account['created'] ? 'Welcome, ' . $account['user']['name'] . ' - your account is ready.'
                                         : 'Welcome back, ' . $account['user']['name'] . '.');
    redirect(safe_next($back['next']));
}

// ------------------------------------------------------- the emailed code --
if ($step === 'code') {
    $pending = user_code_pending();
    if (!$pending) {
        flash('error', 'Sign in again for a new code.');
        redirect('login');
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        if (isset($_POST['resend'])) {
            $sent = user_code_resend();
            flash($sent ? 'success' : 'error', $sent ? 'A new code is on its way.'
                                                     : 'Give it a minute before asking for another code.');
            redirect('login/code');
        }
        if (!rate_limit('login_code', 20, 600)) {
            $error = 'Too many tries. Please wait ten minutes.';
        } else {
            $checked = user_code_check((string) ($_POST['code'] ?? ''));
            if ($checked['ok']) {
                $finish($checked['user'], safe_next($checked['next']));
            }
            if (!user_code_pending()) {
                flash('error', $checked['error']);
                redirect('login');
            }
            $error = $checked['error'];
        }
    }
    view('auth/code', [
        'seo'   => ['title' => 'Enter your code', 'robots' => 'noindex, nofollow'],
        'error' => $error,
        'email' => user_mask_email((string) $pending['user']['email']),
    ]);
}

// ----------------------------------------------------- email and password --
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email  = trim((string) ($_POST['email'] ?? ''));
    $result = user_attempt($email, (string) ($_POST['password'] ?? ''));

    if ($result['ok']) {
        if (user_wants_code($result['user'])) {
            user_code_start($result['user'], $next);
            redirect('login/code');
        }
        $finish($result['user'], $next);
    }
    $error = $result['error'];
}

view('auth/login', [
    'seo'    => ['title' => 'Sign in', 'canonical' => url('login'), 'robots' => 'noindex, follow'],
    'error'  => $error,
    'email'  => $email,
    'next'   => $next,
    'google' => google_ready(),
]);
