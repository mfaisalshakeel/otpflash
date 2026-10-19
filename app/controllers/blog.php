<?php
/**
 * The blog.
 *
 *   /blog                      newest posts, ?page=2, ?q=search
 *   /blog/category/{slug}      one category
 *   /blog/tag/{slug}           one tag
 *   /blog/feed                 RSS 2.0
 *   /blog/{slug}               one post (?preview=1 shows a draft to an admin)
 *
 * Every list is the same view with a different heading and canonical, so a
 * category page is as complete a page as the blog itself - which is what
 * lets it rank for its own words.
 */
require_once APP_PATH . '/helpers/blog.php';
require_once APP_PATH . '/helpers/seo.php';

$first  = $params[0] ?? '';
$second = $params[1] ?? '';
$blogTitle = (string) setting('blog_title', 'Blog');
$trail = [['Home', url('')], [$blogTitle, url('blog')]];

// ------------------------------------------------------------------- RSS --
if ($first === 'feed') {
    $site = (string) setting('site_name', 'OTP Flash');
    $list = blog_posts(1, 20)['posts'];
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=900');
    $x = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    echo '<title>' . $x($site . ' - ' . $blogTitle) . '</title>';
    echo '<link>' . $x(url('blog')) . '</link>';
    echo '<description>' . $x((string) setting('blog_description', '')) . '</description>';
    echo '<language>en</language>';
    echo '<atom:link href="' . $x(url('blog/feed')) . '" rel="self" type="application/rss+xml"/>';
    if ($list) {
        echo '<lastBuildDate>' . date(DATE_RSS, strtotime((string) $list[0]['published_at'])) . '</lastBuildDate>';
    }
    foreach ($list as $post) {
        echo '<item><title>' . $x($post['title']) . '</title>';
        echo '<link>' . $x(url('blog/' . $post['slug'])) . '</link>';
        echo '<guid isPermaLink="true">' . $x(url('blog/' . $post['slug'])) . '</guid>';
        echo '<pubDate>' . date(DATE_RSS, strtotime((string) $post['published_at'])) . '</pubDate>';
        if (!empty($post['category_name'])) {
            echo '<category>' . $x($post['category_name']) . '</category>';
        }
        echo '<description>' . $x($post['excerpt']) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

// ------------------------------------------------------------- one post --
if ($first !== '' && $first !== 'category' && $first !== 'tag' && $first !== 'page') {
    if ($second !== '') {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
    $post = one('SELECT p.*, c.name AS category_name, c.slug AS category_slug
                   FROM posts p LEFT JOIN post_categories c ON c.id = p.category_id
                  WHERE p.slug = ?', [$first]);
    $preview = $post && isset($_GET['preview']) && is_admin();
    if (!$post || (post_state($post) !== 'published' && !$preview)) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }

    if (!$preview) {
        // One count per visitor per post per session - a refresh is not a reader.
        $seen = $_SESSION['_read'] ?? [];
        if (!in_array((int) $post['id'], $seen, true)) {
            q('UPDATE posts SET views = views + 1 WHERE id = ?', [(int) $post['id']]);
            $seen[] = (int) $post['id'];
            $_SESSION['_read'] = array_slice($seen, -50);
        }
    }

    [$content, $toc] = post_toc((string) $post['content']);
    $link = url('blog/' . $post['slug']);
    $crumbs = $trail;
    if (!empty($post['category_slug'])) {
        $crumbs[] = [$post['category_name'], url('blog/category/' . $post['category_slug'])];
    }
    $crumbs[] = [$post['title'], $link];

    view('blog/post', [
        'seo' => [
            'title'       => $post['meta_title'] !== '' ? $post['meta_title'] : $post['title'],
            'description' => $post['meta_description'] !== '' ? $post['meta_description'] : post_excerpt($post, 160),
            'canonical'   => $link,
            'image'       => $post['cover_path'] ?: null,
            'type'        => 'article',
            'published'   => $post['published_at'],
            'modified'    => $post['updated_at'],
            'robots'      => $post['noindex'] || $preview ? 'noindex, follow' : null,
            'schema'      => [schema_article($post, $link), schema_breadcrumbs($crumbs)],
        ],
        'post'     => $post,
        'body'     => $content,
        'toc'      => count($toc) >= 3 ? $toc : [],
        'minutes'  => reading_minutes((string) $post['content']),
        'related'  => related_posts($post),
        'crumbs'   => $crumbs,
        'preview'  => $preview,
        'tags'     => post_tags((string) $post['tags']),
    ]);
}

// ----------------------------------------------------------------- lists --
$perPage    = max(3, min(48, (int) setting('blog_per_page', '9')));
$page       = max(1, (int) ($_GET['page'] ?? 1));
$search     = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$category   = null;
$tag        = '';
$heading    = $blogTitle;
$lead       = (string) setting('blog_description', '');
$base       = 'blog';
$meta       = ['title' => $blogTitle, 'description' => $lead];

if ($first === 'category') {
    $category = one('SELECT * FROM post_categories WHERE slug = ? AND is_active = 1', [$second]);
    if (!$category || isset($params[2])) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
    $heading = $category['name'];
    $lead    = (string) ($category['description'] ?? '');
    $base    = 'blog/category/' . $category['slug'];
    $meta    = ['title' => $category['meta_title'] !== '' ? $category['meta_title'] : $category['name'] . ' - ' . $blogTitle,
                'description' => $category['meta_description'] !== '' ? $category['meta_description'] : $lead];
    $trail[] = [$category['name'], url($base)];
} elseif ($first === 'tag') {
    $tag = slugify($second);
    if ($tag === '' || isset($params[2])) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
    $heading = '#' . $tag;
    $lead    = 'Every post tagged ' . $tag . '.';
    $base    = 'blog/tag/' . $tag;
    $meta    = ['title' => 'Posts tagged ' . $tag, 'description' => $lead];
    $trail[] = ['#' . $tag, url($base)];
} elseif ($first === 'page' || $second !== '') {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

$found = blog_posts($page, $perPage, $category ? (int) $category['id'] : null, $search, $tag);
$pages = max(1, (int) ceil($found['total'] / $perPage));
if ($page > $pages && $found['total'] > 0) {
    redirect($base . '?page=' . $pages);
}
if ($tag !== '' && !$found['total']) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

// Page 2 of a list is its own address and says so.
$canonical = url($base) . ($page > 1 ? '?page=' . $page : '');
$featured  = $page === 1 && $search === '' && $category === null && $tag === ''
    ? array_values(array_filter($found['posts'], static fn ($post) => (int) $post['is_featured'] === 1))[0] ?? null
    : null;

view('blog/index', [
    'seo' => [
        'title'       => $meta['title'] . ($page > 1 ? ' - page ' . $page : ''),
        'description' => $meta['description'],
        'canonical'   => $canonical,
        // A search result is for the person who typed it; an empty category is
        // a thin page until its first post arrives.
        'robots'      => $search !== '' || !$found['total'] ? 'noindex, follow' : null,
        'schema'      => [schema_breadcrumbs($trail)],
        'prev'        => $page > 1 ? url($base) . ($page > 2 ? '?page=' . ($page - 1) : '') : null,
        'next'        => $page < $pages ? url($base) . '?page=' . ($page + 1) : null,
    ],
    'heading'    => $heading,
    'lead'       => $lead,
    'posts'      => $featured ? array_values(array_filter($found['posts'], static fn ($post) => $post !== $featured)) : $found['posts'],
    'featured'   => $featured,
    'total'      => $found['total'],
    'page'       => $page,
    'pages'      => $pages,
    'base'       => $base,
    'search'     => $search,
    'categories' => blog_categories(),
    'current'    => $category ? $category['slug'] : '',
    'trail'      => $trail,
]);
