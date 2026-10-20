<?php /** The second step of signing in. @var string $error  @var string $email  masked */ ?>
<section class="auth">
  <div class="auth-card">
    <span class="auth-ico"><svg class="icon"><use href="#i-shield"></use></svg></span>
    <h1>Check your email</h1>
    <p class="psub">We sent a 6-digit code to <b><?= e($email) ?></b>. Enter it to finish signing in - it works for ten minutes.</p>
    <?php if ($error !== ''): ?><div class="form-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('login/code')) ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="c-code">Sign-in code</label>
        <input id="c-code" class="code-input" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
               autocomplete="one-time-code" required autofocus placeholder="••••••">
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Sign in</button>
    </form>
    <form method="post" action="<?= e(url('login/code')) ?>" class="auth-alt">
      <?= csrf_field() ?>
      No email? Check spam, or <button class="lnk-btn" type="submit" name="resend" value="1">send a new code</button>
    </form>
    <p class="auth-alt"><a href="<?= e(url('login')) ?>">Use a different account</a></p>
  </div>
</section>
