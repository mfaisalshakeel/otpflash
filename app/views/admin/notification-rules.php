<?php
/**
 * One row per event, two switches each.
 * @var array $events  @var bool $mailReady  @var string $adminMail
 */
?>
<form method="post" action="<?= e(url('admin/notifications/rules')) ?>">
  <?= csrf_field() ?>

  <?php if (!$mailReady): ?>
    <div class="alert alert-warning">
      No mail server is set up, so the email column does nothing yet. Fill it in
      under <a href="<?= e(url('admin/settings')) ?>">Settings &rarr; Mail</a>.
      The in-panel column works either way.
    </div>
  <?php endif; ?>

  <div class="box">
    <div class="box-head"><b>Events</b></div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>What happens</th>
            <th>Goes to</th>
            <th class="ta-c">In the panel</th>
            <th class="ta-c">By email</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $key => $event): ?>
          <?php $always = !empty($event['always']); ?>
          <tr>
            <td data-label="What happens">
              <b><?= e($event['label']) ?></b>
              <small class="sub"><?= e($event['when']) ?></small>
            </td>
            <td data-label="Goes to">
              <span class="chipx"><?= $event['to'] === 'admin' ? 'You' : 'The customer' ?></span>
            </td>
            <td class="ta-c" data-label="In the panel">
              <?php if ($event['to'] !== 'admin'): ?>
                <span class="muted" title="This one is for the customer, not for you">&mdash;</span>
              <?php elseif ($always): ?>
                <span class="muted" title="Always on">always</span>
              <?php else: ?>
                <label class="sw">
                  <input type="checkbox" name="panel[<?= e($key) ?>]"
                    <?= setting('notify_' . $key . '_panel', '1') === '1' ? 'checked' : '' ?>>
                  <span></span>
                </label>
              <?php endif; ?>
            </td>
            <td class="ta-c" data-label="By email">
              <?php if ($always): ?>
                <span class="muted" title="A sign-in code is only useful by email">always</span>
              <?php else: ?>
                <label class="sw">
                  <input type="checkbox" name="mail[<?= e($key) ?>]"
                    <?= setting('notify_' . $key . '_mail', notification_mail_default($key)) === '1' ? 'checked' : '' ?>>
                  <span></span>
                </label>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="box" style="margin-top:16px">
    <div class="box-head"><b>Where your mail goes</b></div>
    <div class="pad">
      <div class="field2">
        <div class="field">
          <label for="notify_email">Send my notifications to</label>
          <input id="notify_email" type="email" name="notify_email"
                 value="<?= e((string) setting('notify_email', '')) ?>"
                 placeholder="<?= e($adminMail ?: 'you@example.com') ?>">
          <small>Leave it blank to use the support address, then your account's.</small>
        </div>
        <div class="field">
          <label for="low_balance_threshold">Warn me when a provider drops below</label>
          <input id="low_balance_threshold" type="number" step="0.01" min="0"
                 name="low_balance_threshold"
                 value="<?= e((string) setting('low_balance_threshold', '0')) ?>">
          <small>In your own currency. Zero switches the check off.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="formfoot">
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-ghost" href="<?= e(url('admin/notifications')) ?>">Back to the feed</a>
  </div>
</form>
