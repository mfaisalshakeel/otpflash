<?php /** Pages list. @var array $pages */ ?>
<div class="filters">
  <a class="btn btn-primary" style="margin-left:auto" href="<?= e(url('admin/pages/new')) ?>">
    <svg class="icon"><use href="#i-plus"></use></svg> New page</a>
</div>
<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Page</th><th>Footer</th><th>Search</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php if (!$pages): ?>
        <tr><td colspan="5" class="empty">No pages yet.</td></tr>
      <?php else: foreach ($pages as $page): ?>
        <tr onclick="location.href='<?= e(url('admin/pages/edit/' . $page['id'])) ?>'">
          <td data-label="Page"><b><?= e($page['title']) ?></b><small class="sub">/<?= e($page['slug']) ?></small></td>
          <td data-label="Footer"><?= $page['show_in_footer'] ? 'yes' : 'no' ?></td>
          <td data-label="Search"><?= $page['noindex'] ? 'hidden' : 'indexed' ?></td>
          <td data-label="State"><span class="st st-<?= $page['is_active'] ? 'completed' : 'cancelled' ?>"><?= $page['is_active'] ? 'live' : 'hidden' ?></span></td>
          <td class="ta-r">
            <div class="rowacts" onclick="event.stopPropagation()">
              <form method="post" action="<?= e(url('admin/pages/toggle')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $page['id'] ?>">
                <button class="iact <?= $page['is_active'] ? 'iact-on' : '' ?>" type="submit"
                        title="<?= $page['is_active'] ? 'Hide' : 'Publish' ?>" aria-label="<?= $page['is_active'] ? 'Hide' : 'Publish' ?>">
                  <svg class="icon"><use href="#i-power"></use></svg></button>
              </form>
              <a class="iact iact-edit" href="<?= e(url('admin/pages/edit/' . $page['id'])) ?>" title="Edit" aria-label="Edit">
                <svg class="icon"><use href="#i-edit"></use></svg></a>
              <form method="post" action="<?= e(url('admin/pages/delete')) ?>" data-confirm="Delete &quot;<?= e($page['title']) ?>&quot;?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $page['id'] ?>">
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
</div>
