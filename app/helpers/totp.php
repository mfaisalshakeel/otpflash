<?php
/**
 * Time-based one-time passwords (RFC 6238) and the QR that carries the secret.
 *
 * Both are written out here because there is no Composer on the hosts this
 * runs on, and the alternative - posting the secret to someone else's QR
 * image service - would hand the second factor to a third party over a URL
 * that lands in their logs.
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

/** A fresh secret, in the base32 the authenticator apps expect. */
function totp_secret(): string
{
    return base32_encode(random_bytes(20));
}

/** The code for a moment in time. */
function totp_code(string $secret, ?int $at = null): string
{
    $key     = base32_decode($secret);
    $counter = intdiv($at ?? time(), TOTP_PERIOD);
    $hash    = hash_hmac('sha1', pack('J', $counter), $key, true);

    // Dynamic truncation: the low nibble of the last byte picks where to read.
    $offset = ord($hash[19]) & 0x0f;
    $value  = ((ord($hash[$offset])     & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            |  (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Check a code the admin typed.
 *
 * One step either side is allowed, because a phone's clock and a shared
 * host's clock are rarely the same to the second and a window of zero locks
 * people out of their own panel. Compared with hash_equals so the check
 * cannot be timed.
 */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS || $secret === '') {
        return false;
    }

    $now = time();
    for ($step = -$window; $step <= $window; $step++) {
        if (hash_equals(totp_code($secret, $now + ($step * TOTP_PERIOD)), $code)) {
            return true;
        }
    }
    return false;
}

/** The otpauth:// URI an authenticator app reads out of the QR. */
function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => TOTP_DIGITS,
            'period' => TOTP_PERIOD,
        ]);
}

/** The secret in readable blocks, for someone typing it in by hand. */
function totp_readable(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

// =============================================================== base32 ====

function base32_encode(string $binary): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($binary) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text));

    $bits = '';
    foreach (str_split($text) as $character) {
        $index = strpos($alphabet, $character);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

// ========================================================== recovery =======

/**
 * Ten one-use codes, shown once and stored hashed.
 *
 * Hashed because a stolen database should not hand over the second factor,
 * and shown once because a code the admin can re-read in the panel is not a
 * recovery code, it is a second password sitting next to the first.
 */
function recovery_codes_make(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));          // 10 hex characters
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
    }
    return $codes;
}

function recovery_codes_hash(array $codes): string
{
    return json_encode(array_map(
        static fn (string $code) => hash('sha256', recovery_normalise($code)),
        $codes
    ));
}

/** Spaces, case and the dash are noise; the code is the characters. */
function recovery_normalise(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/**
 * Spend a recovery code.
 *
 * Returns the remaining list when it matched, so the caller stores it back -
 * a code that still works after it has been used is not one-use.
 *
 * @return string|null  the new stored value, or null when nothing matched
 */
function recovery_codes_spend(string $stored, string $typed): ?string
{
    $hashes = json_decode($stored ?: '[]', true);
    if (!is_array($hashes)) {
        return null;
    }

    $wanted = hash('sha256', recovery_normalise($typed));
    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && hash_equals($hash, $wanted)) {
            unset($hashes[$index]);
            return json_encode(array_values($hashes));
        }
    }
    return null;
}

function recovery_codes_left(string $stored): int
{
    $hashes = json_decode($stored ?: '[]', true);
    return is_array($hashes) ? count($hashes) : 0;
}
