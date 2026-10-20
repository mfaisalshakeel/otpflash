<?php
/**
 * The number we held costs more than the price that was shown: say so, and
 * ask before anything is charged.
 *
 * The figure offered is our price for the number's real cost - the same rule
 * every price on the shop follows - never the cost itself. Drawn inside the
 * order box straight after Pay, and on the order page for anyone who comes
 * back to it, so the question is the same wherever it is asked.
 *
 * @var array $order  @var array $state  number_state()
 */
$user   = current_user();
$quote  = (float) $state['quote'];
$wallet = $user && (int) $user['id'] === (int) ($order['user_id'] ?? 0)
          && (float) $user['balance'] + 0.001 >= $quote;
$thing  = $order['product'] === 'email' ? 'inbox' : 'number';
?>
<div class="quote" data-quote>
  <div class="quote-head">
    <span class="quote-ico"><svg class="icon"><use href="#i-tag"></use></svg></span>
    <div>
      <b>Not available at <?= e(money((float) $order['price'])) ?></b>
      <small><?= e(order_label($order)) ?></small>
    </div>
  </div>

  <p>The price moved a moment ago. We found your <?= e($thing) ?> and it costs
    <b><?= e(money($quote)) ?></b> right now. It is held for you for
    <b class="quote-clock" data-hold-left="<?= (int) $state['hold_left'] ?>"><?= e(sprintf('%d:%02d', intdiv((int) $state['hold_left'], 60), (int) $state['hold_left'] % 60)) ?></b>
    - nothing has been charged.</p>

  <div class="quote-price">
    <span><small>Was</small><s><?= e(money((float) $order['price'])) ?></s></span>
    <span class="quote-arrow" aria-hidden="true">&rarr;</span>
    <span><small>Now</small><b><?= e(money($quote)) ?></b></span>
  </div>

  <form method="post" action="<?= e(url('order/' . $order['code'] . '/accept')) ?>" data-quote-accept>
    <?= csrf_field() ?>
    <?php if ($wallet): ?><input type="hidden" name="pay" value="wallet"><?php endif; ?>
    <button class="btn btn-primary btn-block" type="submit">
      Pay <?= e(money($quote)) ?><?= $wallet ? ' from your wallet' : '' ?></button>
  </form>
  <form method="post" action="<?= e(url('order/' . $order['code'] . '/cancel')) ?>" data-quote-decline>
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-block" type="submit">No thanks - pay nothing</button>
  </form>
  <p class="help quote-foot">Turn it down and you pay nothing; pick another country or server instead.</p>
</div>
