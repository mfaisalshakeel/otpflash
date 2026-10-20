<?php
/** One order: everything we know, plus the actions. */
$profit = (float) $order['price'] - (float) $order['cost'];
$margin = (float) $order['cost'] > 0 ? round(($profit / (float) $order['cost']) * 100) : null;

/** One action button, posted with the CSRF token. */
$button = static function (string $do, string $label, string $class = 'btn-ghost', ?string $confirm = null) use ($order) {
    $form = '<form method="post" action="' . e(url('admin/orders/action')) . '"';
    if ($confirm !== null) {
        $form .= ' data-confirm="' . e($confirm) . '"';
    }
    return $form . '>' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $order['id'] . '">'
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<button class="btn ' . e($class) . ' btn-block" type="submit">' . e($label) . '</button>'
        . '</form>';
};
?>
<a class="lnk back" href="<?= e(url('admin/orders')) ?>">&larr; Back to orders</a>

<?php
// Failed calls, counted for the heading: the box is the first place to look
// when an order is stuck, and a number on it says whether to.
$failedCalls = count(array_filter($calls, static fn (array $call): bool => (int) $call['ok'] === 0));
?>
<div class="split2">
  <div>
    <div class="box">
      <div class="box-head">
        <b>Order <?= e($order['code']) ?></b>
        <span class="st st-<?= e($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span>
      </div>
      <div class="pad">
        <div class="drow"><span>Bought</span><b><?= e(order_label($order)) ?></b></div>
        <div class="drow"><span>Product</span><b><?= e(PRODUCTS[$order['product']]['name']
            ?? ($order['product'] === 'deposit' ? 'Wallet deposit' : ($order['product'] === 'renewal' ? 'Rental renewal' : $order['product']))) ?>
          <?= ($meta = order_meta($order)) && !empty($meta['option']) ? '&middot; ' . e($meta['option']) : '' ?></b></div>
        <div class="drow"><span>Customer</span><b>
          <?php if (!empty($order['user_id'])): ?>
            <a class="lnk" href="<?= e(url('admin/users/' . (int) $order['user_id'])) ?>"><?= e($order['user_name'] . ' · ' . $order['user_email']) ?></a>
            <small class="sub">wallet <?= e(money((float) $order['user_balance'])) ?></small>
          <?php else: ?>
            Guest<?= $order['email'] !== '' ? ' &middot; ' . e($order['email']) : '' ?>
          <?php endif; ?>
        </b></div>
        <?php if (($order['whatsapp'] ?? '') !== ''): ?>
          <div class="drow"><span>WhatsApp</span><b><?= e($order['whatsapp']) ?>
            <a class="lnk" target="_blank" rel="noopener"
               href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $order['whatsapp'])) ?>?text=<?= rawurlencode('Hi, about your order ' . $order['code']) ?>">Message</a></b></div>
        <?php endif; ?>
        <div class="drow"><span>Charge / cost</span>
          <b><?= e(money($order['price'])) ?> / <?= e(money($order['cost'])) ?></b></div>
        <div class="drow"><span>Profit</span>
          <b class="<?= $profit >= 0 ? 'up' : 'warn' ?>"><?= e(money($profit)) ?><?php
            if ($margin !== null): ?> (<?= $margin >= 0 ? '+' : '' ?><?= (int) $margin ?>%)<?php endif; ?></b></div>
        <div class="drow"><span>Placed</span><b><?= e(when($order['created_at'])) ?></b></div>
        <div class="drow"><span>Customer IP</span><b class="mono"><?= e($order['ip'] ?: '-') ?></b></div>
        <div class="drow"><span>Customer page</span><b>
          <a class="lnk" href="<?= e(url('order/' . $order['code'])) ?>" target="_blank" rel="noopener">Open</a></b></div>
      </div>
    </div>

    <?php if (in_array($order['product'], NUMBER_PRODUCTS, true)): ?>
    <div class="box">
      <div class="box-head"><b><?= $order['product'] === 'email' ? 'Inbox' : 'Number' ?></b>
        <span class="st st-<?= e($order['status']) ?>"><?= e($state['words']) ?></span>
      </div>
      <div class="pad">
        <div class="drow"><span>Current</span><b class="mono"><?= e($state['number'] !== '' ? $state['number'] : '-') ?></b></div>
        <div class="drow"><span>Code</span><b class="mono"><?= e($state['code'] !== '' ? $state['code'] : '-') ?></b></div>
        <div class="drow"><span>Numbers used</span><b><?= (int) $state['attempts'] ?> of <?= (int) $state['attempts_max'] ?></b></div>
        <?php if ($state['seconds_left'] > 0): ?>
          <div class="drow"><span>Held for</span><b><?= (int) ceil($state['seconds_left'] / 60) ?> more minute(s)</b></div>
        <?php endif; ?>

        <?php if ($activations): ?>
          <div class="tbl-wrap" style="margin-top:12px">
            <table class="tbl tbl-tight">
              <thead><tr><th>Number</th><th>OTPGet id</th><th>State</th><th>Code</th><th>Cost</th></tr></thead>
              <tbody>
              <?php foreach ($activations as $activation): ?>
                <tr>
                  <td class="mono"><?= e($activation['number']) ?><small class="sub"><?= e(when($activation['created_at'], 'd M, H:i')) ?></small></td>
                  <td class="mono"><?= e($activation['provider_order_id']) ?><small class="sub"><?= e($activation['product']) ?> <?= e($activation['server']) ?></small></td>
                  <td><?= e(match ($activation['status']) {
                      'held'   => 'held for payment',
                      'queued' => 'queued to give back',
                      default  => $activation['status'],
                  }) ?></td>
                  <td class="mono"><?= e($activation['code'] !== '' ? $activation['code'] : '-') ?></td>
                  <td><?= e(money((float) $activation['cost_base'])) ?><?= $activation['refunded'] ? '<small class="sub">refunded to us</small>' : '' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($order['api_error'] !== ''): ?>
          <div class="alert alert-error" style="margin:14px 0 0"><?= e($order['api_error']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($rental): ?>
    <div class="box">
      <div class="box-head"><b>Rental</b><span class="st st-<?= $rental['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= e($rental['status']) ?></span></div>
      <div class="pad">
        <div class="drow"><span>Number</span><b class="mono"><?= e($rental['number']) ?></b></div>
        <div class="drow"><span>Country</span><b><?= e($rental['country_name']) ?> (server <?= e($rental['provider']) ?>)</b></div>
        <div class="drow"><span>Until</span><b><?= e(when($rental['expires_at'])) ?></b></div>
        <div class="drow"><span>OTPGet ids</span><b class="mono"><?= e($rental['provider_order_id']) ?> / <?= e($rental['provider_rental_id'] ?: '?') ?></b></div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/rentals/' . (int) $rental['id'])) ?>">Open the rental</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="box">
      <div class="box-head"><b>Set by hand</b></div>
      <div class="pad">
        <?php /* For when OTPGet and the shop disagree, or a code was sent to
                 the customer some other way: the status, and the code they
                 should see. */ ?>
        <form method="post" action="<?= e(url('admin/orders/action')) ?>" class="progress-set">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <input type="hidden" name="do" value="progress">
          <div class="field2">
            <div class="field">
              <label for="f-status">Status</label>
              <select id="f-status" name="status">
                <?php foreach (ORDER_STATUSES as $status): ?>
                  <option value="<?= e($status) ?>"<?= $order['status'] === $status ? ' selected' : '' ?>>
                    <?= e(str_replace('_', ' ', $status)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="f-code">Code shown to the customer</label>
              <input id="f-code" name="otp_code" value="" placeholder="<?= e($order['otp_code'] ?: 'leave blank to keep') ?>">
            </div>
          </div>
          <button class="btn btn-ghost btn-sm" type="submit">Save</button>
        </form>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Payment</b>
        <span class="st st-<?= $order['paid_at'] ? 'completed' : 'pending' ?>">
          <?= $order['paid_at'] ? 'confirmed' : 'awaiting' ?></span>
      </div>
      <div class="pad">
        <div class="drow"><span>Method</span><b><?= e($order['payment_name'] ?? '-') ?></b></div>
        <div class="drow"><span>Transaction id</span><b class="mono"><?= e($order['trx_id'] ?: '-') ?></b></div>
        <div class="drow"><span>Amount sent</span>
          <b><?= $order['paid_amount'] === null ? '-' : e(money($order['paid_amount'])) ?></b></div>
        <div class="drow"><span>Confirmed at</span><b><?= e(when($order['paid_at'])) ?></b></div>

        <?php /* Everything the gateway reported, as it reported it. The row
                 above carries one field out of this; the rest is what answers
                 "which payment was this" when a customer asks a month later,
                 and it is searchable from the orders list. Shown raw on
                 purpose - a gateway's own wording is the thing worth keeping,
                 and the next gateway will send different keys. */ ?>
        <?php $reported = json_decode((string) ($order['gateway_details'] ?? ''), true); ?>
        <?php if (is_array($reported) && $reported): ?>
          <details class="gwbox">
            <summary>What the gateway reported (<?= (int) count($reported) ?> fields)</summary>
            <?php foreach ($reported as $key => $value): ?>
              <?php if (!is_scalar($value)) { continue; } ?>
              <div class="drow">
                <span class="mono"><?= e((string) $key) ?></span>
                <b class="mono"><?= e((string) $value) ?></b>
              </div>
            <?php endforeach; ?>
          </details>
        <?php endif; ?>

        <?php /* Whatever this method asked for. The labels come from the
                 definition stored at the time, so an order still reads the
                 same after the admin renames or removes a field. */ ?>
        <?php $answers = payfields_stored($order); ?>
        <?php if ($answers): ?>
          <dl class="pfa">
            <?php foreach ($answers as $key => $answer): ?>
              <div>
                <dt><?= e($answer['label'] ?? $key) ?></dt>
                <dd>
                  <?php if (($answer['type'] ?? '') === 'image'): ?>
                    <a class="pf-proof" target="_blank" rel="noopener"
                       href="<?= e(url('admin/orders/proof/' . (int) $order['id'] . '/' . $key)) ?>">
                      <img src="<?= e(url('admin/orders/proof/' . (int) $order['id'] . '/' . $key)) ?>"
                           alt="<?= e($answer['label'] ?? 'Payment screenshot') ?>" loading="lazy">
                    </a>
                  <?php else: ?>
                    <?= e((string) ($answer['value'] ?? '')) ?>
                  <?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>OTPGet &amp; gateway calls</b>
        <?php if ($failedCalls): ?><span class="st st-api_error"><?= $failedCalls ?> failed</span><?php endif; ?>
      </div>
      <div class="pad">
        <?php partial('admin/_calls', ['calls' => $calls]); ?>
      </div>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Actions</b></div>
      <div class="pad acts">
        <?php if ($order['status'] === 'pending'): ?>
          <?= $button('mark_paid', 'Mark as paid', 'btn-primary',
                      'Mark this order paid? What it bought is delivered straight away.') ?>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['paid', 'api_error'], true) && $order['paid_at'] !== null): ?>
          <?= $button('buy', $order['status'] === 'api_error' ? 'Try again now' : 'Deliver now', 'btn-primary') ?>
        <?php endif; ?>

        <?php if ($order['status'] === 'expired' && $state['can_retry']): ?>
          <?= $button('buy', 'Get a replacement number', 'btn-primary') ?>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['processing', 'completed'], true)): ?>
          <?= $button('check', 'Check for the code now') ?>
        <?php endif; ?>

        <?php if ($state['can_change']): ?>
          <?= $button('change', 'Swap for a new number', 'btn-ghost', 'Give this number back and buy another?') ?>
        <?php endif; ?>
        <?php if ($order['status'] === 'processing' && $state['release_in'] === 0): ?>
          <?= $button('release', 'Give the number back', 'btn-ghost',
                      'Give the number back to OTPGet without a replacement?') ?>
        <?php endif; ?>

        <?php if ($order['paid_at'] !== null && !in_array($order['status'], ['refunded', 'cancelled'], true)
                  && $order['product'] !== 'deposit'): ?>
          <?php if (!empty($order['user_id'])): ?>
            <?= $button('refund_wallet', 'Refund to wallet', 'btn-ghost',
                        'Put ' . money((float) ($order['paid_amount'] ?? $order['price'])) . ' back in the customer\'s wallet?') ?>
          <?php else: ?>
            <?= $button('mark_refunded', 'Mark refunded', 'btn-ghost',
                        'Mark this refunded? Do it once the money has gone back to the customer.') ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($order['status'] === 'pending'): ?>
          <?= $button('cancel', 'Cancel order', 'btn-ghost', 'Cancel this unpaid order?') ?>
        <?php elseif ($cancel['show'] && $cancel['wait'] <= 0): ?>
          <?= $button('cancel', 'Cancel order', 'btn-danger', empty($order['user_id'])
              ? 'Cancel this order? The number goes back to OTPGet. This is a guest: return the money by hand afterwards.'
              : 'Cancel this order? The number goes back to OTPGet and ' . money((float) ($order['paid_amount'] ?? $order['price']))
                . ' is refunded to the customer\'s wallet.') ?>
        <?php elseif ($cancel['show']): ?>
          <p class="muted">Cancel is possible in <?= (int) ceil($cancel['wait'] / 60) ?> min - OTPGet refuses it any sooner.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Activity</b></div>
      <div class="pad">
        <form method="post" action="<?= e(url('admin/orders/action')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <input type="hidden" name="do" value="note">
          <div class="field">
            <label for="f-note">Add an internal note</label>
            <input id="f-note" name="note" placeholder="Only admins see this">
          </div>
          <button class="btn btn-ghost btn-block" type="submit">Save note</button>
        </form>

        <ul class="log" style="margin-top:18px">
          <?php if (!$logs): ?>
            <li class="muted">Nothing logged yet.</li>
          <?php else: foreach ($logs as $entry): ?>
            <li><b><?= e(when($entry['created_at'], 'd M, H:i')) ?></b><?= e($entry['message']) ?></li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
