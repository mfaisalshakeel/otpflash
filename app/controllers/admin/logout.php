<?php
/** Ends the admin session. POST only, so a stray link cannot log anyone out. */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
session_start();
flash('success', 'You have been logged out.');

redirect('admin/login');
