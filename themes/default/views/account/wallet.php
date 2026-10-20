<?php /** @var array $user @var string $section @var array $lines @var array $paging @var float $min @var float $suggest @var array $pending @var bool $canPay @var array $totals */ ?>
<div class="portal-page">
  <div class="phead"><div><h1>Wallet</h1><p>Top up once, then buy numbers in one tap. Refunds land back here by themselves.</p></div></div>

  <div class="dash-top">
    <div class="wallet-card">
      <small>Balance</small>
      <b><?= e(money((float) $user['balance'])) ?></b>
      <span>Never expires. Use it on any number, inbox or rental.</span>
      <dl class="wallet-totals">
        <?php foreach ($totals as $label => $amount): ?>
          <div><dt><?= e($label) ?></dt><dd><?= e(money(max(0, $amount))) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>
    <div class="panel" style="margin:0">
      <div class="ptitle"><svg class="icon"><use href="#i-plus"></use></svg> Add funds</div>
      <?php if (!$canPay): ?>
        <p class="psub">Deposits are switched off for a moment. Please check back shortly.</p>
      <?php else: ?>
        <form method="post" action="<?= e(url('account/wallet')) ?>">
          <?= csrf_field() ?>
          <div class="amounts">
            <?php foreach ([max($min, 200), 500, 1000, 2500, 5000] as $preset): ?>
              <?php if ($preset < $min) { continue; } ?>
              <button type="button" data-amount="<?= e((string) $preset) ?>"><?= e(money($preset)) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="field">
            <label for="depositAmount">Amount</label>
            <input id="depositAmount" name="amount" type="number" min="<?= e((string) $min) ?>" step="1"
                   value="<?= e((string) (int) ceil($suggest)) ?>" required inputmode="numeric">
            <small class="hint">At least <?= e(money($min)) ?>.</small>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Continue to payment</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($pending): ?>
    <div class="alert alert-info" style="margin:16px 0">
      You have <?= count($pending) ?> deposit<?= count($pending) === 1 ? '' : 's' ?> waiting for payment:
      <?php foreach ($pending as $i => $order): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('order/' . $order['code'])) ?>"><b><?= e(money($order['price'])) ?></b> (<?= e($order['code']) ?>)</a><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="panel" style="margin-top:16px">
    <div class="ptitle"><svg class="icon"><use href="#i-list"></use></svg> Statement</div>
    <div class="tbl-wrap">
      <table class="tbl tbl-cards">
        <thead><tr><th>When</th><th>What</th><th>Order</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance</th></tr></thead>
        <tbody>
        <?php if (!$lines): ?>
          <tr><td colspan="5" class="pempty">Nothing yet - your first deposit will appear here.</td></tr>
        <?php endif; ?>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td data-label="When"><?= e(when($line['created_at'], 'd M Y, H:i')) ?></td>
            <td data-label="What"><span class="st st-<?= e($line['type']) ?>"><?= e(WALLET_TYPES[$line['type']] ?? $line['type']) ?></span>
              <small class="sub"><?= e($line['note']) ?></small></td>
            <td data-label="Order"><?= $line['order_code'] ? '<a class="row-link" href="' . e(url('order/' . $line['order_code'])) . '">' . e($line['order_code']) . '</a>' : '-' ?></td>
            <td data-label="Amount" style="text-align:right" class="<?= (float) $line['amount'] >= 0 ? 'amt-plus' : 'amt-minus' ?>">
              <?= (float) $line['amount'] >= 0 ? '+' : '&minus;' ?><?= e(money(abs((float) $line['amount']))) ?></td>
            <td data-label="Balance" style="text-align:right"><?= e(money((float) $line['balance_after'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php partial('account/_pager', ['paging' => $paging, 'base' => 'account/wallet', 'query' => []]); ?>
  </div>
</div>
