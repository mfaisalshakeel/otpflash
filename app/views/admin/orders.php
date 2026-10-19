<?php /** Orders list. */ ?>
<form class="filters" method="get" action="<?= e(url('admin/orders')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Order code, number, email or transaction id">
  </div>
  <select name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (ORDER_STATUSES as $status): ?>
      <option value="<?= e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>>
        <?= e(str_replace('_', ' ', $status)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="product" onchange="this.form.submit()">
    <option value="">Everything sold</option>
    <?php foreach ($products as $key => $name): ?>
      <option value="<?= e($key) ?>"<?= $filters['product'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="date" value="<?= e($filters['date']) ?>" onchange="this.form.submit()">
  <?php if ($filters['user'] !== ''): ?>
    <input type="hidden" name="user" value="<?= e($filters['user']) ?>">
  <?php endif; ?>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/orders')) ?>">Reset</a>
  <?php endif; ?>
</form>

<?php /* Where "back" means: this page of this filtered list. */ ?>
<?php $backQuery = http_build_query(
    array_filter($filters, 'strlen') + ($paging['page'] > 1 ? ['page' => $paging['page']] : [])
); ?>
<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Code</th><th>What</th><th>Number &amp; code</th><th>Customer</th><th>Total</th>
          <th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="7" class="empty">No orders match these filters.</td></tr>
      <?php else: foreach ($orders as $order): ?>
        <tr onclick="location.href='<?= e(url('admin/orders/' . $order['id'])) ?>'">
          <td data-label="Code"><b><?= e($order['code']) ?></b><small class="sub"><?= e(when($order['created_at'], 'd M, H:i')) ?></small></td>
          <td data-label="What"><?= e(excerpt(order_label($order), 42)) ?>
            <small class="sub"><?= e(PRODUCTS[$order['product']]['name'] ?? ucfirst($order['product'])) ?>
              <?= $order['server'] !== '' && $order['product'] === 'sms' ? '· server ' . e($order['server']) : '' ?></small></td>
          <td data-label="Number">
            <?= e($order['number'] !== '' ? $order['number'] : '-') ?>
            <?php if ($order['otp_code'] !== ''): ?>
              <small class="sub">code <b><?= e($order['otp_code']) ?></b></small>
            <?php elseif ((int) $order['attempts'] > 1): ?>
              <small class="sub"><?= (int) $order['attempts'] ?> numbers tried</small>
            <?php endif; ?>
          </td>
          <td data-label="Customer"><?= e($order['user_email'] ?? ($order['email'] !== '' ? $order['email'] : 'guest')) ?>
            <small class="sub"><?= $order['pay_source'] === 'wallet' ? 'wallet' : e($order['trx_id'] !== '' ? $order['trx_id'] : '') ?></small></td>
          <td data-label="Total"><?= e(money($order['price'])) ?></td>
          <td data-label="Status"><span class="st st-<?= e($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
          <?php /* The actions the order screen offers, offered here too, on
                   the same conditions - working through a day's orders should
                   not mean opening every one of them. Each form carries where
                   it was pressed, so the list comes back with its filters. */ ?>
          <td class="ta-r">
            <div class="rowacts" onclick="event.stopPropagation()">
              <a class="iact iact-edit" href="<?= e(url('admin/orders/' . $order['id'])) ?>"
                 title="View details" aria-label="View details">
                <svg class="icon"><use href="#i-eye"></use></svg>
              </a>

              <?php
              $act = static function (string $what, string $icon, string $tone, string $label,
                                      string $confirm = '') use ($order, $backQuery): void {
                  ?>
                  <form method="post" action="<?= e(url('admin/orders/action')) ?>"
                        <?= $confirm !== '' ? 'data-confirm="' . e($confirm) . '"' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="do" value="<?= e($what) ?>">
                    <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                    <button class="iact <?= e($tone) ?>" type="submit"
                            title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
                      <svg class="icon"><use href="#<?= e($icon) ?>"></use></svg>
                    </button>
                  </form>
                  <?php
              };

              if ($order['status'] === 'pending') {
                  $act('mark_paid', 'i-card', 'iact-on', 'Mark as paid',
                       'Mark ' . $order['code'] . ' as paid? The number is bought straight away.');
              }
              if (in_array($order['status'], ['paid', 'api_error'], true) && $order['paid_at'] !== null) {
                  $act('buy', 'i-bolt', 'iact-edit', 'Deliver now');
              }
              if (in_array($order['status'], ['processing', 'completed'], true)) {
                  $act('check', 'i-refresh', 'iact-edit', 'Check for SMS now');
              }
              if ($order['status'] === 'pending') {
                  $act('cancel', 'i-close', 'iact-danger', 'Cancel order',
                       'Cancel ' . $order['code'] . '?');
              }
              ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php partial('admin/_pager', [
    'paging' => $paging,
    'base'   => 'admin/orders',
    'query'  => $filters,
    'noun'   => 'order',
    'shown'  => count($orders),
  ]); ?>
</div>
