<?php
/**
 * The signed-in customer's own pages.
 *
 * Not the shop's layout with a menu squeezed in: the portal is somewhere a
 * customer works - checks a code, tops up, reads a rental's inbox - so it
 * gets an app's shape. A sidebar of everything that is theirs, with the
 * balance at the top of it; on a phone the same sidebar slides in from the
 * menu button, and the four places they go most sit in a bar along the
 * bottom, under the thumb.
 *
 * @var string $content  rendered page
 * @var array  $seo
 * @var array  $user     the signed-in customer
 * @var string $section  which item is lit
 * @var string $body_class optional
 */
require_once APP_PATH . '/helpers/seo.php';

$user     = $user ?? current_user();
$section  = $section ?? 'dashboard';
$siteName = setting('site_name', 'OTP Flash');
$logo     = setting('logo_path', '');
$seo      = ($seo ?? []) + ['title' => $title ?? 'My account', 'robots' => 'noindex, nofollow'];
$tabs     = store_tabs();
$active   = (int) col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'processing'", [(int) $user['id']], 0);

$items = [
    'dashboard' => ['account',         'Dashboard',          'i-grid'],
    'orders'    => ['account/orders',  'My orders',          'i-bag'],
    'rentals'   => ['account/rentals', 'Rentals',            'i-calendar'],
    'wallet'    => ['account/wallet',  'Wallet',             'i-wallet'],
    'profile'   => ['account/profile', 'Profile & security', 'i-shield'],
];
if (!isset($tabs['rental']) && $section !== 'rentals') {
    unset($items['rentals']);
}
$buy = [['', 'Virtual number', 'i-phone']];
if (isset($tabs['email'])) {
    $buy[] = ['temporary-email', 'Temporary email', 'i-mail'];
}
if (isset($tabs['rental'])) {
    $buy[] = ['rent-number', 'Rent a number', 'i-calendar'];
}
$chat = chat_channels('header');
?><!doctype html>
<html lang="en" class="no-js" data-currency="<?= e(base_currency_symbol()) ?>">
<head>
<?php partial('partials/head', ['seo' => $seo]); ?>
</head>
<body class="is-portal <?= e($body_class ?? '') ?>" data-layout="portal">
<a class="skip" href="#main">Skip to content</a>
<?php partial('partials/icons'); ?>

<div class="app">
  <aside class="app-side" id="appSide" aria-label="Your account">
    <div class="app-brand">
      <a href="<?= e(url('')) ?>" class="logo" aria-label="<?= e($siteName) ?> home">
        <?php if ($logo): ?>
          <img src="<?= e(url($logo)) ?>" alt="<?= e($siteName) ?>" height="34" style="height:34px;width:auto">
        <?php else: ?>
          <span class="logo-mark"><svg class="icon"><use href="#i-bolt"></use></svg></span>
          <span><?= e($siteName) ?></span>
        <?php endif; ?>
      </a>
      <button class="app-x" type="button" data-side-close aria-label="Close the menu">
        <svg class="icon"><use href="#i-close"></use></svg></button>
    </div>

    <div class="app-wallet">
      <small>Wallet balance</small>
      <b><?= e(money((float) $user['balance'])) ?></b>
      <a class="btn btn-sm" href="<?= e(url('account/wallet')) ?>"><svg class="icon"><use href="#i-plus"></use></svg> Add funds</a>
    </div>

    <nav class="app-nav">
      <?php foreach ($items as $key => [$path, $label, $icon]): ?>
        <a href="<?= e(url($path)) ?>"<?= $section === $key ? ' class="on" aria-current="page"' : '' ?>>
          <svg class="icon"><use href="#<?= e($icon) ?>"></use></svg> <span><?= e($label) ?></span>
          <?php if ($key === 'orders' && $active): ?><span class="pill" title="Waiting for a code"><?= $active ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="app-group">
      <small>Buy</small>
      <nav class="app-nav">
        <?php foreach ($buy as [$path, $label, $icon]): ?>
          <a href="<?= e(url($path)) ?>"><svg class="icon"><use href="#<?= e($icon) ?>"></use></svg> <span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="app-side-foot">
      <?php foreach ($chat as $channel): ?>
        <a href="<?= e($channel['url']) ?>" <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>>
          <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg> <span>Help on <?= e($channel['name']) ?></span></a>
      <?php endforeach; ?>
      <form method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit"><svg class="icon"><use href="#i-out"></use></svg> <span>Sign out</span></button>
      </form>
    </div>
  </aside>
  <div class="app-scrim" data-side-close></div>

  <div class="app-main">
    <header class="app-top">
      <button class="app-burger" type="button" data-side-open aria-controls="appSide" aria-expanded="false" aria-label="Menu">
        <svg class="icon"><use href="#i-menu"></use></svg></button>
      <a href="<?= e(url('')) ?>" class="logo app-top-logo" aria-label="<?= e($siteName) ?> home">
        <?php if ($logo): ?>
          <img src="<?= e(url($logo)) ?>" alt="<?= e($siteName) ?>" height="30" style="height:30px;width:auto">
        <?php else: ?>
          <span class="logo-mark"><svg class="icon"><use href="#i-bolt"></use></svg></span>
        <?php endif; ?>
      </a>
      <div class="app-top-right">
        <a class="btn btn-ghost btn-sm hide-sm" href="<?= e(url('track')) ?>" data-track-open>
          <svg class="icon"><use href="#i-search"></use></svg> Track</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('')) ?>"><svg class="icon"><use href="#i-plus"></use></svg>
          <span class="hide-sm">Buy a number</span><span class="show-sm">Buy</span></a>
        <?php partial('partials/account-menu', ['user' => $user]); ?>
      </div>
    </header>

    <?php foreach (flashes() as $message): ?>
      <div class="app-flash">
        <div class="alert alert-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></div>
      </div>
    <?php endforeach; ?>

    <main id="main" class="app-content"><?= $content ?></main>
  </div>
</div>

<nav class="app-tabs" aria-label="Account shortcuts">
  <?php foreach ([['', 'Buy', 'i-plus', 'buy'], ['account/orders', 'Orders', 'i-bag', 'orders'],
                  ['account/wallet', 'Wallet', 'i-wallet', 'wallet'], ['account', 'Account', 'i-user', 'dashboard']] as [$path, $label, $icon, $key]): ?>
    <a href="<?= e(url($path)) ?>"<?= $section === $key || ($key === 'dashboard' && $section === 'profile') ? ' class="on" aria-current="page"' : '' ?>>
      <svg class="icon"><use href="#<?= e($icon) ?>"></use></svg><span><?= e($label) ?></span>
      <?php if ($key === 'orders' && $active): ?><i class="dot" aria-hidden="true"></i><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php partial('partials/modals'); ?>
<?php partial('partials/scripts'); ?>
</body>
</html>
