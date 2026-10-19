<?php /** @var array $user @var string $section @var array $live @var array $recent @var array $rentals @var array $stats */ ?>
<div class="wrap portal">
  <div class="portal-grid">
    <?php partial('account/_nav', ['user' => $user, 'section' => $section]); ?>
    <div>
      <div class="phead">
        <div><h1>Hi, <?= e(explode(' ', $user['name'])[0]) ?></h1><p>Here is everything you have going on.</p></div>
        <a class="btn btn-primary" href="<?= e(url('')) ?>"><svg class="icon"><use href="#i-plus"></use></svg> Buy a number</a>
      </div>

      <div class="dash-top">
        <div class="wallet-card">
          <small>Wallet balance</small>
          <b><?= e(money((float) $user['balance'])) ?></b>
          <span>Numbers bought from here are one tap, and refunds land back here automatically.</span>
          <a class="btn" href="<?= e(url('account/wallet')) ?>"><svg class="icon"><use href="#i-plus"></use></svg> Add funds</a>
        </div>
        <div class="quick">
          <a href="<?= e(url('')) ?>"><svg class="icon"><use href="#i-phone"></use></svg> Virtual number</a>
          <?php if (isset(store_tabs()['email'])): ?><a href="<?= e(url('temporary-email')) ?>"><svg class="icon"><use href="#i-mail"></use></svg> Temp email</a><?php endif; ?>
          <?php if (isset(store_tabs()['rental'])): ?><a href="<?= e(url('rent-number')) ?>"><svg class="icon"><use href="#i-calendar"></use></svg> Rent a number</a><?php endif; ?>
          <a href="<?= e(url('account/orders')) ?>"><svg class="icon"><use href="#i-bag"></use></svg> All orders</a>
        </div>
      </div>

      <div class="kpis">
        <div class="kpi"><small>Numbers bought</small><b><?= qty_fmt($stats['numbers']) ?></b></div>
        <div class="kpi"><small>Codes received</small><b><?= qty_fmt($stats['codes']) ?></b></div>
        <div class="kpi"><small>Spent</small><b><?= e(money(max(0, $stats['spent']))) ?></b></div>
        <div class="kpi"><small>Refunded to you</small><b><?= e(money($stats['refunded'])) ?></b></div>
      </div>

      <?php if ($live): ?>
        <div class="panel">
          <div class="ptitle"><svg class="icon"><use href="#i-sms"></use></svg> Live now</div>
          <div class="live-list">
            <?php foreach ($live as $order): ?>
              <a class="live-item" href="<?= e(url('order/' . $order['code'])) ?>">
                <?= service_icon_html(['name' => $order['service_name'], 'icon' => $order['service_icon'] ?? '', 'color' => $order['service_color'] ?? '']) ?>
                <span class="grow"><b><?= e(order_label($order)) ?></b>
                  <small class="mono"><?= e($order['number'] !== '' ? $order['number'] : 'getting your number…') ?></small></span>
                <?php if ($order['otp_code'] !== ''): ?>
                  <span class="live-code"><?= e($order['otp_code']) ?></span>
                <?php else: ?>
                  <span class="st st-processing">waiting for SMS</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($rentals): ?>
        <div class="panel">
          <div class="ptitle"><svg class="icon"><use href="#i-calendar"></use></svg> Active rentals</div>
          <div class="live-list">
            <?php foreach ($rentals as $rental): ?>
              <a class="live-item" href="<?= e(url('account/rentals/' . $rental['id'])) ?>">
                <img class="flag" src="<?= e(flag_url($rental['country_code'])) ?>" alt="" width="28" height="21">
                <span class="grow"><b class="mono"><?= e($rental['number']) ?></b><small>until <?= e(when($rental['expires_at'], 'd M Y, H:i')) ?></small></span>
                <span class="st st-active">open inbox</span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel">
        <div class="ptitle"><svg class="icon"><use href="#i-bag"></use></svg> Recent orders
          <a class="help" style="margin:0 0 0 auto" href="<?= e(url('account/orders')) ?>">See all</a></div>
        <?php partial('account/_orders-table', ['orders' => $recent]); ?>
      </div>
    </div>
  </div>
</div>
