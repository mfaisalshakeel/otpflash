<?php /** @var array $themes  @var string $active */ ?>
<div class="alert alert-info">
  A theme is a folder in <span class="mono">themes/</span> with a
  <span class="mono">theme.php</span> beside <span class="mono">views/</span> and
  <span class="mono">assets/</span>. Drop one in and it appears here &mdash; nothing else
  needs changing. A theme only has to ship the views it wants to look different;
  anything it leaves out falls back to the default theme.
</div>

<?php if (!$themes): ?>
  <div class="box"><div class="pad">
    <p class="muted">No themes found in <span class="mono">themes/</span>.</p>
  </div></div>
<?php else: ?>
  <div class="theme-grid">
    <?php foreach ($themes as $slug => $theme): ?>
      <?php $shot = theme_screenshot($theme); ?>
      <div class="theme-card<?= $slug === $active ? ' on' : '' ?>">
        <div class="theme-shot">
          <?php if ($shot): ?>
            <img src="<?= e($shot) ?>" alt="<?= e($theme['name']) ?>">
          <?php else: ?>
            <div class="theme-shot-empty">
              <svg class="icon"><use href="#i-grid"></use></svg>
              <span>No screenshot</span>
            </div>
          <?php endif; ?>
          <?php if ($slug === $active): ?>
            <span class="theme-live">Live</span>
          <?php endif; ?>
        </div>

        <div class="theme-body">
          <b><?= e($theme['name']) ?></b>
          <small class="mono"><?= e($slug) ?><?= $theme['version'] !== ''
            ? ' &middot; v' . e($theme['version']) : '' ?></small>
          <?php if ($theme['description'] !== ''): ?>
            <p><?= e($theme['description']) ?></p>
          <?php endif; ?>
          <?php if ($theme['author'] !== ''): ?>
            <small class="muted">by <?= e($theme['author']) ?></small>
          <?php endif; ?>
        </div>

        <div class="theme-foot">
          <?php if ($slug === $active): ?>
            <a class="btn btn-ghost btn-block" href="<?= e(url('')) ?>" target="_blank" rel="noopener">
              View the site
            </a>
          <?php else: ?>
            <form method="post" action="<?= e(url('admin/themes/activate')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="theme" value="<?= e($slug) ?>">
              <button class="btn btn-primary btn-block" type="submit">Activate</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
