<?php
/**
 * Clear a log - all of it, or what is older than so many days.
 *
 * One small form, drawn on each log's own screen and again on Maintenance,
 * so the button is where the clutter is and the rule (logs.php) is in one place.
 *
 * @var string $kind   a key of LOG_KINDS
 * @var string $back   where to come back to afterwards
 * @var bool   $inline drawn in a filter bar rather than in a box
 */
require_once APP_PATH . '/helpers/logs.php';
$info   = LOG_KINDS[$kind];
$inline = $inline ?? false;
$total  = log_count($kind);
?>
<form method="post" action="<?= e(url('admin/maintenance/logs')) ?>" class="clearlog<?= $inline ? ' clearlog-inline' : '' ?>"
      data-confirm="Clear the <?= e(strtolower($info['label'])) ?>? This cannot be undone.">
  <?= csrf_field() ?>
  <input type="hidden" name="kind" value="<?= e($kind) ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <select name="days" aria-label="Which entries to clear">
    <?php foreach (LOG_CLEAR_AGES as $days => $label): ?>
      <option value="<?= (int) $days ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-danger<?= $inline ? '' : ' btn-sm' ?>" type="submit" <?= $total ? '' : 'disabled' ?>
          title="<?= $total ? e(qty_fmt($total) . ' ' . plural('entry', $total) . ' in this log') : 'Nothing to clear' ?>">
    <svg class="icon"><use href="#i-trash"></use></svg> Clear log</button>
</form>
