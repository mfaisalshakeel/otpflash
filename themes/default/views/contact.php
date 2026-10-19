<?php
/** @var array $errors  @var array $input */
$chat = chat_channels('contact');
?>
<section class="page-head">
  <div class="wrap">
    <h1>Contact us</h1>
    <p>Questions before you order, or something wrong with one you placed? Write to us here,
       or message us for the fastest reply.</p>
  </div>
</section>

<section class="section" style="padding-top:24px">
  <div class="wrap" style="max-width:760px">
    <?php if ($chat): ?>
      <?php /* The address is worth printing here - this is the page someone
               came to looking for it. Everywhere else it is a button. */ ?>
      <div class="chatrow">
        <?php foreach ($chat as $channel): ?>
          <a class="btn btn-lg <?= e($channel['class']) ?>" href="<?= e($channel['url']) ?>"
             <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>
             title="<?= e($channel['title']) ?>" aria-label="<?= e($channel['title']) ?>">
            <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg>
            <?= e($channel['key'] === 'email' ? $channel['detail'] : $channel['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('contact')) ?>" class="track-card">
      <?= csrf_field() ?>
      <div class="hp" aria-hidden="true">
        <label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="field">
        <label for="c-name">Your Name</label>
        <input id="c-name" name="name" value="<?= e($input['name']) ?>" required
               <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['name'])): ?><span class="err"><?= e($errors['name']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="c-email">Email</label>
        <input id="c-email" type="email" name="email" value="<?= e($input['email']) ?>"
               <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['email'])): ?>
          <span class="err"><?= e($errors['email']) ?></span>
        <?php else: ?><small>Or leave this and give a WhatsApp number instead.</small><?php endif; ?>
      </div>

      <div class="field">
        <label for="c-wa">WhatsApp Number</label>
        <input id="c-wa" type="tel" name="whatsapp" value="<?= e($input['whatsapp']) ?>"
               inputmode="tel" maxlength="18" placeholder="+92 300 1234567"
               <?= isset($errors['whatsapp']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['whatsapp'])): ?><span class="err"><?= e($errors['whatsapp']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="c-body">Message</label>
        <textarea id="c-body" name="body" rows="6" required
                  style="width:100%;border:1.5px solid var(--line);border-radius:13px;padding:13px 15px;
                         font:inherit;font-size:15px;background:var(--bg-soft);outline:none"
                  <?= isset($errors['body']) ? 'aria-invalid="true"' : '' ?>><?= e($input['body']) ?></textarea>
        <?php if (isset($errors['body'])): ?><span class="err"><?= e($errors['body']) ?></span><?php endif; ?>
      </div>

      <button class="btn btn-primary btn-block btn-lg" type="submit">Send message</button>
    </form>
  </div>
</section>
