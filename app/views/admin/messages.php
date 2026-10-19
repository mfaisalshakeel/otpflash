<?php /** Contact messages. */ ?>
<div class="filters">
  <form method="get" action="<?= e(url('admin/messages')) ?>" style="display:contents">
    <select name="state" onchange="this.form.submit()">
      <option value="">All messages</option>
      <option value="unread"<?= $filter === 'unread' ? ' selected' : '' ?>>Unread only</option>
      <option value="read"<?= $filter === 'read' ? ' selected' : '' ?>>Read only</option>
    </select>
  </form>
  <?php if ($unread > 0): ?>
    <form method="post" action="<?= e(url('admin/messages/read-all')) ?>" style="margin-left:auto">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">Mark all read (<?= qty_fmt($unread) ?>)</button>
    </form>
  <?php endif; ?>
</div>

<div class="box">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr><th>From</th><th>Message</th><th>Received</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$messages): ?>
        <tr><td colspan="4" class="empty">No messages yet.</td></tr>
      <?php else: foreach ($messages as $message): ?>
        <tr<?= $message['is_read'] ? ' class="dim"' : '' ?>>
          <td data-label="From">
            <b><?= e($message['name']) ?></b>
            <small class="sub">
              <?= e($message['email'] ?: $message['whatsapp'] ?: 'no contact given') ?>
            </small>
          </td>
          <td style="white-space:pre-wrap;max-width:460px" data-label="Message"><?= e($message['body']) ?></td>
          <td data-label="Received"><?= e(when($message['created_at'])) ?></td>
          <?php /* Icons, like every other row in the panel: the colour says
                   what the button does and the words are in title and
                   aria-label. A row of identical grey text buttons gave no
                   way to find the dangerous one. */ ?>
          <td class="ta-r">
            <div class="rowacts">
              <?php /* Answering is the thing an admin is here to do, so it is
                       a button rather than an address to copy out by hand. */ ?>
              <?php if (trim((string) $message['email']) !== ''): ?>
                <a class="iact iact-edit" href="mailto:<?= e($message['email']) ?>"
                   title="Reply by email" aria-label="Reply by email">
                  <svg class="icon"><use href="#i-mail"></use></svg>
                </a>
              <?php elseif (trim((string) $message['whatsapp']) !== ''): ?>
                <a class="iact iact-edit" target="_blank" rel="noopener"
                   href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $message['whatsapp'])) ?>"
                   title="Reply on WhatsApp" aria-label="Reply on WhatsApp">
                  <svg class="icon"><use href="#i-whatsapp"></use></svg>
                </a>
              <?php endif; ?>

              <form method="post" action="<?= e(url('admin/messages/read')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $message['id'] ?>">
                <button class="iact <?= $message['is_read'] ? 'iact-off' : 'iact-on' ?>" type="submit"
                        title="<?= $message['is_read'] ? 'Mark unread' : 'Mark read' ?>"
                        aria-label="<?= $message['is_read'] ? 'Mark unread' : 'Mark read' ?>">
                  <svg class="icon"><use href="#<?= $message['is_read'] ? 'i-eye' : 'i-check' ?>"></use></svg>
                </button>
              </form>

              <form method="post" action="<?= e(url('admin/messages/delete')) ?>"
                    data-confirm="Delete this message?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $message['id'] ?>">
                <button class="iact iact-danger" type="submit" title="Delete" aria-label="Delete">
                  <svg class="icon"><use href="#i-trash"></use></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($messages): ?>
    <?php partial('admin/_pager', [
      'paging' => $paging,
      'base'   => 'admin/messages',
      'query'  => ['state' => $filter],
      'noun'   => 'message',
      'shown'  => count($messages),
    ]); ?>
  <?php endif; ?>
</div>
