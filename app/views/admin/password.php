<?php /** @var array $errors */ ?>
<form method="post" action="<?= e(url('admin/password')) ?>" class="split2">
  <?= csrf_field() ?>
  <div class="box">
    <div class="box-head"><b>Change your password</b></div>
    <div class="pad">
      <div class="field">
        <label for="f-cur">Current password</label>
        <input id="f-cur" type="password" name="current_password" required autocomplete="current-password"
               <?= isset($errors['current_password']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['current_password'])): ?>
          <span class="err"><?= e($errors['current_password']) ?></span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="f-new">New password</label>
        <input id="f-new" type="password" name="new_password" required minlength="8"
               autocomplete="new-password"
               <?= isset($errors['new_password']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['new_password'])): ?>
          <span class="err"><?= e($errors['new_password']) ?></span>
        <?php else: ?><small>At least 8 characters.</small><?php endif; ?>
      </div>
      <div class="field">
        <label for="f-new2">Repeat new password</label>
        <input id="f-new2" type="password" name="new_password_confirm" required minlength="8"
               autocomplete="new-password"
               <?= isset($errors['new_password_confirm']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['new_password_confirm'])): ?>
          <span class="err"><?= e($errors['new_password_confirm']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div>
    <div class="box">
      <div class="box-head"><b>Save</b></div>
      <div class="pad">
        <button class="btn btn-primary btn-block btn-lg" type="submit">Change password</button>
      </div>
    </div>
  </div>
</form>
