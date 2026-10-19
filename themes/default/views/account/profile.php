<?php /** @var array $user @var string $section @var array $errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="err">' . e($errors[$k]) . '</small>' : '';
?>
<div class="wrap portal">
  <div class="portal-grid">
    <?php partial('account/_nav', ['user' => $user, 'section' => $section]); ?>
    <div>
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
            <div class="field"><label for="p-phone">Phone <span class="opt">(optional)</span></label>
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
    </div>
  </div>
</div>
