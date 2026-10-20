<?php
/**
 * The record.
 * @var array $rows @var array $paging
 * @var string $search @var string $severity @var string $actor @var int $alerts
 */
$keep = static function (array $with) {
    return url('admin/audit') . '?' . http_build_query(array_filter(
        array_merge(['q' => $_GET['q'] ?? '', 'severity' => $_GET['severity'] ?? '',
                     'actor' => $_GET['actor'] ?? ''], $with), 'strlen'));
};
?>
<div class="filters">
  <form class="search wide" id="auditSearch" method="get" action="<?= e(url('admin/audit')) ?>">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($search) ?>" placeholder="Search what happened, or who">
    <input type="hidden" name="severity" value="<?= e($severity) ?>">
    <input type="hidden" name="actor" value="<?= e($actor) ?>">
  </form>
  <?php /* Outside the pill, through `form`: the selects beside it submit
           themselves and the search box had nothing to press. */ ?>
  <button class="btn btn-ghost" type="submit" form="auditSearch">Search</button>

  <form method="get" action="<?= e(url('admin/audit')) ?>" style="display:contents">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="actor" value="<?= e($actor) ?>">
    <select name="severity" onchange="this.form.submit()">
      <option value="">Everything</option>
      <?php foreach (['info' => 'Routine', 'warn' => 'Worth a look', 'alert' => 'Needs attention'] as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= $severity === $k ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <form method="get" action="<?= e(url('admin/audit')) ?>" style="display:contents">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="severity" value="<?= e($severity) ?>">
    <select name="actor" onchange="this.form.submit()">
      <option value="">Anyone</option>
      <?php foreach (['admin' => 'An admin', 'customer' => 'A customer',
                      'cron' => 'Cron', 'system' => 'The system'] as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= $actor === $k ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($search !== '' || $severity !== '' || $actor !== ''): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/audit')) ?>">Reset</a>
  <?php endif; ?>
  <?php partial('admin/_clear-log', ['kind' => 'audit', 'back' => 'admin/audit', 'inline' => true]); ?>
</div>

<?php if ($alerts && $severity !== 'alert'): ?>
  <div class="alert alert-error">
    <b><?= qty_fmt($alerts) ?></b> entr<?= $alerts === 1 ? 'y needs' : 'ies need' ?> attention -
    a refused sale, a rejected exchange rate or an order held back.
    <a href="<?= e($keep(['severity' => 'alert'])) ?>">Show them</a>.
  </div>
<?php endif; ?>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>When</th><th>What</th><th>Who</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="empty">Nothing recorded yet.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr class="au-<?= e($row['severity']) ?>">
          <td data-label="When"><?= e(when($row['created_at'], 'd M, H:i:s')) ?></td>
          <td data-label="What">
            <b><?= e($row['action']) ?></b>
            <?php if ($row['summary'] !== ''): ?>
              <small class="sub"><?= e($row['summary']) ?></small>
            <?php endif; ?>
          </td>
          <td data-label="Who">
            <span class="chipx"><?= e($row['actor_name'] ?: $row['actor_type']) ?></span>
            <small class="sub mono"><?= e($row['ip']) ?></small>
          </td>
          <td class="ta-r">
            <?php if ($row['before_json'] !== '' || $row['after_json'] !== ''): ?>
              <a class="iact iact-edit" href="<?= e(url('admin/audit/row/' . $row['id'])) ?>"
                 title="What changed" aria-label="What changed">
                <svg class="icon"><use href="#i-list"></use></svg>
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/audit', 'noun' => 'entry', 'shown' => count($rows),
      'query' => array_filter(['q' => $search, 'severity' => $severity, 'actor' => $actor], 'strlen')]); ?>
</div>
