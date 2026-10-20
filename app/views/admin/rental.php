<?php
/**
 * One rental and its inbox.
 *
 * @var array $rental  @var array $messages  @var array $inbox  @var array $renewals
 */
$button = static function (string $do, string $label, string $class = 'btn-ghost', ?string $confirm = null) use ($rental): string {
    return '<form method="post" action="' . e(url('admin/rentals/action')) . '"'
        . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $rental['id'] . '">'
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<button class="btn ' . e($class) . ' btn-block" type="submit">' . e($label) . '</button></form>';
};
$profit = (float) $rental['price'] - (float) $rental['cost'];
?>
<a class="lnk back" href="<?= e(url('admin/rentals')) ?>">&larr; Back to rentals</a>

<div class="split2 split-wide">
  <div>
    <div class="box">
      <div class="box-head"><b>Inbox</b><span class="muted"><?= qty_fmt($inbox['total']) ?> message(s)</span></div>
      <div class="tbl-wrap">
        <table class="tbl tbl-tight">
          <thead><tr><th>Received</th><th>From</th><th>Message</th><th>Code</th></tr></thead>
          <tbody>
          <?php if (!$messages): ?>
            <tr><td colspan="4" class="empty">Nothing has arrived on this number yet.</td></tr>
          <?php else: foreach ($messages as $message): ?>
            <tr>
              <td><?= e(when($message['received_at'] ?: $message['created_at'], 'd M, H:i')) ?></td>
              <td><?= e($message['sender'] !== '' ? $message['sender'] : '-') ?></td>
              <td><?= e((string) $message['body']) ?></td>
              <td class="mono"><b><?= e($message['code'] !== '' ? $message['code'] : '-') ?></b></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php partial('admin/_pager', ['paging' => $inbox, 'base' => 'admin/rentals/' . (int) $rental['id'],
          'noun' => 'message', 'shown' => count($messages)]); ?>
    </div>

    <?php if ($renewals): ?>
      <div class="box">
        <div class="box-head"><b>Renewals</b></div>
        <div class="tbl-wrap">
          <table class="tbl tbl-tight">
            <thead><tr><th>Order</th><th>When</th><th>Price</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($renewals as $order): ?>
              <tr onclick="location.href='<?= e(url('admin/orders/' . $order['id'])) ?>'">
                <td><b><?= e($order['code']) ?></b></td>
                <td><?= e(when($order['created_at'], 'd M Y, H:i')) ?></td>
                <td><?= e(money($order['price'])) ?></td>
                <td><span class="st st-<?= e($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b class="mono"><?= e($rental['number'] !== '' ? $rental['number'] : 'No number yet') ?></b>
        <span class="st st-<?= e($rental['status']) ?>"><?= e($rental['status']) ?></span></div>
      <div class="pad">
        <div class="drow"><span>Country</span><b><?= e($rental['country_name']) ?></b></div>
        <div class="drow"><span>Length</span><b><?= e(rental_duration_words((int) $rental['duration_hours'])) ?> &middot; Server <?= e($rental['provider']) ?></b></div>
        <div class="drow"><span>Rented</span><b><?= e(when($rental['created_at'])) ?></b></div>
        <div class="drow"><span>Runs until</span><b><?= $rental['expires_at'] ? e(when($rental['expires_at'])) : '-' ?></b></div>
        <div class="drow"><span>Price / cost</span><b><?= e(money((float) $rental['price'])) ?> / <?= e(money((float) $rental['cost'])) ?></b></div>
        <div class="drow"><span>Profit</span><b class="<?= $profit >= 0 ? 'up' : 'warn' ?>"><?= e(money($profit)) ?></b></div>
        <div class="drow"><span>Customer</span><b>
          <?php if ($rental['user_email']): ?>
            <a class="lnk" href="<?= e(url('admin/users/' . (int) $rental['user_id'])) ?>"><?= e($rental['user_name'] . ' · ' . $rental['user_email']) ?></a>
          <?php else: ?>-<?php endif; ?></b></div>
        <div class="drow"><span>Order</span><b>
          <?php if ($rental['order_code']): ?>
            <a class="lnk" href="<?= e(url('admin/orders/' . (int) $rental['order_id'])) ?>"><?= e($rental['order_code']) ?></a>
          <?php else: ?>-<?php endif; ?></b></div>
        <div class="drow"><span>OTPGet ids</span><b class="mono"><?= e($rental['provider_order_id'] ?: '-') ?>
          <?= $rental['provider_rental_id'] !== '' ? ' / ' . e($rental['provider_rental_id']) : '' ?></b></div>
        <div class="drow"><span>Inbox checked</span><b><?= $rental['checked_at'] ? e(when($rental['checked_at'], 'd M, H:i:s')) : 'never' ?></b></div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Actions</b></div>
      <div class="pad acts">
        <?php if ($rental['status'] === 'active'): ?>
          <?= $button('check', 'Check the inbox now', 'btn-primary') ?>
          <?php if ((string) $rental['provider'] === '2'): ?>
            <?= $button('cancel', 'Cancel at OTPGet and refund', 'btn-danger',
                'Cancel this rental at OTPGet and refund the customer\'s wallet? Only works within 20 minutes and before any SMS.') ?>
          <?php endif; ?>
          <?= $button('close', 'Close on our side', 'btn-ghost',
              'Close this rental? The customer stops seeing it as open. Nothing is asked of OTPGet and nothing is refunded.') ?>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/rentals/action')) ?>" class="inline-form"
              data-confirm="Extend this rental on our side?">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $rental['id'] ?>">
          <input type="hidden" name="do" value="extend">
          <div class="field"><label for="r-hours">Extend by (hours)</label>
            <input id="r-hours" name="hours" type="number" min="1" max="2160" value="24">
            <small>Changes our record only - for goodwill after a renewal paid elsewhere.</small></div>
          <button class="btn btn-ghost btn-block" type="submit">Extend</button>
        </form>
      </div>
    </div>
  </div>
</div>
