<?php
/**
 * The blog post editor.
 *
 * @var array $post  @var array $errors  @var array $categories  @var array $checks  @var string $state
 */
$err = static fn (string $name): string => isset($errors[$name])
    ? '<span class="err">' . e($errors[$name]) . '</span>' : '';
$when = $post['published_at'] ? date('Y-m-d\TH:i', strtotime((string) $post['published_at'])) : '';
?>
<a class="lnk back" href="<?= e(url('admin/posts')) ?>">&larr; Back to posts</a>

<form method="post" action="<?= e(url('admin/posts/save')) ?>" enctype="multipart/form-data"
      class="split2 split-wide" data-seo-form>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">

  <div>
    <div class="box">
      <div class="pad">
        <div class="field">
          <label for="f-title">Title <span style="color:var(--danger)">*</span></label>
          <input id="f-title" name="title" value="<?= e($post['title']) ?>" maxlength="190" required
                 class="input-xl" placeholder="How to get a WhatsApp verification code without a SIM"
                 <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
          <?= $err('title') ?>
        </div>
        <div class="field">
          <label for="f-slug">Address</label>
          <div class="input-prefix">
            <span><?= e(url('blog/')) ?></span>
            <input id="f-slug" name="slug" value="<?= e($post['slug']) ?>" maxlength="120"
                   placeholder="filled-in-from-the-title" <?= isset($errors['slug']) ? 'aria-invalid="true"' : '' ?>>
          </div>
          <?= $err('slug') ?>
        </div>
        <div class="field">
          <label for="f-content">Content</label>
          <?php /* Summernote is drawn over this box by admin.js and writes its
                   HTML back into it, so the textarea is what saves either way. */ ?>
          <textarea id="f-content" name="content" rows="18" data-editor data-height="520"
                    placeholder="Start writing. Use Heading 2 for sections - they become the table of contents."><?= e($post['content']) ?></textarea>
        </div>
        <div class="field">
          <label for="f-excerpt">Summary</label>
          <textarea class="text" id="f-excerpt" name="excerpt" rows="3" maxlength="400"
                    placeholder="Two lines for the blog list and social shares. Left empty, the opening of the post is used."><?= e($post['excerpt']) ?></textarea>
        </div>
      </div>
    </div>

    <?php partial('admin/_seo-box', ['row' => $post, 'base' => url('blog/'), 'checks' => $checks, 'keyword' => true]); ?>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Publish</b>
        <span class="st st-<?= e(['published' => 'completed', 'scheduled' => 'processing', 'draft' => 'cancelled'][$state]) ?>"><?= e($state) ?></span></div>
      <div class="pad">
        <div class="field">
          <label for="f-status">Status</label>
          <select id="f-status" name="status">
            <option value="draft"<?= $post['status'] !== 'published' ? ' selected' : '' ?>>Draft - only you can see it</option>
            <option value="published"<?= $post['status'] === 'published' ? ' selected' : '' ?>>Published</option>
          </select>
        </div>
        <div class="field">
          <label for="f-published_at">Publish date</label>
          <input id="f-published_at" name="published_at" type="datetime-local" value="<?= e($when) ?>">
          <small>A time in the future schedules it. Empty means now.</small>
          <?= $err('published_at') ?>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= $post['id'] ? 'Save changes' : 'Create post' ?></button>
        <?php if ($post['id'] && $state === 'published'): ?>
          <a class="btn btn-ghost btn-block" style="margin-top:9px" target="_blank" rel="noopener"
             href="<?= e(url('blog/' . $post['slug'])) ?>">View post</a>
        <?php elseif ($post['id']): ?>
          <a class="btn btn-ghost btn-block" style="margin-top:9px" target="_blank" rel="noopener"
             href="<?= e(url('blog/' . $post['slug'] . '?preview=1')) ?>">Preview</a>
        <?php endif; ?>
        <?php if ($post['id']): ?>
          <p class="muted" style="margin:12px 0 0;font-size:12.5px"><?= qty_fmt((int) $post['views']) ?> views<?=
            !empty($post['updated_at']) ? ' &middot; saved ' . e(when($post['updated_at'], 'd M, H:i')) : '' ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Cover image</b></div>
      <div class="pad">
        <?php partial('admin/_upload', ['name' => 'cover', 'label' => 'Shown on the list, the post and social shares',
            'current' => (string) $post['cover_path'], 'accept' => 'image/png,image/jpeg,image/webp,image/gif',
            'hint' => '1200 × 675 works everywhere. Under 2 MB.']); ?>
        <?= $err('cover') ?>
        <?php if ($post['cover_path'] !== ''): ?>
          <label class="check-row"><input type="checkbox" name="remove_cover" value="1"> Remove the cover</label>
        <?php endif; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Organise</b></div>
      <div class="pad">
        <div class="field">
          <label for="f-category_id">Category</label>
          <select id="f-category_id" name="category_id">
            <option value="">No category</option>
            <?php foreach ($categories as $id => $name): ?>
              <option value="<?= (int) $id ?>"<?= (int) $post['category_id'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
          </select>
          <small><a class="lnk" href="<?= e(url('admin/post-categories')) ?>">Manage categories</a></small>
        </div>
        <div class="field">
          <label for="f-tags">Tags</label>
          <input id="f-tags" name="tags" value="<?= e(str_replace(',', ', ', (string) $post['tags'])) ?>" maxlength="500"
                 placeholder="whatsapp, verification, privacy">
          <small>Separated by commas. Each tag gets its own page.</small>
        </div>
        <div class="field">
          <label for="f-author_name">Author</label>
          <input id="f-author_name" name="author_name" value="<?= e($post['author_name']) ?>" maxlength="120"
                 placeholder="<?= e(setting('site_name', 'OTP Flash')) ?> team">
        </div>
        <label class="switch block">
          <input type="checkbox" name="is_featured" value="1" <?= $post['is_featured'] ? 'checked' : '' ?>>
          <span class="track"></span> Feature at the top of the blog
        </label>
        <label class="switch block">
          <input type="checkbox" name="noindex" value="1" <?= $post['noindex'] ? 'checked' : '' ?>>
          <span class="track"></span> Hide from search engines
        </label>
      </div>
    </div>

    <?php if ($post['id']): ?>
      <div class="box">
        <div class="pad acts">
          <button class="btn btn-ghost btn-block" type="submit" form="dup-post">Duplicate as a draft</button>
          <button class="btn btn-danger btn-block" type="submit" form="del-post">Delete post</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($post['id']): ?>
  <form id="dup-post" method="post" action="<?= e(url('admin/posts/duplicate')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
  </form>
  <form id="del-post" method="post" action="<?= e(url('admin/posts/delete')) ?>"
        data-confirm="Delete &quot;<?= e($post['title']) ?>&quot;? This cannot be undone.">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
  </form>
<?php endif; ?>
