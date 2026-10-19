<?php
/**
 * XML sitemaps.
 *
 *   /sitemap.xml                 the index, pointing at the parts below
 *   /sitemap.xml?part=pages      home, the tabs, content pages, FAQ, contact
 *   /sitemap.xml?part=services   one landing page per service
 *   /sitemap.xml?part=countries  one per country
 *   /sitemap.xml?part=pairs&p=1  service x country pages, 20,000 to a part
 *   /sitemap.xml?part=blog       posts and categories
 *
 * Split because the pairs alone can run to tens of thousands, past what one
 * sitemap may hold, and because a part that changes daily (the catalogue)
 * should not make a crawler re-read the parts that do not (the pages).
 *
 * Only what is actually on sale is listed: a landing page for a service with
 * nothing to buy is a page we tell search engines not to index, and a
 * sitemap that lists it contradicts that.
 */
require_once APP_PATH . '/helpers/storefront.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

const SITEMAP_PAIRS_PER_PART = 20000;

$part     = (string) ($_GET['part'] ?? '');
$landing  = setting('seo_landing_pages', '1') === '1';
$now      = date('c');
[$sellable, $sellParams] = offer_sellable_sql('o');

$urlset = static function (array $urls): never {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
       . 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($urls as $entry) {
        echo '  <url><loc>' . e($entry['loc']) . '</loc>'
           . (!empty($entry['mod']) ? '<lastmod>' . e(date('c', strtotime((string) $entry['mod']))) . '</lastmod>' : '')
           . '<changefreq>' . $entry['freq'] . '</changefreq><priority>' . $entry['priority'] . '</priority>'
           . (!empty($entry['image']) ? '<image:image><image:loc>' . e($entry['image']) . '</image:loc></image:image>' : '')
           . "</url>\n";
    }
    echo "</urlset>\n";
    exit;
};

if ($part === 'pages') {
    $urls = [['loc' => url(''), 'priority' => '1.0', 'freq' => 'daily', 'mod' => setting('catalogue_synced_at', '')]];
    foreach (array_keys(store_tabs()) as $tab) {
        if ($tab !== 'numbers') {
            $urls[] = ['loc' => tab_url($tab), 'priority' => '0.9', 'freq' => 'daily'];
        }
    }
    foreach (all('SELECT slug FROM pages WHERE is_active = 1 AND noindex = 0 ORDER BY sort_order, id') as $page) {
        $urls[] = ['loc' => url($page['slug']), 'priority' => '0.4', 'freq' => 'monthly'];
    }
    $urls[] = ['loc' => url('faq'),     'priority' => '0.5', 'freq' => 'monthly'];
    $urls[] = ['loc' => url('contact'), 'priority' => '0.4', 'freq' => 'monthly'];
    $urls[] = ['loc' => url('blog'),    'priority' => '0.6', 'freq' => 'weekly'];
    $urlset($urls);
}

if ($part === 'services' && $landing) {
    $urls = [];
    foreach (store_services('numbers') as $service) {
        $urls[] = ['loc' => url($service['slug']), 'priority' => $service['is_popular'] ? '0.9' : '0.8', 'freq' => 'daily'];
    }
    if (isset(store_tabs()['email'])) {
        foreach (store_services('email') as $service) {
            $urls[] = ['loc' => url('temporary-email/' . $service['slug']), 'priority' => '0.6', 'freq' => 'weekly'];
        }
    }
    $urlset($urls);
}

if ($part === 'countries' && $landing) {
    $urls = [];
    foreach (store_countries('numbers') as $country) {
        $urls[] = ['loc' => url('country/' . $country['slug']), 'priority' => '0.7', 'freq' => 'daily'];
    }
    if (isset(store_tabs()['rental'])) {
        foreach (store_countries('rental') as $country) {
            $urls[] = ['loc' => url('rent-number/' . $country['slug']), 'priority' => '0.6', 'freq' => 'weekly'];
        }
    }
    $urlset($urls);
}

if ($part === 'pairs' && $landing) {
    [$numbers, $params] = offer_sellable_sql('o', tab_products('numbers'));
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $rows = all("SELECT DISTINCT s.slug AS service, c.slug AS country, s.is_popular AS sp, c.is_popular AS cp
                   FROM offers o
                   JOIN services s ON s.id = o.service_id AND s.is_active = 1
                   JOIN countries c ON c.id = o.country_id AND c.is_active = 1
                  WHERE {$numbers}
               ORDER BY s.slug, c.slug
                  LIMIT " . SITEMAP_PAIRS_PER_PART . ' OFFSET ' . (($page - 1) * SITEMAP_PAIRS_PER_PART), $params);
    $urls = [];
    foreach ($rows as $row) {
        $urls[] = ['loc' => url($row['service'] . '/' . $row['country']),
                   'priority' => $row['sp'] && $row['cp'] ? '0.8' : '0.6', 'freq' => 'daily'];
    }
    $urlset($urls);
}

if ($part === 'blog') {
    $urls = [];
    foreach (optional_all("SELECT slug, cover_path, updated_at, published_at FROM posts
                            WHERE status = 'published' AND published_at <= ? AND noindex = 0
                         ORDER BY published_at DESC", [date('Y-m-d H:i:s')]) as $post) {
        $urls[] = ['loc' => url('blog/' . $post['slug']), 'priority' => '0.7', 'freq' => 'monthly',
                   'mod' => $post['updated_at'] ?: $post['published_at'],
                   'image' => $post['cover_path'] !== '' ? url($post['cover_path']) : ''];
    }
    // An empty category is a thin page: it waits for its first post.
    foreach (optional_all("SELECT c.slug FROM post_categories c
                            WHERE c.is_active = 1 AND EXISTS (SELECT 1 FROM posts p WHERE p.category_id = c.id
                                  AND p.status = 'published' AND p.published_at <= ? AND p.noindex = 0)
                         ORDER BY c.sort_order", [date('Y-m-d H:i:s')]) as $category) {
        $urls[] = ['loc' => url('blog/category/' . $category['slug']), 'priority' => '0.5', 'freq' => 'weekly'];
    }
    $urlset($urls);
}

// ------------------------------------------------------------- the index --
$parts = ['pages', 'blog'];
if ($landing) {
    $parts[] = 'services';
    $parts[] = 'countries';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($parts as $name) {
    echo '  <sitemap><loc>' . e(url('sitemap.xml') . '?part=' . $name) . '</loc><lastmod>' . e($now) . "</lastmod></sitemap>\n";
}
if ($landing) {
    [$numbers, $params] = offer_sellable_sql('o', tab_products('numbers'));
    $pairs = (int) col("SELECT COUNT(*) FROM (SELECT DISTINCT o.service_id, o.country_id FROM offers o
                          WHERE {$numbers} AND o.service_id IS NOT NULL AND o.country_id IS NOT NULL) x", $params, 0);
    for ($page = 1; $page <= max(1, (int) ceil($pairs / SITEMAP_PAIRS_PER_PART)); $page++) {
        if ($pairs === 0) {
            break;
        }
        echo '  <sitemap><loc>' . e(url('sitemap.xml') . '?part=pairs&p=' . $page) . '</loc><lastmod>' . e($now) . "</lastmod></sitemap>\n";
    }
}
echo "</sitemapindex>\n";
