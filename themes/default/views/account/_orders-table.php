<?php /** A table of a customer's orders. @var array $orders */ ?>
<div class="tbl-wrap">
  <table class="tbl">
    <thead><tr><th>Order</th><th>What</th><th>Number</th><th>Code</th><th>Paid</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$orders): ?>
      <tr><td colspan="6" class="pempty">Nothing yet. <a class="row-link" href="<?= e(url('')) ?>">Buy your first number</a></td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $order): ?>
      <tr>
        <td><a class="row-link" href="<?= e(url('order/' . $order['code'])) ?>"><?= e($order['code']) ?></a>
          <small class="sub"><?= e(when($order['created_at'], 'd M, H:i')) ?></small></td>
        <td><?= e(order_label($order)) ?></td>
        <td class="mono"><?= e($order['number'] !== '' ? $order['number'] : '-') ?></td>
        <td class="mono"><b><?= e($order['otp_code'] !== '' ? $order['otp_code'] : '-') ?></b></td>
        <td><?= e(money($order['price'])) ?><small class="sub"><?= $order['pay_source'] === 'wallet' ? 'wallet' : 'gateway' ?></small></td>
        <td><span class="st st-<?= e($order['status']) ?>"><?= e(ORDER_STATUS_WORDS[$order['status']] ?? $order['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
