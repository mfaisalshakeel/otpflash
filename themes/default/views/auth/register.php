<?php /** @var array $errors  @var array $input  @var string $next  @var bool $google */
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="err">' . e($errors[$k]) . '</small>' : '';
?>
<section class="auth">
  <div class="auth-card">
    <h1>Create your account</h1>
    <p class="psub">Free, and takes ten seconds.</p>
    <ul class="auth-perks">
      <li><svg class="icon"><use href="#i-check"></use></svg> Pay for numbers in one tap from your wallet</li>
      <li><svg class="icon"><use href="#i-check"></use></svg> No SMS? Refunded to your wallet automatically</li>
      <li><svg class="icon"><use href="#i-check"></use></svg> Rent numbers for days or months, with an inbox</li>
      <li><svg class="icon"><use href="#i-check"></use></svg> Every order and code in one place</li>
    </ul>
    <?php if ($google): ?><?php partial('partials/google-button', ['next' => $next]); ?><?php endif; ?>
    <form method="post" action="<?= e(url('register')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">
      <div class="field">
        <label for="r-name">Your name</label>
        <input id="r-name" name="name" value="<?= e($input['name']) ?>" required autocomplete="name" <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
        <?= $err('name') ?>
      </div>
      <div class="field">
        <label for="r-email">Email</label>
        <input id="r-email" type="email" name="email" value="<?= e($input['email']) ?>" required autocomplete="email" <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
        <?= $err('email') ?>
      </div>
      <div class="field2">
        <div class="field">
          <label for="r-pass">Password</label>
          <input id="r-pass" type="password" name="password" required minlength="8" autocomplete="new-password" <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
          <?= $err('password') ?>
        </div>
        <div class="field">
          <label for="r-pass2">Repeat it</label>
          <input id="r-pass2" type="password" name="password_confirm" required minlength="8" autocomplete="new-password" <?= isset($errors['password_confirm']) ? 'aria-invalid="true"' : '' ?>>
          <?= $err('password_confirm') ?>
        </div>
      </div>
      <label class="check field">
        <input type="checkbox" name="terms" value="1" required>
        <span>I agree to the <a href="<?= e(url('terms-of-service')) ?>" target="_blank">terms</a> and
          <a href="<?= e(url('privacy-policy')) ?>" target="_blank">privacy policy</a>.</span>
      </label>
      <?= $err('terms') ?>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Create account</button>
    </form>
    <p class="auth-alt">Already have one? <a href="<?= e(url('login' . ($next !== 'account' ? '?next=' . rawurlencode($next) : ''))) ?>">Sign in</a></p>
  </div>
</section>
