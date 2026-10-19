<?php
/** POST /logout - only a POST signs out, so a link in an email cannot. */
require_once APP_PATH . '/helpers/users.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    user_sign_out();
    flash('success', 'You are signed out.');
}
redirect('');
