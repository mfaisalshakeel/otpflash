<?php /** @var string $token  @var string $error */ ?>
<section class="auth">
  <div class="auth-card">
    <h1>Choose a new password</h1>
    <p class="psub">At least 8 characters.</p>
    <?php if ($error !== ''): ?><div class="form-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('reset/' . $token)) ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="n-pass">New password</label>
        <input id="n-pass" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
      </div>
      <div class="field">
        <label for="n-pass2">Repeat it</label>
        <input id="n-pass2" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Save and sign in</button>
    </form>
  </div>
</section>
