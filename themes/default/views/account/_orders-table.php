<?php /** A table of a customer's orders. @var array $orders */ ?>
<div class="tbl-wrap">
  <table class="tbl tbl-cards">
    <thead><tr><th>Order</th><th>What</th><th>Number</th><th>Code</th><th>Paid</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$orders): ?>
      <tr><td colspan="6" class="pempty">Nothing yet. <a class="row-link" href="<?= e(url('')) ?>">Buy your first number</a></td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $order): ?>
      <tr>
        <td data-label="Order"><a class="row-link" href="<?= e(url('order/' . $order['code'])) ?>"><?= e($order['code']) ?></a>
          <small class="sub"><?= e(when($order['created_at'], 'd M, H:i')) ?></small></td>
        <td data-label="What"><?= e(order_label($order)) ?></td>
        <td data-label="Number" class="mono"><?= e($order['number'] !== '' ? $order['number'] : '-') ?></td>
        <td data-label="Code" class="mono"><b><?= e($order['otp_code'] !== '' ? $order['otp_code'] : '-') ?></b></td>
        <td data-label="Paid"><?= e(money($order['price'])) ?><small class="sub"><?= $order['pay_source'] === 'wallet' ? 'from wallet' : 'paid directly' ?></small></td>
        <td data-label="Status"><span class="st st-<?= e($order['status']) ?>"><?= e(ORDER_STATUS_WORDS[$order['status']] ?? $order['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
