<?php
/**
 * Footer. Its link columns are what search engines crawl the shop through:
 * the popular services and countries are real landing pages, so every one of
 * them is a page a search can arrive on.
 */
require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/storefront.php';

$siteName  = setting('site_name', 'OTP Flash');
$footPages = all('SELECT slug, title FROM pages WHERE is_active = 1 AND show_in_footer = 1
                  ORDER BY sort_order, id');
$landing   = landing_pages_on();
$topServices  = $landing ? array_slice(store_services('numbers'), 0, 8) : [];
$topCountries = $landing ? array_slice(store_countries('numbers'), 0, 8) : [];
$methods   = all('SELECT name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id LIMIT 6');
?>
<footer class="site-foot">
  <div class="wrap">
    <div class="fgrid">
      <div class="fabout">
        <a href="<?= e(url('')) ?>" class="logo">
          <span class="logo-mark"><svg class="icon"><use href="#i-bolt"></use></svg></span>
          <span><?= e($siteName) ?></span>
        </a>
        <p><?= e(setting('site_tagline', 'Virtual numbers for OTP verification, in seconds')) ?></p>
        <?php if ($methods): ?>
          <div class="pays" aria-label="Payment methods">
            <?php foreach ($methods as $method): ?>
              <span class="pay"><?= e($method['name']) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($topServices): ?>
      <div>
        <h2 class="fh">Popular numbers</h2>
        <ul>
          <?php foreach ($topServices as $service): ?>
            <li><a href="<?= e(url($service['slug'])) ?>"><?= e($service['name']) ?> number</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($topCountries): ?>
      <div>
        <h2 class="fh">Countries</h2>
        <ul>
          <?php foreach ($topCountries as $country): ?>
            <li><a href="<?= e(url('country/' . $country['slug'])) ?>"><?= e($country['name']) ?> numbers</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div>
        <h2 class="fh">Company</h2>
        <ul>
          <li><a href="<?= e(url('blog')) ?>">Blog</a></li>
          <li><a href="<?= e(url('faq')) ?>">FAQ</a></li>
          <li><a href="<?= e(url('contact')) ?>">Contact us</a></li>
          <li><a href="<?= e(url('track')) ?>" data-track-open>Track an order</a></li>
          <?php foreach ($footPages as $page): ?>
            <li><a href="<?= e(url($page['slug'])) ?>"><?= e($page['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="fbottom">
      <span>&copy; <?= date('Y') ?> <?= e($siteName) ?>. All rights reserved.</span>
      <span>Numbers are for receiving verification codes. Not affiliated with any app shown.</span>
    </div>
  </div>
</footer>
