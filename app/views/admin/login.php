<?php
/**
 * Sign in: the password, then a code where two-factor is on.
 *
 * @var ?string $error  @var string $username  @var string $step
 * @var string  $mode   @var int    $minutes
 */
?>
<form method="post" class="auth-card" action="<?= e(url('admin/login')) ?>">
  <?= csrf_field() ?>

  <?php if ($step === 'code'): ?>
    <h1>One more step</h1>
    <p class="auth-sub">
      <?= $mode === 'email'
            ? 'We sent a six-digit code to your email. It works for ' . (int) $minutes . ' minutes.'
            : 'Open your authenticator app and enter the six-digit code.' ?>
    </p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php foreach (flashes() as $flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <div class="field">
      <label for="code">Code</label>
      <input id="code" name="code" required autofocus inputmode="numeric"
             autocomplete="one-time-code" class="code-input"
             placeholder="000000" maxlength="13">
      <small>A recovery code works here too, if you cannot reach your
        <?= $mode === 'email' ? 'inbox' : 'phone' ?>.</small>
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Continue</button>

    <?php if ($mode === 'email'): ?>
      <button class="btn btn-ghost btn-block" type="submit" name="resend" value="1"
              formnovalidate>Send another code</button>
    <?php endif; ?>

    <p class="auth-foot">
      <?php /* A link here used to point at admin/logout, which is POST only -
               so it bounced back to this same step and "start again" did not.
               Abandoning the half-finished sign-in is its own small action. */ ?>
      <button class="linkish" type="submit" name="cancel" value="1" formnovalidate>
        Start again
      </button>
    </p>

  <?php else: ?>
    <h1>Sign in</h1>
    <p class="auth-sub">Enter your admin details to continue.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="field">
      <label for="username">Username or email</label>
      <input id="username" name="username" value="<?= e($username) ?>" required autofocus
             autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" type="password" name="password" required autocomplete="current-password">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Sign in</button>
  <?php endif; ?>
</form>
