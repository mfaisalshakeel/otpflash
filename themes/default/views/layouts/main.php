<?php
/**
 * Front-site layout.
 *
 * @var string $content  rendered page
 * @var array  $seo      what seo_head() prints - every controller passes one
 * @var string $title    fallback when a view passes no seo array
 * @var string $body_class optional
 */
require_once APP_PATH . '/helpers/seo.php';

$siteName = setting('site_name', 'OTP Flash');
$themeCss = ':root{--brand:' . preg_replace('/[^#0-9a-fA-F]/', '', setting('theme_color', '#6c4df6')) . '}';
$seo      = $seo ?? ['title' => $title ?? '', 'robots' => $meta_robots ?? null];
if (empty($seo['robots']) && !empty($meta_robots)) {
    $seo['robots'] = $meta_robots;
}
// Every page carries the organisation, so whichever page a search engine
// lands on first ties the site together.
$seo['schema'] = array_merge([schema_organization()], (array) ($seo['schema'] ?? []));
?><!doctype html>
<html lang="en" class="no-js" data-currency="<?= e(base_currency_symbol()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= seo_head($seo) ?>

<meta name="theme-color" content="<?= e(preg_replace('/[^#0-9a-fA-F]/', '', setting('theme_color', '#6c4df6'))) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(theme_asset('app.css')) ?>">
<?php if ($icon = setting('favicon_path')): ?>
<link rel="icon" href="<?= e(url($icon)) ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($siteName) ?> blog" href="<?= e(url('blog/feed')) ?>">
<style><?= $themeCss ?></style>
<?= setting('head_code', '') /* trusted: pasted by an admin in Settings */ ?>
</head>
<body class="<?= e($body_class ?? '') ?>">
<a class="skip" href="#main">Skip to content</a>
<?php partial('partials/icons'); ?>
<?php partial('partials/header'); ?>

<?php foreach (flashes() as $message): ?>
  <div class="wrap flash-wrap">
    <div class="alert alert-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></div>
  </div>
<?php endforeach; ?>

<main id="main"><?= $content ?></main>

<?php partial('partials/footer'); ?>
<?php partial('partials/modals'); ?>

<?php /* Whichever channels Settings puts here, stacked and wordless; the
         accessible name says which app each one opens. */ ?>
<?php if ($chatFloat = chat_channels('float')): ?>
<div class="fabs">
  <?php foreach ($chatFloat as $channel): ?>
    <a class="fab fab-<?= e($channel['key']) ?>" href="<?= e($channel['url']) ?>"
       <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>
       title="<?= e($channel['title']) ?>" aria-label="<?= e($channel['title']) ?>">
      <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>window.OTPF = <?= json_encode([
    'base'     => url(''),
    'token'    => csrf_token(),
    'currency' => base_currency_symbol(),
    'user'     => is_logged_in() ? ['balance' => (float) current_user()['balance']] : null,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(theme_asset('app.js')) ?>" defer></script>
</body>
</html>
