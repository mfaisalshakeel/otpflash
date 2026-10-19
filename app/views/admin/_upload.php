<?php
/**
 * An image field.
 *
 * The browser's own file input is a grey "Choose File / No file chosen" box
 * that takes its look from the operating system and ignores everything else
 * on the page. The real input is still here, still named the same, still the
 * thing that submits - it is just moved out of sight and driven by the label,
 * which is what a file input is designed to allow. With JavaScript off the
 * label still opens the picker; only the preview and the drop target are lost.
 *
 * @var string  $name     form field name
 * @var string  $label    what to call it
 * @var string  $current  path of the image already saved, or ''
 * @var string  $accept   accept attribute
 * @var string  $hint     the line under the box
 */
$id = 'u-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
?>
<div class="field upl" data-upload>
  <label for="<?= e($id) ?>"><?= e($label) ?></label>

  <input id="<?= e($id) ?>" type="file" name="<?= e($name) ?>" accept="<?= e($accept) ?>"
         class="upl-input" data-upload-input>

  <label class="upl-zone" for="<?= e($id) ?>" data-drop>
    <span class="upl-thumb" data-thumb<?= $current ? '' : ' data-empty' ?>>
      <?php if ($current): ?>
        <img src="<?= e(url($current)) ?>" alt="">
      <?php else: ?>
        <svg class="icon"><use href="#i-image"></use></svg>
      <?php endif; ?>
    </span>
    <span class="upl-txt">
      <b data-upload-title><?= $current ? 'Replace this image' : 'Drop an image, or browse' ?></b>
      <small><?= e($hint) ?></small>
    </span>
    <span class="upl-browse">Browse</span>
  </label>

  <div class="upl-chosen" data-chosen hidden>
    <svg class="icon"><use href="#i-check"></use></svg>
    <span data-chosen-name></span>
    <button type="button" class="upl-clear" data-upload-clear aria-label="Clear the chosen file">&times;</button>
  </div>

  <?php if ($current): ?>
    <label class="switch upl-remove">
      <input type="checkbox" name="remove_<?= e($name) ?>" value="1">
      <span class="track"></span> Remove the current image
    </label>
  <?php endif; ?>
</div>
