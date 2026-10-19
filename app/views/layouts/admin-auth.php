<?php
/** Bare layout for the login screen - no sidebar, no session needed. */
$siteName = setting('site_name', 'OTP Flash');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Login') ?> &mdash; <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="auth-body">
<?php partial('partials/icons'); ?>
<main class="auth-wrap">
  <div class="auth-brand">
    <span class="logo-mark"><svg class="icon"><use href="#i-rocket"></use></svg></span>
    <span><?= e($siteName) ?><small>Admin Panel</small></span>
  </div>
  <?php foreach (flashes() as $message): ?>
    <div class="alert alert-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
