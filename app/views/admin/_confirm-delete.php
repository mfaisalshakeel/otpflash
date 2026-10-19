<?php
/**
 * The screen between pressing delete and it happening.
 *
 * Shown rather than a dialog, because what goes with a row is a list and a
 * dialog is one sentence. The form posts back to the same place with one
 * field more, so the route, the token and the permission check are all the
 * ones that were already there.
 *
 * @var string $title  @var string $what  @var array $lines  @var ?string $blocked
 * @var string $action @var array  $fields  @var string $cancel
 */
?>
<div class="box" style="max-width:640px">
  <div class="box-head">
    <b>Delete <?= e($what) ?>?</b>
    <span class="st st-cancelled">cannot be undone</span>
  </div>
  <div class="pad">
    <?php if ($blocked !== null): ?>
      <div class="alert alert-warning" style="margin-bottom:0"><?= e($blocked) ?></div>
      <div class="stack" style="margin-top:16px">
        <a class="btn btn-ghost" href="<?= e(url($cancel)) ?>">Back</a>
      </div>
    <?php else: ?>
      <?php if ($lines): ?>
        <p class="muted" style="margin-bottom:12px">Here is what that does:</p>
        <ul class="impact">
          <?php foreach ($lines as $line): ?>
            <li class="impact-<?= e($line['effect']) ?>">
              <svg class="icon"><use href="#<?= $line['effect'] === 'goes' ? 'i-trash' : 'i-shield' ?>"></use></svg>
              <span><?= e($line['text']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">Nothing else points at it.</p>
      <?php endif; ?>

      <form method="post" action="<?= e(url($action)) ?>" style="margin-top:18px;display:flex;gap:9px">
        <?= csrf_field() ?>
        <?php foreach ($fields as $key => $value): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="confirmed" value="1">
        <button class="btn btn-danger" type="submit">Yes, delete it</button>
        <a class="btn btn-ghost" href="<?= e(url($cancel)) ?>">Keep it</a>
      </form>
    <?php endif; ?>
  </div>
</div>
