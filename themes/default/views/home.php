<?php
/**
 * The storefront: a short band, the picker straight under it, and the
 * reassurance a first-time buyer looks for before paying.
 *
 * The picker sits on the band's curve, not below a tall hero: buying is the
 * whole point of the page, so it is the first thing on a phone's screen.
 *
 * @var array $tabs  @var array $state  @var string $headline  @var string $lead
 * @var string $intro  @var bool $isHome  @var bool $empty  @var string $query
 * @var array $trail  @var array $faqs  @var array $posts  @var array $counts  @var ?array $user
 */
$tab = $state['tab'];
?>
<section class="hero hero-compact">
  <div class="wrap hero-in">
    <?php if (count($trail) > 1): ?>
      <nav class="crumbs" aria-label="Breadcrumb">
        <?php foreach ($trail as $i => [$name, $link]): ?>
          <?php if ($i < count($trail) - 1): ?>
            <a href="<?= e($link) ?>"><?= e($name) ?></a><span aria-hidden="true">/</span>
          <?php else: ?>
            <span aria-current="page"><?= e($name) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <h1><?= e($headline) ?></h1>
    <p class="hero-lead"><?= e($lead) ?></p>
    <ul class="hero-points">
      <li><svg class="icon"><use href="#i-globe"></use></svg>
        <b><?= qty_fmt(max($counts['countries'], 1)) ?>+</b> countries</li>
      <li><svg class="icon"><use href="#i-layers"></use></svg>
        <b><?= qty_fmt(max($counts['services'], 1)) ?>+</b> apps</li>
      <li><svg class="icon"><use href="#i-bolt"></use></svg> <b>Instant</b> delivery</li>
      <li><svg class="icon"><use href="#i-shield"></use></svg> <b>Auto</b> refund</li>
    </ul>
  </div>
</section>

<section class="buy" id="buy">
  <div class="wrap">
    <?php if ($empty): ?>
      <div class="alert alert-warning">Nothing is on sale for this right now - stock changes all the time,
        so try another country or app below, or check back in a few minutes.</div>
    <?php endif; ?>
    <?php partial('partials/picker', ['tabs' => $tabs, 'state' => $state, 'query' => $query, 'user' => $user]); ?>
  </div>
</section>

<?php if ($intro !== ''): ?>
<section class="section section-tight">
  <div class="wrap">
    <div class="prose prose-card"><?= $intro /* trusted: written by an admin on the Services screen */ ?></div>
  </div>
</section>
<?php endif; ?>

<section class="section">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2>Your code in under a minute</h2>
    </div>
    <ol class="steps">
      <li class="step"><span class="step-n">1</span><h3>Pick the app and country</h3>
        <p>Search any of the apps and countries above. Every server and its price is shown before you pay.</p></li>
      <li class="step"><span class="step-n">2</span><h3>Pay your way</h3>
        <p>Pay once with any method we list, or from your wallet in one tap. No account needed for a number.</p></li>
      <li class="step"><span class="step-n">3</span><h3>Copy the number</h3>
        <p>It is on screen the moment you have paid. Paste it into the app and ask it to send the code.</p></li>
      <li class="step"><span class="step-n">4</span><h3>The code appears</h3>
        <p>No refreshing - the OTP shows up by itself. No SMS in time? Change the number free.</p></li>
    </ol>
  </div>
</section>

<section class="section section-soft">
  <div class="wrap">
    <div class="features">
      <div class="feat"><span class="feat-i"><svg class="icon"><use href="#i-bolt"></use></svg></span>
        <h3>Instant and automatic</h3><p>No waiting for anyone: the number is bought the second payment lands, and the code is read for you.</p></div>
      <div class="feat"><span class="feat-i"><svg class="icon"><use href="#i-shield"></use></svg></span>
        <h3>You only pay for codes</h3><p>A number that never receives its SMS is replaced free - or refunded to your wallet.</p></div>
      <div class="feat"><span class="feat-i"><svg class="icon"><use href="#i-lock"></use></svg></span>
        <h3>Private by default</h3><p>No account needed and no personal details asked. Your order code is your key.</p></div>
      <div class="feat"><span class="feat-i"><svg class="icon"><use href="#i-calendar"></use></svg></span>
        <h3>Keep a number</h3><p>Need it for longer? Rent one for a day, a month or a year, with every SMS kept in your inbox.</p></div>
    </div>
  </div>
</section>

<?php if ($faqs): ?>
<section class="section" id="faq">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">FAQ</span>
      <h2>Questions people ask</h2>
    </div>
    <div class="faq">
      <?php foreach ($faqs as $i => $faq): ?>
        <details class="fitem"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= e($faq['question']) ?></summary>
          <p><?= e($faq['answer']) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($posts): ?>
<section class="section section-soft">
  <div class="wrap">
    <div class="section-head section-head-row">
      <div><span class="eyebrow">From the blog</span><h2>Guides and how-tos</h2></div>
      <a class="btn btn-ghost" href="<?= e(url('blog')) ?>">All posts <svg class="icon"><use href="#i-arrow"></use></svg></a>
    </div>
    <div class="posts">
      <?php foreach ($posts as $post): ?>
        <?php partial('partials/post-card', ['post' => $post]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php $chatHome = chat_channels('home'); ?>
<?php if ($chatHome): ?>
<section class="wrap">
  <div class="band">
    <h2>Not sure which number to pick?</h2>
    <p>Message us and we will tell you which country and server works best for your app right now.</p>
    <div class="bandrow">
      <?php foreach ($chatHome as $channel): ?>
        <a class="btn btn-ghost btn-lg" href="<?= e($channel['url']) ?>"
           <?= $channel['key'] === 'email' ? '' : 'target="_blank" rel="noopener"' ?>
           title="<?= e($channel['title']) ?>" aria-label="<?= e($channel['title']) ?>">
          <svg class="icon"><use href="#<?= e($channel['icon']) ?>"></use></svg>
          <?= e($channel['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
