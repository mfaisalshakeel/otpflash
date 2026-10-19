<?php
/**
 * Every offer, with what it costs us and what we charge.
 *
 * @var array $offers  @var array $filters  @var array $paging  @var array $services  @var array $countries
 */
$backQuery = http_build_query(array_filter($filters, 'strlen') + ($paging['page'] > 1 ? ['page' => $paging['page']] : []));
$billing   = otpget_currency();
?>
<form class="filters" method="get" action="<?= e(url('admin/catalogue')) ?>">
  <div class="search wide">
    <svg class="icon"><use href="#i-search"></use></svg>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="App, country or OTPGet id">
  </div>
  <select name="product" onchange="this.form.submit()">
    <option value="">Every product</option>
    <?php foreach (PRODUCTS as $key => $product): ?>
      <option value="<?= e($key) ?>"<?= $filters['product'] === $key ? ' selected' : '' ?>><?= e($product['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="service" data-search onchange="this.form.submit()">
    <option value="">Every service</option>
    <?php foreach ($services as $id => $name): ?>
      <option value="<?= (int) $id ?>"<?= $filters['service'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="country" data-search onchange="this.form.submit()">
    <option value="">Every country</option>
    <?php foreach ($countries as $id => $name): ?>
      <option value="<?= (int) $id ?>"<?= $filters['country'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="state" onchange="this.form.submit()">
    <?php foreach (['' => 'Listed by OTPGet', 'off' => 'Switched off', 'pinned' => 'Price pinned', 'noprice' => 'No price (no rate)', 'missing' => 'Gone from OTPGet', 'all' => 'Everything'] as $value => $label): ?>
      <option value="<?= e($value) ?>"<?= $filters['state'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <?php foreach (['' => 'Sort: name', 'price' => 'Sort: cheapest', 'margin' => 'Sort: thinnest margin', 'synced' => 'Sort: last synced'] as $value => $label): ?>
      <option value="<?= e($value) ?>"<?= $filters['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (array_filter($filters, 'strlen')): ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/catalogue')) ?>">Reset</a>
  <?php endif; ?>
</form>

<form method="post" action="<?= e(url('admin/catalogue/bulk')) ?>" id="bulkForm" data-bulk>
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= e($backQuery) ?>">
  <div class="bulkbar" data-bulk-bar>
    <b><span data-bulk-count>0</span> selected</b>
    <button class="btn btn-ghost btn-sm" type="submit" name="do" value="on">Switch on</button>
    <button class="btn btn-ghost btn-sm" type="submit" name="do" value="off">Switch off</button>
    <button class="btn btn-ghost btn-sm" type="submit" name="do" value="unpin">Back to automatic price</button>
    <input type="number" name="price" min="0" step="0.01" placeholder="Price" style="width:110px;height:34px;border-radius:9px;border:1px solid var(--line);padding:0 10px">
    <button class="btn btn-primary btn-sm" type="submit" name="do" value="pin">Pin this price</button>
  </div>
</form>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th class="ta-c"><input type="checkbox" class="rowchk" data-bulk-all aria-label="Select every row on this page"></th>
          <th>Offer</th><th>Sold as</th><th>Cost</th><th>Price</th><th>Margin</th><th>Synced</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$offers): ?>
        <tr><td colspan="8" class="empty">Nothing here. <a class="lnk" href="<?= e(url('admin/otpget')) ?>">Sync the catalogue</a> to fill it.</td></tr>
      <?php else: foreach ($offers as $offer): ?>
        <?php
        $sale   = offer_sale_price($offer);
        $base   = to_base((float) $offer['cost'], (string) $offer['cost_currency']);
        $margin = $base !== null && $sale > 0 ? $sale - $base : null;
        ?>
        <tr style="cursor:default<?= !$offer['is_active'] || $offer['missing'] ? ';opacity:.55' : '' ?>">
          <td class="ta-c"><input type="checkbox" class="rowchk" name="ids[]" value="<?= (int) $offer['id'] ?>" form="bulkForm" data-bulk-row></td>
          <td data-label="Offer">
            <span style="display:inline-flex;align-items:center;gap:9px">
              <?php if ($offer['service_name'] !== null): ?>
                <?= service_icon_html(['name' => $offer['service_name'], 'icon' => $offer['icon'], 'color' => $offer['color']]) ?>
              <?php endif; ?>
              <?php if ($offer['iso2'] !== null): ?>
                <img src="<?= e(flag_url($offer['iso2'])) ?>" alt="" width="24" height="18" style="border-radius:3px">
              <?php endif; ?>
              <span><b><?= e(trim(($offer['service_name'] ?? '') . ' · ' . ($offer['country_name'] ?? $offer['provider_country']), ' ·')) ?></b>
                <small class="sub"><?= e($offer['provider_name']) ?></small></span>
            </span>
          </td>
          <td data-label="Sold as"><?= e(offer_option_label($offer)) ?>
            <small class="sub mono"><?= e($offer['product']) ?> <?= e($offer['server']) ?> · <?= e($offer['provider_country']) ?>/<?= e($offer['provider_service']) ?></small></td>
          <td data-label="Cost"><?= e(money_in((float) $offer['cost'], (string) $offer['cost_currency'] ?: $billing)) ?>
            <?php if ($base !== null && strtoupper((string) $offer['cost_currency']) !== strtoupper((string) base_currency()['code'])): ?>
              <small class="sub"><?= e(money($base)) ?></small>
            <?php endif; ?></td>
          <td data-label="Price">
            <form method="post" action="<?= e(url('admin/catalogue/price')) ?>" class="inline-form" style="gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $offer['id'] ?>">
              <input type="hidden" name="back" value="<?= e($backQuery) ?>">
              <input type="number" name="price" min="0" step="0.01" style="width:96px;height:34px"
                     value="<?= $offer['price_override'] !== null ? e((string) $offer['price_override']) : '' ?>"
                     placeholder="<?= e(number_format((float) $offer['price'], 2, '.', '')) ?>"
                     title="Empty means the automatic price, <?= e(money((float) $offer['price'])) ?>">
              <button class="iact iact-edit" type="submit" title="Save price" aria-label="Save price"><svg class="icon"><use href="#i-check"></use></svg></button>
            </form>
            <small class="sub"><?= $offer['price_override'] !== null ? 'pinned by you' : 'automatic' ?></small>
          </td>
          <td data-label="Margin"><?php if ($margin === null): ?><span class="muted">-</span><?php else: ?>
            <b class="<?= $margin > 0 ? 'up' : 'warn' ?>"><?= e(money($margin)) ?></b><?php endif; ?></td>
          <td data-label="Synced"><?= e(when($offer['synced_at'], 'd M, H:i')) ?>
            <?php if ($offer['missing']): ?><small class="sub warn">gone from OTPGet</small><?php endif; ?>
            <?php if ($offer['stock'] !== null): ?><small class="sub"><?= qty_fmt((int) $offer['stock']) ?> in stock</small><?php endif; ?></td>
          <td class="ta-r">
            <form method="post" action="<?= e(url('admin/catalogue/toggle')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $offer['id'] ?>">
              <input type="hidden" name="back" value="<?= e($backQuery) ?>">
              <button class="iact <?= $offer['is_active'] ? 'iact-on' : 'iact-off' ?>" type="submit"
                      title="<?= $offer['is_active'] ? 'On sale - switch off' : 'Switched off - switch on' ?>"
                      aria-label="<?= $offer['is_active'] ? 'Switch off' : 'Switch on' ?>">
                <svg class="icon"><use href="#i-power"></use></svg></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php partial('admin/_pager', ['paging' => $paging, 'base' => 'admin/catalogue', 'query' => $filters,
      'noun' => 'offer', 'shown' => count($offers)]); ?>
</div>
