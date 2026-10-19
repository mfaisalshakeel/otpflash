<?php
/**
 * The feed.
 *
 * Every row says what kind of thing happened before it says anything else:
 * the glyph and its colour come from notify.php's one table, the same one
 * the bell's dropdown reads, so a row cannot be red here and grey up there.
 *
 * @var array $rows  @var int $unread  @var string $filter  @var array $paging
 */
?>
<div class="filters">
  <?php /* Two links rather than a select: there are two answers, and a
           select to choose between two things is a click more than it is
           worth. */ ?>
  <a class="btn <?= $filter === 'unread' ? 'btn-ghost' : 'btn-primary' ?>"
     href="<?= e(url('admin/notifications')) ?>">All</a>
  <a class="btn <?= $filter === 'unread' ? 'btn-primary' : 'btn-ghost' ?>"
     href="<?= e(url('admin/notifications?show=unread')) ?>">
    Unread<?= $unread ? ' (' . (int) $unread . ')' : '' ?>
  </a>

  <a class="btn btn-ghost" href="<?= e(url('admin/notifications/rules')) ?>">
    <svg class="icon"><use href="#i-gear"></use></svg> What reaches me
  </a>

  <?php if ($unread): ?>
    <form method="post" action="<?= e(url('admin/notifications/read')) ?>" style="margin-left:auto">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">
        <svg class="icon"><use href="#i-check"></use></svg> Mark all read
      </button>
    </form>
  <?php endif; ?>

  <form method="post" action="<?= e(url('admin/notifications/clear')) ?>"
        <?= $unread ? '' : 'style="margin-left:auto"' ?>
        data-confirm="Clear every notification you have already read?">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit">
      <svg class="icon"><use href="#i-trash"></use></svg> Clear read
    </button>
  </form>
</div>

<div class="box">
  <?php if (!$rows): ?>
    <div class="empty-state">
      <b><?= $filter === 'unread' ? 'Nothing unread.' : 'Nothing yet.' ?></b>
      <p class="muted"><?= $filter === 'unread'
        ? 'Everything here has been seen.'
        : 'Orders, payments and provider trouble will show up here.' ?></p>
    </div>
  <?php else: ?>
    <ul class="nlist">
      <?php foreach ($rows as $row): ?>
        <?php $look = notification_look((string) $row['event']); ?>
        <li class="nitem<?= $row['is_read'] ? '' : ' is-unread' ?>">
          <span class="nicon nicon-<?= e($look['tone']) ?>" aria-hidden="true">
            <svg class="icon"><use href="#<?= e($look['icon']) ?>"></use></svg>
          </span>

          <div class="nbody">
            <b><?= e($row['title']) ?></b>
            <?php if ($row['body'] !== ''): ?>
              <small class="sub"><?= e($row['body']) ?></small>
            <?php endif; ?>
            <div class="nwhen">
              <span class="ntag"><?= e($look['label']) ?></span>
              <?= e(when($row['created_at'], 'd M, H:i')) ?>
            </div>
          </div>

          <?php /* Icons, like every other row of actions in the panel: the
                   colour says what the button does and the words are in
                   title and aria-label. */ ?>
          <div class="nacts">
            <?php if ($row['link'] !== ''): ?>
              <a class="iact" href="<?= e(url($row['link'])) ?>"
                 title="Open what this is about" aria-label="Open what this is about">
                <svg class="icon"><use href="#i-out"></use></svg>
              </a>
            <?php endif; ?>

            <?php if (!$row['is_read']): ?>
              <form method="post" action="<?= e(url('admin/notifications/read')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="show" value="<?= e($filter) ?>">
                <button class="iact iact-on" type="submit" title="Mark read" aria-label="Mark read">
                  <svg class="icon"><use href="#i-check"></use></svg>
                </button>
              </form>
            <?php endif; ?>

            <form method="post" action="<?= e(url('admin/notifications/delete')) ?>"
                  data-confirm="Delete this notification?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="show" value="<?= e($filter) ?>">
              <button class="iact iact-danger" type="submit" title="Delete" aria-label="Delete">
                <svg class="icon"><use href="#i-trash"></use></svg>
              </button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php partial('admin/_pager', [
      'paging' => $paging,
      'base'   => 'admin/notifications',
      'query'  => $filter === 'unread' ? ['show' => 'unread'] : [],
      'noun'   => 'notification',
      'shown'  => count($rows),
    ]); ?>
  <?php endif; ?>
</div>
