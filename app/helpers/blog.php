<?php
/**
 * The blog: what counts as published, and the pieces a post page is built from.
 *
 * "Published" is one rule written once - status published AND a publish time
 * that has arrived - because a post scheduled for Friday must stay out of the
 * list, the sitemap, the feed and the related posts alike, and four places
 * writing that test is four chances for one of them to leak it on Tuesday.
 */

/** The WHERE fragment for a post the public may see, and its binding. */
function post_live_sql(string $alias = 'p'): array
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return ["{$prefix}status = 'published' AND {$prefix}published_at IS NOT NULL AND {$prefix}published_at <= ?",
            [date('Y-m-d H:i:s')]];
}

/** What a post is doing, in one word for the admin list. */
function post_state(array $post): string
{
    if ($post['status'] !== 'published') {
        return 'draft';
    }
    return strtotime((string) $post['published_at']) > time() ? 'scheduled' : 'published';
}

/**
 * A page of live posts, newest first.
 *
 * @return array{posts: array, total: int}
 */
function blog_posts(int $page, int $perPage, ?int $categoryId = null, string $search = '', string $tag = ''): array
{
    [$live, $binds] = post_live_sql();
    $where = [$live];
    if ($categoryId !== null) {
        $where[] = 'p.category_id = ?';
        $binds[] = $categoryId;
    }
    if ($search !== '') {
        $where[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ?)';
        array_push($binds, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
    }
    if ($tag !== '') {
        // Tags are saved as a bare comma list ("otp,whatsapp"), so these four
        // shapes are every place one tag can sit - and "otp" cannot match
        // "otp-bypass". Spelled out because the two databases disagree about
        // how to join strings.
        $where[] = '(p.tags = ? OR p.tags LIKE ? OR p.tags LIKE ? OR p.tags LIKE ?)';
        array_push($binds, $tag, $tag . ',%', '%,' . $tag, '%,' . $tag . ',%');
    }
    $whereSql = implode(' AND ', $where);

    $total  = (int) col("SELECT COUNT(*) FROM posts p WHERE $whereSql", $binds, 0);
    $offset = max(0, ($page - 1) * $perPage);
    $posts  = all(
        "SELECT p.id, p.slug, p.title, p.excerpt, p.content, p.cover_path, p.published_at, p.is_featured, p.tags,
                p.author_name, c.name AS category_name, c.slug AS category_slug
           FROM posts p LEFT JOIN post_categories c ON c.id = p.category_id
          WHERE $whereSql
       ORDER BY p.published_at DESC, p.id DESC
          LIMIT $perPage OFFSET $offset",
        $binds
    );
    foreach ($posts as &$post) {
        $post['excerpt'] = post_excerpt($post);
        unset($post['content']);
    }
    return ['posts' => $posts, 'total' => $total];
}

/** The summary under a title: the one written, else the opening of the post. */
function post_excerpt(array $post, int $length = 180): string
{
    $written = trim((string) ($post['excerpt'] ?? ''));
    if ($written !== '') {
        return $written;
    }
    // Tags become spaces first: strip_tags() alone runs "works.</p><h2>Pick"
    // together into "works.Pick".
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(
        strip_tags(preg_replace('/<[^>]+>/', ' ', (string) ($post['content'] ?? ''))), ENT_QUOTES)));
    return excerpt($text, $length);
}

/** Minutes to read, at a pace most people manage on a phone. */
function reading_minutes(string $html): int
{
    $words = str_word_count(strip_tags($html));
    return max(1, (int) round($words / 220));
}

/** The tags of a post as a list. */
function post_tags(string $tags): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn ($tag) => slugify(trim($tag)), explode(',', $tags)
    ))));
}

/**
 * Give every H2/H3 an id and return the contents list built from them.
 *
 * Headings the editor wrote keep any id they already had, so a link someone
 * shared to #pricing keeps working after the post is edited.
 *
 * @return array{0: string, 1: array<int, array{id: string, text: string, level: int}>}
 */
function post_toc(string $html): array
{
    $toc  = [];
    $used = [];
    $html = preg_replace_callback('~<h([23])([^>]*)>(.*?)</h\1>~is', static function (array $m) use (&$toc, &$used) {
        $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES));
        if ($text === '') {
            return $m[0];
        }
        if (preg_match('~\bid\s*=\s*"([^"]+)"~i', $m[2], $existing)) {
            $id = $existing[1];
            $attributes = $m[2];
        } else {
            $id = slugify($text) ?: 'section';
            $base = $id;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $attributes = $m[2] . ' id="' . e($id) . '"';
        }
        $used[$id] = true;
        $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];
        return '<h' . $m[1] . $attributes . '>' . $m[3] . '</h' . $m[1] . '>';
    }, $html) ?? $html;

    return [post_lazy_images($html), $toc];
}

/**
 * Every image in a post loads when it scrolls near, not with the page.
 *
 * The cover is the only picture above the fold and it is not in here, so
 * nothing the reader sees first waits on this. An image the editor already
 * marked keeps what it was given.
 */
function post_lazy_images(string $html): string
{
    return preg_replace_callback('~<img\b([^>]*)>~i', static function (array $m): string {
        $attributes = $m[1];
        if (!preg_match('~\bloading\s*=~i', $attributes)) {
            $attributes .= ' loading="lazy"';
        }
        if (!preg_match('~\bdecoding\s*=~i', $attributes)) {
            $attributes .= ' decoding="async"';
        }
        return '<img' . $attributes . '>';
    }, $html) ?? $html;
}

/** Up to $limit live posts like this one: same category first, then the newest. */
function related_posts(array $post, int $limit = 3): array
{
    [$live, $binds] = post_live_sql();
    $rows = all(
        "SELECT p.slug, p.title, p.excerpt, p.content, p.cover_path, p.published_at,
                c.name AS category_name, c.slug AS category_slug
           FROM posts p LEFT JOIN post_categories c ON c.id = p.category_id
          WHERE $live AND p.id <> ?
       ORDER BY CASE WHEN p.category_id = ? THEN 0 ELSE 1 END, p.published_at DESC
          LIMIT " . max(1, $limit),
        array_merge($binds, [(int) $post['id'], (int) ($post['category_id'] ?? 0)])
    );
    foreach ($rows as &$row) {
        $row['excerpt'] = post_excerpt($row);
        unset($row['content']);
    }
    return $rows;
}

/** Active categories with how many live posts each holds. */
function blog_categories(): array
{
    [$live, $binds] = post_live_sql();
    return optional_all(
        "SELECT c.id, c.slug, c.name,
                (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id AND $live) AS posts
           FROM post_categories c
          WHERE c.is_active = 1
       ORDER BY c.sort_order, c.name",
        $binds
    );
}
