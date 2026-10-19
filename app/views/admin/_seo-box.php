<?php
/**
 * The search-result preview, the meta fields and the checklist - the same box
 * on a blog post and on a page, so the two are judged by the same rules.
 *
 * Lives inside a form marked [data-seo-form]; admin.js recounts it as you type.
 *
 * @var array  $row      the post or page
 * @var string $base     the address the slug is appended to, e.g. https://site/blog/
 * @var array  $checks   seo_checklist()
 * @var bool   $keyword  whether to offer a focus keyword
 */
$passed = count(array_filter($checks, static fn ($check) => $check['ok']));
$score  = $checks ? (int) round($passed / count($checks) * 100) : 0;
$tone   = $score >= 80 ? 'good' : ($score >= 50 ? 'fair' : 'poor');
?>
<div class="box">
  <div class="box-head"><b>Search appearance</b>
    <span class="seo-score seo-<?= e($tone) ?>" title="Checks passed when this page was loaded"><?= $score ?>/100</span></div>
  <div class="pad">
    <div class="serp" data-serp data-base="<?= e($base) ?>">
      <span class="serp-url" data-serp-url><?= e($base . $row['slug']) ?></span>
      <span class="serp-title" data-serp-title><?= e($row['meta_title'] !== '' ? $row['meta_title'] : ($row['title'] !== '' ? $row['title'] : 'Your title')) ?></span>
      <span class="serp-desc" data-serp-desc><?= e($row['meta_description']) ?></span>
    </div>

    <?php if ($keyword): ?>
      <div class="field">
        <label for="f-focus_keyword">Focus keyword</label>
        <input id="f-focus_keyword" name="focus_keyword" value="<?= e($row['focus_keyword']) ?>" maxlength="120"
               placeholder="e.g. whatsapp virtual number">
        <small>The phrase this should rank for. The checks below look for it.</small>
      </div>
    <?php endif; ?>

    <div class="field">
      <label for="f-meta_title">Search title <span class="len" data-len="meta_title" data-max="60"></span></label>
      <input id="f-meta_title" name="meta_title" value="<?= e($row['meta_title']) ?>" maxlength="190"
             placeholder="Defaults to the title">
    </div>
    <div class="field">
      <label for="f-meta_description">Meta description <span class="len" data-len="meta_description" data-max="160"></span></label>
      <textarea class="text" id="f-meta_description" name="meta_description" rows="3" maxlength="300"
                placeholder="One or two sentences that make someone click"><?= e($row['meta_description']) ?></textarea>
    </div>

    <ul class="seo-checks">
      <?php foreach ($checks as $check): ?>
        <li class="<?= $check['ok'] ? 'seo-pass' : 'seo-miss' ?>">
          <svg class="icon"><use href="#<?= $check['ok'] ? 'i-check' : 'i-close' ?>"></use></svg>
          <?= e($check['text']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <small class="muted">Rechecked every time you save. Advice, not a rule - publish whenever you are happy.</small>
  </div>
</div>
