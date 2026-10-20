<?php
/**
 * A customer's orders, one row each.
 *
 * A list rather than a table: on a phone a six-column table became six
 * labelled lines per order, and what a customer scans for is which app,
 * whether the code came, and the code itself. So each row leads with the
 * app's icon and name, keeps the status and price on the right, and shows
 * the number - and the code, large, once it is there - underneath.
 *
 * @var array $orders  with service_icon and service_color joined on
 */
?>
<?php if (!$orders): ?>
  <div class="empty-state"><b>Nothing yet</b>Every number, inbox and rental you buy shows up here, with its code.
    <a class="btn btn-primary btn-sm" href="<?= e(url('')) ?>">Buy your first number</a></div>
<?php else: ?>
  <ul class="ord-list">
    <?php foreach ($orders as $order): ?>
      <li>
        <a class="ord-row" href="<?= e(url('order/' . $order['code'])) ?>">
          <?= service_icon_html(['name' => $order['service_name'] !== '' ? $order['service_name'] : order_label($order),
              'icon' => $order['service_icon'] ?? '', 'color' => $order['service_color'] ?? '']) ?>
          <span class="ord-main">
            <b><?= e(order_label($order)) ?></b>
            <small><span class="mono"><?= e($order['code']) ?></span> · <?= e(when($order['created_at'], 'd M, H:i')) ?></small>
            <?php if ($order['number'] !== '' || $order['otp_code'] !== ''): ?>
              <span class="ord-num">
                <?php if ($order['number'] !== ''): ?><span class="mono"><?= e($order['number']) ?></span><?php endif; ?>
                <?php if ($order['otp_code'] !== ''): ?><span class="ord-code">Code <b class="mono"><?= e($order['otp_code']) ?></b></span><?php endif; ?>
              </span>
            <?php endif; ?>
          </span>
          <span class="ord-side">
            <b><?= e(money($order['price'])) ?></b>
            <span class="st st-<?= e($order['status']) ?>"><?= e(ORDER_STATUS_WORDS[$order['status']] ?? $order['status']) ?></span>
          </span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
