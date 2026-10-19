<?php
/**
 * OTPGet: connect, choose what to sell, price it, sync it.
 *
 * @var bool $configured  @var string $keyHint  @var array $balance  @var string $lastError
 * @var array $counts  @var string $syncedAt  @var int $units  @var array $currencies
 */
$enabled  = products_enabled();
$sms      = product_servers('sms');
$email    = product_servers('email');
$rental   = product_servers('rental');
$value    = static fn (string $key, string $default = ''): string => (string) setting($key, $default);
?>
<?php partial('admin/_explain', ['title' => 'How prices and visibility work', 'key' => 'otpget',
    'lines' => catalogue_explainer(), 'links' => ['Open the catalogue' => 'admin/catalogue']]); ?>
<div class="split2">
  <div>
    <?php /* -------------------------------------------------- sync ---- */ ?>
    <div class="box upd-hero<?= $configured ? ' upd-hero-on' : '' ?>">
      <div class="upd-hero-top">
        <span class="upd-mark"><svg class="icon"><use href="#i-refresh"></use></svg></span>
        <div class="upd-hero-txt">
          <b>Catalogue sync</b>
          <small><?= $syncedAt !== '' ? 'Last synced ' . e(when($syncedAt)) : 'Never synced yet' ?>
            &middot; <?= qty_fmt(array_sum(array_map(static fn ($c) => (int) $c['live'], $counts))) ?> offers on sale</small>
        </div>
      </div>

      <?php if ($configured): ?>
        <div class="upd-body" data-update
             data-plan="<?= e(url('admin/otpget/plan')) ?>"
             data-step="<?= e(url('admin/otpget/step')) ?>"
             data-token="<?= e(csrf_token()) ?>">
          <p class="upd-lead">Reads every country on every server you sell from OTPGet and brings prices and
            stock up to date - one country per request, so nothing times out. Cron does the same a few
            countries at a time in the background<?= $units ? ' (' . qty_fmt($units) . ' countries and sites last time)' : '' ?>.</p>

          <div class="bar" data-bar-wrap hidden><div class="bar-fill" data-bar style="width:0%"></div></div>
          <p class="bar-note" data-bar-note hidden><span data-bar-label>Starting&hellip;</span> <b class="mono" data-bar-count></b></p>

          <form method="post" action="<?= e(url('admin/otpget/plan')) ?>" data-update-form>
            <?= csrf_field() ?>
            <button class="btn btn-primary btn-lg btn-block" type="submit" data-update-go data-busy="Syncing&hellip;">
              <svg class="icon"><use href="#i-refresh"></use></svg> Sync the catalogue now
            </button>
          </form>
          <div class="alert alert-error" data-update-error hidden></div>
          <div class="upd-done" data-update-done hidden>
            <svg class="icon"><use href="#i-check"></use></svg>
            <span><b>Sync finished.</b> <small data-done-note>Reloading in 3&hellip;</small></span>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/otpget')) ?>">Reload now</a>
          </div>
        </div>
      <?php else: ?>
        <div class="upd-body"><p class="upd-lead">Add your API key below first - sync needs it.</p></div>
      <?php endif; ?>
    </div>

    <form method="post" action="<?= e(url('admin/otpget/save')) ?>">
      <?= csrf_field() ?>

      <?php /* ------------------------------------------ connection ---- */ ?>
      <div class="box">
        <div class="box-head"><b>Connection</b>
          <span class="st st-<?= $configured && $lastError === '' ? 'completed' : 'pending' ?>">
            <?= $configured ? ($lastError === '' ? 'connected' : 'problem') : 'not set up' ?></span>
        </div>
        <div class="pad">
          <?php if ($lastError !== ''): ?>
            <div class="alert alert-error" style="margin-top:0"><?= e($lastError) ?></div>
          <?php endif; ?>
          <div class="field">
            <label for="o-key">API key</label>
            <input id="o-key" name="otpget_api_key" type="password" autocomplete="off" spellcheck="false"
                   placeholder="<?= e($keyHint !== '' ? 'Saved (' . $keyHint . ') - type to replace' : 'Paste your key from otpget.com') ?>">
            <small>From your OTPGet dashboard. It is never shown again once saved; leave the box empty to keep it.</small>
          </div>
          <div class="field2">
            <div class="field">
              <label for="o-url">API address</label>
              <input id="o-url" name="otpget_base_url" value="<?= e($value('otpget_base_url', 'https://otpget.com/stubs')) ?>">
              <small>Leave as it is unless OTPGet tells you otherwise.</small>
            </div>
            <div class="field">
              <label for="o-cur">Your OTPGet account is billed in</label>
              <select id="o-cur" name="otpget_currency">
                <?php foreach (array_unique(array_merge(['PKR'], $currencies)) as $code): ?>
                  <option value="<?= e($code) ?>"<?= otpget_currency() === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                <?php endforeach; ?>
              </select>
              <small>PKR for every OTPGet account unless you asked for another. Costs are converted with your currency rates.</small>
            </div>
          </div>
          <div class="field">
            <label for="o-low">Warn me when the balance falls under (in <?= e(base_currency()['code']) ?>)</label>
            <input id="o-low" name="low_balance_threshold" type="number" min="0" step="1" value="<?= e($value('low_balance_threshold', '0')) ?>">
            <small>0 turns the warning off.</small>
          </div>
          <?php if ($keyHint !== ''): ?>
            <label class="check"><input type="checkbox" name="forget_key" value="1"> Remove the saved key</label>
          <?php endif; ?>
        </div>
      </div>

      <?php /* ---------------------------------------- what to sell ---- */ ?>
      <div class="box">
        <div class="box-head"><b>What the shop sells</b></div>
        <div class="pad">
          <?php foreach (PRODUCTS as $key => $product): ?>
            <?php $live = (int) ($counts[$key]['live'] ?? 0); ?>
            <div class="rule">
              <label class="switch block">
                <input type="checkbox" name="products[]" value="<?= e($key) ?>" <?= in_array($key, $enabled, true) ? 'checked' : '' ?>>
                <span class="track"></span> <b><?= e($product['name']) ?></b>
                <small class="muted">&middot; <?= qty_fmt($live) ?> offers<?= !empty($counts[$key]['low']) ? ', from ' . e(money((float) $counts[$key]['low'])) : '' ?></small>
              </label>
              <small class="muted" style="display:block;margin:2px 0 8px 52px"><?= e($product['blurb']) ?></small>

              <?php if ($key === 'sms'): ?>
                <div class="chanpick" style="margin-left:52px">
                  <?php foreach (['1', '2', '3', '4', '5', '6'] as $server): ?>
                    <label class="chanopt">
                      <input type="checkbox" name="sms_servers[]" value="<?= $server ?>" <?= in_array($server, $sms, true) ? 'checked' : '' ?>>
                      <span>Server <?= $server ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($key === 'email'): ?>
                <div class="chanpick" style="margin-left:52px">
                  <?php foreach (['1' => 'Server 1 - by app', '2' => 'Server 2 - by website'] as $server => $label): ?>
                    <label class="chanopt">
                      <input type="checkbox" name="email_servers[]" value="<?= $server ?>" <?= in_array($server, $email, true) ? 'checked' : '' ?>>
                      <span><?= e($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="field" style="margin:10px 0 0 52px">
                  <label for="o-sites">Websites to sell server 2 inboxes for</label>
                  <textarea id="o-sites" name="email_sites" rows="3"><?= e(implode(', ', email_sites())) ?></textarea>
                  <small>Server 2 sells an inbox for a website. Each one listed here becomes a choice on the shop.</small>
                </div>
              <?php elseif ($key === 'rental'): ?>
                <div class="chanpick" style="margin-left:52px">
                  <?php foreach (['1' => 'Server 1 - 12 hours to 1 year', '2' => 'Server 2 - monthly, cancellable'] as $server => $label): ?>
                    <label class="chanopt">
                      <input type="checkbox" name="rental_providers[]" value="<?= $server ?>" <?= in_array($server, $rental, true) ? 'checked' : '' ?>>
                      <span><?= e($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php /* --------------------------------------------- pricing ---- */ ?>
      <div class="box">
        <div class="box-head"><b>Pricing</b></div>
        <div class="pad">
          <p class="muted" style="margin-top:0">Price = OTPGet's cost, converted to <?= e(base_currency()['code']) ?>,
            plus your markup, then rounded <b>up</b>. Prices rebuild themselves on every sync and whenever you save here.</p>
          <div class="field2">
            <div class="field">
              <label for="o-mk">Markup for everything (%)</label>
              <input id="o-mk" name="default_markup" type="number" min="0" step="0.1" value="<?= e($value('default_markup', '30')) ?>">
            </div>
            <div class="field">
              <label for="o-min">Lowest price you sell at</label>
              <input id="o-min" name="min_price" type="number" min="0" step="1" value="<?= e($value('min_price', '0')) ?>">
            </div>
          </div>
          <div class="field2">
            <?php foreach (PRODUCTS as $key => $product): ?>
              <div class="field">
                <label for="o-mk-<?= e($key) ?>"><?= e($product['name']) ?> markup (%)</label>
                <input id="o-mk-<?= e($key) ?>" name="markup_<?= e($key) ?>" type="number" min="0" step="0.1"
                       value="<?= e($value('markup_' . $key, '')) ?>" placeholder="same as above">
              </div>
            <?php endforeach; ?>
            <div class="field">
              <label for="o-round">Round prices up to</label>
              <select id="o-round" name="price_rounding">
                <?php foreach (['0' => 'Do not round', '0.5' => 'Nearest 0.50', '1' => 'Whole number', '5' => 'Multiple of 5', '10' => 'Multiple of 10'] as $step => $label): ?>
                  <option value="<?= e($step) ?>"<?= $value('price_rounding', '1') === (string) $step ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <?php /* ------------------------------------------ the names ---- */ ?>
      <div class="box">
        <div class="box-head"><b>What customers call each option</b></div>
        <div class="pad">
          <p class="muted" style="margin-top:0">Optional. "Server 2" means nothing to a customer; "Fast" or "Best value" might.</p>
          <div class="field2">
            <?php foreach (['1', '2', '3', '4', '5', '6'] as $server): ?>
              <div class="field">
                <label for="o-l<?= $server ?>">Server <?= $server ?></label>
                <input id="o-l<?= $server ?>" name="server_label_<?= $server ?>" value="<?= e($value('server_label_' . $server)) ?>" placeholder="Server <?= $server ?>">
              </div>
            <?php endforeach; ?>
            <div class="field"><label for="o-lp">Premium</label>
              <input id="o-lp" name="server_label_premium" value="<?= e($value('server_label_premium')) ?>" placeholder="Premium · SMS or call"></div>
            <div class="field"><label for="o-lc">Budget</label>
              <input id="o-lc" name="server_label_cheap" value="<?= e($value('server_label_cheap')) ?>" placeholder="Budget"></div>
          </div>
        </div>
      </div>

      <?php /* -------------------------------------- background sync ---- */ ?>
      <div class="box">
        <div class="box-head"><b>Background sync</b></div>
        <div class="pad">
          <label class="switch block">
            <input type="checkbox" name="sync_on_cron" value="1" <?= $value('sync_on_cron', '1') === '1' ? 'checked' : '' ?>>
            <span class="track"></span> Keep the catalogue fresh from cron
          </label>
          <div class="field" style="margin-top:12px">
            <label for="o-batch">Countries to read per cron run</label>
            <input id="o-batch" name="sync_batch" type="number" min="1" max="50" value="<?= e($value('sync_batch', '8')) ?>">
            <small>Each one is a request to OTPGet. Eight every ten minutes keeps a big catalogue no more than a few hours old.</small>
          </div>
        </div>
      </div>

      <div class="savebar"><button class="btn btn-primary btn-lg" type="submit">Save</button></div>
    </form>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Balance</b></div>
      <div class="pad">
        <div class="drow"><span>OTPGet wallet</span><b><?= e($balance['own']) ?></b></div>
        <?php if ($balance['base'] !== null && $balance['code'] !== base_currency()['code']): ?>
          <div class="drow"><span>In <?= e(base_currency()['code']) ?></span><b><?= e(money($balance['base'])) ?></b></div>
        <?php endif; ?>
        <div class="drow"><span>Checked</span><b><?= e($balance['at'] !== '' ? when($balance['at']) : 'never') ?></b></div>
        <form method="post" action="<?= e(url('admin/otpget/test')) ?>" style="margin-top:12px">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-block" type="submit" <?= $configured ? '' : 'disabled' ?>>
            <svg class="icon"><use href="#i-plug"></use></svg> Test the connection</button>
        </form>
        <p class="muted">Numbers are bought from this wallet the moment a customer pays. Top it up at
          <a class="lnk" href="https://otpget.com" target="_blank" rel="noopener">otpget.com</a>.</p>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Prices now</b></div>
      <div class="pad">
        <?php foreach (PRODUCTS as $key => $product): ?>
          <div class="drow"><span><?= e($product['name']) ?></span>
            <b><?= qty_fmt((int) ($counts[$key]['live'] ?? 0)) ?> on sale<?= !empty($counts[$key]['low']) ? ' · from ' . e(money((float) $counts[$key]['low'])) : '' ?></b></div>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('admin/otpget/reprice')) ?>" style="margin-top:12px">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-block" type="submit">Rebuild every price now</button>
        </form>
        <a class="btn btn-ghost btn-block" style="margin-top:8px" href="<?= e(url('admin/catalogue')) ?>">Open the catalogue</a>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>How it fits together</b></div>
      <div class="pad">
        <ol class="howto">
          <li>Save your API key. The balance check proves it works.</li>
          <li>Press <b>Sync the catalogue now</b>. Countries get their flags and apps their icons by themselves.</li>
          <li>Set your markup. Every price follows, now and on every sync after.</li>
          <li>Switch off anything you do not want to sell - a product, a server, a country or an app.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
