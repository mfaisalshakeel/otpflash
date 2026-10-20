<?php
/**
 * The signed-in customer's corner: their initial and balance, opening a menu
 * of everything that is theirs. The chip is a link to the dashboard, so
 * without the script it still goes somewhere useful; the script turns it
 * into the menu's button.
 *
 * @var array $user
 */
$initial = mb_strtoupper(mb_substr((string) $user['name'], 0, 1));
$links = [
    ['account',         'Dashboard',          'i-grid'],
    ['account/orders',  'My orders',          'i-bag'],
    ['account/wallet',  'Wallet',             'i-wallet'],
    ['account/profile', 'Profile & security', 'i-shield'],
];
if (isset(store_tabs()['rental'])) {
    array_splice($links, 2, 0, [['account/rentals', 'Rentals', 'i-calendar']]);
}
?>
<div class="acctnav" data-acct>
  <a class="acct-chip" href="<?= e(url('account')) ?>" data-acct-toggle aria-haspopup="menu" aria-expanded="false"
     aria-controls="acctMenu" title="Your account">
    <span class="acct-av"><?= e($initial) ?></span>
    <span class="acct-bal"><small>Balance</small><b><?= e(money((float) $user['balance'])) ?></b></span>
    <svg class="icon acct-caret" aria-hidden="true"><use href="#i-chevron"></use></svg>
  </a>
  <div class="acct-menu" id="acctMenu" role="menu" data-acct-menu hidden>
    <div class="acct-menu-head">
      <span class="acct-av"><?= e($initial) ?></span>
      <div><b><?= e($user['name']) ?></b><small><?= e($user['email']) ?></small></div>
    </div>
    <div class="acct-menu-bal">
      <span><small>Wallet balance</small><b><?= e(money((float) $user['balance'])) ?></b></span>
      <a class="btn btn-primary btn-sm" href="<?= e(url('account/wallet')) ?>" role="menuitem">
        <svg class="icon"><use href="#i-plus"></use></svg> Add funds</a>
    </div>
    <nav class="acct-menu-links">
      <?php foreach ($links as [$path, $label, $icon]): ?>
        <a href="<?= e(url($path)) ?>" role="menuitem"><svg class="icon"><use href="#<?= e($icon) ?>"></use></svg> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="<?= e(url('logout')) ?>" class="acct-menu-out">
      <?= csrf_field() ?>
      <button type="submit" role="menuitem"><svg class="icon"><use href="#i-out"></use></svg> Sign out</button>
    </form>
  </div>
</div>
