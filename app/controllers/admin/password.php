<?php
/** Change the signed-in admin's password. */

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    $me = one('SELECT id, password_hash FROM admins WHERE id = ?', [$_SESSION['admin_id']]);

    if (!$me || !password_verify($current, $me['password_hash'])) {
        $errors['current_password'] = 'That is not your current password.';
    }
    if (strlen($new) < 8) {
        $errors['new_password'] = 'Use at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors['new_password_confirm'] = 'The two new passwords do not match.';
    }

    if (!$errors) {
        update_row('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$me['id']]);
        // A password change should invalidate any other session that was open.
        session_regenerate_id(true);
        log_line('Admin password changed from ' . client_ip());
        flash('success', 'Password changed.');
        redirect('admin/password');
    }

    flash('error', 'Please fix the highlighted fields.');
}

view('admin/password', [
    'title'    => 'Change Password',
    'subtitle' => 'Your account',
    'errors'   => $errors,
], 'layouts/admin');
