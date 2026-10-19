<?php
/**
 * Confirming a base-currency change.
 *
 * The counts are the whole point of this screen: the admin is about to
 * rewrite every amount in the database and should see how many before they
 * do, not afterwards.
 *
 * @var array $row   the currency being made base
 * @var array $plan  rebase_preview() for it
 */
$code  = strtoupper((string) $row['code']);
$nouns = [
    'offers'              => 'catalogue price',
    'orders'              => 'order amount',
    'activations'         => 'number cost',
    'users'               => 'wallet balance',
    'wallet_transactions' => 'wallet line',
    'rentals'             => 'rental price',
    'currencies'          => 'exchange rate',
];
?>
<a class="lnk back" href="<?= e(url('admin/currencies')) ?>">&larr; Back to currencies</a>

<div class="box" style="max-width:620px">
  <div class="box-head"><b>Make <?= e($code) ?> the base currency</b></div>
  <div class="pad">

    <?php if (!$plan['ok']): ?>
      <div class="alert alert-error" style="margin:0"><?= e($plan['message']) ?></div>

    <?php else: ?>
      <p class="muted" style="margin-bottom:16px">
        Every amount in this panel is stored in <b><?= e($plan['from']) ?></b> and carries no
        currency of its own. Changing the base converts all of them at
        <b><?= e(rtrim(rtrim(number_format($plan['rate'], 6), '0'), '.')) ?>
        <?= e($plan['from']) ?></b> per <b><?= e($code) ?></b>.
      </p>

      <div class="box" style="margin-bottom:16px">
        <div class="box-head"><b>What gets rewritten</b></div>
        <table class="tbl">
          <tbody>
            <?php foreach ($plan['counts'] as $table => $count): ?>
              <tr>
                <td><?= e(qty_fmt($count)) ?>
                  <?= e($nouns[$table] ?? $table) ?><?= $count === 1 ? '' : 's' ?></td>
                <td style="text-align:right" class="muted">
                  <?= $table === 'currencies' ? 'rebased onto ' . e($code) : 'divided by the rate' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="alert alert-warning" style="margin-bottom:16px">
        Order amounts are converted too, so past orders will read in
        <?= e($code) ?> rather than the <?= e($plan['from']) ?> they were taken in.
        There is no undo &mdash; take a database backup first if you want one.
      </div>

      <form method="post" action="<?= e(url('admin/currencies/base')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
        <div class="field">
          <label for="confirm">Type <b><?= e($code) ?></b> to confirm</label>
          <input id="confirm" name="confirm" autocomplete="off" placeholder="<?= e($code) ?>" required>
        </div>
        <button class="btn btn-danger btn-lg" type="submit">
          Convert everything and make <?= e($code) ?> the base
        </button>
        <a class="btn btn-ghost btn-lg" href="<?= e(url('admin/currencies')) ?>">Cancel</a>
      </form>
    <?php endif; ?>

  </div>
</div>
