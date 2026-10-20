<?php /** @var array $user @var string $section @var array $errors @var array $twoFactor  available, on, google */
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="err">' . e($errors[$k]) . '</small>' : '';
?>
<div class="portal-page">
  <div class="phead"><div><h1>Profile</h1><p>Member since <?= e(when($user['created_at'], 'd M Y')) ?>.</p></div></div>
  <div class="dash-top">
    <div class="panel">
      <div class="ptitle"><svg class="icon"><use href="#i-user"></use></svg> Your details</div>
      <form method="post" action="<?= e(url('account/profile')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="details">
        <div class="field"><label for="p-name">Name</label>
          <input id="p-name" name="name" value="<?= e(old('name', $user['name'])) ?>" required autocomplete="name"><?= $err('name') ?></div>
        <div class="field"><label for="p-email">Email</label>
          <input id="p-email" value="<?= e($user['email']) ?>" disabled>
          <small class="hint">Message us to change the email on your account.</small></div>
        <div class="field"><label for="p-phone">Phone <span class="optional">(optional)</span></label>
          <input id="p-phone" name="phone" value="<?= e(old('phone', $user['phone'])) ?>" autocomplete="tel" inputmode="tel"><?= $err('phone') ?></div>
        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </div>
    <div class="panel">
      <div class="ptitle"><svg class="icon"><use href="#i-lock"></use></svg> Password</div>
      <form method="post" action="<?= e(url('account/profile')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="password">
        <div class="field"><label for="p-cur">Current password</label>
          <input id="p-cur" type="password" name="current" required autocomplete="current-password"><?= $err('current') ?></div>
        <div class="field"><label for="p-new">New password</label>
          <input id="p-new" type="password" name="password" required minlength="8" autocomplete="new-password"><?= $err('password') ?></div>
        <div class="field"><label for="p-new2">Repeat it</label>
          <input id="p-new2" type="password" name="password_confirm" required minlength="8" autocomplete="new-password"><?= $err('password_confirm') ?></div>
        <button class="btn btn-primary" type="submit">Change password</button>
      </form>
    </div>
  </div>
  <?php if ($twoFactor['available'] || $twoFactor['google']): ?>
    <div class="panel">
      <div class="ptitle"><svg class="icon"><use href="#i-shield"></use></svg> Sign-in security</div>
      <?php if ($twoFactor['available']): ?>
        <div class="tf-state<?= $twoFactor['on'] ? ' on' : '' ?>">
          <svg class="icon"><use href="#<?= $twoFactor['on'] ? 'i-check' : 'i-lock' ?>"></use></svg>
          <span><b>Two-step sign-in is <?= $twoFactor['on'] ? 'on' : 'off' ?>.</b>
            <?= $twoFactor['on'] ? 'After your password we email a 6-digit code to ' . e($user['email']) . '.'
                                 : 'Your password alone signs you in.' ?></span>
        </div>
        <p class="help">With it on, someone who learns your password still cannot get into your wallet without
          the code from your inbox.</p>
        <form method="post" action="<?= e(url('account/profile')) ?>" class="tf-form">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="two_factor">
          <input type="hidden" name="enable" value="<?= $twoFactor['on'] ? '0' : '1' ?>">
          <div class="field"><label for="tf-cur">Your password, to confirm</label>
            <input id="tf-cur" type="password" name="current" required autocomplete="current-password"><?= $err('tf_current') ?></div>
          <button class="btn <?= $twoFactor['on'] ? 'btn-ghost' : 'btn-primary' ?>" type="submit">
            <?= $twoFactor['on'] ? 'Turn it off' : 'Turn it on' ?></button>
        </form>
      <?php endif; ?>
      <?php if ($twoFactor['google']): ?>
        <p class="help"><b>Google sign-in is linked.</b> Signing in with Google does not ask for the emailed code -
          Google has already checked it is you.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
