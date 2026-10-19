<?php
/**
 * One customer.
 *
 * @var array $user  @var array $summary  @var float $deposited
 * @var array $orders  @var array $rentals  @var array $statement
 */
$form = static function (string $do, string $label, string $class = 'btn-ghost', ?string $confirm = null) use ($user): string {
    return '<form method="post" action="' . e(url('admin/users/action')) . '"'
        . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $user['id'] . '">'
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<button class="btn ' . e($class) . ' btn-block" type="submit">' . e($label) . '</button></form>';
};
?>
<a class="lnk back" href="<?= e(url('admin/users')) ?>">&larr; Back to customers</a>

<div class="cards4">
  <div class="scard scard-plain"><div><small>Wallet balance</small><b><?= e(money((float) $user['balance'])) ?></b>
    <span class="muted"><?= e(money($deposited)) ?> deposited in all</span></div></div>
  <div class="scard scard-plain"><div><small>Paid orders</small><b><?= qty_fmt((int) $summary['orders']) ?></b>
    <span class="muted">deposits not counted</span></div></div>
  <div class="scard scard-plain"><div><small>Spent</small><b><?= e(money((float) $summary['spent'])) ?></b>
    <span class="muted">on numbers, inboxes and rentals</span></div></div>
  <div class="scard scard-plain"><div><small>Profit from them</small><b><?= e(money((float) $summary['profit'])) ?></b>
    <span class="<?= (float) $summary['profit'] >= 0 ? 'up' : 'warn' ?>">price less OTPGet cost</span></div></div>
</div>

<div class="split2 split-wide">
  <div>
    <div class="box">
      <div class="box-head"><b>Orders</b>
        <a class="lnk" href="<?= e(url('admin/orders?user=' . (int) $user['id'])) ?>">See all</a></div>
      <div class="tbl-wrap">
        <table class="tbl tbl-tight">
          <thead><tr><th>Code</th><th>What</th><th>Number</th><th>Total</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$orders): ?>
            <tr><td colspan="5" class="empty">No orders yet.</td></tr>
          <?php else: foreach ($orders as $order): ?>
            <tr onclick="location.href='<?= e(url('admin/orders/' . $order['id'])) ?>'">
              <td><b><?= e($order['code']) ?></b><small class="sub"><?= e(when($order['created_at'], 'd M, H:i')) ?></small></td>
              <td><?= e(excerpt($order['product'] === 'deposit' ? 'Wallet deposit' : order_label($order), 36)) ?></td>
              <td class="mono"><?= e($order['number'] !== '' ? $order['number'] : '-') ?>
                <?php if ($order['otp_code'] !== ''): ?><small class="sub">code <?= e($order['otp_code']) ?></small><?php endif; ?></td>
              <td><?= e(money($order['price'])) ?><small class="sub"><?= e($order['pay_source'] === 'wallet' ? 'wallet' : 'gateway') ?></small></td>
              <td><span class="st st-<?= e($order['status']) ?>"><?= e(str_replace('_', ' ', $order['status'])) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($rentals): ?>
      <div class="box">
        <div class="box-head"><b>Rentals</b>
          <a class="lnk" href="<?= e(url('admin/rentals?user=' . (int) $user['id'])) ?>">See all</a></div>
        <div class="tbl-wrap">
          <table class="tbl tbl-tight">
            <thead><tr><th>Number</th><th>Country</th><th>Until</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rentals as $rental): ?>
              <tr onclick="location.href='<?= e(url('admin/rentals/' . $rental['id'])) ?>'">
                <td class="mono"><b><?= e($rental['number'] !== '' ? $rental['number'] : '-') ?></b></td>
                <td><?= e($rental['country_name']) ?></td>
                <td><?= $rental['expires_at'] ? e(when($rental['expires_at'], 'd M Y, H:i')) : '-' ?></td>
                <td><span class="st st-<?= e($rental['status']) ?>"><?= e($rental['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="box">
      <div class="box-head"><b>Wallet statement</b>
        <a class="lnk" href="<?= e(url('admin/wallet?user=' . (int) $user['id'])) ?>">Full ledger</a></div>
      <div class="tbl-wrap">
        <table class="tbl tbl-tight">
          <thead><tr><th>When</th><th>What</th><th>Amount</th><th>Balance</th></tr></thead>
          <tbody>
          <?php if (!$statement): ?>
            <tr><td colspan="4" class="empty">The wallet has never moved.</td></tr>
          <?php else: foreach ($statement as $line): ?>
            <tr>
              <td><?= e(when($line['created_at'], 'd M, H:i')) ?></td>
              <td><?= e(WALLET_TYPES[$line['type']] ?? $line['type']) ?>
                <small class="sub"><?= e($line['note']) ?><?= $line['order_code'] ? ' · ' . e($line['order_code']) : '' ?></small></td>
              <td class="<?= (float) $line['amount'] >= 0 ? 'amt-in' : 'amt-out' ?>">
                <?= (float) $line['amount'] >= 0 ? '+' : '' ?><?= e(money((float) $line['amount'])) ?></td>
              <td><?= e(money((float) $line['balance_after'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b><?= e($user['name']) ?></b>
        <span class="st st-<?= e($user['status']) ?>"><?= e($user['status']) ?></span></div>
      <div class="pad">
        <div class="drow"><span>Customer no.</span><b>#<?= (int) $user['id'] ?></b></div>
        <div class="drow"><span>Joined</span><b><?= e(when($user['created_at'])) ?></b></div>
        <div class="drow"><span>Last signed in</span><b><?= $user['last_login_at'] ? e(when($user['last_login_at'])) : 'never' ?></b></div>
        <div class="drow"><span>From IP</span><b class="mono"><?= e($user['last_login_ip'] ?: '-') ?></b></div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Adjust the wallet</b></div>
      <form class="pad" method="post" action="<?= e(url('admin/users/action')) ?>"
            data-confirm="Change this customer's balance?">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="do" value="adjust">
        <div class="field">
          <label for="w-way">Direction</label>
          <select id="w-way" name="way">
            <option value="credit">Add to the balance</option>
            <option value="debit">Take from the balance</option>
          </select>
        </div>
        <div class="field">
          <label for="w-amount">Amount (<?= e(trim(base_currency_symbol())) ?>)</label>
          <input id="w-amount" name="amount" type="number" step="0.01" min="0.01" required>
        </div>
        <div class="field">
          <label for="w-note">Note</label>
          <input id="w-note" name="note" maxlength="190" required placeholder="e.g. Bank transfer received, goodwill credit">
          <small>The customer sees this on their statement.</small>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Save adjustment</button>
      </form>
    </div>

    <div class="box">
      <div class="box-head"><b>Details</b></div>
      <form class="pad" method="post" action="<?= e(url('admin/users/action')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="do" value="save">
        <div class="field"><label for="u-name">Name</label>
          <input id="u-name" name="name" value="<?= e($user['name']) ?>" maxlength="120" required></div>
        <div class="field"><label for="u-email">Email</label>
          <input id="u-email" name="email" type="email" value="<?= e($user['email']) ?>" maxlength="190" required></div>
        <div class="field"><label for="u-phone">Phone</label>
          <input id="u-phone" name="phone" value="<?= e($user['phone']) ?>" maxlength="40"></div>
        <button class="btn btn-ghost btn-block" type="submit">Save details</button>
      </form>
    </div>

    <div class="box">
      <div class="box-head"><b>Account</b></div>
      <div class="pad acts">
        <?= $form('reset', 'Email a password reset link') ?>
        <?php if ($user['status'] === 'active'): ?>
          <?= $form('block', 'Block this account', 'btn-danger',
              'Block ' . $user['email'] . '? They are signed out and cannot sign in. Their balance is kept.') ?>
        <?php else: ?>
          <?= $form('unblock', 'Unblock this account', 'btn-primary') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
