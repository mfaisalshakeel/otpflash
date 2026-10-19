<?php /** A content page. @var array $page */ ?>
<section class="page-head">
  <div class="wrap">
    <nav class="crumbs crumbs-dark" aria-label="Breadcrumb"><a href="<?= e(url('')) ?>">Home</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($page['title']) ?></span></nav>
    <h1><?= e($page['title']) ?></h1>
  </div>
</section>
<section class="section section-tight">
  <div class="wrap narrow">
    <div class="prose prose-card"><?= $page['content'] /* trusted: written by an admin */ ?></div>
  </div>
</section>
