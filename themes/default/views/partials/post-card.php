<?php /** One blog post in a grid. @var array $post */ ?>
<article class="pcard">
  <a href="<?= e(url('blog/' . $post['slug'])) ?>" class="pcard-img" tabindex="-1" aria-hidden="true">
    <?php if (!empty($post['cover_path'])): ?>
      <img src="<?= e(url($post['cover_path'])) ?>" alt="" loading="lazy" decoding="async" width="600" height="340">
    <?php else: ?>
      <span class="pcard-ph"><svg class="icon"><use href="#i-doc"></use></svg></span>
    <?php endif; ?>
  </a>
  <div class="pcard-body">
    <?php if (!empty($post['category_name'])): ?>
      <a class="pcard-cat" href="<?= e(url('blog/category/' . $post['category_slug'])) ?>"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <h3><a href="<?= e(url('blog/' . $post['slug'])) ?>"><?= e($post['title']) ?></a></h3>
    <p><?= e(excerpt((string) ($post['excerpt'] ?? ''), 130)) ?></p>
    <time datetime="<?= e(date('c', strtotime((string) $post['published_at']))) ?>"><?= e(when($post['published_at'], 'd M Y')) ?></time>
  </div>
</article>
