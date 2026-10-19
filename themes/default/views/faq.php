<?php /** @var array $faqs */ ?>
<section class="page-head">
  <div class="wrap">
    <nav class="crumbs crumbs-dark" aria-label="Breadcrumb"><a href="<?= e(url('')) ?>">Home</a><span aria-hidden="true">/</span><span aria-current="page">FAQ</span></nav>
    <h1>Frequently asked questions</h1>
    <p>If your question is not here, message us and we will answer it.</p>
  </div>
</section>

<section class="section section-tight">
  <div class="wrap narrow">
    <?php if (!$faqs): ?>
      <div class="empty-state"><b>Nothing here yet</b>Questions will appear here soon.</div>
    <?php else: ?>
      <div class="faq">
        <?php foreach ($faqs as $i => $faq): ?>
          <details class="fitem"<?= $i === 0 ? ' open' : '' ?>>
            <summary><?= e($faq['question']) ?></summary>
            <p><?= e($faq['answer']) ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* The tracker without the script: the dialog's form, on a page. */ ?>
    <div class="panel track-box" id="track">
      <div class="ptitle"><svg class="icon"><use href="#i-search"></use></svg> Track an order</div>
      <form method="get" action="<?= e(url('track')) ?>" class="inline-form">
        <label class="sr-only" for="trackCode">Order code</label>
        <input id="trackCode" name="code" placeholder="e.g. OF-8F42KD3M" required autocomplete="off">
        <button class="btn btn-primary" type="submit">Open order</button>
      </form>
    </div>
  </div>
</section>
