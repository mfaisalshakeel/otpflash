<?php
/**
 * robots.txt, pointing at the sitemap.
 *
 * The admin, orders, the portal and the API stay out: none of them is a page
 * anybody should land on from a search, and an order page is somebody's
 * number and code. An admin can add lines of their own in SEO settings.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "User-agent: *\n";
// The picker's own state is a query on the home page, and every one of those
// is the same page: crawling them would index the home page a hundred
// thousand times over under a hundred thousand addresses.
foreach (['/admin', '/order', '/track', '/api', '/install', '/account', '/login', '/register',
          '/forgot', '/reset', '/hook', '/cron', '/*?q=', '/*?tab=', '/*?service=',
          '/*?country='] as $path) {
    echo 'Disallow: ' . $path . "\n";
}
echo "Allow: /\n";

$extra = trim((string) setting('seo_robots_extra', ''));
if ($extra !== '') {
    echo "\n" . str_replace("\r", '', $extra) . "\n";
}

echo "\nSitemap: " . url('sitemap.xml') . "\n";
