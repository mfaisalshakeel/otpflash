<?php /** @var array $user @var string $section @var array $rental @var array $messages @var array $options @var string $iso @var bool $canCancel */ ?>
<div class="wrap portal">
  <div class="portal-grid">
    <?php partial('account/_nav', ['user' => $user, 'section' => $section]); ?>
    <div>
      <a class="help" href="<?= e(url('account/rentals')) ?>">&larr; All rentals</a>
      <div class="panel" style="margin-top:10px">
        <div class="rent-head">
          <img class="flag flag-lg" src="<?= e(flag_url($iso)) ?>" alt="<?= e($rental['country_name']) ?>" width="44" height="33">
          <div style="flex:1;min-width:0">
            <span class="rent-num mono"><?= e($rental['number']) ?></span>
            <p class="psub" style="margin:0"><?= e($rental['country_name']) ?> &middot; Server <?= e($rental['provider']) ?> &middot;
              <?= $rental['status'] === 'active' ? 'yours until <b>' . e(when($rental['expires_at'])) . '</b>' : e($rental['status']) ?></p>
          </div>
          <button class="btn btn-primary" type="button" data-copy="<?= e($rental['number']) ?>"><svg class="icon"><use href="#i-copy"></use></svg> Copy</button>
        </div>
      </div>

      <div class="ogrid">
        <div>
          <div class="panel">
            <div class="ptitle"><svg class="icon"><use href="#i-inbox"></use></svg> Inbox
              <?php if ($rental['status'] === 'active'): ?><span class="sbadge sb-live" style="margin-left:auto;font-size:12px"><span class="d"></span> live</span><?php endif; ?></div>
            <?php /* Only the newest page listens for new SMS: an older page is history,
                     and redrawing it with the newest would lose the reader's place. */ ?>
            <div class="inbox" <?= $rental['status'] === 'active' && $paging['page'] === 1 ? 'data-inbox data-url="' . e(url('account/rentals/' . $rental['id'] . '/inbox')) . '" data-count="' . (int) $paging['total'] . '"' : '' ?>>
              <?php partial('account/_inbox', ['messages' => $messages]); ?>
            </div>
            <?php partial('account/_pager', ['paging' => $paging, 'base' => 'account/rentals/' . (int) $rental['id']]); ?>
          </div>
        </div>
        <aside>
          <?php if ($options): ?>
            <div class="panel">
              <div class="ptitle"><svg class="icon"><use href="#i-refresh"></use></svg> Renew</div>
              <p class="psub">Add time to this same number.</p>
              <form method="post" action="<?= e(url('account/rentals/' . $rental['id'] . '/renew')) ?>">
                <?= csrf_field() ?>
                <div class="renew-opts">
                  <?php foreach ($options as $i => $option): ?>
                    <label class="opt<?= $i === 0 ? ' on' : '' ?>">
                      <input type="radio" name="offer_id" value="<?= (int) $option['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                      <span class="opt-radio" aria-hidden="true"></span>
                      <span class="olabel"><?= e($option['label']) ?></span>
                      <span class="oprice"><?= e(money($option['sale_price'])) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-primary btn-block" type="submit" name="pay" value="wallet">Renew from wallet</button>
                <button class="btn btn-ghost btn-block" type="submit" name="pay" value="gateway" style="margin-top:8px">Pay another way</button>
                <p class="obal">Balance <b><?= e(money((float) $user['balance'])) ?></b></p>
              </form>
            </div>
          <?php endif; ?>
          <?php if ($canCancel): ?>
            <div class="panel">
              <div class="ptitle"><svg class="icon"><use href="#i-close"></use></svg> Changed your mind?</div>
              <p class="psub">Server 2 rentals can be handed back within 20 minutes, before any SMS arrives, for a full refund to your wallet.</p>
              <form method="post" action="<?= e(url('account/rentals/' . $rental['id'] . '/cancel')) ?>" data-confirm="Hand this number back for a refund?">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-block" type="submit">Cancel and refund</button>
              </form>
            </div>
          <?php endif; ?>
        </aside>
      </div>
    </div>
  </div>
</div>
