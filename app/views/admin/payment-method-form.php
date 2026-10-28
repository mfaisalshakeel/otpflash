<?php
/**
 * One payment method. The gateway's own fields are rendered from its manifest,
 * so a new gateway needs no change here.
 *
 * @var array $row  @var int $id  @var array $config  @var array $gateways  @var array $errors
 */
$val = static function (string $name, $fallback = '') use ($row) {
    $old = old($name, null);
    return $old !== null ? $old : ($row[$name] ?? $fallback);
};
$err     = static fn(string $name) => $errors[$name] ?? null;
$current = (string) $val('driver', 'manual');
?>
<a class="lnk back" href="<?= e(url('admin/payment-methods')) ?>">&larr; Back to payment methods</a>

<form method="post" action="<?= e(url('admin/payment-methods/save')) ?>" class="split2"
      enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div>
    <div class="box">
      <div class="box-head"><b>Gateway</b></div>
      <div class="pad">
        <div class="choice">
          <?php foreach ($gateways as $key => $gateway): ?>
            <label class="opt<?= $key === $current ? ' on' : '' ?>">
              <input type="radio" name="driver" value="<?= e($key) ?>"
                     <?= $key === $current ? 'checked' : '' ?> data-driver>
              <b><?= e($gateway['name']) ?></b>
              <small><?= e($gateway['blurb']) ?></small>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php /* What it collects in, and whether we can. A gateway that settles
             in one currency says so itself and the box is not offered; what
             is offered instead is the way to make that currency work. */ ?>
    <?php if (table_has_column('payment_methods', 'currency')): ?>
      <?php
      $baseCode = strtoupper((string) base_currency()['code']);
      $fixedBy  = [];
      foreach ($gateways as $key => $gateway) {
          $fixed = strtoupper(trim((string) ($gateway['currency'] ?? '')));
          if ($fixed !== '') {
              $fixedBy[$key] = $fixed;
          }
      }
      $chosen = strtoupper(trim((string) ($row['currency'] ?? '')));
      $ready  = $id > 0 || isset($fixedBy[$current])
          ? payment_currency_ready(($row ?: []) + ['driver' => $current])
          : ['ok' => true, 'code' => $baseCode, 'message' => ''];
      ?>
      <div class="box">
        <div class="box-head"><b>Currency</b>
          <span class="muted"><?= e($ready['code']) ?></span>
        </div>
        <div class="pad">
          <?php if (isset($fixedBy[$current])): ?>
            <p class="muted" style="margin-bottom:12px">
              <b><?= e($gateways[$current]['name']) ?></b> settles in
              <b><?= e($fixedBy[$current]) ?></b> and nothing else, so that is what this
              method collects in. Orders priced in <?= e($baseCode) ?> are converted at the
              rate on your Currencies screen.
            </p>
            <input type="hidden" name="currency" value="<?= e($fixedBy[$current]) ?>">
          <?php else: ?>
            <div class="field">
              <label for="f-currency">Collects in</label>
              <?php /* A gateway that only settles in a handful says so, and
                       then those are the only ones offered - PayPal will not
                       take an invoice in rupees however the shop prices. */ ?>
              <?php $only = array_map('strtoupper',
                  (array) ($gateways[$current]['currencies'] ?? [])); ?>
              <select id="f-currency" name="currency">
                <?php if (!$only || in_array($baseCode, $only, true)): ?>
                  <option value="">Your base currency &mdash; <?= e($baseCode) ?>, no conversion</option>
                <?php endif; ?>
                <?php foreach (currencies() as $code => $currency): ?>
                  <?php if ($code === $baseCode && (!$only || in_array($baseCode, $only, true))) { continue; } ?>
                  <?php if ($only && !in_array($code, $only, true)) { continue; } ?>
                  <option value="<?= e($code) ?>"<?= $chosen === $code ? ' selected' : '' ?>>
                    <?= e($code) ?> &mdash; <?= e($currency['name'] ?? $code) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small>The customer is asked for the order's total converted into this, at the
                rate on your Currencies screen.<?php if ($only): ?>
                <?= e($gateways[$current]['name']) ?> settles in
                <?= e(implode(', ', $only)) ?> only.<?php endif; ?></small>
            </div>
          <?php endif; ?>

          <?php if (!$ready['ok']): ?>
            <?php /* The method is switched off until this is fixed, so the
                     screen says so plainly and offers the one button that
                     fixes it rather than sending the admin away to guess. */ ?>
            <?php /* An ordinary form, so it works with the script blocked.
                     It adds the currency, fetches today's rate and comes
                     back here - which is the whole fix, and the only one. */ ?>
            <div class="curfix">
              <span class="curfix-mark"><svg class="icon"><use href="#i-alert"></use></svg></span>
              <div>
                <b>This method cannot take payments yet</b>
                <p class="muted"><?= e($ready['message']) ?>
                  Customers are not shown it until there is a rate.</p>
                <?php /* The button is here and its form is at the foot of
                         the page, tied together by `form`. A form inside the
                         method's own form is not a form at all: the browser
                         ends the outer one at the inner tag, and every field
                         below it - the logo among them - stops being part of
                         what gets saved. */ ?>
                <div class="curfix-acts">
                  <button class="btn btn-primary btn-sm" type="submit" form="addCurrencyForm">
                    Add <?= e($ready['code']) ?> and fetch today's rate
                  </button>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/currencies')) ?>">Currencies</a>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($gateways as $key => $gateway): ?>
      <?php if (!$gateway['fields']) { continue; } ?>
      <div class="box" data-for-driver="<?= e($key) ?>">
        <div class="box-head"><b><?= e($gateway['name']) ?> settings</b></div>
        <div class="pad">
          <?php foreach ($gateway['fields'] as $fieldKey => $field): ?>
            <?php $name = payment_field_name($key, $fieldKey); $type = $field['type'] ?? 'text'; ?>
            <div class="field">
              <label for="f-<?= e($name) ?>"><?= e($field['label'] ?? $fieldKey) ?>
                <?php if (!empty($field['required'])): ?><span style="color:var(--danger)">*</span><?php endif; ?>
              </label>
              <?php if ($type === 'select'): ?>
                <?php $oldCfg = old($name, null); $chosen = $oldCfg !== null ? (string) $oldCfg : (string) ($config[$fieldKey] ?? ($field['default'] ?? '')); ?>
                <select id="f-<?= e($name) ?>" name="<?= e($name) ?>">
                  <?php foreach ((array) ($field['options'] ?? []) as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"
                      <?= (string) $value === $chosen ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="f-<?= e($name) ?>" name="<?= e($name) ?>"
                       type="<?= e($type) ?>"
                       value="<?= $type === 'password' ? '' : e(old($name, null) !== null ? old($name) : ($config[$fieldKey] ?? ($field['default'] ?? ''))) ?>"
                       <?= !empty($field['placeholder']) ? 'placeholder="' . e($field['placeholder']) . '"' : '' ?>
                       <?= $err($name) ? 'aria-invalid="true"' : '' ?> autocomplete="off">
              <?php endif; ?>
              <?php if ($err($name)): ?>
                <span class="err"><?= e($err($name)) ?></span>
              <?php elseif (!empty($field['hint'])): ?>
                <small><?= e($field['hint']) ?></small>
              <?php elseif ($type === 'password' && $id): ?>
                <small>Leave empty to keep the saved value.</small>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php /* A gateway that can call us gets a URL of its own. It is
                   handed over with every payment, so there is nothing to
                   register - it is here because the gateway may need the
                   domain approving, and then somebody has to be able to read
                   it out. */ ?>
          <?php if (!empty($gateway['ipn']) && is_callable($gateway['ipn'])): ?>
            <div class="field">
              <label for="f-ipn-<?= e($key) ?>">Webhook URL</label>
              <input id="f-ipn-<?= e($key) ?>" readonly
                     value="<?= $id
                         ? e(url('hook/' . $key . '/' . (int) $id . '/{ORDER-CODE}'))
                         : 'Save this method first - the URL carries its id.' ?>">
              <small><?= e($gateway['name']) ?> is given this with every payment, so there is
                nothing to register by hand. It only ever makes the confirmation arrive sooner:
                the order page and cron confirm the payment by asking either way, so a domain
                the gateway has not approved loses nothing.</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="box" data-manual-only>
      <div class="box-head"><b>Account details</b></div>
      <div class="pad">
        <div class="field">
          <label for="f-account_title">Account title</label>
          <input id="f-account_title" name="account_title" value="<?= e($val('account_title')) ?>">
        </div>
        <div class="field">
          <label for="f-account_number">Account number / IBAN</label>
          <input id="f-account_number" name="account_number" value="<?= e($val('account_number')) ?>"
                 <?= $err('account_number') ? 'aria-invalid="true"' : '' ?>>
          <?php if ($err('account_number')): ?>
            <span class="err"><?= e($err('account_number')) ?></span>
          <?php endif; ?>
        </div>
        <div class="field2">
          <div class="field">
            <label for="f-extra_label">Extra field label</label>
            <input id="f-extra_label" name="extra_label" value="<?= e($val('extra_label')) ?>" placeholder="Branch">
          </div>
          <div class="field">
            <label for="f-extra_value">Extra field value</label>
            <input id="f-extra_value" name="extra_value" value="<?= e($val('extra_value')) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="box" data-manual-only>
      <div class="box-head">
        <b>What the customer is asked</b>
        <button class="btn btn-ghost btn-sm" type="button" data-pf-add>+ Add a field</button>
      </div>
      <div class="pad">
        <p class="muted small" style="margin-bottom:12px">
          The customer fills these in after paying. Mark one as the reference and
          it shows in the orders list. An image field takes a screenshot - those
          are kept outside the public folder and only ever shown to you.
        </p>
        <?php if ($err('pf')): ?>
          <div class="alert alert-error"><?= e($err('pf')) ?></div>
        <?php endif; ?>

        <div class="pf-list" data-pf-list>
          <?php foreach ($payfields as $index => $field): ?>
            <div class="pf-row" data-pf-row>
              <div class="pf-grid">
                <div class="field">
                  <label>Label</label>
                  <input name="pf_label[]" value="<?= e($field['label']) ?>" placeholder="Transaction ID">
                </div>
                <div class="field">
                  <label>Type</label>
                  <select name="pf_type[]" data-pf-type>
                    <?php foreach ($pftypes as $key => $type): ?>
                      <option value="<?= e($key) ?>"<?= $field['type'] === $key ? ' selected' : '' ?>>
                        <?= e($type['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="field">
                <label>Hint under the box <span class="opt-tag">optional</span></label>
                <input name="pf_hint[]" value="<?= e($field['hint']) ?>">
              </div>
              <div class="field" data-pf-options<?= $field['type'] === 'select' ? '' : ' hidden' ?>>
                <label>The choices, one per line</label>
                <textarea name="pf_options[]" rows="3"><?= e(implode("\n", $field['options'])) ?></textarea>
              </div>
              <input type="hidden" name="pf_key[]" value="<?= e($field['key']) ?>">
              <div class="pf-foot">
                <label class="check">
                  <input type="checkbox" name="pf_required[<?= $index ?>]" value="1"
                         <?= $field['required'] ? 'checked' : '' ?>>
                  <span>Required</span>
                </label>
                <label class="check">
                  <input type="radio" name="pf_reference" value="<?= $index ?>"
                         <?= $field['reference'] ? 'checked' : '' ?>>
                  <span>This is the reference</span>
                </label>
                <button class="iact iact-danger" type="button" data-pf-remove
                        title="Remove this field" aria-label="Remove this field">
                  <svg class="icon"><use href="#i-trash"></use></svg>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <p class="muted small" data-pf-empty<?= $payfields ? ' hidden' : '' ?>>
          No fields yet, so the customer is only asked for a transaction id.
        </p>
      </div>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>How it looks</b></div>
      <div class="pad">
        <div class="field">
          <label for="f-name">Name <span style="color:var(--danger)">*</span></label>
          <input id="f-name" name="name" value="<?= e($val('name')) ?>" required
                 <?= $err('name') ? 'aria-invalid="true"' : '' ?> placeholder="JazzCash">
          <?php if ($err('name')): ?><span class="err"><?= e($err('name')) ?></span><?php endif; ?>
        </div>
        <?php /* The logo the customer looks for. Without one the short code
                 is drawn in a coloured square instead, which is why that is
                 still here and still worth filling in. */ ?>
        <?php partial('admin/_upload', [
            'name'    => 'logo',
            'label'   => 'Logo',
            'current' => (string) $val('logo_path'),
            'accept'  => 'image/png,image/jpeg,image/gif,image/webp',
            'hint'    => 'Shown to the customer when they pick how to pay. '
                       . 'A square PNG around 120px reads best.',
        ]); ?>
        <div class="field">
          <label for="f-short_name">Short code</label>
          <input id="f-short_name" name="short_name" value="<?= e($val('short_name')) ?>" placeholder="JC">
          <small>Two letters, used when there is no logo.</small>
        </div>
        <div class="field">
          <label for="f-instructions">Instructions</label>
          <textarea id="f-instructions" name="instructions" rows="4"><?= e($val('instructions')) ?></textarea>
          <small>Shown under the details on the payment page.</small>
        </div>
        <div class="field">
          <label for="f-sort">Sort order</label>
          <input id="f-sort" type="number" name="sort_order" value="<?= e($val('sort_order', 0)) ?>">
        </div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Save</b></div>
      <div class="pad">
        <label class="switch block">
          <input type="checkbox" name="is_active" value="1" <?= $val('is_active', 1) ? 'checked' : '' ?>>
          <span class="track"></span> Offer this to customers
        </label>
        <button class="btn btn-primary btn-block btn-lg" type="submit" style="margin-top:12px">
          <?= $id ? 'Save changes' : 'Create' ?>
        </button>
        <a class="btn btn-ghost btn-block" style="margin-top:9px"
           href="<?= e(url('admin/payment-methods')) ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php /* Outside the form above on purpose - see the note by its button. */ ?>
<?php if (table_has_column('payment_methods', 'currency') && isset($ready) && !$ready['ok']): ?>
  <form method="post" action="<?= e(url('admin/currencies/add')) ?>" id="addCurrencyForm" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="code" value="<?= e($ready['code']) ?>">
    <input type="hidden" name="back"
           value="admin/payment-methods<?= $id > 0 ? '/edit/' . (int) $id : '/new' ?>">
  </form>
<?php endif; ?>

<script>
/* Show only the chosen gateway's settings. The server reads what it needs
   either way, so this is only tidiness. */
(function () {
  var manualKinds = <?= json_encode(array_keys(array_filter($gateways, fn($g) => $g['kind'] === 'manual'))) ?>;

  function sync() {
    var picked = document.querySelector('[data-driver]:checked');
    picked = picked ? picked.value : 'manual';

    document.querySelectorAll('[data-for-driver]').forEach(function (block) {
      block.style.display = block.getAttribute('data-for-driver') === picked ? '' : 'none';
    });
    document.querySelectorAll('[data-manual-only]').forEach(function (block) {
      block.style.display = manualKinds.indexOf(picked) > -1 ? '' : 'none';
    });
    document.querySelectorAll('.opt').forEach(function (opt) {
      opt.classList.toggle('on', opt.querySelector('input').value === picked);
    });
  }

  document.querySelectorAll('[data-driver]').forEach(function (r) {
    r.addEventListener('change', sync);
  });
  sync();

  // ---- the field builder ----
  // Rows are cloned from the one already in the page rather than built from a
  // template string, so the markup exists in exactly one place: the PHP above.
  var list  = document.querySelector('[data-pf-list]');
  var empty = document.querySelector('[data-pf-empty]');

  function renumber() {
    list.querySelectorAll('[data-pf-row]').forEach(function (row, i) {
      var req = row.querySelector('input[name^="pf_required"]');
      if (req) { req.name = 'pf_required[' + i + ']'; }
      var ref = row.querySelector('input[name="pf_reference"]');
      if (ref) { ref.value = i; }
    });
    if (empty) { empty.hidden = list.querySelector('[data-pf-row]') !== null; }
  }

  function blankRow() {
    var first = list.querySelector('[data-pf-row]');
    var row;
    if (first) {
      row = first.cloneNode(true);
      row.querySelectorAll('input[type="text"], input:not([type]), textarea').forEach(function (i) {
        i.value = '';
      });
      row.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (i) {
        i.checked = false;
      });
      var key = row.querySelector('input[name="pf_key[]"]');
      if (key) { key.value = ''; }          // a new row gets a key from its label
      var type = row.querySelector('[data-pf-type]');
      if (type) { type.selectedIndex = 0; }
    } else {
      // Nothing to clone from: ask the server for the markup by reloading
      // with one empty row, which is simpler than keeping a second copy here.
      row = null;
    }
    return row;
  }

  document.querySelectorAll('[data-pf-add]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = blankRow();
      if (!row) { window.location.search = '?addfield=1'; return; }
      list.appendChild(row);
      wire(row);
      renumber();
      row.querySelector('input').focus();
    });
  });

  function wire(row) {
    var remove = row.querySelector('[data-pf-remove]');
    if (remove) {
      remove.addEventListener('click', function () {
        row.remove();
        renumber();
      });
    }
    var type = row.querySelector('[data-pf-type]');
    if (type) {
      type.addEventListener('change', function () {
        var options = row.querySelector('[data-pf-options]');
        if (options) { options.hidden = type.value !== 'select'; }
      });
    }
  }

  if (list) {
    list.querySelectorAll('[data-pf-row]').forEach(wire);
    renumber();
  }
})();
</script>
