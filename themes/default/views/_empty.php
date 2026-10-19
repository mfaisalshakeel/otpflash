<?php /** Shown when nothing is on sale yet - better than a blank shop. */ ?>
<section class="section">
  <div class="wrap">
    <div class="empty-state" style="max-width:560px;margin:8vh auto">
      <b>This shop is opening soon</b>
      Numbers will appear here as soon as the catalogue is connected.
      <?php if (is_admin()): ?>
        <p style="margin-top:18px">
          <a class="btn btn-primary" href="<?= e(url('admin/otpget')) ?>">Connect OTPGet</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</section>
