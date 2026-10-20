<?php
/** What went out. @var array $rows  @var array $events  @var array $paging */
?>
<div class="filters">
  <a class="btn btn-ghost" href="<?= e(url('admin/emails')) ?>">Back to templates</a>
  <?php partial('admin/_clear-log', ['kind' => 'emails', 'back' => 'admin/emails/log', 'inline' => true]); ?>
</div>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>When</th><th>To</th><th>Message</th><th>Result</th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="empty">Nothing has been sent yet.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td data-label="When"><?= e(when($row['created_at'], 'd M, H:i')) ?></td>
          <td data-label="To"><?= e($row['recipient']) ?><small class="sub"><?= e(excerpt($row['subject'], 48)) ?></small></td>
          <td data-label="Message">
            <?= e($events[$row['event']]['label'] ?? ($row['event'] ?: 'Test')) ?>
          </td>
          <td data-label="Result">
            <span class="st st-<?= $row['status'] === 'sent' ? 'completed' : 'api_error' ?>">
              <?= e($row['status']) ?>
            </span>
            <?php if ($row['error'] !== ''): ?>
              <small class="sub"><?= e($row['error']) ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/emails/log', 'noun' => 'email',
      'shown' => count($rows)]); ?>
</div>
