<?php /** @var array $user @var string $section @var array $rentals */ ?>
<div class="wrap portal">
  <div class="portal-grid">
    <?php partial('account/_nav', ['user' => $user, 'section' => $section]); ?>
    <div>
      <div class="phead">
        <div><h1>Rentals</h1><p>Numbers you keep, each with an inbox for every SMS it receives.</p></div>
        <?php if (isset(store_tabs()['rental'])): ?>
          <a class="btn btn-primary" href="<?= e(url('rent-number')) ?>"><svg class="icon"><use href="#i-plus"></use></svg> Rent a number</a>
        <?php endif; ?>
      </div>
      <?php if (!$rentals): ?>
        <div class="empty-state"><b>No rentals yet</b>Rent a number for a day, a week, a month or a year - it stays yours, with an inbox.</div>
      <?php else: ?>
        <div class="live-list">
          <?php foreach ($rentals as $rental): ?>
            <a class="live-item" href="<?= e(url('account/rentals/' . $rental['id'])) ?>">
              <img class="flag" src="<?= e(flag_url($rental['country_code'])) ?>" alt="" width="28" height="21">
              <span class="grow"><b class="mono"><?= e($rental['number']) ?></b>
                <small><?= e($rental['country_name']) ?> &middot; <?= $rental['status'] === 'active'
                    ? 'until ' . e(when($rental['expires_at'], 'd M Y, H:i')) : e($rental['status']) ?>
                  &middot; <?= (int) $rental['messages'] ?> message<?= (int) $rental['messages'] === 1 ? '' : 's' ?></small></span>
              <span class="st st-<?= $rental['status'] === 'active' ? 'active' : 'cancelled' ?>"><?= e($rental['status']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
