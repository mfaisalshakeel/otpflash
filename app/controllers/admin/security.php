<?php
/**
 * Two-factor sign-in.
 *
 *   /admin/security           where it stands, and how to change it
 *   /admin/security/enable    confirm a code, then it is on
 *   /admin/security/disable   off again, password required
 *   /admin/security/codes     a fresh set of recovery codes
 */
require_once APP_PATH . '/helpers/totp.php';
require_once APP_PATH . '/helpers/qr.php';
require_once APP_PATH . '/helpers/notify.php';

$action = $params[0] ?? '';
$admin  = one('SELECT * FROM admins WHERE id = ?', [(int) admin_user()['id']]);
$issuer = (string) setting('site_name', 'OTP Flash');

/** Shown once, then gone: a recovery list the admin can re-read is a password. */
$freshCodes = $_SESSION['_fresh_recovery'] ?? null;
unset($_SESSION['_fresh_recovery']);

if ($action === 'enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = ($_POST['mode'] ?? '') === 'email' ? 'email' : 'totp';

    if ($mode === 'email') {
        if (!mail_ready()) {
            flash('error', 'Email codes need a working mail server. Set one up on Settings, '
                . 'and send yourself a test from Email templates first.');
            redirect('admin/security');
        }
        if (!filter_var((string) $admin['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Your account has no valid email address to send a code to.');
            redirect('admin/security');
        }

        $codes = recovery_codes_make();
        update_row('admins', [
            'two_factor'     => 'email',
            'totp_secret'    => '',
            'recovery_codes' => recovery_codes_hash($codes),
        ], 'id = ?', [$admin['id']]);

        $_SESSION['_fresh_recovery'] = $codes;
        log_line('Two-factor (email) switched on for ' . $admin['username']);
        flash('success', 'Email codes are on. Save the recovery codes below.');
        redirect('admin/security');
    }

    // The secret was generated when the page was drawn and parked in the
    // session: putting it in a form field would hand it to anything that can
    // read the page, and regenerating it here would never match the code the
    // admin has just typed from their phone.
    $secret = (string) ($_SESSION['_totp_pending'] ?? '');
    $code   = (string) ($_POST['code'] ?? '');

    if ($secret === '') {
        flash('error', 'That setup expired. Start again.');
        redirect('admin/security');
    }
    if (!totp_verify($secret, $code)) {
        flash('error', 'That code did not match. Check your phone\'s clock is set '
            . 'automatically, then try the next code.');
        redirect('admin/security');
    }

    $codes = recovery_codes_make();
    update_row('admins', [
        'two_factor'     => 'totp',
        'totp_secret'    => $secret,
        'recovery_codes' => recovery_codes_hash($codes),
    ], 'id = ?', [$admin['id']]);

    unset($_SESSION['_totp_pending']);
    $_SESSION['_fresh_recovery'] = $codes;
    log_line('Two-factor (app) switched on for ' . $admin['username']);
    flash('success', 'Two-factor is on. Save the recovery codes below.');
    redirect('admin/security');
}

if ($action === 'disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // The password again, because turning the second factor off is exactly
    // what someone sitting at a borrowed session would want to do.
    if (!password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'])) {
        flash('error', 'That password was wrong.');
        redirect('admin/security');
    }

    update_row('admins', [
        'two_factor'     => 'off',
        'totp_secret'    => '',
        'recovery_codes' => '',
    ], 'id = ?', [$admin['id']]);

    log_line('Two-factor switched off for ' . $admin['username']);
    flash('success', 'Two-factor is off.');
    redirect('admin/security');
}

if ($action === 'codes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($admin['two_factor'] === 'off') {
        flash('error', 'Turn two-factor on first.');
        redirect('admin/security');
    }
    if (!password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'])) {
        flash('error', 'That password was wrong.');
        redirect('admin/security');
    }

    $codes = recovery_codes_make();
    update_row('admins', ['recovery_codes' => recovery_codes_hash($codes)],
        'id = ?', [$admin['id']]);

    $_SESSION['_fresh_recovery'] = $codes;
    flash('success', 'New codes. The old ones no longer work.');
    redirect('admin/security');
}

// Setting up: a secret that survives until it is confirmed.
$pending = null;
if ($admin['two_factor'] === 'off') {
    if (empty($_SESSION['_totp_pending'])) {
        $_SESSION['_totp_pending'] = totp_secret();
    }
    $secret  = (string) $_SESSION['_totp_pending'];
    $pending = [
        'secret'   => $secret,
        'readable' => totp_readable($secret),
        'qr'       => qr_svg(totp_uri($secret, (string) $admin['username'], $issuer), 190,
                             'Two-factor setup QR code'),
    ];
}

view('admin/security', [
    'title'      => 'Two-factor sign-in',
    'subtitle'   => 'A second step after your password',
    'admin'      => $admin,
    'pending'    => $pending,
    'codesLeft'  => recovery_codes_left((string) ($admin['recovery_codes'] ?? '')),
    'freshCodes' => $freshCodes,
    'mailReady'  => mail_ready(),
], 'layouts/admin');
