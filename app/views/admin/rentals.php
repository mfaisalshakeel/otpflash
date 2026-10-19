<?php /** Rentals list. @var array $rentals @var array $filters @var array $paging */ ?>
<form class="filters" method="get" action="<?= e(url('admin/rentals')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Number, country, customer email or OTPGet id">
  </div>
  <select name="status" onchange="this.form.submit()">
    <option value="">Every rental</option>
    <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'ended' => 'Closed by us'] as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($filters['user'] !== ''): ?><input type="hidden" name="user" value="<?= e($filters['user']) ?>"><?php endif; ?>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/rentals')) ?>">Reset</a>
  <?php endif; ?>
</form>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Number</th><th>Country</th><th>Customer</th><th>Price</th><th>Until</th><th>SMS</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$rentals): ?>
        <tr><td colspan="7" class="empty"><?= array_filter($filters, 'strlen') ? 'No rentals match these filters.' : 'No rentals yet.' ?></td></tr>
      <?php else: foreach ($rentals as $rental): ?>
        <tr onclick="location.href='<?= e(url('admin/rentals/' . $rental['id'])) ?>'">
          <td data-label="Number" class="mono"><b><?= e($rental['number'] !== '' ? $rental['number'] : 'waiting for OTPGet') ?></b>
            <small class="sub">Server <?= e($rental['provider']) ?> &middot; <?= e(rental_duration_words((int) $rental['duration_hours'])) ?></small></td>
          <td data-label="Country"><?php if ($rental['country_code'] !== ''): ?><img class="flag" src="<?= e(flag_url($rental['country_code'])) ?>" alt="" width="20" height="15"> <?php endif; ?><?= e($rental['country_name']) ?></td>
          <td data-label="Customer"><?= e($rental['user_email'] ?? '-') ?></td>
          <td data-label="Price"><?= e(money((float) $rental['price'])) ?><small class="sub">cost <?= e(money((float) $rental['cost'])) ?></small></td>
          <td data-label="Until"><?= $rental['expires_at'] ? e(when($rental['expires_at'], 'd M, H:i')) : '-' ?></td>
          <td data-label="SMS"><?= qty_fmt((int) $rental['messages']) ?></td>
          <td data-label="Status"><span class="st st-<?= e($rental['status']) ?>"><?= e($rental['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/rentals',
      'query' => paging_query($filters), 'noun' => 'rental', 'shown' => count($rentals)]); ?>
</div>
