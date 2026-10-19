<?php
/**
 * Two-factor: where it stands, and how to change it.
 *
 * @var array  $admin   @var ?array $pending  @var int $codesLeft
 * @var ?array $freshCodes  @var bool $mailReady
 */
$mode = (string) ($admin['two_factor'] ?? 'off');
$on   = $mode !== 'off';
?>

<?php if ($freshCodes): ?>
  <div class="box rc-box">
    <div class="box-head">
      <b>Your recovery codes</b>
      <button class="btn btn-ghost btn-sm" type="button"
              data-copy="<?= e(implode("\n", $freshCodes)) ?>">
        <svg class="icon"><use href="#i-copy"></use></svg> Copy all
      </button>
    </div>
    <div class="pad">
      <p class="muted">
        Each one works once, and this is the only time they are shown - they are
        stored hashed, so nobody can read them back out, us included. Keep them
        somewhere that is not the device holding your codes.
      </p>
      <ol class="rc-list">
        <?php foreach ($freshCodes as $code): ?>
          <li class="mono"><?= e($code) ?></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
<?php endif; ?>

<div class="<?= $pending ? 'split2' : '' ?>">
  <div>
    <div class="box">
      <div class="box-head">
        <b>Status</b>
        <span class="st <?= $on ? 'st-completed' : 'st-cancelled' ?>">
          <?= $on ? 'on' : 'off' ?>
        </span>
      </div>
      <div class="pad">
        <?php if ($on): ?>
          <p>
            Signing in asks for a code after your password:
            <b><?= $mode === 'email' ? 'emailed to ' . e($admin['email'])
                                     : 'from your authenticator app' ?></b>.
          </p>
          <p class="muted">
            <?= (int) $codesLeft ?> recovery code<?= $codesLeft === 1 ? '' : 's' ?> left.
            <?php if ($codesLeft === 0): ?>
              <b class="warn">None left - make a new set before you lose the
              <?= $mode === 'email' ? 'inbox' : 'phone' ?>.</b>
            <?php endif; ?>
          </p>

          <form method="post" action="<?= e(url('admin/security/codes')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <div class="field">
              <label for="codepass">Your password</label>
              <input id="codepass" type="password" name="password" required
                     autocomplete="current-password">
            </div>
            <button class="btn btn-ghost" type="submit">New recovery codes</button>
          </form>

          <hr class="rule">

          <form method="post" action="<?= e(url('admin/security/disable')) ?>"
                class="inline-form"
                data-confirm="Turn two-factor off? Your password alone will then be enough to sign in.">
            <?= csrf_field() ?>
            <div class="field">
              <label for="offpass">Your password</label>
              <input id="offpass" type="password" name="password" required
                     autocomplete="current-password">
            </div>
            <button class="btn btn-danger" type="submit">Turn two-factor off</button>
          </form>

        <?php else: ?>
          <p>
            Your password is the only thing between the internet and this panel.
            A second step means a leaked password on its own reaches nothing.
          </p>
          <p class="muted">Pick one of the two on the right.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($pending): ?>
    <div>
      <div class="box">
        <div class="box-head"><b>Authenticator app</b><span class="chipx">recommended</span></div>
        <div class="pad">
          <p class="muted">
            Scan this with Google Authenticator, Authy, 1Password or any other
            TOTP app. It keeps working when your email does not.
          </p>

          <div class="qr-wrap"><?= $pending['qr'] /* our own SVG, no user input */ ?></div>

          <p class="muted small">Cannot scan? Type this in instead:</p>
          <p class="secret mono" data-copy="<?= e($pending['secret']) ?>">
            <?= e($pending['readable']) ?>
            <svg class="icon"><use href="#i-copy"></use></svg>
          </p>

          <form method="post" action="<?= e(url('admin/security/enable')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="totp">
            <div class="field">
              <label for="code">The six digits it shows now</label>
              <input id="code" name="code" required inputmode="numeric" maxlength="6"
                     class="code-input" placeholder="000000" autocomplete="one-time-code">
              <small>Confirming proves the app and this server agree on the time.</small>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Turn it on</button>
          </form>
        </div>
      </div>

      <div class="box" style="margin-top:16px">
        <div class="box-head"><b>Email a code instead</b></div>
        <div class="pad">
          <?php if ($mailReady): ?>
            <p class="muted">
              A six-digit code goes to <b><?= e($admin['email']) ?></b> each time you
              sign in. Simpler, but only as safe as that inbox.
            </p>
            <form method="post" action="<?= e(url('admin/security/enable')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="mode" value="email">
              <button class="btn btn-ghost btn-block" type="submit">Use emailed codes</button>
            </form>
          <?php else: ?>
            <p class="muted">
              Needs a working mail server. Set one up under
              <a href="<?= e(url('admin/settings')) ?>">Settings &rarr; Mail</a>, then send
              yourself a test from <a href="<?= e(url('admin/emails')) ?>">Email templates</a>.
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
