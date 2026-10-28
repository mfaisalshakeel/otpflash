<?php
/**
 * The last step of the picker: what exactly, for how much, and pay.
 *
 * Rendered by the server for the first paint and again for every choice the
 * script makes (/api/picker sends this partial back as HTML), so there is
 * one definition of what buying looks like.
 *
 * A real form with real radios: without the script it posts to /order and
 * the order page takes over from there.
 *
 * @var array $state  picker_state()  @var ?array $user  @var bool $guestOk
 */
$tab     = $state['tab'];
$service = $state['service'];
$country = $state['country'];
$options = $state['options'];
$rental  = $tab === 'rental';

if (!$options):
    $waitingFor = match ($tab) {
        'numbers' => !$service && !$country ? 'Pick a service and a country to see every server and its price.'
                     : (!$service ? 'Now pick the app you need the number for.' : 'Now pick the country for your number.'),
        'email'   => 'Pick the app you are signing up to, then choose a mailbox.',
        'rental'  => 'Pick a country to see how long you can rent a number for.',
        default   => '',
    };
    $chosenNothing = ($tab === 'numbers' && (!$service || !$country)) || ($tab === 'email' && !$service)
                     || ($rental && !$country);
?>
  <div class="opt-empty">
    <span class="opt-empty-ico"><svg class="icon"><use href="#<?= $rental ? 'i-calendar' : ($tab === 'email' ? 'i-mail' : 'i-phone') ?>"></use></svg></span>
    <?php if ($chosenNothing): ?>
      <b>Almost there</b>
      <p><?= e($waitingFor) ?></p>
    <?php else: ?>
      <b>Out of stock right now</b>
      <p>Nothing is on sale for this choice at the moment. Try another country or app - stock changes every few minutes.</p>
    <?php endif; ?>
    <?php
    // What each tab can honestly promise: an inbox is not a number, and a
    // rental is not replaced when it stays quiet - it keeps what it gets.
    $promises = match (true) {
        $rental          => [['i-bolt', 'Yours the moment it is paid'], ['i-inbox', 'Every SMS kept in your inbox'],
                             ['i-refresh', 'Renew it whenever you like']],
        $tab === 'email' => [['i-bolt', 'Address on screen in seconds'], ['i-mail', 'Code appears automatically'],
                             ['i-shield', 'No email? Refunded or replaced']],
        default          => [['i-bolt', 'Number on screen in seconds'], ['i-sms', 'Code appears automatically'],
                             ['i-shield', 'No SMS? The next number is free']],
    };
    ?>
    <ul class="opt-promise">
      <?php foreach ($promises as [$icon, $line]): ?>
        <li><svg class="icon"><use href="#<?= e($icon) ?>"></use></svg> <?= e($line) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php return; endif; ?>

<?php
// Where signing in brings them back to: this very choice, not wherever the
// partial happened to be rendered from (it is also sent back by the API).
$next    = ltrim(substr(choice_url($tab, $service, $country), strlen(base_url())), '/');
$cheap   = min(array_column($options, 'price'));
$balance = $user ? (float) $user['balance'] : 0.0;
?>
<form class="optform" method="post" action="<?= e(url('order')) ?>" data-order-form>
  <?= csrf_field() ?>
  <?php /* A bot filling the hidden field looks exactly like success to the bot. */ ?>
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">

  <div class="osum">
    <?php if ($service): ?><?= service_icon_html($service, 'svc-ico svc-ico-lg') ?><?php endif; ?>
    <?php if ($country): ?>
      <img class="flag flag-lg" src="<?= e(flag_url($country['iso'])) ?>" alt="" width="40" height="30">
    <?php endif; ?>
    <div class="osum-txt">
      <b><?= e(trim(($service['name'] ?? ($rental ? 'Rental number' : '')) . ($country ? ' · ' . $country['name'] : ''))) ?></b>
      <small><?= $country && $country['dial'] !== '' ? '+' . e($country['dial']) . ' · ' : '' ?>from <?= price($cheap) ?></small>
    </div>
  </div>

  <?php
  // One option already chosen and the rest folded away - radios labelled with
  // server numbers are a question most people cannot answer. Which one is
  // chosen, and what stays in view, is options_arrange()'s decision.
  $option = static function (array $option, bool $chosen, bool $cheapest, bool $alone): void { ?>
      <label class="opt<?= $chosen ? ' on' : '' ?><?= $alone ? ' opt-single' : '' ?> opt-<?= e($option['quality']) ?>">
        <input type="radio" name="offer_id" value="<?= (int) $option['id'] ?>" data-price="<?= e((string) $option['price']) ?>"
               data-money="<?= e(money($option['price'])) ?>" data-quality="<?= e($option['quality']) ?>" <?= $chosen ? 'checked' : '' ?>>
        <span class="opt-radio" aria-hidden="true"></span>
        <span class="olabel"><?= e($option['label']) ?><small><?= e($option['note']) ?></small></span>
        <span class="oprice"><?= price($option['price']) ?>
          <?php if ($option['product'] === 'premium'): ?><em class="obadge obadge-p">Premium</em>
          <?php elseif ($option['quality'] === 'best'): ?><em class="obadge obadge-best">Best delivery</em>
          <?php elseif ($option['quality'] === 'budget'): ?><em class="obadge obadge-budget">Budget</em><?php endif; ?>
          <?php if ($cheapest && !$alone): ?><em class="obadge obadge-price">Lowest price</em><?php endif; ?>
        </span>
      </label>
  <?php };

  ['shown' => $shown, 'more' => $more, 'recommended' => $recommended] = options_arrange($options, $rental);
  $first  = $recommended;
  $better = $recommended['quality'] !== 'budget' ? $recommended : null;
  ?>
  <fieldset class="opts">
    <legend class="sr-only">Choose an option</legend>
    <?php foreach ($shown as $index => $one): ?>
      <?php $option($one, $index === 0, $one['price'] <= $cheap, count($options) === 1); ?>
    <?php endforeach; ?>
    <?php if ($more): ?>
      <details class="more-opts">
        <summary>More options <span>(<?= count($more) ?>)</span></summary>
        <?php foreach ($more as $other): ?>
          <?php $option($other, false, $other['price'] <= $cheap, false); ?>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  </fieldset>

  <?php if (array_filter($options, static fn ($o) => $o['quality'] === 'budget')): ?>
    <div class="opt-warn" data-budget-warn <?= $recommended['quality'] === 'budget' ? '' : 'hidden' ?> role="note">
      <svg class="icon"><use href="#i-alert"></use></svg>
      <div>
        <b>Budget numbers are cheaper, but the code sometimes does not arrive.</b>
        <?php if ($better): ?>
          For an account that matters, a <?= $better['quality'] === 'best' ? 'Best delivery' : 'standard' ?> number is the safer buy.
          <button type="button" class="opt-switch" data-pick-option="<?= (int) $better['id'] ?>">
            Switch to <?= e($better['label']) ?> &middot; <?= price($better['price']) ?></button>
        <?php else: ?>
          It is the only kind on sale for this right now. If no SMS comes, you get a free new number.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($rental && !$user): ?>
    <div class="note"><svg class="icon"><use href="#i-lock"></use></svg>
      <span>A rental number comes with an inbox you return to for days, so it lives in an account.
        Sign in or create one - it takes ten seconds.</span></div>
    <a class="btn btn-primary btn-block btn-lg" href="<?= e(url('login?next=' . rawurlencode($next))) ?>">Sign in to rent</a>
    <?php if (setting('registration_open', '1') === '1'): ?>
      <a class="btn btn-ghost btn-block" href="<?= e(url('register?next=' . rawurlencode($next))) ?>">Create a free account</a>
    <?php endif; ?>

  <?php elseif ($user): ?>
    <?php
    // One Pay button, signed in or not. A wallet that covers the price pays
    // in one tap; one that does not simply is not used, and the customer pays
    // in the box that opens - sending them off to top up first turned one
    // purchase into two. The script makes the same call for every option.
    $covers     = $balance + 0.001 >= (float) $first['price'];
    $canGateway = (bool) payable_methods();
    ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit" name="pay" data-buy
            value="<?= $covers || !$canGateway ? 'wallet' : 'gateway' ?>"
            data-wallet-label="Pay {price} from wallet" data-label="Pay {price}"
            data-balance="<?= e((string) $balance) ?>" data-gateway="<?= $canGateway ? '1' : '0' ?>"
            data-wait="<?= $covers ? 'Getting your number…' : 'Placing your order…' ?>">
      <?= $covers ? 'Pay ' . price($first['price']) . ' from wallet' : 'Pay ' . price($first['price']) ?>
    </button>
    <p class="obal" data-wallet-note>Wallet balance <b><?= e(money($balance)) ?></b>
      <span data-wallet-short <?= $covers ? 'hidden' : '' ?>>&middot; not enough for this one, so pay directly</span></p>
    <?php if ($canGateway): ?>
      <button class="btn btn-ghost btn-block" type="submit" name="pay" value="gateway" data-buy-gateway
              <?= $covers ? '' : 'hidden' ?>>Pay another way</button>
    <?php endif; ?>

  <?php elseif ($guestOk): ?>
    <?php $ask = checkout_fields(); ?>
    <?php if ($ask['whatsapp'] || $ask['email']): ?>
      <div class="ocontact" data-contact>
        <?php if ($ask['whatsapp']): ?>
          <label class="ofield">
            <span>WhatsApp <em>(optional)</em></span>
            <span class="ofield-in"><svg class="icon"><use href="#i-whatsapp"></use></svg>
              <input type="tel" name="whatsapp" inputmode="tel" autocomplete="tel" maxlength="20"
                     placeholder="+92 300 1234567" data-remember="whatsapp"></span>
          </label>
        <?php endif; ?>
        <?php if ($ask['email']): ?>
          <label class="ofield">
            <span>Email <em>(optional)</em></span>
            <span class="ofield-in"><svg class="icon"><use href="#i-mail"></use></svg>
              <input type="email" name="email" autocomplete="email" maxlength="190"
                     placeholder="you@example.com" data-remember="email"></span>
          </label>
        <?php endif; ?>
        <small class="ocontact-hint"><?= $ask['email'] ? 'We email your number and code as a receipt.' : 'Only used if we need to reach you about this order.' ?>
          Your code shows on screen either way.</small>
      </div>
    <?php endif; ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit" name="pay" value="gateway" data-buy
            data-label="Buy now · {price}" data-wait="Placing your order…">
      Buy now &middot; <?= price($first['price']) ?>
    </button>
    <p class="obal">Buying often? <a href="<?= e(url('login?next=' . rawurlencode($next))) ?>">Sign in</a> to pay from a wallet in one tap,
      with automatic refunds.</p>

  <?php else: ?>
    <a class="btn btn-primary btn-block btn-lg" href="<?= e(url('login?next=' . rawurlencode($next))) ?>">Sign in to buy</a>
  <?php endif; ?>

  <ul class="opt-trust">
    <li><svg class="icon"><use href="#i-shield"></use></svg> <?= $rental ? 'Inbox kept for the whole rental' : 'Free replacement if no SMS' ?></li>
    <li><svg class="icon"><use href="#i-lock"></use></svg> Secure checkout</li>
  </ul>
</form>
