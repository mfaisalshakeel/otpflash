<?php
/**
 * The blog, a category or a tag: the same page with a different heading.
 *
 * @var string $heading @var string $lead @var array $posts @var ?array $featured @var int $total
 * @var int $page @var int $pages @var string $base @var string $search @var array $categories
 * @var string $current @var array $trail
 */
$pageUrl = static fn (int $n): string => url($base) . ($n > 1 ? '?page=' . $n : '');
?>
<section class="page-head">
  <div class="wrap">
    <nav class="crumbs crumbs-dark" aria-label="Breadcrumb">
      <?php foreach ($trail as $i => [$name, $link]): ?>
        <?php if ($i): ?><span aria-hidden="true">/</span><?php endif; ?>
        <?php if ($i === count($trail) - 1): ?><span aria-current="page"><?= e($name) ?></span>
        <?php else: ?><a href="<?= e($link) ?>"><?= e($name) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <h1><?= e($heading) ?></h1>
    <?php if ($lead !== ''): ?><p><?= e($lead) ?></p><?php endif; ?>
  </div>
</section>

<section class="section section-tight">
  <div class="wrap blog-layout">
    <div>
      <?php if ($search !== ''): ?>
        <p class="blog-found"><?= qty_fmt($total) ?> result<?= $total === 1 ? '' : 's' ?> for <b>“<?= e($search) ?>”</b>
          &middot; <a href="<?= e(url($base)) ?>">clear</a></p>
      <?php endif; ?>

      <?php if ($featured): ?>
        <article class="pfeature">
          <a class="pfeature-img" href="<?= e(url('blog/' . $featured['slug'])) ?>" tabindex="-1" aria-hidden="true">
            <?php if ($featured['cover_path'] !== ''): ?>
              <img src="<?= e(url($featured['cover_path'])) ?>" alt="" width="800" height="450" fetchpriority="high">
            <?php else: ?><span class="pcard-ph"><svg class="icon"><use href="#i-doc"></use></svg></span><?php endif; ?>
          </a>
          <div class="pfeature-body">
            <span class="pcard-cat">Featured<?= !empty($featured['category_name']) ? ' · ' . e($featured['category_name']) : '' ?></span>
            <h2><a href="<?= e(url('blog/' . $featured['slug'])) ?>"><?= e($featured['title']) ?></a></h2>
            <p><?= e(excerpt($featured['excerpt'], 220)) ?></p>
            <time datetime="<?= e(date('c', strtotime((string) $featured['published_at']))) ?>"><?= e(when($featured['published_at'], 'd M Y')) ?></time>
          </div>
        </article>
      <?php endif; ?>

      <?php if ($posts): ?>
        <div class="posts posts-2">
          <?php foreach ($posts as $post): ?>
            <?php partial('partials/post-card', ['post' => $post]); ?>
          <?php endforeach; ?>
        </div>
      <?php elseif (!$featured): ?>
        <div class="empty-state"><b><?= $search !== '' ? 'Nothing matches that' : 'No posts here yet' ?></b>
          <?= $search !== '' ? 'Try a shorter word, or browse the categories.' : 'Check back soon - guides are on the way.' ?></div>
      <?php endif; ?>

      <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Pages">
          <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>" rel="prev" aria-label="Previous page">&larr;</a><?php endif; ?>
          <?php for ($n = max(1, $page - 2); $n <= min($pages, $page + 2); $n++): ?>
            <?php if ($n === $page): ?><span class="on" aria-current="page"><?= $n ?></span>
            <?php else: ?><a href="<?= e($pageUrl($n)) ?>"><?= $n ?></a><?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>" rel="next" aria-label="Next page">&rarr;</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>

    <aside class="blog-side">
      <div class="panel">
        <form class="blog-search" method="get" action="<?= e(url('blog')) ?>" role="search">
          <svg class="icon"><use href="#i-search"></use></svg>
          <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search the blog" aria-label="Search the blog">
        </form>
        <?php if ($categories): ?>
          <div class="ptitle">Categories</div>
          <ul class="blog-cats">
            <li><a href="<?= e(url('blog')) ?>"<?= $current === '' && $base === 'blog' ? ' class="on"' : '' ?>>All posts</a></li>
            <?php foreach ($categories as $cat): ?>
              <?php if ((int) $cat['posts'] === 0) { continue; } ?>
              <li><a href="<?= e(url('blog/category/' . $cat['slug'])) ?>"<?= $current === $cat['slug'] ? ' class="on"' : '' ?>>
                <?= e($cat['name']) ?> <span><?= (int) $cat['posts'] ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="side-cta">
          <b>Need a number now?</b>
          <p>Pick an app and a country - the code arrives in seconds.</p>
          <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Buy a number</a>
        </div>
      </div>
    </aside>
  </div>
</section>
