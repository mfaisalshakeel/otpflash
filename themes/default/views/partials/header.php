<?php
/**
 * Site header. Every link is a real page, so it works without the script.
 *
 * The account corner is the one thing that changes with who is looking: a
 * guest is offered Sign in, a customer sees their balance - the number they
 * most often come back to check - and a way into the portal.
 */
require_once APP_PATH . '/helpers/catalog.php';

$siteName = setting('site_name', 'OTP Flash');
$logo     = setting('logo_path', '');
$user     = current_user();
$here     = current_path();
$tabs     = store_tabs();

// One answer for both the bar and the drawer: the same link in two places
// must not be able to disagree about whether it is there.
$chat  = chat_channels('header');
$links = [['', 'Buy numbers']];
if (isset($tabs['email'])) {
    $links[] = ['temporary-email', 'Temp email'];
}
if (isset($tabs['rental'])) {
    $links[] = ['rent-number', 'Rent a number'];
}
$links[] = ['blog', 'Blog'];
$links[] = ['faq', 'FAQ'];
?>
<header class="site-head">
  <div class="wrap nav">
    <a href="<?= e(url('')) ?>" class="logo" aria-label="<?= e($siteName) ?> home">
      <?php if ($logo): ?>
        <img src="<?= e(url($logo)) ?>" alt="<?= e($siteName) ?>" height="38" style="height:38px;width:auto">
      <?php else: ?>
        <span class="logo-mark"><svg class="icon"><use href="#i-bolt"></use></svg></span>
        <span><?= e($siteName) ?></span>
      <?php endif; ?>
    </a>

    <nav class="nav-links" aria-label="Main">
      <?php foreach ($links as [$path, $label]): ?>
        <a href="<?= e(url($path)) ?>"<?= $here === $path || ($path !== '' && str_starts_with($here, $path . '/')) ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="nav-right">
      <?php foreach ($chat as $channel): ?>
        <a href="<?= e($channel['url']) ?>" class="chat-link"
           <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>
           title="<?= e($channel['title']) ?>" aria-label="<?= e($channel['title']) ?>">
          <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg>
        </a>
      <?php endforeach; ?>
      <a class="btn btn-ghost btn-sm hide-sm" href="<?= e(url('track')) ?>" data-track-open>
        <svg class="icon"><use href="#i-search"></use></svg> Track
      </a>
      <?php if ($user): ?>
        <a class="acct-chip" href="<?= e(url('account')) ?>" title="Your account">
          <span class="acct-av"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
          <span class="acct-bal"><small>Balance</small><b><?= e(money((float) $user['balance'])) ?></b></span>
        </a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('login')) ?>">Sign in</a>
        <?php if (setting('registration_open', '1') === '1'): ?>
          <a class="btn btn-primary btn-sm hide-sm" href="<?= e(url('register')) ?>">Sign up</a>
        <?php endif; ?>
      <?php endif; ?>
      <button class="burger" type="button" data-drawer aria-label="Menu" aria-expanded="false" aria-controls="drawer">
        <svg class="icon"><use href="#i-menu"></use></svg>
      </button>
    </div>
  </div>

  <div class="drawer" id="drawer">
    <div class="wrap">
      <?php foreach ($links as [$path, $label]): ?>
        <a href="<?= e(url($path)) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= e(url('track')) ?>" data-track-open>Track an order</a>
      <?php if ($user): ?>
        <a href="<?= e(url('account')) ?>">My account</a>
        <a href="<?= e(url('account/wallet')) ?>">Add funds</a>
      <?php else: ?>
        <a href="<?= e(url('login')) ?>">Sign in</a>
        <?php if (setting('registration_open', '1') === '1'): ?>
          <a href="<?= e(url('register')) ?>">Create an account</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php foreach ($chat as $channel): ?>
        <a href="<?= e($channel['url']) ?>"
           <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>>
          Help on <?= e($channel['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</header>
