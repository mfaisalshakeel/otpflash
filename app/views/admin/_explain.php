<?php
/**
 * A short note explaining what the screen you are on actually does.
 *
 * Collapsed by default once it has been read: the admin who needs it needs
 * it on the first day, and the one who does not should not be scrolling
 * past it every day after.
 *
 * @var string   $title
 * @var string[] $lines  trusted, written here - they carry <b> and &mdash;
 * @var string   $key    what to remember being dismissed under
 * @var array    $links  optional [label => url]
 */
?>
<details class="explain" data-explain="<?= e($key) ?>">
  <summary>
    <span class="explain-mark"><svg class="icon"><use href="#i-alert"></use></svg></span>
    <b><?= e($title) ?></b>
    <small>How this works</small>
  </summary>
  <div class="explain-body">
    <ul>
      <?php foreach ($lines as $line): ?>
        <li><?= $line /* written in catalogue_explainer(), not user input */ ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!empty($links)): ?>
      <div class="explain-links">
        <?php foreach ($links as $label => $href): ?>
          <a class="btn btn-ghost btn-sm" href="<?= e(url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</details>
