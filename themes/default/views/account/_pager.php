<?php /** A front-site pager. @var array $paging  @var string $base  @var array $query */
if ($paging['pages'] < 2) { return; }
$link = static fn (int $n): string => url($base) . '?' . http_build_query(array_filter($query ?? [], 'strlen') + ['page' => $n]);
?>
<nav class="pager" aria-label="Pages">
  <?php if ($paging['page'] > 1): ?><a href="<?= e($link($paging['page'] - 1)) ?>" rel="prev" aria-label="Previous page">&larr;</a><?php endif; ?>
  <?php for ($n = max(1, $paging['page'] - 2); $n <= min($paging['pages'], $paging['page'] + 2); $n++): ?>
    <?php if ($n === $paging['page']): ?><span class="on" aria-current="page"><?= $n ?></span>
    <?php else: ?><a href="<?= e($link($n)) ?>"><?= $n ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($paging['page'] < $paging['pages']): ?><a href="<?= e($link($paging['page'] + 1)) ?>" rel="next" aria-label="Next page">&rarr;</a><?php endif; ?>
</nav>
