<?php
/**
 * One order: paying for it, then the number and its code.
 *
 * The left column is whatever the customer has to do next - pay, wait, copy
 * the number, read the code - and nothing else; the right column is the
 * receipt. While something is still moving the page asks /status every few
 * seconds (app.js, [data-live]) and updates itself, so nobody has to know to
 * press refresh to see their code.
 *
 * @var array  $order  @var ?array $state  number_state(), for number products
 * @var bool   $owner  the signed-in customer placed it
 * @var array  $cancel  order_cancel_choice()  @var bool $cancelOpen  back from signing in to cancel
 * @var array  $methods  @var array $gateways  @var bool $checkOnLoad  @var array $payErrors
 */
$status    = (string) $order['status'];
$product   = (string) $order['product'];
$isNumber  = $state !== null;
$isEmail   = $product === 'email';
$isDeposit = $product === 'deposit';
$isRental  = in_array($product, ['rental', 'renewal'], true);
$submitted = $status === 'pending' && (trim((string) $order['trx_id']) !== '' || !empty($order['payment_details']));
$redo      = $submitted && isset($_GET['pay']);
// "Awaiting payment" reads as an accusation to someone who has just paid.
$words     = $submitted ? 'Checking payment' : (ORDER_STATUS_WORDS[$status] ?? $status);
$live      = ($status === 'pending' && $submitted) || $status === 'paid' || $status === 'processing'
             || ($status === 'completed' && $isNumber && $state['seconds_left'] > 0);

$chatMessage = "Hi, order {$order['code']}\n" . order_label($order) . "\n" . 'Amount: ' . money($order['price'])
    . ($order['number'] !== '' ? "\nNumber: {$order['number']}" : '');
$chat = chat_channels('order', $chatMessage);

$tone = match ($status) {
    'completed'            => 'ok',
    'processing', 'paid'   => 'live',
    'expired', 'api_error' => 'warn',
    'refunded', 'cancelled'=> 'muted',
    default                => 'pending',
};
$again = !empty($order['service_slug']) ? url((string) $order['service_slug']) : url('');
?>
<section class="page-band">
  <div class="wrap obar">
    <div class="ocode">
      <small>Order code</small>
      <span class="ocode-row"><?= e($order['code']) ?>
        <button class="chip-btn" type="button" data-copy="<?= e($order['code']) ?>" aria-label="Copy the order code">
          <svg class="icon"><use href="#i-copy"></use></svg> Copy</button>
      </span>
    </div>
    <span class="sbadge sb-<?= e($tone) ?>"><span class="d"></span> <?= e($words) ?></span>
  </div>
</section>

<div class="wrap opage">
  <div class="ogrid">
    <div class="ocol-main"
         <?= $live ? 'data-live data-status="' . e($status) . '" data-url="' . e(url('order/' . $order['code'] . '/status')) . '"' : '' ?>>

      <?php if ($status === 'pending' && (!$submitted || $redo)): ?>
        <?php /* ---------------------------------------------- pay ---- */ ?>
        <div class="panel">
          <div class="ptitle"><svg class="icon"><use href="#i-card"></use></svg>
            <?= $isDeposit ? 'Add funds to your wallet' : 'Complete your payment' ?></div>
          <p class="psub"><?= $isDeposit
              ? 'Pay ' . e(money($order['price'])) . ' and it lands in your wallet the moment the payment is confirmed.'
              : 'Your ' . ($isEmail ? 'inbox' : 'number') . ' is bought the second the payment is confirmed - keep this page open.' ?></p>
        </div>
        <?php partial('partials/pay', ['order' => $order, 'methods' => $methods,
            'gateways' => $gateways, 'chat' => $chat, 'payErrors' => $payErrors]); ?>
        <?php if (!$redo && !$isDeposit): ?>
          <form class="pay-cancel" method="post" action="<?= e(url('order/' . $order['code'] . '/cancel')) ?>" data-numact
                data-confirm-title="Cancel this order?" data-confirm="Nothing has been charged, so there is nothing to refund."
                data-confirm-ok="Yes, cancel it" data-confirm-tone="info">
            <?= csrf_field() ?>
            <button class="lnk-btn" type="submit">Changed your mind? Cancel this order</button>
          </form>
        <?php endif; ?>

      <?php elseif ($isNumber && in_array($status, ['pending', 'paid'], true)): ?>
        <?php /* ----------------- the number screen, before the number ---- *
           From the moment the payment is sent this is already the screen the
           code arrives on: the same card, with the number still to come. The
           page asks for its state every few seconds and redraws itself as
           the number and then the code arrive - nothing to open or press. */ ?>
        <div class="numcard numcard-soon" data-numcard>
          <div class="nc-head">
            <?php if (!empty($order['service_icon']) || $order['service_name'] !== ''): ?>
              <?= service_icon_html(['name' => $order['service_name'], 'icon' => $order['service_icon'] ?? '',
                  'color' => $order['service_color'] ?? ''], 'svc-ico svc-ico-lg') ?>
            <?php endif; ?>
            <?php if ($order['country_iso'] !== ''): ?>
              <img class="flag flag-lg" src="<?= e(flag_url($order['country_iso'])) ?>" alt="<?= e($order['country_name']) ?>" width="40" height="30">
            <?php endif; ?>
            <div class="nc-title">
              <b><?= e(order_label($order)) ?></b>
              <small><?= $isEmail ? 'Temporary email' : 'Virtual number' ?></small>
            </div>
          </div>
          <div class="nc-number">
            <small><?= $isEmail ? 'Your temporary email address' : 'Your number' ?></small>
            <span class="nc-num nc-num-soon" aria-hidden="true"><?= $isEmail ? '••••••@••••.•••'
                : e(($state['dial'] !== '' ? '+' . $state['dial'] . ' ' : '+') . '••• •••••••') ?></span>
            <p class="nc-soon-line"><span class="spinner spinner-sm" aria-hidden="true"></span>
              <?= $status === 'paid' ? 'Payment received - getting your ' . ($isEmail ? 'inbox' : 'number') . '…'
                  : 'Confirming your payment - usually a few minutes.' ?></p>
          </div>
          <div class="nc-sms">
            <div class="nc-waiting">
              <span class="pulse" aria-hidden="true"><i></i><i></i><i></i></span>
              <div>
                <b>Your code will appear right here</b>
                <small>Keep this page open. The <?= $isEmail ? 'address' : 'number' ?> shows up by itself, then the code - no need to refresh.</small>
              </div>
            </div>
          </div>
          <?php if ($status === 'pending'): ?>
            <p class="nc-tips nc-tips-plain">Sent the wrong payment details?
              <a href="<?= e(url('order/' . $order['code']) . '?pay=1') ?>">Send them again</a></p>
          <?php endif; ?>
        </div>

      <?php elseif ($status === 'pending'): ?>
        <?php /* ---------------------------- a manual payment to confirm ---- */ ?>
        <div class="panel wait-panel">
          <span class="spinner" aria-hidden="true"></span>
          <h2>We are confirming your payment</h2>
          <p>Usually a few minutes. Your <?= $isDeposit ? 'funds appear' : ($isEmail ? 'inbox appears' : 'number appears') ?>
            right here as soon as it is confirmed - you do not need to refresh.</p>
          <p class="help">Sent the wrong details? <a href="<?= e(url('order/' . $order['code']) . '?pay=1') ?>">Send them again</a></p>
        </div>

      <?php elseif ($status === 'paid'): ?>
        <div class="panel wait-panel">
          <span class="spinner" aria-hidden="true"></span>
          <h2><?= $isDeposit ? 'Adding it to your wallet…' : ($isRental ? 'Setting up your rental…' : 'Getting your ' . ($isEmail ? 'inbox' : 'number') . '…') ?></h2>
          <p>Payment received. This takes a few seconds.</p>
        </div>

      <?php elseif ($isNumber && in_array($status, ['processing', 'completed'], true)): ?>
        <?php /* ----------------------------------------- the number ---- */ ?>
        <div class="numcard" data-numcard>
          <div class="nc-head">
            <?php if (!empty($order['service_icon']) || $order['service_name'] !== ''): ?>
              <?= service_icon_html(['name' => $order['service_name'], 'icon' => $order['service_icon'] ?? '',
                  'color' => $order['service_color'] ?? ''], 'svc-ico svc-ico-lg') ?>
            <?php endif; ?>
            <?php if ($order['country_iso'] !== ''): ?>
              <img class="flag flag-lg" src="<?= e(flag_url($order['country_iso'])) ?>" alt="<?= e($order['country_name']) ?>" width="40" height="30">
            <?php endif; ?>
            <div class="nc-title">
              <b><?= e(order_label($order)) ?></b>
              <?php $changesLeft = max(0, (int) $state['attempts_max'] - (int) $state['attempts']); ?>
              <small><?= $isEmail ? 'Temporary email' : 'Virtual number' ?><?php if ($status === 'processing' && $state['attempts_max'] > 1): ?>
                &middot; <?= $changesLeft ?> free change<?= $changesLeft === 1 ? '' : 's' ?> left<?php endif; ?></small>
            </div>
            <?php if ($state['seconds_left'] > 0): ?>
              <div class="nc-timer" data-timer data-seconds="<?= (int) $state['seconds_left'] ?>"
                   title="How long this <?= $isEmail ? 'inbox' : 'number' ?> is held for you">
                <svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.9" class="nc-track"/><circle cx="18" cy="18" r="15.9" class="nc-bar" data-timer-bar/></svg>
                <span data-timer-text><?= sprintf('%d:%02d', intdiv((int) $state['seconds_left'], 60), (int) $state['seconds_left'] % 60) ?></span>
              </div>
            <?php elseif ($state['code'] !== ''): ?>
              <?php /* A clock at 0:00 beside a delivered code reads as something gone wrong. */ ?>
              <span class="nc-done"><svg class="icon"><use href="#i-check"></use></svg> Delivered</span>
            <?php endif; ?>
          </div>

          <div class="nc-number">
            <small><?= $isEmail ? 'Your temporary email address' : 'Your number' ?></small>
            <div class="nc-num-row">
              <b class="nc-num" data-number-text><?= e($state['number']) ?></b>
            </div>
            <div class="nc-copies">
              <button class="btn btn-primary" type="button" data-copy="<?= e($state['number']) ?>">
                <svg class="icon"><use href="#i-copy"></use></svg> Copy <?= $isEmail ? 'email' : 'number' ?></button>
              <?php if (!$isEmail && $state['dial'] !== '' && $state['local'] !== ltrim($state['number'], '+')): ?>
                <button class="btn btn-ghost" type="button" data-copy="<?= e($state['local']) ?>">
                  Copy without +<?= e($state['dial']) ?></button>
              <?php endif; ?>
            </div>
          </div>

          <div class="nc-sms<?= $state['code'] !== '' ? ' has-code' : '' ?>" data-sms>
            <div class="nc-waiting" <?= $state['code'] !== '' ? 'hidden' : '' ?>>
              <span class="pulse" aria-hidden="true"><i></i><i></i><i></i></span>
              <div>
                <b>Waiting for the <?= $isEmail ? 'email' : 'SMS' ?>…</b>
                <small>Enter the <?= $isEmail ? 'address' : 'number' ?> in <?= e($order['service_name'] ?: 'the app') ?> and ask it to send the code.
                  It appears here by itself.</small>
              </div>
            </div>
            <div class="nc-code" <?= $state['code'] === '' ? 'hidden' : '' ?>>
              <small>Your code</small>
              <div class="nc-code-row">
                <b class="nc-code-text" data-code-text><?= e($state['code']) ?></b>
                <button class="btn btn-primary" type="button" data-copy="<?= e($state['code']) ?>" data-code-copy>
                  <svg class="icon"><use href="#i-copy"></use></svg> Copy code</button>
              </div>
            </div>
            <ul class="nc-codes" data-codes <?= count($state['codes']) < 2 ? 'hidden' : '' ?>>
              <?php foreach ($state['codes'] as $received): ?>
                <li><b><?= e($received['code']) ?></b><small><?= e($received['at']) ?></small></li>
              <?php endforeach; ?>
            </ul>
          </div>

          <div class="nc-actions">
            <?php if ($status === 'processing'): ?>
              <?php $freeLeft = max(0, (int) $state['attempts_max'] - (int) $state['attempts']); ?>
              <?php /* Free, and it says so on the button: a replacement number is
                       part of what was paid for, up to max_numbers_per_order. */ ?>
              <form method="post" action="<?= e(url('order/' . $order['code'] . '/change')) ?>" data-numact
                    data-confirm-title="Change to a new number - free?" data-confirm-tone="info" data-confirm-ok="Yes, change it"
                    data-confirm="This number goes back and you get a different one for <?= e($order['service_name'] ?: 'the same app') ?>, at no extra cost. <?= $freeLeft ?> free change<?= $freeLeft === 1 ? '' : 's' ?> left.">
                <?= csrf_field() ?>
                <button class="btn btn-ghost" type="submit" data-change data-release="<?= (int) $state['release_in'] ?>" data-left="<?= $freeLeft ?>"
                        <?= $state['can_change'] ? '' : 'disabled' ?>>
                  <svg class="icon"><use href="#i-refresh"></use></svg>
                  <span data-change-text><?= $state['release_in'] > 0
                      ? 'Free change in ' . sprintf('%d:%02d', intdiv($state['release_in'], 60), $state['release_in'] % 60)
                      : ($freeLeft <= 0 ? 'No free changes left' : 'Change number - free') ?></span>
                </button>
              </form>
              <?php if ($cancel['show']): ?>
                <?php /* Waits out the same two minutes as Change number: OTPGet
                         refuses a cancel any sooner. */ ?>
                <button class="btn btn-ghost" type="button" data-cancel data-cancel-open <?= $cancel['wait'] > 0 ? 'disabled' : '' ?>>
                  <svg class="icon"><use href="#i-close"></use></svg> Cancel</button>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn btn-primary" href="<?= e($again) ?>"><svg class="icon"><use href="#i-plus"></use></svg> Buy another</a>
            <?php endif; ?>
          </div>

          <?php if ($cancel['show']): ?>
            <?php partial('partials/cancel', ['order' => $order, 'cancel' => $cancel, 'open' => $cancelOpen && $cancel['wait'] <= 0]); ?>
          <?php endif; ?>

          <?php if ($status === 'processing' && $state['code'] === ''): ?>
            <ol class="nc-tips">
              <li>Copy the <?= $isEmail ? 'address' : 'number' ?> and paste it into <?= e($order['service_name'] ?: 'the app') ?>.</li>
              <li>Ask for the code. Most arrive in under a minute.</li>
              <li>No code after a couple of minutes? <b>Change number</b> - it is free<?= $owner ? ', or cancel for a refund' : '' ?>.</li>
            </ol>
          <?php elseif ($state['code'] !== '' && $state['seconds_left'] > 0): ?>
            <p class="nc-tips nc-tips-plain">Asked for another code? It shows up here too, while the <?= $isEmail ? 'inbox' : 'number' ?> is held.</p>
          <?php endif; ?>
        </div>

      <?php elseif ($status === 'expired'): ?>
        <div class="panel state-panel">
          <span class="state-ico state-warn"><svg class="icon"><use href="#i-clock"></use></svg></span>
          <h2>No code arrived on this <?= $isEmail ? 'inbox' : 'number' ?></h2>
          <p>The <?= $isEmail ? 'inbox' : 'number' ?> <b><?= e($order['number']) ?></b> closed before a code came, so it was not charged.
            <?= !empty($order['user_id']) && $order['status'] === 'refunded' ? 'Your money is back in your wallet.'
                : ($state && $state['can_retry'] ? 'Your next number is free.' : '') ?></p>
          <?php if ($state && $state['can_retry']): ?>
            <form method="post" action="<?= e(url('order/' . $order['code'] . '/retry')) ?>" data-numact>
              <?= csrf_field() ?>
              <button class="btn btn-primary btn-lg" type="submit"><svg class="icon"><use href="#i-refresh"></use></svg>
                Get a new number free</button>
            </form>
            <p class="help"><?= (int) ($state['attempts_max'] - $state['attempts']) ?> free replacement<?= $state['attempts_max'] - $state['attempts'] === 1 ? '' : 's' ?> left on this order.</p>
          <?php else: ?>
            <p class="help">This order has used all of its numbers. Message us with the order code and we will make it right.</p>
          <?php endif; ?>
          <?php if ($cancel['show']): ?>
            <button class="lnk-btn" type="button" data-cancel-open>Cancel this order instead</button>
            <?php partial('partials/cancel', ['order' => $order, 'cancel' => $cancel, 'open' => $cancelOpen]); ?>
          <?php endif; ?>
        </div>

      <?php elseif ($status === 'api_error'): ?>
        <div class="panel state-panel">
          <span class="state-ico state-warn"><svg class="icon"><use href="#i-alert"></use></svg></span>
          <h2>We could not get <?= $isRental ? 'that rental' : 'a number' ?> just now</h2>
          <p><?= e($state['error'] ?? 'No numbers were available for this a moment ago. Stock changes every minute.') ?></p>
          <div class="state-acts">
            <?php if ($state && $state['can_retry']): ?>
              <form method="post" action="<?= e(url('order/' . $order['code'] . '/retry')) ?>" data-numact>
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-refresh"></use></svg> Try again</button>
              </form>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e($again) ?>">Pick another country or server</a>
          </div>
          <p class="help">Your payment is safe on this order - a number bought later is still covered by it.</p>
          <?php if ($cancel['show']): ?>
            <button class="lnk-btn" type="button" data-cancel-open>Cancel this order instead</button>
            <?php partial('partials/cancel', ['order' => $order, 'cancel' => $cancel, 'open' => $cancelOpen]); ?>
          <?php endif; ?>
        </div>

      <?php elseif ($status === 'refunded'): ?>
        <div class="panel state-panel">
          <span class="state-ico"><svg class="icon"><use href="#i-wallet"></use></svg></span>
          <h2>Refunded</h2>
          <p><?= !empty($order['user_id'])
              ? e(money((float) ($order['paid_amount'] ?? $order['price']))) . ' went back into your wallet. Use it on your next number - it is one tap.'
              : 'The money for this order was returned to you.' ?></p>
          <div class="state-acts">
            <a class="btn btn-primary" href="<?= e($again) ?>">Buy another number</a>
            <?php if (!empty($order['user_id'])): ?><a class="btn btn-ghost" href="<?= e(url('account/wallet')) ?>">See your wallet</a><?php endif; ?>
          </div>
        </div>

      <?php elseif ($isDeposit && $status === 'completed'): ?>
        <div class="panel state-panel">
          <span class="state-ico state-ok"><svg class="icon"><use href="#i-check"></use></svg></span>
          <h2><?= e(money($order['price'])) ?> added to your wallet</h2>
          <p>Every number from now on is one tap, and anything that does not deliver is refunded straight back.</p>
          <div class="state-acts">
            <a class="btn btn-primary" href="<?= e(url('')) ?>">Buy a number</a>
            <a class="btn btn-ghost" href="<?= e(url('account/wallet')) ?>">See your wallet</a>
          </div>
        </div>

      <?php elseif ($isRental && $status === 'completed'): ?>
        <div class="panel state-panel">
          <span class="state-ico state-ok"><svg class="icon"><use href="#i-check"></use></svg></span>
          <h2>Your rental is ready</h2>
          <p><b><?= e($order['number']) ?></b> is yours. Its inbox is in your account.</p>
          <a class="btn btn-primary" href="<?= e(url('account/rentals')) ?>">Open my rentals</a>
        </div>

      <?php else: ?>
        <div class="panel state-panel">
          <span class="state-ico"><svg class="icon"><use href="#i-close"></use></svg></span>
          <h2>This order is <?= e(strtolower($words)) ?></h2>
          <a class="btn btn-primary" href="<?= e(url('')) ?>">Back to the shop</a>
        </div>
      <?php endif; ?>
    </div>

    <aside class="ocol-side">
      <div class="panel">
        <div class="ptitle"><svg class="icon"><use href="#i-doc"></use></svg> Order summary</div>
        <div class="orow"><span><?= $isDeposit ? 'For' : 'Item' ?></span><b><?= e($isDeposit ? 'Wallet top-up' : order_label($order)) ?></b></div>
        <?php if (($meta = order_meta($order)) && !empty($meta['option']) && !$isDeposit): ?>
          <div class="orow"><span>Option</span><b><?= e((string) $meta['option']) ?></b></div>
        <?php endif; ?>
        <div class="orow"><span>Placed</span><b><?= e(when($order['created_at'])) ?></b></div>
        <?php if ($order['paid_at']): ?>
          <div class="orow"><span>Paid with</span><b><?= e($order['pay_source'] === 'wallet' ? 'Wallet' : ($order['payment_name'] ?? '-')) ?></b></div>
        <?php endif; ?>
        <div class="ototal"><span>Total</span><span class="big"><?= e(money($order['price'])) ?></span></div>
      </div>

      <div class="panel">
        <div class="ptitle"><svg class="icon"><use href="#i-search"></use></svg> Keep this code</div>
        <p class="psub">It is how you come back to this <?= $isEmail ? 'inbox' : 'number' ?> and its code from any device.</p>
        <div class="acct">
          <div><small>Order code</small><b><?= e($order['code']) ?></b></div>
          <button class="copy" type="button" data-copy="<?= e($order['code']) ?>">Copy</button>
        </div>
        <?php if (!is_logged_in() && setting('registration_open', '1') === '1'): ?>
          <p class="help"><a href="<?= e(url('register')) ?>">Create a free account</a> to keep every order in one place and pay from a wallet.</p>
        <?php endif; ?>
        <?php if ($chat): ?>
          <p class="help">Something wrong?
            <?php foreach ($chat as $i => $channel): ?><?= $i ? ' &bull; ' : '' ?><a
               href="<?= e($channel['url']) ?>"
               <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>
               >Message us on <?= e($channel['name']) ?></a><?php endforeach; ?>
          </p>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>

<?php if (!empty($checkOnLoad)): ?>
  <?php /* Back from a gateway that takes the payment on its own pages: ask
           once, quietly. If the money is there the page reloads showing the
           number; if not, cron and the next visit will ask again. */ ?>
  <script>
  (function () {
    var body = new FormData();
    body.append('_token', '<?= e(csrf_token()) ?>');
    fetch('<?= e(url('order/' . $order['code'] . '/check')) ?>', {
      method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin'
    }).then(function (r) { return r.json(); })
      .then(function (result) { if (result && result.state === 'paid') { window.location.reload(); } })
      .catch(function () {});
  })();
  </script>
<?php endif; ?>
