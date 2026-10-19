<?php /** SEO settings. @var array $settings @var array $counts @var string $sample */
$value = static fn (string $key, string $fallback = ''): string => (string) ($settings[$key] ?? $fallback);
$landing = $value('seo_landing_pages', '1') === '1';
$urls = 1 + $counts['pages'] + $counts['posts'] + ($landing ? $counts['services'] + $counts['countries'] + $counts['pairs'] : 0);
?>
<form method="post" action="<?= e(url('admin/seo/save')) ?>" enctype="multipart/form-data" class="split2 split-wide">
  <?= csrf_field() ?>
  <div>
    <div class="box">
      <div class="box-head"><b>Titles and descriptions</b></div>
      <div class="pad">
        <div class="field">
          <label for="s-format">Title format</label>
          <input id="s-format" name="seo_title_format" value="<?= e($value('seo_title_format', '{title} | {site}')) ?>" maxlength="80">
          <small>{title} is the page's own title, {site} the shop name. Right now a landing page reads:
            <b><?= e($sample) ?></b></small>
        </div>
        <div class="field">
          <label for="s-desc">Default description</label>
          <textarea class="text" id="s-desc" name="seo_description" rows="3" maxlength="300"><?= e($value('seo_description')) ?></textarea>
          <small>Used on any page that has none of its own - the home page first of all. Aim for 140-160 characters.</small>
        </div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Landing pages</b></div>
      <div class="pad">
        <label class="switch block">
          <input type="checkbox" name="seo_landing_pages" value="1" <?= $landing ? 'checked' : '' ?>>
          <span class="track"></span> Give every service, country and pair its own indexable page
        </label>
        <p class="muted">Pages like <b>/whatsapp</b>, <b>/country/pakistan</b> and <b>/whatsapp/pakistan</b> - each with its own
          title, description, price range in search results and a place in the sitemap. This is what people actually search
          for, so leave it on unless you have a reason. Write each service's and country's own words on
          <a class="lnk" href="<?= e(url('admin/services')) ?>">Services</a> and
          <a class="lnk" href="<?= e(url('admin/countries')) ?>">Countries</a>.</p>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Sharing and identity</b></div>
      <div class="pad">
        <?php partial('admin/_upload', ['name' => 'seo_og_image', 'label' => 'Default share image',
            'current' => $value('seo_og_image'), 'accept' => 'image/png,image/jpeg,image/webp',
            'hint' => '1200 × 630. Shown when a page without its own image is shared on WhatsApp, Facebook or X.']); ?>
        <?php if ($value('seo_og_image') !== ''): ?>
          <label class="check-row" style="margin:-6px 0 14px"><input type="checkbox" name="remove_og_image" value="1"> Remove it</label>
        <?php endif; ?>
        <div class="field2">
          <div class="field"><label for="s-org">Business name</label>
            <input id="s-org" name="seo_org_name" value="<?= e($value('seo_org_name')) ?>" maxlength="120"
                   placeholder="<?= e(setting('site_name', 'OTP Flash')) ?>">
            <small>For the knowledge panel, if it differs from the shop name.</small></div>
          <div class="field"><label for="s-tw">X (Twitter) handle</label>
            <input id="s-tw" name="seo_twitter" value="<?= e($value('seo_twitter')) ?>" maxlength="31" placeholder="otpflash"></div>
        </div>
        <div class="field">
          <label for="s-same">Your profiles elsewhere</label>
          <textarea id="s-same" name="seo_same_as" rows="3" placeholder="https://facebook.com/yourpage&#10;https://x.com/yourhandle"><?= e($value('seo_same_as')) ?></textarea>
          <small>One https:// link per line. Tells search engines these accounts are the same business as this site.</small>
        </div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Blog</b></div>
      <div class="pad">
        <div class="field2">
          <div class="field"><label for="b-title">Blog heading</label>
            <input id="b-title" name="blog_title" value="<?= e($value('blog_title', 'Blog')) ?>" maxlength="120"></div>
          <div class="field"><label for="b-per">Posts per page</label>
            <input id="b-per" name="blog_per_page" type="number" min="3" max="48" value="<?= (int) $value('blog_per_page', '9') ?>"></div>
        </div>
        <div class="field"><label for="b-desc">Blog description</label>
          <textarea class="text" id="b-desc" name="blog_description" rows="2" maxlength="300"><?= e($value('blog_description')) ?></textarea></div>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Crawlers</b></div>
      <div class="pad">
        <div class="field2">
          <div class="field"><label for="s-g">Google verification</label>
            <input id="s-g" name="seo_google_verify" value="<?= e($value('seo_google_verify')) ?>" placeholder="Paste the meta tag or its code">
            <small>Search Console &rarr; Add property &rarr; HTML tag.</small></div>
          <div class="field"><label for="s-b">Bing verification</label>
            <input id="s-b" name="seo_bing_verify" value="<?= e($value('seo_bing_verify')) ?>" placeholder="Paste the meta tag or its code"></div>
        </div>
        <div class="field">
          <label for="s-robots">Extra robots.txt lines</label>
          <textarea id="s-robots" name="seo_robots_extra" rows="4" placeholder="Disallow: /some-private-path"><?= e($value('seo_robots_extra')) ?></textarea>
          <small>Added under the built-in rules. Only User-agent, Allow, Disallow, Crawl-delay and Sitemap lines are kept.</small>
        </div>
      </div>
    </div>
  </div>

  <div>
    <div class="box">
      <div class="box-head"><b>Save</b></div>
      <div class="pad"><button class="btn btn-primary btn-block btn-lg" type="submit">Save SEO settings</button></div>
    </div>

    <div class="box">
      <div class="box-head"><b>What search engines see</b></div>
      <div class="pad">
        <div class="drow"><span>Addresses in the sitemap</span><b><?= qty_fmt($urls) ?></b></div>
        <div class="drow"><span>Pages</span><b><?= qty_fmt($counts['pages']) ?></b></div>
        <div class="drow"><span>Blog posts</span><b><?= qty_fmt($counts['posts']) ?></b></div>
        <div class="drow"><span>Service pages</span><b><?= $landing ? qty_fmt($counts['services']) : 'off' ?></b></div>
        <div class="drow"><span>Country pages</span><b><?= $landing ? qty_fmt($counts['countries']) : 'off' ?></b></div>
        <div class="drow"><span>Service × country pages</span><b><?= $landing ? qty_fmt($counts['pairs']) : 'off' ?></b></div>
        <div class="acts" style="margin-top:14px">
          <a class="btn btn-ghost btn-block" href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener">Open sitemap.xml</a>
          <a class="btn btn-ghost btn-block" href="<?= e(url('robots.txt')) ?>" target="_blank" rel="noopener">Open robots.txt</a>
          <a class="btn btn-ghost btn-block" href="<?= e(url('blog/feed')) ?>" target="_blank" rel="noopener">Open the RSS feed</a>
          <a class="btn btn-ghost btn-block" href="https://search.google.com/test/rich-results?url=<?= e(rawurlencode(url(''))) ?>"
             target="_blank" rel="noopener">Test rich results</a>
        </div>
        <p class="muted" style="margin-top:12px">Submit <b><?= e(url('sitemap.xml')) ?></b> once in Google Search Console and
          Bing Webmaster Tools. It updates itself as you add posts and sync the catalogue.</p>
      </div>
    </div>

    <div class="box">
      <div class="box-head"><b>Already done for you</b></div>
      <div class="pad">
        <ul class="seo-checks">
          <?php foreach ([
              'A canonical address on every page',
              'Open Graph and X cards for link previews',
              'Organization, WebSite and search box data',
              'Breadcrumbs on every landing page and post',
              'Product price ranges on service pages',
              'FAQ rich results from your FAQs',
              'Article data, contents list and related posts on the blog',
              'A sitemap index split by type, with images',
              'Drafts, scheduled and hidden posts kept out of everything',
          ] as $line): ?>
            <li class="seo-pass"><svg class="icon"><use href="#i-check"></use></svg> <?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</form>
