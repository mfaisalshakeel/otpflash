<?php
/**
 * What an import would do, row by row, before it does it.
 *
 * @var array  $checked  blog_csv_check()
 * @var array  $unknown  column names the file had that we do not use
 * @var string $token    the held file
 * @var array  $options  how it was asked to publish
 */
$counts = ['create' => 0, 'replace' => 0, 'skip' => 0];
$states = ['published' => 0, 'scheduled' => 0, 'draft' => 0];
foreach ($checked as $item) {
    $counts[$item['action']]++;
    if ($item['action'] !== 'skip') {
        $states[$item['state']]++;
    }
}
$going = $counts['create'] + $counts['replace'];
$warned = count(array_filter($checked, static fn (array $item): bool => $item['action'] !== 'skip' && $item['warnings']));
?>
<a class="lnk back" href="<?= e(url('admin/posts/import')) ?>">&larr; Choose another file</a>

<div class="cards4">
  <div class="scard"><div class="si si-b"><svg class="icon"><use href="#i-plus"></use></svg></div>
    <div><small>New posts</small><b><?= qty_fmt($counts['create']) ?></b>
      <?php if ($counts['replace']): ?><span class="muted"><?= qty_fmt($counts['replace']) ?> replacing</span><?php endif; ?></div></div>
  <div class="scard"><div class="si si-a"><svg class="icon"><use href="#i-calendar"></use></svg></div>
    <div><small>Scheduled</small><b><?= qty_fmt($states['scheduled']) ?></b>
      <span class="muted"><?= qty_fmt($states['published']) ?> live now, <?= qty_fmt($states['draft']) ?> drafts</span></div></div>
  <div class="scard"><div class="si si-c"><svg class="icon"><use href="#i-alert"></use></svg></div>
    <div><small>With something to fix</small><b><?= qty_fmt($warned) ?></b><span class="muted">imported anyway</span></div></div>
  <div class="scard"><div class="si si-d"><svg class="icon"><use href="#i-close"></use></svg></div>
    <div><small>Left out</small><b><?= qty_fmt($counts['skip']) ?></b><span class="muted">duplicates and errors</span></div></div>
</div>

<?php if ($unknown): ?>
  <div class="alert alert-info">Columns not used: <?= e(implode(', ', $unknown)) ?>.</div>
<?php endif; ?>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl import-tbl">
      <thead><tr><th>Line</th><th>Post</th><th>Goes out</th><th>Words</th><th>Links</th><th>Checks</th></tr></thead>
      <tbody>
      <?php foreach ($checked as $item): ?>
        <?php $data = $item['data']; ?>
        <tr class="import-<?= e($item['action']) ?>">
          <td class="mono"><?= (int) $item['line'] ?></td>
          <td>
            <b><?= e($data['title'] !== '' ? $data['title'] : '(no title)') ?></b>
            <small class="sub mono">/blog/<?= e($data['slug']) ?></small>
            <small class="sub"><?= e($data['category_name'] !== '' ? $data['category_name'] : 'No category') ?>
              <?= $data['focus_keyword'] !== '' ? '&middot; ' . e($data['focus_keyword']) : '' ?></small>
          </td>
          <td>
            <?php if ($item['action'] === 'skip'): ?>
              <span class="st st-cancelled">left out</span>
            <?php elseif ($item['state'] === 'scheduled'): ?>
              <span class="st st-processing">scheduled</span><small class="sub"><?= e(when($data['published_at'], 'd M Y, H:i')) ?></small>
            <?php elseif ($item['state'] === 'published'): ?>
              <span class="st st-completed">live now</span>
            <?php else: ?>
              <span class="st st-pending">draft</span>
            <?php endif; ?>
            <?php if ($item['action'] === 'replace'): ?><small class="sub">replaces the old post</small><?php endif; ?>
          </td>
          <td><?= qty_fmt($data['words']) ?></td>
          <td><?= (int) $data['links'] ?></td>
          <td>
            <?php if (!$item['problems'] && !$item['warnings']): ?>
              <span class="import-ok"><svg class="icon"><use href="#i-check"></use></svg> All good</span>
            <?php endif; ?>
            <ul class="import-notes">
              <?php foreach ($item['problems'] as $problem): ?><li class="import-problem"><?= e($problem) ?></li><?php endforeach; ?>
              <?php foreach ($item['warnings'] as $warning): ?><li class="import-warning"><?= e($warning) ?></li><?php endforeach; ?>
            </ul>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<form method="post" action="<?= e(url('admin/posts/import/save')) ?>" class="import-actions">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <input type="hidden" name="mode" value="<?= e($options['mode']) ?>">
  <input type="hidden" name="start" value="<?= e($options['start']) ?>">
  <input type="hidden" name="every_hours" value="<?= (int) $options['every_hours'] ?>">
  <?php if ($options['update']): ?><input type="hidden" name="update" value="1"><?php endif; ?>
  <a class="btn btn-ghost" href="<?= e(url('admin/posts/import')) ?>">Cancel</a>
  <button class="btn btn-primary" type="submit" <?= $going ? '' : 'disabled' ?>>
    Import <?= qty_fmt($going) ?> post<?= $going === 1 ? '' : 's' ?></button>
</form>
