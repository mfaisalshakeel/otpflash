<?php
/**
 * Admin login, in one or two steps.
 *
 * Throttled per IP so the form cannot be used to guess passwords, and the
 * failure message never says whether the username or the password was wrong.
 *
 * Where two-factor is on, the password only gets as far as a pending session
 * key. Nothing is logged in until the second step passes, so a stolen
 * password on its own reaches nothing.
 */
require_once APP_PATH . '/helpers/totp.php';
require_once APP_PATH . '/helpers/notify.php';
require_once APP_PATH . '/helpers/audit.php';

if (is_admin()) {
    redirect('admin');
}

/** Minutes an emailed code is good for. */
const LOGIN_CODE_MINUTES = 10;

$error    = null;
$username = '';
$step     = 'password';

/** The half-finished sign-in, if there is one. */
$pending = $_SESSION['_2fa'] ?? null;
if ($pending && ($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['_2fa']);
    $pending = null;
    $error   = 'That took too long. Sign in again.';
}

/** Finish: the admin is who they said they were. */
$signIn = static function (array $admin, string $password = ''): void {
    session_regenerate_id(true);          // a fixated id is useless afterwards
    unset($_SESSION['_2fa']);
    $_SESSION['admin_id'] = (int) $admin['id'];

    if ($password !== '' && password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        update_row('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
            'id = ?', [$admin['id']]);
    }
    update_row('admins', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$admin['id']]);
    log_line('Admin login: ' . $admin['username'] . ' from ' . client_ip());
    audit('admin.signed_in', [
        'entity' => 'admins', 'entity_id' => (int) $admin['id'],
        'summary' => $admin['username'] . ' signed in'
                   . (($admin['two_factor'] ?? 'off') !== 'off' ? ' with two-factor' : ''),
    ]);

    $after = $_SESSION['_after_login'] ?? 'admin';
    unset($_SESSION['_after_login']);
    redirect($after !== '' ? $after : 'admin');
};

/** Put a code in the admin's inbox and remember its hash, not the code. */
$sendCode = static function (array $admin): bool {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['_2fa']['code_hash'] = hash('sha256', $code);
    $_SESSION['_2fa']['sent_at']   = time();

    notify('admin_login_code', [
        'code'    => $code,
        'minutes' => (string) LOGIN_CODE_MINUTES,
        'ip'      => client_ip(),
        'email'   => $admin['email'],
    ]);
    return true;
};

// Abandoning a half-finished sign-in. Nothing was logged in, so there is
// nothing to log out of - the pending key is the whole of it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    unset($_SESSION['_2fa']);
    redirect('admin/login');
}

// ------------------------------------------------------ step two: a code ----
if ($pending && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $step  = 'code';
    $admin = one('SELECT * FROM admins WHERE id = ?', [(int) $pending['admin_id']]);
    $typed = trim((string) $_POST['code']);

    if (!$admin) {
        unset($_SESSION['_2fa']);
        $error = 'Sign in again.';
        $step  = 'password';
    } elseif (!rate_limit('admin_2fa', 10, 600, false)) {
        $error = 'Too many codes tried. Wait a few minutes.';
    } elseif ($typed === '') {
        $error = 'Enter the code.';
    } else {
        $ok = false;

        if ($pending['mode'] === 'totp') {
            $ok = totp_verify((string) $admin['totp_secret'], $typed);
        } elseif (!empty($pending['code_hash'])) {
            $ok = hash_equals((string) $pending['code_hash'],
                hash('sha256', preg_replace('/\D/', '', $typed)));
        }

        if ($ok) {
            $signIn($admin);
        }

        // A recovery code is accepted wherever a normal one is, and is spent
        // whether or not it is the admin's last: a code that still works
        // after it has been used is not a recovery code.
        $remaining = recovery_codes_spend((string) ($admin['recovery_codes'] ?? ''), $typed);
        if ($remaining !== null) {
            update_row('admins', ['recovery_codes' => $remaining], 'id = ?', [$admin['id']]);
            log_line('Recovery code used by ' . $admin['username'] . ' from ' . client_ip());
            $_SESSION['_used_recovery'] = recovery_codes_left($remaining);
            $signIn($admin);
        }

        rate_limit_hit('admin_2fa');
        $error = 'That code did not match.';
        audit('admin.second_step_failed', [
            'entity' => 'admins', 'entity_id' => (int) $admin['id'], 'severity' => 'alert',
            'summary' => 'Password was right but the code was wrong for ' . $admin['username'],
        ]);
        log_line('Failed two-factor for "' . $admin['username'] . '" from ' . client_ip());
    }
}

// Asking for the emailed code to be sent again.
if ($pending && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $step  = 'code';
    $admin = one('SELECT * FROM admins WHERE id = ?', [(int) $pending['admin_id']]);

    if ($admin && $pending['mode'] === 'email') {
        // A resend button with no limit is a way to use the panel as a mail
        // cannon pointed at the admin's own inbox.
        if (time() - (int) ($pending['sent_at'] ?? 0) < 60) {
            $error = 'A code has just gone out. Give it a minute.';
        } else {
            $sendCode($admin);
            flash('success', 'A new code is on its way.');
        }
    }
}

// ------------------------------------------------- step one: the password ---
if (!$pending && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['code'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Look at the budget without spending it; only a wrong password counts.
    if (!rate_limit('admin_login', 8, 600, false)) {
        $error = 'Too many attempts. Wait a few minutes and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $admin = one(
            'SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1',
            [$username, $username]
        );

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $mode = (string) ($admin['two_factor'] ?? 'off');

            if ($mode === 'off') {
                $signIn($admin, $password);
            }

            // Nothing is signed in yet. This key is not a session.
            $_SESSION['_2fa'] = [
                'admin_id' => (int) $admin['id'],
                'mode'     => $mode,
                'expires'  => time() + (LOGIN_CODE_MINUTES * 60),
            ];
            if ($mode === 'email') {
                $sendCode($admin);
            }

            // A rehash cannot wait for step two: the password is only in hand
            // here, and the session that finishes the sign-in will not have it.
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                update_row('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                    'id = ?', [$admin['id']]);
            }

            redirect('admin/login');
        }

        rate_limit_hit('admin_login');
        $error = 'Those details did not match an account.';
        log_line('Failed admin login for "' . $username . '" from ' . client_ip());
        audit('admin.sign_in_failed', [
            'severity' => 'warn',
            'summary'  => 'Wrong details for "' . mb_substr($username, 0, 60) . '"',
        ]);
    }
}

if (isset($_SESSION['_2fa'])) {
    $step    = 'code';
    $pending = $_SESSION['_2fa'];
}

view('admin/login', [
    'title'    => 'Admin login',
    'error'    => $error,
    'username' => $username,
    'step'     => $step,
    'mode'     => $pending['mode'] ?? 'totp',
    'minutes'  => LOGIN_CODE_MINUTES,
], 'layouts/admin-auth');
