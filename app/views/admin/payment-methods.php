<?php /** @var array $methods  @var array $gateways */ ?>
<div class="alert alert-info">
  A gateway is one file in <span class="mono">app/payments/</span>. Drop a file in and it
  appears in the list below &mdash; nothing else needs changing.
  <?= qty_fmt(count($gateways)) ?> installed:
  <?= e(implode(', ', array_column($gateways, 'name'))) ?>.
</div>

<div class="filters">
  <a class="btn btn-primary" href="<?= e(url('admin/payment-methods/new')) ?>" style="margin-left:auto">
    + Add payment method
  </a>
</div>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th class="dragcell"></th><th>Method</th><th>Gateway</th><th>Account</th>
            <th>State</th><th></th></tr>
      </thead>
      <tbody data-sortable="payment_methods"
             data-url="<?= e(url('admin/reorder')) ?>"
             data-token="<?= e(csrf_token()) ?>" data-start="0">
      <?php if (!$methods): ?>
        <tr><td colspan="6" class="empty">No payment methods yet. Customers cannot pay until there is one.</td></tr>
      <?php else: foreach ($methods as $method): ?>
        <?php $gateway = $gateways[$method['driver']] ?? null; ?>
        <tr data-id="<?= (int) $method['id'] ?>">
          <td class="dragcell">
            <span class="rowdrag" data-drag title="Drag to reorder" aria-label="Drag to reorder">
              <svg class="icon"><use href="#i-list"></use></svg>
            </span>
          </td>
          <?php /* The logo beside the name, the same square the customer is
                   shown when they pick how to pay - which is what makes a
                   wrong or missing one obvious from the list rather than
                   only after opening the form. Without one, the short code
                   stands in, exactly as it does on the storefront. */ ?>
          <td data-label="Method">
            <div class="pmrow">
              <span class="pmlogo">
                <?php if (trim((string) ($method['logo_path'] ?? '')) !== ''): ?>
                  <img src="<?= e(url($method['logo_path'])) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <?= e($method['short_name'] !== ''
                       ? $method['short_name']
                       : mb_strtoupper(mb_substr($method['name'], 0, 2))) ?>
                <?php endif; ?>
              </span>
              <span>
                <b><?= e($method['name']) ?></b>
                <small class="sub"><?= e($method['account_title'] ?: '-') ?></small>
              </span>
            </div>
          </td>
          <td data-label="Gateway">
            <?php if ($gateway): ?>
              <span class="chipx"><?= e($gateway['name']) ?></span>
            <?php else: ?>
              <span class="st st-api_error">missing</span>
              <small class="sub mono"><?= e($method['driver']) ?></small>
            <?php endif; ?>
          </td>
          <td data-label="Account">
            <?php if ($method['account_number'] !== ''): ?>
              <span class="mono"><?= e($method['account_number']) ?></span>
            <?php elseif ($gateway && $gateway['kind'] === 'redirect'): ?>
              <span class="muted">handled by <?= e($gateway['name']) ?></span>
            <?php else: ?>
              <span class="muted">not set</span>
            <?php endif; ?>
          </td>
          <td data-label="State"><span class="st st-<?= $method['is_active'] ? 'completed' : 'cancelled' ?>">
            <?= $method['is_active'] ? 'active' : 'off' ?></span></td>
          <?php /* Power, pencil, trash - the same three the CRUD screens draw,
                   in the same order and the same colours, because this screen
                   is one of that family even though it is written by hand. */ ?>
          <td class="ta-r">
            <div class="rowacts">
              <form method="post" action="<?= e(url('admin/payment-methods/toggle')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $method['id'] ?>">
                <button class="iact <?= $method['is_active'] ? 'iact-on' : 'iact-off' ?>" type="submit"
                        title="<?= $method['is_active'] ? 'Switch off' : 'Switch on' ?>"
                        aria-label="<?= $method['is_active'] ? 'Switch off' : 'Switch on' ?>">
                  <svg class="icon"><use href="#i-power"></use></svg>
                </button>
              </form>

              <a class="iact iact-edit" href="<?= e(url('admin/payment-methods/edit/' . $method['id'])) ?>"
                 title="Edit" aria-label="Edit">
                <svg class="icon"><use href="#i-edit"></use></svg>
              </a>

              <form method="post" action="<?= e(url('admin/payment-methods/delete')) ?>"
                    data-confirm="Delete this payment method?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $method['id'] ?>">
                <button class="iact iact-danger" type="submit" title="Delete" aria-label="Delete">
                  <svg class="icon"><use href="#i-trash"></use></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
