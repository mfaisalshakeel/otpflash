<?php /** Customers list. @var array $users @var array $filters @var array $paging */ ?>
<form class="filters" method="get" action="<?= e(url('admin/users')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Name, email, phone or id">
  </div>
  <select name="status" onchange="this.form.submit()">
    <option value="">Everyone</option>
    <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
    <option value="blocked"<?= $filters['status'] === 'blocked' ? ' selected' : '' ?>>Blocked</option>
    <option value="funded"<?= $filters['status'] === 'funded' ? ' selected' : '' ?>>Money in wallet</option>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <option value="">Newest first</option>
    <option value="balance"<?= $filters['sort'] === 'balance' ? ' selected' : '' ?>>Biggest balance</option>
    <option value="orders"<?= $filters['sort'] === 'orders' ? ' selected' : '' ?>>Most orders</option>
    <option value="seen"<?= $filters['sort'] === 'seen' ? ' selected' : '' ?>>Last signed in</option>
  </select>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/users')) ?>">Reset</a>
  <?php endif; ?>
</form>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Customer</th><th>Wallet</th><th>Orders</th><th>Joined</th><th>Last signed in</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7" class="empty"><?= array_filter($filters, 'strlen')
            ? 'No customers match these filters.' : 'Nobody has signed up yet.' ?></td></tr>
      <?php else: foreach ($users as $user): ?>
        <tr onclick="location.href='<?= e(url('admin/users/' . $user['id'])) ?>'">
          <td data-label="Customer"><b><?= e($user['name']) ?></b><small class="sub"><?= e($user['email']) ?></small></td>
          <td data-label="Wallet"><b><?= e(money((float) $user['balance'])) ?></b></td>
          <td data-label="Orders"><?= qty_fmt((int) $user['orders']) ?></td>
          <td data-label="Joined"><?= e(when($user['created_at'], 'd M Y')) ?></td>
          <td data-label="Last signed in"><?= $user['last_login_at'] ? e(when($user['last_login_at'], 'd M, H:i')) : '<span class="muted">never</span>' ?></td>
          <td data-label="Status"><span class="st st-<?= e($user['status']) ?>"><?= e($user['status']) ?></span></td>
          <td class="ta-r">
            <div class="rowacts" onclick="event.stopPropagation()">
              <a class="iact iact-edit" href="<?= e(url('admin/users/' . $user['id'])) ?>" title="Open" aria-label="Open">
                <svg class="icon"><use href="#i-eye"></use></svg></a>
              <a class="iact" href="<?= e(url('admin/orders?user=' . $user['id'])) ?>" title="Their orders" aria-label="Their orders">
                <svg class="icon"><use href="#i-card"></use></svg></a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/users',
      'query' => paging_query($filters), 'noun' => 'customer', 'shown' => count($users)]); ?>
</div>
