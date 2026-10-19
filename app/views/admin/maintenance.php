<?php
/**
 * Maintenance.
 *
 * One box per job, each carrying its own confirmation box. A single shared
 * form would let the wrong button be pressed against the wrong typing.
 *
 * @var array $jobs
 */
?>
<div class="alert alert-warning" style="margin-bottom:18px">
  Everything on this screen is permanent. There is no undo and no bin to
  fish anything back out of &mdash; take a database backup first if the data
  matters.
</div>

<?php foreach ($jobs as $key => $job): ?>
  <div class="box" style="max-width:720px;margin-bottom:16px">
    <div class="box-head">
      <b><?= e($job['label']) ?></b>
      <?php if ($job['total'] > 0): ?>
        <span class="st st-pending"><?= e(qty_fmt($job['total'])) ?> row(s)</span>
      <?php else: ?>
        <span class="st st-cancelled">nothing to delete</span>
      <?php endif; ?>
    </div>
    <div class="pad">
      <p class="muted" style="margin-bottom:14px"><?= e($job['blurb']) ?></p>

      <?php if ($job['counts']): ?>
        <div class="chiprow" style="margin-bottom:14px">
          <?php foreach ($job['counts'] as $table => $count): ?>
            <span class="chipx"><?= e(qty_fmt($count)) ?>
              <?= e(str_replace('_', ' ', (string) $table)) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($job['total'] > 0): ?>
        <form method="post" action="<?= e(url('admin/maintenance/run')) ?>"
              data-confirm="<?= e($job['button']) ?>? This cannot be undone.">
          <?= csrf_field() ?>
          <input type="hidden" name="job" value="<?= e($key) ?>">
          <div class="field" style="max-width:240px">
            <label for="c-<?= e($key) ?>">Type DELETE to confirm</label>
            <input id="c-<?= e($key) ?>" name="confirm" autocomplete="off" placeholder="DELETE" required>
          </div>
          <button class="btn btn-danger" type="submit"><?= e($job['button']) ?></button>
        </form>
      <?php else: ?>
        <p class="muted" style="margin:0">Nothing here to delete.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
