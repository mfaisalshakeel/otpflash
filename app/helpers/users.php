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

// ======================================================= two-step sign-in ==

const USER_CODE_MINUTES = 10;
const USER_CODE_TRIES   = 5;

/** Does this account ask for an emailed code after its password? */
function user_wants_code(array $user): bool
{
    return !empty($user['two_factor']) && table_has_column('users', 'two_factor');
}

/**
 * The password was right; now send the code and wait for it.
 *
 * Nothing is signed in yet. The code lives only in this session, as an HMAC
 * keyed on the app key, with its own expiry and a count of wrong tries - so
 * a stolen password alone opens nothing, and the code cannot be guessed in
 * the time it lasts.
 */
function user_code_start(array $user, string $next): void
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['_login_code'] = [
        'user'    => (int) $user['id'],
        'hash'    => hash_hmac('sha256', $code, (string) cfg('app_key')),
        'expires' => time() + USER_CODE_MINUTES * 60,
        'tries'   => 0,
        'sent'    => time(),
        'next'    => $next,
    ];
    notify('login_code', [
        'name'    => $user['name'],
        'code'    => $code,
        'minutes' => (string) USER_CODE_MINUTES,
        'email'   => $user['email'],
    ]);
}

/** The sign-in waiting for its code in this session, while it still can be finished. */
function user_code_pending(): ?array
{
    $pending = $_SESSION['_login_code'] ?? null;
    if (!is_array($pending) || $pending['expires'] < time() || $pending['tries'] >= USER_CODE_TRIES) {
        return null;
    }
    $user = one("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int) $pending['user']]);
    return $user ? ['user' => $user] + $pending : null;
}

/**
 * Check the code typed in. Right: the sign-in finishes. Wrong too often, or
 * too late: the attempt is thrown away and it starts again from the password.
 *
 * @return array{ok: bool, error: string, user?: array, next?: string}
 */
function user_code_check(string $typed): array
{
    $pending = user_code_pending();
    if (!$pending) {
        unset($_SESSION['_login_code']);
        return ['ok' => false, 'error' => 'That code has run out. Sign in again for a new one.'];
    }
    $typed = preg_replace('/\D/', '', $typed);
    if ($typed === '' || !hash_equals($pending['hash'], hash_hmac('sha256', $typed, (string) cfg('app_key')))) {
        $_SESSION['_login_code']['tries']++;
        $left = USER_CODE_TRIES - $_SESSION['_login_code']['tries'];
        if ($left <= 0) {
            unset($_SESSION['_login_code']);
            return ['ok' => false, 'error' => 'Too many wrong codes. Sign in again for a new one.'];
        }
        return ['ok' => false, 'error' => 'That code is not right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.'];
    }
    unset($_SESSION['_login_code']);
    return ['ok' => true, 'error' => '', 'user' => $pending['user'], 'next' => (string) $pending['next']];
}

/** Send the code again - a new one - at most once a minute. */
function user_code_resend(): bool
{
    $pending = user_code_pending();
    if (!$pending || $pending['sent'] > time() - 60) {
        return false;
    }
    user_code_start($pending['user'], (string) $pending['next']);
    return true;
}

// =========================================================== Google =======

/** Is "Continue with Google" set up? Both halves of the client are needed. */
function google_ready(): bool
{
    return trim((string) setting('google_client_id', '')) !== ''
        && trim((string) setting('google_client_secret', '')) !== ''
        && table_has_column('users', 'google_id');
}

/** The address Google sends the customer back to, registered in Google Cloud. */
function google_redirect_uri(): string
{
    return url('login/google');
}

/** Where to send the customer to choose their Google account. */
function google_auth_url(string $next): string
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['_google'] = ['state' => $state, 'next' => $next, 'at' => time()];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => trim((string) setting('google_client_id')),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

/**
 * Google sent the customer back: trade the code for who they are.
 *
 * The state must be the one this session sent, so a link someone else
 * started cannot sign this browser in. The person is read from Google's
 * userinfo endpoint over a verified connection, which is as good as checking
 * the ID token's signature and needs no key handling here. Only an address
 * Google says it has verified is accepted.
 *
 * @return array{ok: bool, error: string, profile?: array{sub: string, email: string, name: string}, next?: string}
 */
function google_finish(string $code, string $state): array
{
    $sent = $_SESSION['_google'] ?? null;
    unset($_SESSION['_google']);
    if (!is_array($sent) || $state === '' || !hash_equals((string) $sent['state'], $state) || $sent['at'] < time() - 900) {
        return ['ok' => false, 'error' => 'That Google sign-in had expired. Please try again.'];
    }

    $token = google_request('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => trim((string) setting('google_client_id')),
        'client_secret' => trim((string) setting('google_client_secret')),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
    if (empty($token['access_token'])) {
        log_line('Google sign-in: token exchange failed: ' . json_encode($token));
        return ['ok' => false, 'error' => 'Google did not let us sign you in. Please try again.'];
    }
    $profile = google_request('https://openidconnect.googleapis.com/v1/userinfo', null, (string) $token['access_token']);
    if (empty($profile['sub']) || empty($profile['email'])) {
        log_line('Google sign-in: no profile: ' . json_encode($profile));
        return ['ok' => false, 'error' => 'Google did not tell us who you are. Please try again.'];
    }
    if (empty($profile['email_verified'])) {
        return ['ok' => false, 'error' => 'That Google account\'s email is not verified yet.'];
    }
    return ['ok' => true, 'error' => '', 'next' => (string) $sent['next'], 'profile' => [
        'sub'   => (string) $profile['sub'],
        'email' => mb_strtolower((string) $profile['email']),
        'name'  => trim((string) ($profile['name'] ?? '')) ?: strstr((string) $profile['email'], '@', true),
    ]];
}

/** One call to Google; JSON back, or [] when it could not be read. TLS is verified. */
function google_request(string $url, ?array $form, string $bearer = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array_filter(['Accept: application/json',
                                                $bearer !== '' ? 'Authorization: Bearer ' . $bearer : '']),
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return is_array($data) ? $data : [];
}

/**
 * The account a Google profile belongs to, made if there is none.
 *
 * Found by Google's own id first, then by the verified address - which links
 * an account someone made with a password to their Google sign-in, since
 * Google has just proved they own that address. A new account gets a random
 * password nobody knows; "Forgot your password?" sets a real one.
 *
 * @return array{ok: bool, error: string, user?: array, created?: bool}
 */
function user_from_google(array $profile): array
{
    $user = one('SELECT * FROM users WHERE google_id = ?', [$profile['sub']])
        ?? one('SELECT * FROM users WHERE email = ?', [$profile['email']]);

    if ($user) {
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This account is switched off. Message us if you think that is a mistake.'];
        }
        if ((string) ($user['google_id'] ?? '') === '') {
            update_row('users', ['google_id' => $profile['sub'], 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $user['id']]);
            audit('user.google_linked', ['entity' => 'users', 'entity_id' => (int) $user['id'],
                'summary' => $user['email'] . ' linked Google sign-in']);
        }
        return ['ok' => true, 'error' => '', 'user' => $user, 'created' => false];
    }

    if (setting('registration_open', '1') !== '1') {
        return ['ok' => false, 'error' => 'New accounts are closed at the moment.'];
    }
    try {
        $id = insert_row('users', [
            'name'          => mb_substr($profile['name'], 0, 120),
            'email'         => $profile['email'],
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'balance'       => 0,
            'status'        => 'active',
            'google_id'     => $profile['sub'],
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        // The same person finishing the Google round trip in two tabs.
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        return user_from_google($profile);
    }
    $user = one('SELECT * FROM users WHERE id = ?', [$id]);
    audit('user.registered', ['entity' => 'users', 'entity_id' => $id, 'summary' => $profile['email'] . ' signed up with Google']);
    notify('welcome', ['name' => $user['name'], 'account_url' => url('account'), 'email' => $user['email']]);
    notify('admin_new_user', ['name' => $user['name'], 'email' => $user['email'],
        'admin_url' => url('admin/users/' . $id), 'admin_link' => 'admin/users/' . $id]);
    return ['ok' => true, 'error' => '', 'user' => $user, 'created' => true];
}

/** "en•••@gmail.com" - enough to know which inbox to look in, not enough to read off a screen. */
function user_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 2) . str_repeat('•', max(3, min(8, mb_strlen($local) - 2))) . '@' . $domain;
}
