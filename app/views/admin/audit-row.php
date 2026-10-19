<?php
/** One entry, before beside after. @var array $row @var array $before @var array $after */
$fields = array_unique(array_merge(array_keys($before), array_keys($after)));
?>
<div class="box">
  <div class="box-head">
    <b><?= e($row['action']) ?></b>
    <span class="st st-<?= $row['severity'] === 'alert' ? 'api_error'
        : ($row['severity'] === 'warn' ? 'pending' : 'completed') ?>">
      <?= e($row['severity']) ?>
    </span>
  </div>
  <div class="pad">
    <?php if ($row['summary'] !== ''): ?><p><?= e($row['summary']) ?></p><?php endif; ?>
    <dl class="au-meta">
      <div><dt>When</dt><dd><?= e(when($row['created_at'], 'd M Y, H:i:s')) ?></dd></div>
      <div><dt>Who</dt><dd><?= e($row['actor_name'] ?: $row['actor_type']) ?>
        <small class="muted">(<?= e($row['actor_type']) ?>)</small></dd></div>
      <div><dt>From</dt><dd class="mono"><?= e($row['ip'] ?: 'not recorded') ?></dd></div>
      <?php if ($row['entity'] !== ''): ?>
        <div><dt>Record</dt><dd><?= e($row['entity']) ?> #<?= (int) $row['entity_id'] ?></dd></div>
      <?php endif; ?>
    </dl>
  </div>
</div>

<?php if ($fields): ?>
  <div class="box" style="margin-top:16px">
    <div class="box-head"><b>What changed</b></div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Field</th><th>Was</th><th>Became</th></tr></thead>
        <tbody>
        <?php foreach ($fields as $field): ?>
          <tr>
            <td data-label="Field"><b><?= e($field) ?></b></td>
            <td class="mono au-was" data-label="Was"><?= e((string) ($before[$field] ?? '—')) ?></td>
            <td class="mono au-now" data-label="Became"><?= e((string) ($after[$field] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="formfoot">
  <a class="btn btn-ghost" href="<?= e(url('admin/audit')) ?>">Back to the log</a>
</div>
