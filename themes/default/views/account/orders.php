<?php /** @var array $user @var string $section @var array $orders @var array $paging @var string $status */ ?>
<div class="portal-page">
  <div class="phead">
    <div><h1>My orders</h1><p>Every number, inbox and rental you have bought, with its code.</p></div>
    <form method="get" action="<?= e(url('account/orders')) ?>">
      <select name="status" onchange="this.form.submit()" class="btn btn-ghost" aria-label="Filter by status">
        <option value="">Every status</option>
        <?php foreach (ORDER_STATUS_WORDS as $key => $words): ?>
          <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($words) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="panel">
    <?php partial('account/_orders-table', ['orders' => $orders]); ?>
    <?php partial('account/_pager', ['paging' => $paging, 'base' => 'account/orders', 'query' => ['status' => $status]]); ?>
  </div>
</div>
