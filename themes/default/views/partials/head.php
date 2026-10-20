<?php
/**
 * Everything in <head> both layouts share: the SEO tags, the font, the
 * stylesheet, the theme colour and the admin's own head code.
 *
 * @var array $seo  what seo_head() prints
 */
$siteName = setting('site_name', 'OTP Flash');
$themeCss = ':root{--brand:' . preg_replace('/[^#0-9a-fA-F]/', '', setting('theme_color', '#6c4df6')) . '}';
?>
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
