<?php /** Wallet ledger. @var array $lines @var array $totals @var array $filters @var array $paging @var ?array $who */ ?>
<?php if ($who): ?>
  <div class="alert alert-info">Showing only <b><?= e($who['name']) ?></b> (<?= e($who['email']) ?>).
    <a href="<?= e(url('admin/users/' . (int) $who['id'])) ?>">Open the customer</a></div>
<?php endif; ?>

<form class="filters" method="get" action="<?= e(url('admin/wallet')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Customer, note or order code">
  </div>
  <select name="type" onchange="this.form.submit()">
    <option value="">Every kind</option>
    <?php foreach (WALLET_TYPES as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['type'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="date" value="<?= e($filters['date']) ?>" onchange="this.form.submit()">
  <?php if ($filters['user'] !== ''): ?><input type="hidden" name="user" value="<?= e($filters['user']) ?>"><?php endif; ?>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/wallet')) ?>">Reset</a>
  <?php endif; ?>
</form>

<div class="cards4">
  <div class="scard scard-plain"><div><small>Lines</small><b><?= qty_fmt((int) $totals['n']) ?></b>
    <span class="muted"><?= array_filter($filters, 'strlen') ? 'matching these filters' : 'since the shop opened' ?></span></div></div>
  <div class="scard scard-plain"><div><small>Money in</small><b class="amt-in"><?= e(money((float) $totals['money_in'])) ?></b>
    <span class="muted">deposits, refunds, credits</span></div></div>
  <div class="scard scard-plain"><div><small>Money out</small><b class="amt-out"><?= e(money((float) $totals['money_out'])) ?></b>
    <span class="muted">purchases, rentals, debits</span></div></div>
  <div class="scard scard-plain"><div><small>Net</small><b><?= e(money((float) $totals['money_in'] - (float) $totals['money_out'])) ?></b>
    <span class="muted">what these lines left behind</span></div></div>
</div>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>When</th><th>Customer</th><th>What</th><th>Order</th><th>Amount</th><th>Balance after</th></tr></thead>
      <tbody>
      <?php if (!$lines): ?>
        <tr><td colspan="6" class="empty">No wallet lines<?= array_filter($filters, 'strlen') ? ' match these filters' : ' yet' ?>.</td></tr>
      <?php else: foreach ($lines as $line): ?>
        <tr>
          <td data-label="When"><?= e(when($line['created_at'], 'd M, H:i')) ?></td>
          <td data-label="Customer"><a class="lnk" href="<?= e(url('admin/users/' . (int) $line['user_id'])) ?>"><?= e($line['user_name'] ?? '#' . $line['user_id']) ?></a>
            <small class="sub"><?= e($line['user_email'] ?? '') ?></small></td>
          <td data-label="What"><?= e(WALLET_TYPES[$line['type']] ?? $line['type']) ?>
            <small class="sub"><?= e($line['note']) ?><?= $line['admin_name'] ? ' · by ' . e($line['admin_name']) : '' ?></small></td>
          <td data-label="Order"><?php if ($line['order_code']): ?>
            <a class="lnk" href="<?= e(url('admin/orders/' . (int) $line['order_id'])) ?>"><?= e($line['order_code']) ?></a>
            <?php else: ?>-<?php endif; ?></td>
          <td data-label="Amount" class="<?= (float) $line['amount'] >= 0 ? 'amt-in' : 'amt-out' ?>">
            <?= (float) $line['amount'] >= 0 ? '+' : '' ?><?= e(money((float) $line['amount'])) ?></td>
          <td data-label="Balance after"><?= e(money((float) $line['balance_after'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/wallet',
      'query' => paging_query($filters), 'noun' => 'line', 'shown' => count($lines)]); ?>
</div>
