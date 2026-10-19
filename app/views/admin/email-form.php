<?php
/**
 * One template, with its tokens and a preview.
 * @var array $template  @var array $event  @var string $preview
 */
?>
<form method="post" action="<?= e(url('admin/emails/edit/' . $template['id'])) ?>">
  <?= csrf_field() ?>
  <div class="split2">
    <div>
      <div class="box">
        <div class="box-head"><b>The message</b></div>
        <div class="pad">
          <div class="field">
            <label for="subject">Subject</label>
            <input id="subject" name="subject" required
                   value="<?= e(old('subject', $template['subject'])) ?>">
          </div>
          <div class="field">
            <label for="body">Body</label>
            <textarea id="body" name="body" rows="14" required
                      class="mono"><?= e(old('body', $template['body'])) ?></textarea>
            <small>HTML. The header, footer and colours are added around it, so
              write only the words.</small>
          </div>
          <label class="check">
            <input type="checkbox" name="is_active" <?= $template['is_active'] ? 'checked' : '' ?>>
            <span>Send this message</span>
          </label>
        </div>
      </div>

      <div class="formfoot">
        <button class="btn btn-primary" type="submit">Save</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/emails')) ?>">Cancel</a>
      </div>
    </div>

    <div>
      <div class="box">
        <div class="box-head"><b>Words you can drop in</b></div>
        <div class="pad">
          <p class="muted small">Click one to copy it.</p>
          <div class="tokens">
            <?php foreach ($event['tokens'] as $token): ?>
              <button class="token" type="button" data-copy="{<?= e($token) ?>}">
                {<?= e($token) ?>}
              </button>
            <?php endforeach; ?>
          </div>
          <p class="muted small" style="margin-top:12px">
            A token with nothing behind it is removed rather than left showing
            its braces.
          </p>
        </div>
      </div>

      <div class="box" style="margin-top:16px">
        <div class="box-head"><b>How it looks</b><span class="chipx">saved version</span></div>
        <div class="pad">
          <?php /* sandbox with nothing granted: the body is admin-written HTML,
         but a preview has no business running scripts against the panel
         it is drawn inside - and a mail client would not run them. */ ?>
          <iframe class="mail-preview" title="Preview" sandbox
                  srcdoc="<?= e($preview) ?>"></iframe>
        </div>
      </div>
    </div>
  </div>
</form>
