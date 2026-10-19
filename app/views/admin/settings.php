<?php
/**
 * Settings.
 *
 * One form, several tabs. Every field stays in the DOM whichever tab is
 * showing, so Save still saves the lot — hiding a panel must never mean
 * dropping what is in it. Without JavaScript the tab strip is a row of
 * anchors and every panel is simply visible, which is the old long page.
 *
 * @var array  $groups  @var string $cronKey  @var array $pending
 */
$panels = [];

foreach ($groups as $groupName => $fields) {
    $panels[$groupName] = ['fields' => $fields];
}
$panels['Branding'] = ['uploads' => true];
$panels['Cron and database'] = ['system' => true];

$slug = static fn(string $name): string => 'tab-' . slugify($name);
$first = array_key_first($panels);
?>
<form method="post" action="<?= e(url('admin/settings')) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="tabstrip" role="tablist" data-tabs>
    <?php foreach ($panels as $name => $panel): ?>
      <a class="tabitem<?= $name === $first ? ' on' : '' ?>" role="tab"
         href="#<?= e($slug($name)) ?>" data-tab="<?= e($slug($name)) ?>"
         aria-selected="<?= $name === $first ? 'true' : 'false' ?>"><?= e($name) ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($panels as $name => $panel): ?>
    <section class="tabpanel" id="<?= e($slug($name)) ?>" data-panel="<?= e($slug($name)) ?>">

      <?php if (!empty($panel['fields'])): ?>
        <div class="box">
          <div class="box-head"><b><?= e($name) ?></b></div>
          <div class="pad">
            <?php foreach ($panel['fields'] as $key => $field): ?>
              <?php
              $type  = $field['type'] ?? 'text';
              $value = setting($key, '');
              ?>
              <?php if ($type === 'checkbox'): ?>
                <label class="switch block">
                  <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                  <span class="track"></span> <?= e($field['label']) ?>
                </label>
              <?php elseif ($type === 'channels'): ?>
                <?php /* One row per place, the channels across it. A channel
                         with nothing filled in on the Contact tab is shown
                         greyed rather than hidden: "Telegram is off because
                         you have not given a username" is the answer the
                         admin needs, and hiding the box asks them to guess. */ ?>
                <?php $chosen = chat_placement_keys((string) ($field['placement'] ?? '')); ?>
                <div class="field">
                  <label><?= e($field['label']) ?></label>
                  <div class="chanpick">
                    <?php foreach (CHAT_CHANNELS as $chKey => $channel): ?>
                      <?php $ready = chat_channel($chKey) !== null; ?>
                      <label class="chanopt<?= $ready ? '' : ' chanopt-unset' ?>">
                        <input type="checkbox" name="<?= e($key) ?>[]" value="<?= e($chKey) ?>"
                               <?= in_array($chKey, $chosen, true) ? 'checked' : '' ?>>
                        <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg>
                        <span><?= e($channel['name']) ?></span>
                        <?php if (!$ready): ?><small>not set up</small><?php endif; ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <?php if (!empty($field['hint'])): ?><small><?= e($field['hint']) ?></small><?php endif; ?>
                </div>
              <?php else: ?>
                <div class="field<?= $type === 'color' ? ' colorrow-wrap' : '' ?>">
                  <label for="s-<?= e($key) ?>"><?= e($field['label']) ?></label>
                  <?php if ($type === 'textarea'): ?>
                    <textarea id="s-<?= e($key) ?>" name="<?= e($key) ?>"
                              rows="<?= (int) ($field['rows'] ?? 4) ?>"><?= e($value) ?></textarea>
                  <?php elseif ($type === 'select'): ?>
                    <?php /* A callable builds its list when the page renders, which a
                             list of database rows has to; a fixed list is just a list. */ ?>
                    <?php $options = is_callable($field['options'])
                        ? ($field['options'])() : (array) $field['options']; ?>
                    <select id="s-<?= e($key) ?>" name="<?= e($key) ?>">
                      <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?= e($optValue) ?>"<?= $value === (string) $optValue ? ' selected' : '' ?>>
                          <?= e($optLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($type === 'password'): ?>
                    <?php /* Never printed back: the value would sit in the page source
                             for anything that can read it. Blank means unchanged, which
                             the controller knows too. */ ?>
                    <input id="s-<?= e($key) ?>" type="password" name="<?= e($key) ?>"
                           value="" autocomplete="new-password"
                           placeholder="<?= $value !== '' ? 'Stored - leave blank to keep it' : '' ?>">
                  <?php else: ?>
                    <input id="s-<?= e($key) ?>" type="<?= e($type) ?>" name="<?= e($key) ?>"
                           value="<?= e($value) ?>" <?= !empty($field['required']) ? 'required' : '' ?>>
                  <?php endif; ?>
                  <?php if (!empty($field['hint'])): ?><small><?= e($field['hint']) ?></small><?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($panel['uploads'])): ?>
        <div class="box">
          <div class="box-head"><b>Logo and favicon</b></div>
          <div class="pad">
            <?php foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $input => $settingKey): ?>
              <?php partial('admin/_upload', [
                  'name'    => $input,
                  'label'   => ucfirst($input),
                  'current' => (string) setting($settingKey, ''),
                  'accept'  => 'image/png,image/jpeg,image/gif,image/webp,image/x-icon',
                  'hint'    => 'PNG, JPG, GIF, WebP or ICO, up to 2 MB.',
              ]); ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($panel['system'])): ?>
        <div class="split2">
          <div class="box">
            <div class="box-head"><b>Cron</b></div>
            <div class="pad">
              <p class="muted" style="margin-bottom:14px">
                Run this every 5 minutes so paid orders are sent and statuses stay fresh.
              </p>
              <div class="field">
                <label>Command (cPanel cron job)</label>
                <div class="copyrow">
                  <input value="php <?= e(BASE_PATH) ?>/cron.php" readonly>
                  <button class="btn btn-ghost btn-sm" type="button"
                          data-copy="php <?= e(BASE_PATH) ?>/cron.php">Copy</button>
                </div>
              </div>
              <div class="field">
                <label>Or call this URL</label>
                <div class="copyrow">
                  <input value="<?= e(url('cron/' . $cronKey)) ?>" readonly>
                  <button class="btn btn-ghost btn-sm" type="button"
                          data-copy="<?= e(url('cron/' . $cronKey)) ?>">Copy</button>
                </div>
                <small>The key lives in config/config.php. Anything else under /cron returns 404.</small>
              </div>
            </div>
          </div>

          <div class="box">
            <div class="box-head">
              <b>Database</b>
              <?php if ($pending): ?><span class="st st-pending"><?= count($pending) ?> pending</span><?php endif; ?>
            </div>
            <div class="pad">
              <?php if ($pending): ?>
                <p class="muted" style="margin-bottom:12px">
                  This copy of the panel is newer than its database:
                  <span class="mono"><?= e(implode(', ', $pending)) ?></span>
                </p>
                <a class="btn btn-primary btn-block" href="<?= e(url('admin/update')) ?>">
                  Go to the update screen
                </a>
              <?php else: ?>
                <p class="muted">The database is up to date. Nothing to do.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <div class="savebar">
    <span class="muted">Changes apply across every tab.</span>
    <button class="btn btn-primary btn-lg" type="submit">Save settings</button>
  </div>
</form>
