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
 * @var array $state  picker_state()  @var ?array $user  @var bool $guestOk  @var bool $askEmail
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
$first   = $options[0];
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
      <small><?= $country && $country['dial'] !== '' ? '+' . e($country['dial']) . ' · ' : '' ?><?= count($options) ?> option<?= count($options) === 1 ? '' : 's' ?>, from <?= e(money($cheap)) ?></small>
    </div>
  </div>

  <fieldset class="opts">
    <legend class="sr-only">Choose an option</legend>
    <?php foreach ($options as $i => $option): ?>
      <label class="opt<?= $i === 0 ? ' on' : '' ?>">
        <input type="radio" name="offer_id" value="<?= (int) $option['id'] ?>" data-price="<?= e((string) $option['price']) ?>"
               data-money="<?= e(money($option['price'])) ?>" <?= $i === 0 ? 'checked' : '' ?>>
        <span class="opt-radio" aria-hidden="true"></span>
        <span class="olabel"><?= e($option['label']) ?><small><?= e($option['note']) ?></small></span>
        <span class="oprice"><?= e(money($option['price'])) ?>
          <?php if ($option['price'] <= $cheap && count($options) > 1): ?><em class="obadge">Best price</em><?php endif; ?>
          <?php if ($option['product'] === 'premium'): ?><em class="obadge obadge-p">Premium</em><?php endif; ?>
        </span>
      </label>
    <?php endforeach; ?>
  </fieldset>

  <?php if ($rental && !$user): ?>
    <div class="note"><svg class="icon"><use href="#i-lock"></use></svg>
      <span>A rental number comes with an inbox you return to for days, so it lives in an account.
        Sign in or create one - it takes ten seconds.</span></div>
    <a class="btn btn-primary btn-block btn-lg" href="<?= e(url('login?next=' . rawurlencode($next))) ?>">Sign in to rent</a>
    <?php if (setting('registration_open', '1') === '1'): ?>
      <a class="btn btn-ghost btn-block" href="<?= e(url('register?next=' . rawurlencode($next))) ?>">Create a free account</a>
    <?php endif; ?>

  <?php elseif ($user): ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit" name="pay" value="wallet" data-buy
            data-label="Pay {price} from wallet" data-wait="Getting your number…"
            <?= $balance + 0.001 < (float) $first['price'] ? 'data-short' : '' ?>>
      Pay <?= e(money($first['price'])) ?> from wallet
    </button>
    <p class="obal">Wallet balance <b><?= e(money($balance)) ?></b>
      &middot; <a href="<?= e(url('account/wallet')) ?>">Add funds</a></p>
    <button class="btn btn-ghost btn-block" type="submit" name="pay" value="gateway" data-buy-gateway>
      Pay another way</button>

  <?php elseif ($guestOk): ?>
    <?php if ($askEmail): ?>
      <div class="field field-sm">
        <label for="opt-email">Email for the receipt <span class="optional">(optional)</span></label>
        <input id="opt-email" type="email" name="email" placeholder="you@example.com" autocomplete="email">
      </div>
    <?php endif; ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit" name="pay" value="gateway" data-buy
            data-label="Buy now · {price}" data-wait="Placing your order…">
      Buy now &middot; <?= e(money($first['price'])) ?>
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
