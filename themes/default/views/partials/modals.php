<?php
/**
 * The two dialogs every page carries.
 *
 *   #payModal    opened by the picker once an order is placed and a payment
 *                method has to be chosen; the server sends the pay step and
 *                it is dropped into [data-pay-slot].
 *   #trackModal  find an order by its code.
 *
 * Both are ordinary markup with the hidden attribute; app.js opens and
 * closes them. Without the script nothing opens them, and nothing needs to:
 * the picker posts to /order and the order page shows the pay step itself.
 */
?>
<div class="modal-back" id="payModal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="payModalTitle" tabindex="-1">
    <button class="modal-x" type="button" data-close aria-label="Close">
      <svg class="icon"><use href="#i-close"></use></svg></button>
    <div class="modal-head">
      <span class="om-tick"><svg class="icon"><use href="#i-check"></use></svg></span>
      <div>
        <b id="payModalTitle">Order placed &mdash; choose how to pay</b>
        <small data-pay-summary></small>
      </div>
    </div>
    <div class="acct acct-code">
      <div><small>Your order code</small><b data-pay-code></b></div>
      <button class="copy" type="button" data-copy="" data-pay-copy>Copy</button>
    </div>
    <div data-pay-slot></div>
    <p class="help modal-foot">You can also pay later from the order page:
      <a href="#" data-pay-link>open order</a></p>
  </div>
</div>

<div class="modal-back" id="trackModal" hidden>
  <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="trackTitle" tabindex="-1">
    <button class="modal-x" type="button" data-close aria-label="Close">
      <svg class="icon"><use href="#i-close"></use></svg></button>
    <h2 id="trackTitle" class="modal-title">Track an order</h2>
    <p class="psub">Type the order code you were given - it starts with <?= e(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) setting('order_prefix', 'OF'))) ?: 'OF') ?>-.</p>
    <form id="trackForm" method="get" action="<?= e(url('track')) ?>" data-api="<?= e(url('api/track')) ?>">
      <div class="field">
        <label class="sr-only" for="tkInput">Order code</label>
        <input id="tkInput" name="code" placeholder="e.g. OF-8F42KD3M" autocomplete="off" spellcheck="false"
               autocapitalize="characters" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit" data-track-go>Find my order</button>
    </form>
    <div class="form-err" data-track-error hidden></div>
    <div class="tk-card" data-track-card hidden>
      <div class="orow"><span>Order</span><b data-tk="code"></b></div>
      <div class="orow"><span>Item</span><b data-tk="service"></b></div>
      <div class="orow"><span>Status</span><b data-tk="status_text"></b></div>
      <div class="orow"><span>Placed</span><b data-tk="placed_at"></b></div>
      <a class="btn btn-primary btn-block" href="#" data-tk-open>Open the order</a>
    </div>
    <?php if (is_logged_in()): ?>
      <p class="help">Signed in? Every order you placed is in <a href="<?= e(url('account/orders')) ?>">My orders</a>.</p>
    <?php endif; ?>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
