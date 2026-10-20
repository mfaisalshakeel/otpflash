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
<?php partial('partials/head', ['seo' => $seo]); ?>
</head>
<body class="<?= e($body_class ?? '') ?>" data-layout="main">
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

<?php partial('partials/scripts'); ?>
</body>
</html>
