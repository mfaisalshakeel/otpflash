<?php
/**
 * The currency the visitor reads prices in.
 *
 * Every currency's rate, symbol and rounding is printed here as data, which
 * is why switching costs nothing: the script rewrites the prices already on
 * the page and writes the choice into a cookie, with no request to us at
 * all. The one exception is a signed-in customer, whose choice is also sent
 * to the server so it follows them to another device.
 *
 * None of this markup is about *this* visitor - it is the same bytes for
 * everyone, which is what lets the page be cached by anything in front of
 * us. Which one is current is decided in the browser, from the cookie.
 *
 * @var array $choices  currency_choices()
 */
if (count($choices) < 2) {
    return;     // one currency is not a choice
}
$base = strtoupper((string) base_currency()['code']);
?>
<div class="curpick" data-curpick>
  <button class="curbtn" type="button" data-curpick-open aria-expanded="false"
          aria-haspopup="true" aria-label="Change currency">
    <svg class="icon"><use href="#i-globe"></use></svg>
    <span data-cur-code><?= e($base) ?></span>
    <svg class="icon curcaret"><use href="#i-chevron"></use></svg>
  </button>

  <div class="curmenu" data-curpick-menu hidden>
    <div class="curmenu-head">
      <input class="cursearch" type="search" autocomplete="off" spellcheck="false"
             placeholder="Search currency" aria-label="Search currency" data-curpick-search>
    </div>
    <div class="curlist" role="listbox" data-curpick-list>
      <?php foreach ($choices as $code => $c): ?>
        <button class="curopt" type="button" role="option" aria-selected="false"
                data-cur="<?= e($code) ?>"
                data-symbol="<?= e($c['symbol']) ?>"
                data-rate="<?= e((string) $c['rate']) ?>"
                data-dec="<?= (int) $c['decimals'] ?>"
                data-step="<?= e((string) $c['step']) ?>"
                data-find="<?= e(strtolower($code . ' ' . $c['name'] . ' ' . $c['symbol'])) ?>">
          <b><?= e($code) ?></b>
          <span><?= e($c['name']) ?></span>
          <i><?= e($c['symbol']) ?></i>
        </button>
      <?php endforeach; ?>
    </div>
    <p class="curnote" data-curpick-empty hidden>No currency matches that.</p>
    <?php /* Said plainly, because a converted price is a guide and the
             charge is not. Hiding this would be the shop knowing something
             about the customer's money that the customer does not. */ ?>
    <p class="curnote">Prices convert for reading. You are charged in
      <?= e($base) ?>.</p>
  </div>
</div>
