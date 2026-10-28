<?php /** The script and what it needs to know, at the foot of both layouts. */
require_once APP_PATH . '/helpers/storefront.php'; ?>
<script>window.OTPF = <?= json_encode([
    'base'     => url(''),
    'token'    => csrf_token(),
    'currency' => base_currency_symbol(),
    // Whether an app or a country has an address of its own. Off, and the
    // script keeps every choice on this page as a query, the way the server
    // does - the two must agree or Back would land on a 404.
    'landing'  => landing_pages_on(),
    'user'     => is_logged_in() ? ['balance' => (float) current_user()['balance']] : null,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(theme_asset('app.js')) ?>" defer></script>
