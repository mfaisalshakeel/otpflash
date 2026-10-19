<?php /** @var string $error  @var string $email  @var string $next */ ?>
<section class="auth">
  <div class="auth-card">
    <h1>Welcome back</h1>
    <p class="psub">Sign in to pay from your wallet, see every number you bought and manage your rentals.</p>
    <?php if ($error !== ''): ?><div class="form-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('login')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field">
        <label for="l-email">Email</label>
        <input id="l-email" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus>
      </div>
      <div class="field">
        <label for="l-pass">Password</label>
        <input id="l-pass" type="password" name="password" required autocomplete="current-password">
      </div>
      <div class="auth-row"><span></span><a href="<?= e(url('forgot')) ?>">Forgot your password?</a></div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Sign in</button>
    </form>
    <?php if (setting('registration_open', '1') === '1'): ?>
      <p class="auth-alt">New here? <a href="<?= e(url('register' . ($next !== 'account' ? '?next=' . rawurlencode($next) : ''))) ?>">Create a free account</a></p>
    <?php endif; ?>
    <?php if (setting('guest_checkout', '1') === '1'): ?>
      <p class="auth-alt">Just need one number? <a href="<?= e(url('')) ?>">Buy without an account</a></p>
    <?php endif; ?>
  </div>
</section>
