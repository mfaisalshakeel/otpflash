<?php
/**
 * @var bool   $available  @var string $codeVersion  @var string $dbVersion
 * @var array  $steps      @var array  $pending      @var array  $applied
 * @var array  $environment
 */
?>
<div class="split2">
  <div>

    <div class="box upd-hero<?= $available ? ' upd-hero-on' : '' ?>">
      <div class="upd-hero-top">
        <span class="upd-mark">
          <svg class="icon"><use href="#<?= $available ? 'i-down' : 'i-check' ?>"></use></svg>
        </span>
        <div class="upd-hero-txt">
          <b><?= $available ? 'Update available' : 'You are up to date' ?></b>
          <small>
            <?php if ($available): ?>
              Database is on <?= e($dbVersion) ?>, the files are on <?= e($codeVersion) ?>.
            <?php else: ?>
              Files and database are both on <?= e($codeVersion) ?>.
            <?php endif; ?>
          </small>
        </div>
        <span class="upd-ver mono"><?= e($codeVersion) ?></span>
      </div>

      <?php if ($available): ?>
        <div class="upd-body" data-update
             data-plan="<?= e(url('admin/update/plan')) ?>"
             data-step="<?= e(url('admin/update/step')) ?>"
             data-token="<?= e(csrf_token()) ?>">

          <p class="upd-lead">
            <?= count($pending) ?>
            change<?= count($pending) === 1 ? '' : 's' ?> to apply to the database.
            Nothing is removed and nothing is overwritten &mdash; each change runs once
            and is recorded.
          </p>

          <div class="bar" data-bar-wrap hidden>
            <div class="bar-fill" data-bar style="width:0%"></div>
          </div>
          <p class="bar-note" data-bar-note hidden>
            <span data-bar-label>Starting&hellip;</span>
            <b class="mono" data-bar-count></b>
          </p>

          <ol class="upd-steps" data-steps>
            <?php foreach ($steps as $step): ?>
              <li class="upd-step" data-key="<?= e($step['key']) ?>">
                <span class="upd-dot"><span class="spin"></span></span>
                <span class="upd-step-txt">
                  <b><?= e($step['label']) ?></b>
                  <small><?= e($step['detail']) ?></small>
                </span>
                <span class="upd-note" data-note></span>
              </li>
            <?php endforeach; ?>
          </ol>

          <form method="post" action="<?= e(url('admin/update/run')) ?>" data-update-form>
            <?= csrf_field() ?>
            <button class="btn btn-primary btn-lg btn-block" type="submit" data-update-go>
              <svg class="icon"><use href="#i-refresh"></use></svg>
              Run the update
            </button>
          </form>

          <div class="alert alert-error" data-update-error hidden></div>
          <div class="upd-done" data-update-done hidden>
            <svg class="icon"><use href="#i-check"></use></svg>
            <span><b>Update finished.</b>
              <small data-done-note>Reloading in 3&hellip;</small></span>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/update')) ?>">Reload now</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($applied): ?>
      <div class="box">
        <div class="box-head">
          <b>History</b>
          <span class="muted"><?= count($applied) ?> applied</span>
        </div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Change</th><th class="ta-r">Applied</th></tr></thead>
            <tbody>
            <?php foreach ($applied as $row): ?>
              <tr>
                <td style="cursor:default" data-label="Change">
                  <b><?= e(migration_label($row['id'])) ?></b>
                  <small class="sub mono"><?= e($row['id']) ?></small>
                </td>
                <td class="ta-r muted" style="cursor:default" data-label="Applied">
                  <?= e(when($row['applied_at'], 'd M Y, H:i')) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>This install</b></div>
      <div style="padding:6px 18px 16px">
        <?php foreach ($environment as $label => $value): ?>
          <div class="drow"><span><?= e($label) ?></span><b class="mono"><?= e($value) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>How updating works</b></div>
      <div style="padding:4px 18px 18px">
        <ol class="howto">
          <li>Upload the new release over the old files, leaving
              <code>config/</code>, <code>storage/</code> and <code>uploads/</code> alone.</li>
          <li>Come back to this screen. It compares the version in the files with the
              version recorded in the database.</li>
          <li>Press <b>Run the update</b>. Each change runs as its own request, so a
              shared host cannot time the whole thing out halfway.</li>
        </ol>
        <p class="muted">Take a database backup first. An update only adds tables,
           columns and settings, but a backup costs a minute and a restore does not.</p>
      </div>
    </div>
  </div>
</div>
