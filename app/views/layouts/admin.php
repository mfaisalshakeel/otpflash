<?php
/**
 * Admin shell: sidebar, top bar and the page body.
 *
 * @var string $content   rendered page
 * @var string $title     page title
 * @var string $subtitle  line under the title
 */
$active   = $GLOBALS['__admin_nav_active'] ?? 'index';
$user     = admin_user();
$siteName = setting('site_name', 'OTP Flash');
// optional_count throughout: this shell is drawn on every admin screen, the
// update screen included, and it has to survive code that is newer than the
// database. A badge is worth nothing next to being able to run the update.
$unread   = optional_count('SELECT COUNT(*) FROM messages WHERE is_read = 0');
$pending  = optional_count("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND trx_id <> ''");
$trouble  = optional_count("SELECT COUNT(*) FROM orders WHERE status IN ('api_error')");
// The last balance OTPGet told us, not a fresh call: this is drawn on every
// screen, and a slow provider must not make every screen slow.
require_once APP_PATH . '/helpers/catalog.php';
$balance  = otpget_balance_known();
require_once APP_PATH . '/helpers/notify.php';   // the bell's dropdown
$alerts      = optional_count('SELECT COUNT(*) FROM notifications WHERE is_read = 0');
$auditAlerts = optional_count("SELECT COUNT(*) FROM audit_log WHERE severity = 'alert'");
// optional_count: the table arrives with a migration, and the sidebar is drawn
// on the update screen too.
$failedCalls = optional_count('SELECT COUNT(*) FROM order_calls WHERE ok = 0 AND created_at >= ?',
    [date('Y-m-d H:i:s', time() - 86400)]);

$nav = [
    ['group' => 'Main'],
    ['key' => 'index',     'label' => 'Dashboard',       'icon' => 'i-grid', 'href' => 'admin'],
    ['key' => 'orders',    'label' => 'Orders',          'icon' => 'i-bag', 'href' => 'admin/orders',
     'badge' => ($pending + $trouble) ?: null],
    ['key' => 'users',     'label' => 'Customers',       'icon' => 'i-user', 'href' => 'admin/users'],
    ['key' => 'rentals',   'label' => 'Rentals',         'icon' => 'i-calendar', 'href' => 'admin/rentals'],
    ['key' => 'wallet',    'label' => 'Wallet ledger',   'icon' => 'i-coin', 'href' => 'admin/wallet'],
    ['key' => 'messages',  'label' => 'Messages',        'icon' => 'i-chat', 'href' => 'admin/messages',
     'badge' => $unread ?: null],
    ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'i-bell',
     'href' => 'admin/notifications', 'badge' => $alerts ?: null],
    ['key' => 'api-log',   'label' => 'API log',         'icon' => 'i-signal', 'href' => 'admin/api-log',
     'badge' => $failedCalls ?: null],
    ['group' => 'OTPGet'],
    ['key' => 'otpget',    'label' => 'Connection & sync', 'icon' => 'i-plug', 'href' => 'admin/otpget'],
    ['key' => 'catalogue', 'label' => 'Catalogue',       'icon' => 'i-layers', 'href' => 'admin/catalogue'],
    ['key' => 'services',  'label' => 'Services',        'icon' => 'i-box', 'href' => 'admin/services'],
    ['key' => 'countries', 'label' => 'Countries',       'icon' => 'i-globe', 'href' => 'admin/countries'],
    ['group' => 'Content'],
    ['key' => 'posts',     'label' => 'Blog posts',      'icon' => 'i-doc', 'href' => 'admin/posts'],
    ['key' => 'post-categories', 'label' => 'Blog categories', 'icon' => 'i-tag',
     'href' => 'admin/post-categories'],
    ['key' => 'pages',     'label' => 'Pages',           'icon' => 'i-doc', 'href' => 'admin/pages'],
    ['key' => 'faqs',      'label' => 'FAQs',            'icon' => 'i-help', 'href' => 'admin/faqs'],
    ['key' => 'seo',       'label' => 'SEO',             'icon' => 'i-search', 'href' => 'admin/seo'],
    ['group' => 'Appearance'],
    ['key' => 'themes',    'label' => 'Themes',          'icon' => 'i-palette', 'href' => 'admin/themes'],
    ['group' => 'System'],
    ['key' => 'payment-methods', 'label' => 'Payment Methods', 'icon' => 'i-card',
     'href' => 'admin/payment-methods'],
    ['key' => 'currencies', 'label' => 'Currencies',     'icon' => 'i-coin', 'href' => 'admin/currencies'],
    ['key' => 'cron',       'label' => 'Cron',           'icon' => 'i-clock','href' => 'admin/cron'],
    ['key' => 'update',    'label' => 'Update',          'icon' => 'i-refresh','href' => 'admin/update',
     'dot' => !empty($GLOBALS['__update_available'])],
    ['key' => 'emails',    'label' => 'Email templates', 'icon' => 'i-mail', 'href' => 'admin/emails'],
    ['key' => 'audit',     'label' => 'Audit log',       'icon' => 'i-history',
     'href' => 'admin/audit', 'badge' => $auditAlerts ?: null],
    ['key' => 'maintenance', 'label' => 'Maintenance',   'icon' => 'i-trash',
     'href' => 'admin/maintenance'],
    ['key' => 'settings',  'label' => 'Settings',        'icon' => 'i-gear', 'href' => 'admin/settings'],
    ['key' => 'security',  'label' => 'Two-factor',      'icon' => 'i-lock', 'href' => 'admin/security'],
    ['key' => 'password',  'label' => 'Change Password', 'icon' => 'i-key','href' => 'admin/password'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="asset-root" content="<?= e(url('')) ?>">
<meta name="editor-upload" content="<?= e(url('admin/media/upload')) ?>">
<title><?= e($title ?? 'Admin') ?> &mdash; <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<?php if ($icon = setting('favicon_path')): ?>
<link rel="icon" href="<?= e(url($icon)) ?>">
<?php endif; ?>
</head>
<body>
<?php partial('partials/icons'); ?>

<div class="shell">
  <aside class="side">
    <div class="brand">
      <span class="logo-mark"><svg class="icon"><use href="#i-rocket"></use></svg></span>
      <span><?= e($siteName) ?><small>Admin Panel</small></span>
      <button class="sideclose" type="button" data-side-close>&times;</button>
    </div>

    <nav class="snav">
      <?php foreach ($nav as $item): ?>
        <?php if (array_key_exists('when', $item) && !$item['when']): continue; endif; ?>
        <?php if (isset($item['group'])): ?>
          <span class="sgroup"><?= e($item['group']) ?></span>
        <?php else: ?>
          <a href="<?= e(url($item['href'])) ?>"<?= $active === $item['key'] ? ' class="on"' : '' ?>>
            <svg class="icon"><use href="#<?= e($item['icon']) ?>"></use></svg>
            <?= e($item['label']) ?>
            <?php if (!empty($item['badge'])): ?><span class="pill"><?= (int) $item['badge'] ?></span><?php endif; ?>
            <?php if (!empty($item['dot'])): ?><span class="updot" title="Update available"></span><?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>

      <form method="post" action="<?= e(url('admin/logout')) ?>" style="margin-top:4px">
        <?= csrf_field() ?>
        <button type="submit" class="snav-btn">
          <svg class="icon"><use href="#i-out"></use></svg> Log out
        </button>
      </form>
    </nav>

    <div class="sfoot">
      <a class="bal-mini" href="<?= e(url('admin/otpget')) ?>">
        <small>OTPGet balance</small>
        <b><?= e($balance['own']) ?></b>
        <?php if ($balance['at'] !== ''): ?>
          <small>checked <?= e(when($balance['at'], 'd M, H:i')) ?></small>
        <?php endif; ?>
      </a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="burger" type="button" data-side-open>
        <svg class="icon"><use href="#i-menu"></use></svg>
      </button>
      <div class="crumb">
        <b><?= e($title ?? 'Admin') ?></b>
        <small><?= e($subtitle ?? '') ?></small>
      </div>
      <div class="tb-right">
        <?php /* Up here, not only in the sidebar: this is the thing you glance
                 at between tasks, and on a phone the sidebar is behind the
                 burger where nothing can be glanced at. */ ?>
        <?php /* The bell is a link to the feed that the script turns into a
                 dropdown. Pressed without the script it still goes to the
                 page, which is what it did before there was a dropdown. */ ?>
        <div class="bellwrap">
          <a class="iconbtn<?= $alerts ? ' has-dot' : '' ?>" id="bellBtn" data-bell
             href="<?= e(url('admin/notifications')) ?>"
             aria-haspopup="true" aria-expanded="false" aria-controls="bellDrop"
             title="<?= $alerts ? $alerts . ' unread' : 'Notifications' ?>"
             aria-label="<?= $alerts ? 'Notifications, ' . $alerts . ' unread' : 'Notifications' ?>">
            <svg class="icon"><use href="#i-bell"></use></svg>
            <?php if ($alerts): ?>
              <span class="tb-count"><?= $alerts > 99 ? '99+' : (int) $alerts ?></span>
            <?php endif; ?>
          </a>

          <div class="ndrop" id="bellDrop" hidden>
            <div class="ndrop-head">
              <b>Notifications</b>
              <?php if ($alerts): ?>
                <span class="ndrop-unread"><?= (int) $alerts ?> unread</span>
              <?php endif; ?>
            </div>

            <?php $latest = notifications_latest(8); ?>
            <?php if (!$latest): ?>
              <p class="ndrop-empty">Nothing yet. Orders, payments, sign-ups and
                 OTPGet trouble turn up here.</p>
            <?php else: ?>
              <ul class="ndrop-list">
                <?php foreach ($latest as $note): ?>
                  <?php $look = notification_look((string) $note['event']); ?>
                  <li>
                    <a class="ndrop-item<?= $note['is_read'] ? '' : ' is-unread' ?>"
                       href="<?= e(url($note['link'] !== '' ? $note['link'] : 'admin/notifications')) ?>">
                      <span class="nicon nicon-<?= e($look['tone']) ?>" aria-hidden="true">
                        <svg class="icon"><use href="#<?= e($look['icon']) ?>"></use></svg>
                      </span>
                      <span class="ndrop-txt">
                        <b><?= e($note['title']) ?></b>
                        <small><?= e(when($note['created_at'], 'd M, H:i')) ?></small>
                      </span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="ndrop-foot">
              <?php if ($alerts): ?>
                <form method="post" action="<?= e(url('admin/notifications/read')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm" type="submit">Mark all read</button>
                </form>
              <?php endif; ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/notifications')) ?>">See all</a>
            </div>
          </div>
        </div>
        <a class="iconbtn" href="<?= e(url('')) ?>" target="_blank" rel="noopener" title="View site">
          <svg class="icon"><use href="#i-rocket"></use></svg>
        </a>
        <div class="me">
          <span class="avatar"><?= e(strtoupper(mb_substr($user['username'] ?? 'A', 0, 2))) ?></span>
          <span class="me-txt"><b><?= e($user['username'] ?? '') ?></b><small>Administrator</small></span>
        </div>
      </div>
    </header>

    <div class="content">
      <?php if (!empty($GLOBALS['__update_available']) && $active !== 'update'): ?>
        <div class="alert alert-info">
          A newer version of the panel is on the server.
          <a href="<?= e(url('admin/update')) ?>">Run the database update</a> to finish it.
        </div>
      <?php endif; ?>
      <?php foreach (flashes() as $message): ?>
        <div class="alert alert-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>

  <div class="scrim" data-side-close></div>
</div>

<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>
</body>
</html>
