<?php
/**
 * Customer accounts: signing up, signing in, and getting back in.
 *
 * Built to the same rules as the admin's login, because a wallet is money:
 * passwords are password_hash()ed and rehashed when the algorithm moves on,
 * the session id is replaced on every sign-in, failures are throttled per IP
 * and never say which half was wrong, and a reset link is single-use, short
 * lived and stored only as a hash.
 */

require_once APP_PATH . '/helpers/notify.php';
require_once APP_PATH . '/helpers/audit.php';

const USER_RESET_MINUTES = 60;

/**
 * Where to go after signing in: somewhere on this site, or home.
 *
 * Only a path, never a URL - "next=https://evil.example" is the oldest trick
 * there is, and "//evil.example" is the same trick in a hat.
 */
function safe_next(string $next, string $default = 'account'): string
{
    $next = trim($next);
    if ($next === '' || str_contains($next, '//') || str_contains($next, '\\')
        || preg_match('~^[a-z][a-z0-9+.-]*:~i', $next) || !preg_match('~^/?[A-Za-z0-9_\-/?=&%.]*$~', $next)) {
        return $default;
    }
    return ltrim($next, '/');
}

/** Sign a customer in: a fresh session id, and the time noted. */
function user_sign_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    update_row('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()],
        'id = ?', [(int) $user['id']]);
    current_user(true);
    user_claim_browser_orders((int) $user['id']);
}

/**
 * Move the orders this browser placed as a guest into the account just
 * signed in to.
 *
 * Only orders nobody owns yet, from the last sixty days, named in this
 * browser's signed list - so signing in moves your own guest orders and
 * cannot take anyone else's. From then on they are refunded to the wallet
 * like any account order.
 *
 * @return int orders moved
 */
function user_claim_browser_orders(int $userId): int
{
    $moved = 0;
    foreach (browser_orders() as $code) {
        $order = one('SELECT id, code FROM orders WHERE code = ? AND user_id IS NULL AND created_at >= ?',
            [$code, date('Y-m-d H:i:s', time() - 60 * 86400)]);
        if ($order && q('UPDATE orders SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, (int) $order['id']])->rowCount()) {
            insert_row('order_logs', ['order_id' => (int) $order['id'], 'is_internal' => 1, 'created_at' => date('Y-m-d H:i:s'),
                'message' => 'Moved into account #' . $userId . ' when it signed in on the browser that placed it.']);
            $moved++;
        }
    }
    return $moved;
}

function user_sign_out(): void
{
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

/**
 * Check an email and password.
 *
 * @return array{ok: bool, user: ?array, error: string}
 */
function user_attempt(string $email, string $password): array
{
    $vague = 'That email and password do not match an account.';

    if (!rate_limit('user_login_fail', 8, 600, false)) {
        return ['ok' => false, 'user' => null,
                'error' => 'Too many tries. Please wait ten minutes, or reset your password.'];
    }

    $user = one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);

    // Verified against a dummy hash when there is no such account, so the
    // answer takes the same time whether or not the email is registered.
    $hash = $user['password_hash'] ?? '$2y$10$CupTkau/Dssr8POZaUekYOiyeAD6jqcLxB/VV6iI741cT1gtrxYme';
    if (!password_verify($password, $hash) || !$user) {
        rate_limit_hit('user_login_fail');
        return ['ok' => false, 'user' => null, 'error' => $vague];
    }
    if ($user['status'] !== 'active') {
        return ['ok' => false, 'user' => null,
                'error' => 'This account is switched off. Message us if you think that is a mistake.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        update_row('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
    }

    return ['ok' => true, 'user' => $user, 'error' => ''];
}

/**
 * Make an account.
 *
 * @return array{ok: bool, user: ?array, errors: array<string, string>}
 */
function user_register(array $input): array
{
    $name     = trim((string) ($input['name'] ?? ''));
    $email    = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $errors   = [];

    if ($name === '' || mb_strlen($name) > 120) {
        $errors['name'] = 'Tell us what to call you.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'That email address does not look right.';
    } elseif (col('SELECT 1 FROM users WHERE email = ?', [$email])) {
        $errors['email'] = 'There is already an account with that email. Sign in instead, or reset the password.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif ($password !== (string) ($input['password_confirm'] ?? '')) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if (empty($input['terms'])) {
        $errors['terms'] = 'Please accept the terms to continue.';
    }
    if ($errors) {
        return ['ok' => false, 'user' => null, 'errors' => $errors];
    }

    try {
        $id = insert_row('users', [
            'name'          => mb_substr($name, 0, 120),
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'balance'       => 0,
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        // Two sign-ups with the same address in the same second.
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        return ['ok' => false, 'user' => null, 'errors' => ['email' => 'There is already an account with that email.']];
    }

    $user = one('SELECT * FROM users WHERE id = ?', [$id]);
    audit('user.registered', ['entity' => 'users', 'entity_id' => $id, 'summary' => $email . ' signed up']);
    notify('welcome', ['name' => $name, 'account_url' => url('account'), 'email' => $email]);
    notify('admin_new_user', ['name' => $name, 'email' => $email,
        'admin_url' => url('admin/users/' . $id), 'admin_link' => 'admin/users/' . $id]);

    return ['ok' => true, 'user' => $user, 'errors' => []];
}

/**
 * Send a reset link, if the address has an account.
 *
 * Says nothing either way to the caller's caller: "no account with that
 * email" is a free answer to whether somebody is a customer.
 */
function user_send_reset(string $email): void
{
    $user = one("SELECT * FROM users WHERE email = ? AND status = 'active'", [mb_strtolower(trim($email))]);
    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(24));
    update_row('users', [
        'reset_hash'       => hash('sha256', $token),
        'reset_expires_at' => date('Y-m-d H:i:s', time() + USER_RESET_MINUTES * 60),
    ], 'id = ?', [(int) $user['id']]);

    notify('password_reset', [
        'name'      => $user['name'],
        'reset_url' => url('reset/' . $token),
        'minutes'   => (string) USER_RESET_MINUTES,
        'email'     => $user['email'],
    ]);
}

/** The account a reset link belongs to, while it is still good. */
function user_by_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    return one("SELECT * FROM users WHERE reset_hash = ? AND reset_expires_at > ? AND status = 'active'",
        [hash('sha256', $token), date('Y-m-d H:i:s')]);
}

/** Set a new password, and spend the link that allowed it. */
function user_set_password(int $userId, string $password): void
{
    update_row('users', [
        'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
        'reset_hash'       => '',
        'reset_expires_at' => null,
        'updated_at'       => date('Y-m-d H:i:s'),
    ], 'id = ?', [$userId]);
    audit('user.password', ['entity' => 'users', 'entity_id' => $userId, 'summary' => 'Password changed']);
}
