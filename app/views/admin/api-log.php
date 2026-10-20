<?php
/**
 * Every call to OTPGet and the gateways.
 * @var array $calls @var int $total @var int $page @var int $pages
 * @var string $source @var string $result @var string $search
 * @var int $failedPayments @var int $failedOtpget
 */
$keep = static function (array $with): string {
    return url('admin/api-log') . '?' . http_build_query(array_filter(
        array_merge(['source' => $_GET['source'] ?? '', 'result' => $_GET['result'] ?? '',
                     'q' => $_GET['q'] ?? ''], $with), 'strlen'));
};
?>
<div class="cards2">
  <a class="scard" href="<?= e($keep(['source' => 'payment', 'result' => 'failed', 'page' => ''])) ?>">
    <div class="si si-c"><svg class="icon"><use href="#i-card"></use></svg></div>
    <div><small>Failed payment calls, last 24 hours</small><b><?= qty_fmt($failedPayments) ?></b>
      <span class="muted">Show them</span></div></a>
  <a class="scard" href="<?= e($keep(['source' => 'otpget', 'result' => 'failed', 'page' => ''])) ?>">
    <div class="si si-d"><svg class="icon"><use href="#i-plug"></use></svg></div>
    <div><small>Failed OTPGet calls, last 24 hours</small><b><?= qty_fmt($failedOtpget) ?></b>
      <span class="muted">Show them</span></div></a>
</div>

<div class="filters">
  <form class="search wide" id="apiSearch" method="get" action="<?= e(url('admin/api-log')) ?>">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($search) ?>" placeholder="An order code, or words in the reply">
    <input type="hidden" name="source" value="<?= e($source) ?>">
    <input type="hidden" name="result" value="<?= e($result) ?>">
  </form>
  <button class="btn btn-ghost" type="submit" form="apiSearch">Search</button>

  <form method="get" action="<?= e(url('admin/api-log')) ?>" style="display:contents">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="result" value="<?= e($result) ?>">
    <select name="source" onchange="this.form.submit()">
      <option value="">OTPGet and payments</option>
      <option value="payment"<?= $source === 'payment' ? ' selected' : '' ?>>Payments only</option>
      <option value="otpget"<?= $source === 'otpget' ? ' selected' : '' ?>>OTPGet only</option>
    </select>
  </form>

  <form method="get" action="<?= e(url('admin/api-log')) ?>" style="display:contents">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="source" value="<?= e($source) ?>">
    <select name="result" onchange="this.form.submit()">
      <option value="">Every result</option>
      <option value="failed"<?= $result === 'failed' ? ' selected' : '' ?>>Failed only</option>
    </select>
  </form>

  <?php if ($search !== '' || $source !== '' || $result !== ''): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/api-log')) ?>">Reset</a>
  <?php endif; ?>
</div>

<div class="box">
  <div class="pad">
    <?php partial('admin/_calls', ['calls' => $calls, 'withOrder' => true]); ?>
  </div>
</div>

<?php if ($pages > 1): ?>
  <div class="pager">
    <span><?= qty_fmt($total) ?> calls &middot; page <?= $page ?> of <?= $pages ?></span>
    <span class="pgnav">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($keep(['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($keep(['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
    </span>
  </div>
<?php endif; ?>
