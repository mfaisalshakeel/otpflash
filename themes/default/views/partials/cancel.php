<?php
/**
 * Cancelling a number: what this visitor can do, and what it costs them.
 *
 * A dialog over the number screen, opened by its Cancel button, so the
 * choice is in front of the customer rather than unfolding somewhere below
 * the fold. Opens by itself when the customer comes back from signing in to
 * cancel (?cancel=1). The choices come from order_cancel_choice(); this only
 * draws them.
 *
 * @var array $order  @var array $cancel  order_cancel_choice()  @var bool $open
 */
$action = url('order/' . $order['code'] . '/cancel');
$next   = rawurlencode($cancel['next']);
?>
<div class="modal-back" id="cancel" data-cancel-box <?= $open ? '' : 'hidden' ?>>
  <div class="modal modal-sm cancel-box" role="dialog" aria-modal="true" aria-labelledby="cancelTitle" tabindex="-1">
  <div class="cancel-head">
    <b id="cancelTitle">Cancel this <?= $order['product'] === 'email' ? 'inbox' : 'number' ?>?</b>
    <button class="cancel-x" type="button" data-close data-cancel-close aria-label="Keep it">&times;</button>
  </div>

  <?php if ($cancel['others']): ?>
    <p>This order belongs to an account. Sign in to that account to cancel it and get the refund.</p>
    <a class="btn btn-primary btn-block" href="<?= e(url('login?next=' . $next)) ?>">Sign in</a>

  <?php elseif ($cancel['refund']): ?>
    <p>It goes back, and <b><?= e($cancel['amount']) ?></b> returns to your wallet straight away - ready for your next number.</p>
    <form method="post" action="<?= e($action) ?>" data-numact>
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-block" type="submit">Cancel &amp; refund <?= e($cancel['amount']) ?></button>
    </form>

  <?php else: ?>
    <p>Refunds go into a wallet, so they need an account. Sign in or create one free - this order moves into it
      and you come straight back here to cancel it with a refund.</p>
    <div class="cancel-acts">
      <a class="btn btn-primary" href="<?= e(url('login?next=' . $next)) ?>">Sign in &amp; get a refund</a>
      <?php if (setting('registration_open', '1') === '1'): ?>
        <a class="btn btn-ghost" href="<?= e(url('register?next=' . $next)) ?>">Create a free account</a>
      <?php endif; ?>
    </div>
    <div class="cancel-anyway">
      <b>Or cancel without a refund</b>
      <p>You will lose <?= e($cancel['amount']) ?>. It cannot be given back afterwards.</p>
      <form method="post" action="<?= e($action) ?>" data-numact data-confirm-title="Cancel without a refund?"
            data-confirm="You will lose <?= e($cancel['amount']) ?>. It cannot be given back afterwards. Signing in first gets it back into a wallet instead."
            data-confirm-ok="Cancel and lose <?= e($cancel['amount']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="forfeit" value="1">
        <button class="btn btn-danger-soft btn-sm" type="submit">Cancel anyway</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</div>
