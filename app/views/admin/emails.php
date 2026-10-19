<?php
/**
 * The template list.
 * @var array $rows  @var array $events  @var bool $mailReady
 * @var string $adminMail  @var int $failed
 */
?>
<div class="filters">
  <a class="btn btn-ghost" href="<?= e(url('admin/emails/log')) ?>">
    <svg class="icon"><use href="#i-list"></use></svg> Send log
    <?php if ($failed): ?><span class="pill pill-danger"><?= qty_fmt($failed) ?></span><?php endif; ?>
  </a>
  <a class="btn btn-ghost" href="<?= e(url('admin/notifications/rules')) ?>">
    Which ones are sent
  </a>
</div>

<?php if (!$mailReady): ?>
  <div class="alert alert-warning">
    <b>Nothing can be sent yet.</b> Choose a driver and fill in the server under
    <a href="<?= e(url('admin/settings')) ?>">Settings &rarr; Mail</a>. You can still
    write the messages here in the meantime.
  </div>
<?php endif; ?>

<div class="box">
  <div class="box-head">
    <b>Messages</b>
    <form method="post" action="<?= e(url('admin/emails/test')) ?>" class="testform">
      <?= csrf_field() ?>
      <input type="email" name="to" placeholder="<?= e($adminMail ?: 'you@example.com') ?>"
             aria-label="Send a test to">
      <button class="btn btn-ghost btn-sm" type="submit" <?= $mailReady ? '' : 'disabled' ?>>
        Send a test
      </button>
    </form>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Message</th><th>Subject</th><th>Goes to</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $event = $events[$row['event']] ?? null; if (!$event) { continue; } ?>
        <tr>
          <td data-label="Message">
            <b><?= e($event['label']) ?></b>
            <small class="sub"><?= e($event['when']) ?></small>
          </td>
          <td data-label="Subject"><?= e(excerpt($row['subject'], 60)) ?></td>
          <td data-label="Goes to">
            <span class="chipx"><?= $event['to'] === 'admin' ? 'You' : 'The customer' ?></span>
          </td>
          <td class="ta-r">
            <div class="rowacts">
              <form method="post" action="<?= e(url('admin/emails/restore')) ?>"
                    data-confirm="Put this message back to the wording it shipped with?">
                <?= csrf_field() ?>
                <input type="hidden" name="event" value="<?= e($row['event']) ?>">
                <button class="iact" type="submit" title="Restore the default wording"
                        aria-label="Restore the default wording">
                  <svg class="icon"><use href="#i-refresh"></use></svg>
                </button>
              </form>
              <form method="post" action="<?= e(url('admin/emails/toggle')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button class="iact <?= $row['is_active'] ? 'iact-on' : 'iact-off' ?>" type="submit"
                        title="<?= $row['is_active'] ? 'Stop sending this' : 'Start sending this' ?>"
                        aria-label="<?= $row['is_active'] ? 'Stop sending this' : 'Start sending this' ?>">
                  <svg class="icon"><use href="#i-power"></use></svg>
                </button>
              </form>
              <a class="iact iact-edit" href="<?= e(url('admin/emails/edit/' . $row['id'])) ?>"
                 title="Edit" aria-label="Edit">
                <svg class="icon"><use href="#i-edit"></use></svg>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
