<?php
/**
 * The bar under a list: what is being shown, and the way to the rest.
 *
 * Drawn by every list screen from paging(), so Prev/Next behave the same
 * everywhere and a screen cannot invent its own idea of a page.
 *
 * @var array  $paging  from paging()
 * @var string $base    the screen's own path, e.g. 'admin/services'
 * @var array  $query   the filters to carry between pages
 * @var string $noun    what is being counted
 * @var int    $shown   rows on this page
 */
$query = $query ?? [];
$noun  = $noun ?? 'row';
$shown = $shown ?? 0;

$link = static function (int $target) use ($base, $query): string {
    return url($base . '?' . http_build_query($query + ['page' => $target]));
};
?>
<div class="pager">
  <span>Showing <?= e(qty_fmt($shown)) ?> of <?= e(qty_fmt($paging['total'])) ?> <?=
    e(plural($noun, (int) $paging['total'])) ?></span>

  <?php if ($paging['pages'] > 1): ?>
    <div class="pbtns">
      <?php if ($paging['page'] > 1): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e($link($paging['page'] - 1)) ?>"
           title="Previous page" aria-label="Previous page">Prev</a>
      <?php endif; ?>

      <?php /* Two either side. A row of forty page numbers is not a way to
               find anything, and the search box above is. */ ?>
      <?php for ($p = max(1, $paging['page'] - 2); $p <= min($paging['pages'], $paging['page'] + 2); $p++): ?>
        <a class="btn btn-ghost btn-sm<?= $p === $paging['page'] ? ' on' : '' ?>"
           href="<?= e($link($p)) ?>"<?= $p === $paging['page'] ? ' aria-current="page"' : '' ?>><?= (int) $p ?></a>
      <?php endfor; ?>

      <?php if ($paging['page'] < $paging['pages']): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e($link($paging['page'] + 1)) ?>"
           title="Next page" aria-label="Next page">Next</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
