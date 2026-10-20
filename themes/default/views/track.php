<?php /** Find an order by its code. @var string $code  @var string $error */ ?>
<section class="section">
  <div class="wrap track-page">
    <div class="panel">
      <div class="ptitle"><svg class="icon"><use href="#i-search"></use></svg> Track an order</div>
      <p class="psub">Type the order code from your receipt. It opens the order - your number and its code.</p>
      <?php if ($error !== ''): ?>
        <div class="form-err"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="get" action="<?= e(url('track')) ?>">
        <div class="field">
          <label class="sr-only" for="trackCode">Order code</label>
          <input id="trackCode" name="code" value="<?= e($code) ?>" placeholder="e.g. OF-8F42KD3M" autocomplete="off"
                 spellcheck="false" autocapitalize="characters" required autofocus>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Find my order</button>
      </form>
      <?php if (is_logged_in()): ?>
        <p class="help">Every order you placed while signed in is in <a href="<?= e(url('account/orders')) ?>">My orders</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
