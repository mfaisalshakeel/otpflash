<?php
/**
 * How long a list page is, and the numbers for drawing its pager.
 *
 * One helper because every admin list wants the same three things - a limit,
 * an offset and a page count - and a screen that works them out for itself is
 * a screen that will disagree with the others about what page 2 means.
 */

/** The rows-per-page the admin chose, or a sensible default. */
function admin_per_page(): int
{
    $chosen = (int) setting('admin_per_page', '30');

    // Clamped rather than trusted: the value reaches SQL as a LIMIT, and a 0
    // or a negative one is a list that shows nothing at all.
    return max(10, min(200, $chosen ?: 30));
}

/**
 * Everything a paged query and its pager need.
 *
 * The page comes from the request and is clamped to what exists, so a stale
 * ?page=9 after a filter narrows the list lands on the last real page rather
 * than on an empty one.
 *
 * @return array{page:int, pages:int, per:int, offset:int, total:int}
 */
function paging(int $total, ?int $perPage = null): array
{
    $per   = $perPage ?: admin_per_page();
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(1, min($pages, (int) ($_GET['page'] ?? 1)));

    return [
        'page'   => $page,
        'pages'  => $pages,
        'per'    => $per,
        'offset' => ($page - 1) * $per,
        'total'  => $total,
    ];
}

/**
 * One of these, or several of them.
 *
 * Small on purpose: the panel counts services, orders, categories, currencies
 * and pages, and the only rule those need is the one about -y. A library for
 * this would be a dependency to say "categories".
 */
function plural(string $noun, int $count): string
{
    if ($count === 1) {
        return $noun;
    }
    if (preg_match('/[^aeiou]y$/i', $noun)) {
        return substr($noun, 0, -1) . 'ies';
    }
    if (preg_match('/(s|x|z|ch|sh)$/i', $noun)) {
        return $noun . 'es';
    }

    return $noun . 's';
}

/**
 * The query string to carry when moving between pages.
 *
 * Whatever the screen is filtered by stays filtered, which is the whole point
 * - a Next that forgets the search is a Next back to the top of everything.
 */
function paging_query(array $filters): array
{
    return array_filter($filters, static fn($value) => $value !== '' && $value !== null);
}
