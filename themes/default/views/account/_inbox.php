<?php /** A rental's messages, newest first. @var array $messages */ ?>
<?php if (!$messages): ?>
  <div class="empty-state"><b>No messages yet</b>Use the number anywhere - every SMS it receives shows up here by itself.</div>
<?php else: foreach ($messages as $message): ?>
  <article class="msg">
    <div class="msg-top"><span><?= e($message['sender'] !== '' ? $message['sender'] : 'Unknown sender') ?></span>
      <time><?= e(when($message['received_at'], 'd M, H:i')) ?></time></div>
    <p><?= e((string) $message['body']) ?></p>
    <?php if ($message['code'] !== ''): ?>
      <span class="msg-code"><?= e($message['code']) ?>
        <button class="chip-btn" type="button" data-copy="<?= e($message['code']) ?>">Copy</button></span>
    <?php endif; ?>
  </article>
<?php endforeach; endif; ?>
