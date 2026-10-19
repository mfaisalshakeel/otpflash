<?php
/** The page editor. @var array $page  @var array $errors  @var array $checks */
$err = static fn (string $name): string => isset($errors[$name])
    ? '<span class="err">' . e($errors[$name]) . '</span>' : '';
?>
<a class="lnk back" href="<?= e(url('admin/pages')) ?>">&larr; Back to pages</a>

<form method="post" action="<?= e(url('admin/pages/save')) ?>" enctype="multipart/form-data"
      class="split2 split-wide" data-seo-form>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $page['id'] ?>">

  <div>
    <div class="box">
      <div class="pad">
        <div class="field">
          <label for="f-title">Title <span style="color:var(--danger)">*</span></label>
          <input id="f-title" name="title" value="<?= e($page['title']) ?>" maxlength="190" required class="input-xl"
                 <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
          <?= $err('title') ?>
        </div>
        <div class="field">
          <label for="f-slug">Address</label>
          <div class="input-prefix">
            <span><?= e(url('')) ?></span>
            <input id="f-slug" name="slug" value="<?= e($page['slug']) ?>" maxlength="120"
                   <?= isset($errors['slug']) ? 'aria-invalid="true"' : '' ?>>
          </div>
          <?= $err('slug') ?>
        </div>
        <div class="field">
          <label for="f-content">Content</label>
          <textarea id="f-content" name="content" rows="18" data-editor data-height="480"><?= e($page['content']) ?></textarea>
        </div>
      </div>
    </div>
    <?php partial('admin/_seo-box', ['row' => $page, 'base' => url(''), 'checks' => $checks, 'keyword' => false]); ?>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Publish</b>
        <span class="st st-<?= $page['is_active'] ? 'completed' : 'cancelled' ?>"><?= $page['is_active'] ? 'live' : 'hidden' ?></span></div>
      <div class="pad">
        <label class="switch block"><input type="checkbox" name="is_active" value="1" <?= $page['is_active'] ? 'checked' : '' ?>>
          <span class="track"></span> Published</label>
        <label class="switch block"><input type="checkbox" name="show_in_footer" value="1" <?= $page['show_in_footer'] ? 'checked' : '' ?>>
          <span class="track"></span> Link it in the footer</label>
        <label class="switch block"><input type="checkbox" name="noindex" value="1" <?= $page['noindex'] ? 'checked' : '' ?>>
          <span class="track"></span> Hide from search engines</label>
        <div class="field"><label for="f-sort_order">Sort order</label>
          <input id="f-sort_order" name="sort_order" type="number" value="<?= (int) $page['sort_order'] ?>"></div>
        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= $page['id'] ? 'Save changes' : 'Create page' ?></button>
        <?php if ($page['id'] && $page['is_active']): ?>
          <a class="btn btn-ghost btn-block" style="margin-top:9px" target="_blank" rel="noopener"
             href="<?= e(url($page['slug'])) ?>">View page</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Share image</b></div>
      <div class="pad">
        <?php partial('admin/_upload', ['name' => 'og_image', 'label' => 'Shown when the page is shared',
            'current' => (string) $page['og_image'], 'accept' => 'image/png,image/jpeg,image/webp',
            'hint' => '1200 × 630. Empty uses the site\'s default share image.']); ?>
        <?= $err('og_image') ?>
        <?php if ($page['og_image'] !== ''): ?>
          <label class="check-row"><input type="checkbox" name="remove_og_image" value="1"> Remove it</label>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
