<?php
/**
 * One blog post.
 *
 * @var array $post @var string $body @var array $toc @var int $minutes @var array $related
 * @var array $crumbs @var bool $preview @var array $tags
 */
$link  = url('blog/' . $post['slug']);
$share = rawurlencode($post['title'] . ' ' . $link);
?>
<?php if ($preview): ?>
  <div class="preview-bar">Preview - this post is <b><?= e(post_state($post)) ?></b> and only admins can see it.
    <a href="<?= e(url('admin/posts/edit/' . $post['id'])) ?>">Back to the editor</a></div>
<?php endif; ?>

<article class="post" itemscope itemtype="https://schema.org/BlogPosting">
  <header class="wrap post-head">
    <nav class="crumbs crumbs-dark" aria-label="Breadcrumb">
      <?php foreach ($crumbs as $i => [$name, $url]): ?>
        <?php if ($i): ?><span aria-hidden="true">/</span><?php endif; ?>
        <?php if ($i === count($crumbs) - 1): ?><span aria-current="page"><?= e(excerpt($name, 40)) ?></span>
        <?php else: ?><a href="<?= e($url) ?>"><?= e($name) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php if (!empty($post['category_slug'])): ?>
      <a class="pcard-cat" href="<?= e(url('blog/category/' . $post['category_slug'])) ?>"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <h1 itemprop="headline"><?= e($post['title']) ?></h1>
    <div class="post-meta">
      <span><?= e($post['author_name'] !== '' ? $post['author_name'] : setting('site_name', 'OTP Flash') . ' team') ?></span>
      <time datetime="<?= e(date('c', strtotime((string) ($post['published_at'] ?: $post['created_at'])))) ?>" itemprop="datePublished">
        <?= e(when($post['published_at'] ?: $post['created_at'], 'd M Y')) ?></time>
      <?php if (!empty($post['updated_at']) && strtotime((string) $post['updated_at']) - strtotime((string) $post['published_at']) > 86400): ?>
        <span>Updated <?= e(when($post['updated_at'], 'd M Y')) ?></span>
      <?php endif; ?>
      <span><?= $minutes ?> min read</span>
    </div>
  </header>

  <div class="wrap blog-layout">
    <div>
      <?php if ($post['cover_path'] !== ''): ?>
        <figure class="post-cover"><img src="<?= e(url($post['cover_path'])) ?>" alt="<?= e(($post['image_alt'] ?? '') !== '' ? $post['image_alt'] : $post['title']) ?>"
             width="1200" height="600" fetchpriority="high" itemprop="image"></figure>
      <?php endif; ?>

      <div class="prose prose-card" itemprop="articleBody">
        <?php if ($toc): ?>
          <nav class="toc" aria-label="Contents">
            <b>In this guide</b>
            <ol>
              <?php foreach ($toc as $item): ?>
                <li class="toc-<?= (int) $item['level'] ?>"><a href="#<?= e($item['id']) ?>"><?= e($item['text']) ?></a></li>
              <?php endforeach; ?>
            </ol>
          </nav>
        <?php endif; ?>
        <?= $body /* trusted: written by an admin in the editor */ ?>

        <?php if ($tags): ?>
          <div class="post-tags">
            <?php foreach ($tags as $tag): ?>
              <a href="<?= e(url('blog/tag/' . $tag)) ?>">#<?= e($tag) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="share">Share
          <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= e($share) ?>" target="_blank" rel="noopener">WhatsApp</a>
          <a class="btn btn-ghost btn-sm" href="https://t.me/share/url?url=<?= e(rawurlencode($link)) ?>&amp;text=<?= e(rawurlencode($post['title'])) ?>" target="_blank" rel="noopener">Telegram</a>
          <a class="btn btn-ghost btn-sm" href="https://x.com/intent/post?text=<?= e($share) ?>" target="_blank" rel="noopener">X</a>
          <a class="btn btn-ghost btn-sm" href="https://www.facebook.com/sharer/sharer.php?u=<?= e(rawurlencode($link)) ?>" target="_blank" rel="noopener">Facebook</a>
          <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($link) ?>">Copy link</button>
        </div>
      </div>
    </div>

    <aside class="blog-side">
      <div class="panel">
        <div class="side-cta">
          <b>Get your code in seconds</b>
          <p>Virtual numbers for WhatsApp, Telegram, Google and more. Refunded automatically if no SMS arrives.</p>
          <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Buy a number</a>
        </div>
        <?php if ($toc): ?>
          <div class="ptitle">On this page</div>
          <ul class="blog-cats toc-side">
            <?php foreach ($toc as $item): ?>
              <?php if ($item['level'] === 2): ?><li><a href="#<?= e($item['id']) ?>"><?= e($item['text']) ?></a></li><?php endif; ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</article>

<?php if ($related): ?>
  <section class="section section-soft">
    <div class="wrap">
      <div class="section-head section-head-row"><div><span class="eyebrow">Keep reading</span><h2>More guides</h2></div></div>
      <div class="posts">
        <?php foreach ($related as $item): ?>
          <?php partial('partials/post-card', ['post' => $item]); ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>
