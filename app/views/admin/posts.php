<?php /** Blog posts list. @var array $posts @var array $filters @var array $paging @var array $categories */ ?>
<form class="filters" method="get" action="<?= e(url('admin/posts')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Title, address or tag">
  </div>
  <select name="state" onchange="this.form.submit()">
    <option value="">Every post</option>
    <?php foreach (['published' => 'Live', 'scheduled' => 'Scheduled', 'draft' => 'Drafts'] as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['state'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($categories): ?>
    <select name="category" onchange="this.form.submit()">
      <option value="">Every category</option>
      <?php foreach ($categories as $id => $name): ?>
        <option value="<?= (int) $id ?>"<?= $filters['category'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/posts')) ?>">Reset</a>
  <?php endif; ?>
  <a class="btn btn-ghost" style="margin-left:auto" href="<?= e(url('admin/posts/import')) ?>">
    <svg class="icon"><use href="#i-doc"></use></svg> Import CSV</a>
  <a class="btn btn-primary" href="<?= e(url('admin/posts/new')) ?>">
    <svg class="icon"><use href="#i-plus"></use></svg> New post</a>
</form>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Post</th><th>Category</th><th>Date</th><th>Views</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php if (!$posts): ?>
        <tr><td colspan="6" class="empty"><?= array_filter($filters, 'strlen') ? 'No posts match these filters.'
            : 'No posts yet. Guides on getting codes for a particular app are what people search for.' ?></td></tr>
      <?php else: foreach ($posts as $post): ?>
        <?php $state = post_state($post); ?>
        <tr onclick="location.href='<?= e(url('admin/posts/edit/' . $post['id'])) ?>'">
          <td data-label="Post">
            <div class="post-cell">
              <?php if ($post['cover_path'] !== ''): ?>
                <img src="<?= e(url($post['cover_path'])) ?>" alt="" width="64" height="36" loading="lazy">
              <?php else: ?>
                <span class="post-ph"><svg class="icon"><use href="#i-doc"></use></svg></span>
              <?php endif; ?>
              <span><b><?= e($post['title']) ?></b>
                <small class="sub">/blog/<?= e($post['slug']) ?><?= $post['is_featured'] ? ' · featured' : '' ?><?= $post['noindex'] ? ' · hidden from search' : '' ?></small></span>
            </div>
          </td>
          <td data-label="Category"><?= e($post['category_name'] ?? '-') ?></td>
          <td data-label="Date"><?= e(when($post['published_at'] ?: $post['created_at'], 'd M Y')) ?></td>
          <td data-label="Views"><?= qty_fmt((int) $post['views']) ?></td>
          <td data-label="State"><span class="st st-<?= e(['published' => 'completed', 'scheduled' => 'processing', 'draft' => 'cancelled'][$state]) ?>"><?= e($state) ?></span></td>
          <td class="ta-r">
            <div class="rowacts" onclick="event.stopPropagation()">
              <?php if ($state === 'published'): ?>
                <a class="iact" href="<?= e(url('blog/' . $post['slug'])) ?>" target="_blank" rel="noopener" title="View" aria-label="View">
                  <svg class="icon"><use href="#i-eye"></use></svg></a>
              <?php endif; ?>
              <a class="iact iact-edit" href="<?= e(url('admin/posts/edit/' . $post['id'])) ?>" title="Edit" aria-label="Edit">
                <svg class="icon"><use href="#i-edit"></use></svg></a>
              <form method="post" action="<?= e(url('admin/posts/delete')) ?>" data-confirm="Delete &quot;<?= e($post['title']) ?>&quot;?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                <button class="iact iact-danger" type="submit" title="Delete" aria-label="Delete">
                  <svg class="icon"><use href="#i-trash"></use></svg></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/posts',
      'query' => paging_query($filters), 'noun' => 'post', 'shown' => count($posts)]); ?>
</div>
