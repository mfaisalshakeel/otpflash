<?php
/**
 * SEO: the settings every public page is described with.
 *
 *   GET  /admin/seo        the screen
 *   POST /admin/seo/save   save it
 *
 * A page's own title and description are written on that page's own screen
 * (a post, a page, a service, a country). What lives here is what applies to
 * all of them: how a title is finished off, what a page with nothing of its
 * own says, the image a shared link shows, and what crawlers are told.
 */
require_once APP_PATH . '/helpers/seo.php';
require_once APP_PATH . '/helpers/upload.php';
require_once APP_PATH . '/helpers/audit.php';

$OWNED = ['seo_title_format', 'seo_description', 'seo_og_image', 'seo_twitter', 'seo_org_name', 'seo_same_as',
          'seo_google_verify', 'seo_bing_verify', 'seo_landing_pages', 'seo_robots_extra',
          'blog_title', 'blog_description', 'blog_per_page'];

if (($params[0] ?? '') === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = array_intersect_key(settings_all(), array_flip($OWNED));
    $text   = static fn (string $name, int $max): string => mb_substr(trim((string) ($_POST[$name] ?? '')), 0, $max);

    $format = $text('seo_title_format', 80);
    set_setting('seo_title_format', str_contains($format, '{title}') ? $format : '{title} | {site}');
    set_setting('seo_description', $text('seo_description', 300));
    set_setting('seo_twitter', ltrim(preg_replace('/[^\w@]/', '', $text('seo_twitter', 31)), '@'));
    set_setting('seo_org_name', $text('seo_org_name', 120));
    set_setting('seo_same_as', implode("\n", array_filter(
        preg_split('/\s+/', $text('seo_same_as', 2000)) ?: [],
        static fn ($link) => (bool) preg_match('~^https://[^\s]+$~i', $link)
    )));

    // People paste the whole tag Search Console gives them; the content is
    // the only part that is ours to print.
    foreach (['seo_google_verify', 'seo_bing_verify'] as $name) {
        $value = $text($name, 300);
        if (preg_match('/content\s*=\s*"([^"]+)"/i', $value, $found)) {
            $value = $found[1];
        }
        set_setting($name, preg_replace('/[^\w\-.=]/', '', $value));
    }

    set_setting('seo_landing_pages', isset($_POST['seo_landing_pages']) ? '1' : '0');
    // Only directives a robots.txt understands; anything else is dropped
    // rather than published as a line a crawler might misread.
    set_setting('seo_robots_extra', implode("\n", array_filter(
        array_map('trim', explode("\n", str_replace("\r", '', $text('seo_robots_extra', 2000)))),
        static fn ($line) => $line === '' || $line[0] === '#'
            || (bool) preg_match('/^(user-agent|allow|disallow|crawl-delay|sitemap)\s*:/i', $line)
    )));

    set_setting('blog_title', $text('blog_title', 120) ?: 'Blog');
    set_setting('blog_description', $text('blog_description', 300));
    set_setting('blog_per_page', (string) max(3, min(48, (int) ($_POST['blog_per_page'] ?? 9))));

    if (!empty($_FILES['seo_og_image']['name'])) {
        $stored = store_image_upload('seo_og_image', 'branding', (string) setting('seo_og_image', ''));
        if ($stored['ok']) {
            set_setting('seo_og_image', $stored['path']);
        } else {
            flash('error', 'Share image: ' . $stored['error']);
        }
    } elseif (isset($_POST['remove_og_image'])) {
        set_setting('seo_og_image', '');
    }

    audit('seo.settings', [
        'entity' => 'settings', 'summary' => 'SEO settings saved',
        'before' => $before, 'after' => array_intersect_key(settings_all(true), array_flip($OWNED)),
    ]);
    flash('success', 'Saved.');
    redirect('admin/seo');
}

$counts = [
    'pages'     => (int) col('SELECT COUNT(*) FROM pages WHERE is_active = 1 AND noindex = 0', [], 0),
    'services'  => (int) col('SELECT COUNT(DISTINCT service_id) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
    'countries' => (int) col('SELECT COUNT(DISTINCT country_id) FROM offers WHERE is_active = 1 AND missing = 0', [], 0),
    'pairs'     => (int) col('SELECT COUNT(*) FROM (SELECT DISTINCT service_id, country_id FROM offers
                                WHERE is_active = 1 AND missing = 0 AND product IN (?, ?, ?)) pairs',
                             ['sms', 'premium', 'cheap'], 0),
    'posts'     => (int) col("SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= ? AND noindex = 0",
                             [date('Y-m-d H:i:s')], 0),
];

view('admin/seo', [
    'title'    => 'SEO',
    'subtitle' => 'How every page is described to search engines and link previews',
    'settings' => settings_all(),
    'counts'   => $counts,
    'sample'   => seo_title('WhatsApp number for Pakistan'),
], 'layouts/admin');
