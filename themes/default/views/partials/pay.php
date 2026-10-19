<?php
/**
 * The pay step: which method, then what that method needs.
 *
 * Rendered in two places and so written once - the order page shows it, and
 * the order modal injects the very same markup as its second step, so a
 * customer who has just ordered chooses and pays without leaving the box
 * they ordered in.
 *
 * The choosing is a grid of logos and names and nothing else. It used to be
 * a list where every method had its instructions, its account numbers and
 * its questions open underneath, which meant reading three methods' worth of
 * detail to pick one. What a method needs is now shown *after* it is picked.
 *
 * Everything the script binds to is delegated, so injected markup behaves
 * exactly as markup that arrived with the page.
 *
 * @var array $order  @var array $methods  @var array $gateways
 * @var array $chat   @var array $payErrors
 */
?>
<div class="panel">
  

  <?php if (!$methods): ?>
    <div class="psubtitle">No payment methods are set up yet.</div>
    <div class="note">
      <svg class="icon"><use href="#i-chat"></use></svg>
      <span>No payment methods are set up yet.
        <?php if ($chat): ?>Message us and we will send you the details.<?php endif; ?>
      </span>
    </div>

  <?php else: ?>
   

    <form method="post" action="<?= e(url('order/' . $order['code'] . '/pay')) ?>" id="payForm"
          enctype="multipart/form-data">
      <?= csrf_field() ?>

      <?php /* The grid. Two across wherever the names allow it, which on a
               phone is what makes four methods a glance rather than a
               scroll. The radio is the thing that submits; the tile is a
               label for it, so this works with the script switched off. */ ?>
      <div class="paypick">
        <?php foreach ($methods as $i => $method): ?>
          <?php $gateway = $gateways[$method['driver']] ?? null; ?>
          <label class="payopt<?= $i === 0 ? ' on' : '' ?>" data-pay-opt
                 data-kind="<?= e($gateway['kind'] ?? 'manual') ?>">
            <input type="radio" name="payment_method_id" value="<?= (int) $method['id'] ?>"
                   <?= $i === 0 ? 'checked' : '' ?> data-pm-radio>
            <span class="paylogo">
              <?php if (trim((string) ($method['logo_path'] ?? '')) !== ''): ?>
                <img src="<?= e(url($method['logo_path'])) ?>" alt="" loading="lazy">
              <?php else: ?>
                <?= e($method['short_name'] !== ''
                     ? $method['short_name']
                     : mb_strtoupper(mb_substr($method['name'], 0, 2))) ?>
              <?php endif; ?>
            </span>
            <span class="payname"><?= e($method['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <?php /* What the chosen one needs. Only the open one is shown, and the
               script disables the fields in the others so a hidden required
               box cannot block the submit. Without the script every panel is
               visible and the server reads only the chosen method's. */ ?>
      <?php foreach ($methods as $i => $method): ?>
        <?php $gateway = $gateways[$method['driver']] ?? null; ?>
        <div class="paystep<?= $i === 0 ? ' on' : '' ?>" data-pay-for="<?= (int) $method['id'] ?>">

          <?php if (($gateway['kind'] ?? 'manual') !== 'manual'): ?>
            <button class="btn btn-primary btn-block btn-lg" type="submit" data-pay-go
                    data-wait="<?= e((string) ($gateway['wait'] ?? 'Just a moment…')) ?>"
                    formaction="<?= e(url('order/' . $order['code'] . '/start')) ?>">
              Pay with <?= e($method['name']) ?>
            </button>

          <?php else: ?>
            <?php if ($method['account_number'] !== ''): ?>
              <div class="acct">
                <div><small>Account number</small><b><?= e($method['account_number']) ?></b></div>
                <button class="copy" type="button"
                        data-copy="<?= e($method['account_number']) ?>">Copy</button>
              </div>
            <?php endif; ?>
            <?php if ($method['account_title'] !== ''): ?>
              <div class="acct"><div><small>Account title</small>
                <b><?= e($method['account_title']) ?></b></div></div>
            <?php endif; ?>
            <?php if ($method['extra_value'] !== ''): ?>
              <div class="acct"><div><small><?= e($method['extra_label'] ?: 'Details') ?></small>
                <b><?= e($method['extra_value']) ?></b></div></div>
            <?php endif; ?>
            <?php if (trim((string) $method['instructions']) !== ''): ?>
              <div class="note"><svg class="icon"><use href="#i-chat"></use></svg>
                <span><?= e($method['instructions']) ?></span></div>
            <?php endif; ?>

            <div class="pf-ask" data-pf-for="<?= (int) $method['id'] ?>">
              <?php foreach (payfields($method) as $field): ?>
                <?php $id = 'pf-' . (int) $method['id'] . '-' . $field['key']; ?>
                <div class="field">
                  <label for="<?= e($id) ?>">
                    <?= e($field['label']) ?><?= $field['required'] ? '' : ' (optional)' ?>
                  </label>
                  <?php if ($field['type'] === 'textarea'): ?>
                    <textarea id="<?= e($id) ?>" name="pf_<?= e($field['key']) ?>" rows="3"
                      <?= $field['required'] ? 'data-pf-required' : '' ?>
                      ><?= e(old('pf_' . $field['key'], '')) ?></textarea>
                  <?php elseif ($field['type'] === 'select'): ?>
                    <select id="<?= e($id) ?>" name="pf_<?= e($field['key']) ?>"
                            <?= $field['required'] ? 'data-pf-required' : '' ?>>
                      <option value="">Choose one</option>
                      <?php foreach ($field['options'] as $option): ?>
                        <option value="<?= e($option) ?>"
                          <?= old('pf_' . $field['key'], '') === $option ? ' selected' : '' ?>>
                          <?= e($option) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($field['type'] === 'image'): ?>
                    <input id="<?= e($id) ?>" type="file" name="pf_<?= e($field['key']) ?>"
                           accept="image/png,image/jpeg,image/gif,image/webp"
                           <?= $field['required'] ? 'data-pf-required' : '' ?>>
                  <?php else: ?>
                    <input id="<?= e($id) ?>" name="pf_<?= e($field['key']) ?>"
                           type="<?= e($field['type'] === 'number' ? 'text' : $field['type']) ?>"
                           <?= $field['type'] === 'number' ? 'inputmode="decimal"' : '' ?>
                           value="<?= e(old('pf_' . $field['key'], '')) ?>"
                           <?= $field['required'] ? 'data-pf-required' : '' ?>>
                  <?php endif; ?>
                  <?php if ($field['hint'] !== ''): ?>
                    <small class="hint"><?= e($field['hint']) ?></small>
                  <?php endif; ?>
                  <?php if (!empty($payErrors[$field['key']])): ?>
                    <small class="err"><?= e($payErrors[$field['key']]) ?></small>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="field">
              <label for="amt-<?= (int) $method['id'] ?>">Amount You Sent</label>
              <input id="amt-<?= (int) $method['id'] ?>" name="paid_amount" inputmode="decimal"
                     value="<?= e($order['paid_amount'] ?? $order['price']) ?>">
            </div>

            <button class="btn btn-primary btn-block btn-lg" type="submit" data-pay-go>
              I Have Paid
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </form>
  <?php endif; ?>
</div>
