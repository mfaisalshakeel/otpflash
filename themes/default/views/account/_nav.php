<?php
/** The portal's side menu. @var array $user  @var string $section */
$active = (int) col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'processing'", [(int) $user['id']], 0);
$items  = [
    'dashboard' => ['account',         'Dashboard', 'i-grid'],
    'buy'       => ['',                'Buy a number', 'i-plus'],
    'orders'    => ['account/orders',  'My orders', 'i-bag'],
    'rentals'   => ['account/rentals', 'Rentals', 'i-calendar'],
    'wallet'    => ['account/wallet',  'Wallet', 'i-wallet'],
    'profile'   => ['account/profile', 'Profile', 'i-user'],
];
?>
<aside class="pside">
  <div class="pside-user">
    <span class="acct-av"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
    <div><b><?= e($user['name']) ?></b><small><?= e($user['email']) ?></small></div>
  </div>
  <nav class="pnav" aria-label="Account">
    <?php foreach ($items as $key => [$path, $label, $icon]): ?>
      <a href="<?= e(url($path)) ?>"<?= $section === $key ? ' class="on" aria-current="page"' : '' ?>>
        <svg class="icon"><use href="#<?= e($icon) ?>"></use></svg> <?= e($label) ?>
        <?php if ($key === 'orders' && $active): ?><span class="pill"><?= $active ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit"><svg class="icon"><use href="#i-out"></use></svg> Sign out</button>
    </form>
  </nav>
</aside>
