<?php /** The script and what it needs to know, at the foot of both layouts. */ ?>
<script>window.OTPF = <?= json_encode([
    'base'     => url(''),
    'token'    => csrf_token(),
    'currency' => base_currency_symbol(),
    'user'     => is_logged_in() ? ['balance' => (float) current_user()['balance']] : null,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(theme_asset('app.js')) ?>" defer></script>
