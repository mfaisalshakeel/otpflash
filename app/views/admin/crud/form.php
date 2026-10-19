<?php
/**
 * Generic add/edit form driven by $spec['fields'].
 * @var array $spec  @var array $row  @var int $id
 */
$errors = crud_errors();
$value  = static function (string $name, $fallback = '') use ($row) {
    $old = old($name, null);
    return $old !== null ? $old : ($row[$name] ?? $fallback);
};
?>
<a class="lnk back" href="<?= e(url($spec['base'])) ?>">&larr; Back to <?= e(strtolower($spec['title'])) ?></a>

<form method="post" action="<?= e(url($spec['base'] . '/save')) ?>" class="split2"
      <?= !empty($spec['upload']) ? 'enctype="multipart/form-data"' : '' ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="box">
    <div class="box-head"><b><?= e($id ? 'Edit' : 'New') ?> <?= e(strtolower($spec['single'] ?? 'record')) ?></b></div>
    <div class="pad">
      <?php foreach ($spec['fields'] as $name => $field): ?>
        <?php
        $type  = $field['type'] ?? 'text';
        $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $name));
        $error = $errors[$name] ?? null;
        $val   = $value($name, $field['default'] ?? '');
        ?>
        <?php if ($type === 'hidden'): ?>
          <input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>">

        <?php elseif ($type === 'checkbox'): ?>
          <label class="switch block">
            <input type="checkbox" name="<?= e($name) ?>" value="1" <?= $val ? 'checked' : '' ?>>
            <span class="track"></span> <?= e($label) ?>
          </label>
          <?php if (!empty($field['hint'])): ?>
            <small class="muted" style="display:block;margin:-6px 0 14px"><?= e($field['hint']) ?></small>
          <?php endif; ?>

        <?php elseif ($type === 'note'): ?>
          <?php /* A block the screen writes for itself - markup, not a value,
                   so it is written in PHP and never comes from a request. */ ?>
          <?= $field['html'] ?>

        <?php elseif ($type === 'checkboxes'): ?>
          <?php /* Several of a list, which is a different question from the
                   searchable select beside it: that one replaces its answer,
                   this one adds to it. The boxes are the control and always
                   were - the bar above them only hides what you are not
                   looking for, and it stays hidden until the script is there
                   to do that, because a search box that filters nothing is
                   worse than none.

                   An option may be a plain string or ['label', 'note']; the
                   note is the second line - for a link rule, the link itself,
                   which is the thing an admin actually recognises it by. */ ?>
          <?php $ticked = array_filter(array_map('trim', explode(',', (string) $val))); ?>
          <?php $many = count($field['options']) > 6 || !empty($field['search']); ?>
          <div class="field">
            <label><?= e($label) ?></label>

            <div class="picker<?= $many ? ' picker-tall' : '' ?>">
              <?php if ($many): ?>
                <div class="picker-bar" hidden data-picker-bar>
                  <svg class="icon"><use href="#i-search"></use></svg>
                  <input type="search" data-picker-find autocomplete="off"
                         placeholder="Search <?= e(strtolower($label)) ?>">
                  <span class="picker-count" data-picker-count></span>
                </div>
              <?php endif; ?>

              <div class="picker-list">
                <?php foreach ($field['options'] as $optValue => $option): ?>
                  <?php
                  $optLabel = is_array($option) ? ($option['label'] ?? '') : $option;
                  $optNote  = is_array($option) ? ($option['note'] ?? '') : '';
                  ?>
                  <label class="pick">
                    <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($optValue) ?>"
                           <?= in_array((string) $optValue, $ticked, true) ? 'checked' : '' ?>>
                    <span class="pick-text">
                      <b><?= e($optLabel) ?></b>
                      <?php if ($optNote !== ''): ?><small><?= e($optNote) ?></small><?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
                <p class="pick-none" hidden data-picker-none>Nothing matches that.</p>
              </div>
            </div>

            <?php if ($error): ?>
              <span class="err"><?= e($error) ?></span>
            <?php elseif (!empty($field['hint'])): ?>
              <small><?= e($field['hint']) ?></small>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <div class="field">
            <label for="f-<?= e($name) ?>"><?= e($label) ?>
              <?php if (!empty($field['required'])): ?><span style="color:var(--danger)">*</span><?php endif; ?>
            </label>

            <?php if ($type === 'textarea'): ?>
              <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int) ($field['rows'] ?? 4) ?>"
                <?= $error ? 'aria-invalid="true"' : '' ?>
                <?= !empty($field['required']) ? 'required' : '' ?>><?= e($val) ?></textarea>

            <?php elseif ($type === 'select'): ?>
              <select id="f-<?= e($name) ?>" name="<?= e($name) ?>"
                      <?= !empty($field['search']) ? 'data-search' : '' ?>
                      <?= $error ? 'aria-invalid="true"' : '' ?>>
                <?php if (!empty($field['empty'])): ?>
                  <option value=""><?= e($field['empty']) ?></option>
                <?php endif; ?>
                <?php foreach ($field['options'] as $optValue => $optLabel): ?>
                  <option value="<?= e($optValue) ?>" <?= (string) $val === (string) $optValue ? 'selected' : '' ?>>
                    <?= e($optLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>

            <?php else: ?>
              <input id="f-<?= e($name) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>"
                     value="<?= $type === 'password' ? '' : e($val) ?>"
                     <?= isset($field['step'])  ? 'step="'  . e($field['step'])  . '"' : '' ?>
                     <?= isset($field['min'])   ? 'min="'   . e($field['min'])   . '"' : '' ?>
                     <?= isset($field['max'])   ? 'max="'   . e($field['max'])   . '"' : '' ?>
                     <?= !empty($field['placeholder']) ? 'placeholder="' . e($field['placeholder']) . '"' : '' ?>
                     <?= !empty($field['required']) && empty($field['skip_empty']) ? 'required' : '' ?>
                     <?= $error ? 'aria-invalid="true"' : '' ?>
                     autocomplete="<?= e($field['autocomplete'] ?? 'off') ?>">
            <?php endif; ?>

            <?php if ($error): ?>
              <span class="err"><?= e($error) ?></span>
            <?php elseif (!empty($field['hint'])): ?>
              <small><?= e($field['hint']) ?></small>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Save</b></div>
      <div class="pad">
        <button class="btn btn-primary btn-block btn-lg" type="submit">
          <?= e($id ? 'Save changes' : 'Create') ?>
        </button>
        <a class="btn btn-ghost btn-block" style="margin-top:9px"
           href="<?= e(url($spec['base'])) ?>">Cancel</a>
      </div>
    </div>
    <?php if (!empty($spec['form_note'])): ?>
      <div class="box"><div class="pad"><p class="muted"><?= e($spec['form_note']) ?></p></div></div>
    <?php endif; ?>
  </div>
</form>
