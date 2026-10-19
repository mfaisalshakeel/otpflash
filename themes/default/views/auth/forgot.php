<?php /** @var bool $sent  @var bool $mailOn */ ?>
<section class="auth">
  <div class="auth-card">
    <h1>Reset your password</h1>
    <?php if ($sent): ?>
      <div class="alert alert-success" role="status">If that email has an account, a reset link is on its way. It works for one hour.</div>
      <p class="auth-alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
    <?php else: ?>
      <p class="psub">Type the email you signed up with and we will send you a link to choose a new password.</p>
      <form method="post" action="<?= e(url('forgot')) ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label for="f-email">Email</label>
          <input id="f-email" type="email" name="email" required autocomplete="email" autofocus>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Send the link</button>
      </form>
      <?php if (!$mailOn): ?>
        <p class="help">Not arriving? Message us from the contact page and we will help you back in.</p>
      <?php endif; ?>
      <p class="auth-alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
    <?php endif; ?>
  </div>
</section>
