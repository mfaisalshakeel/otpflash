<?php
/**
 * Blog posts by the spreadsheet: a CSV in, checked, then saved - live,
 * scheduled or as drafts - and the prompt that gets an AI to write one.
 *
 * The prompt and the importer are in one file because they are two halves of
 * one contract: the columns the prompt asks for are BLOG_CSV_COLUMNS, and the
 * checks the importer makes (duplicates, links, lengths) are the rules the
 * prompt tells the writer to follow. Written twice, they would drift apart
 * and every import would come back with the same complaints.
 */
require_once APP_PATH . '/helpers/blog.php';
require_once APP_PATH . '/helpers/catalog.php';
require_once APP_PATH . '/helpers/storefront.php';

/** The columns, in the order the prompt asks for them, and what goes in each. */
const BLOG_CSV_COLUMNS = [
    'title'            => 'The headline, 50-65 characters, with the focus keyword near the front.',
    'slug'             => 'Lower-case words joined by hyphens, 3-6 words, no dates, no stop words.',
    'focus_keyword'    => 'The one search phrase this post should rank for. Different for every post.',
    'meta_title'       => 'Up to 60 characters. May differ from the title; ends with " | {site}" only if it fits.',
    'meta_description' => '140-160 characters, the keyword once, a reason to click. No quotes.',
    'excerpt'          => 'One or two plain sentences for the blog list, under 300 characters.',
    'content'          => 'The article as HTML on one line: <h2>, <h3>, <p>, <ul>/<ol>/<li>, <strong>, <a>, <table>. No <h1>.',
    'category'         => 'One of the categories listed, or a new short name if none fits.',
    'tags'             => '3-6 tags, comma separated, lower case.',
    'status'           => 'published, scheduled or draft.',
    'publish_at'       => 'YYYY-MM-DD HH:MM in the shop\'s time, for scheduled posts (and optional for published).',
    'featured'         => 'yes for at most one post in the batch, otherwise no.',
    'author'           => 'The byline. Leave empty to use the shop name.',
    'image_prompt'     => 'A prompt for an image generator: the cover, 16:9, photographic or clean illustration, no text, no logos.',
    'image_alt'        => 'Alt text for the cover: what is in the picture, under 120 characters.',
];

/** Other spellings of a column a spreadsheet or an AI may write. */
const BLOG_CSV_ALIASES = [
    'headline' => 'title', 'post_title' => 'title', 'url' => 'slug', 'permalink' => 'slug',
    'keyword' => 'focus_keyword', 'focus_key' => 'focus_keyword', 'seo_title' => 'meta_title',
    'description' => 'meta_description', 'seo_description' => 'meta_description',
    'summary' => 'excerpt', 'body' => 'content', 'html' => 'content', 'article' => 'content',
    'date' => 'publish_at', 'publish_date' => 'publish_at', 'published_at' => 'publish_at',
    'schedule' => 'publish_at', 'scheduled_at' => 'publish_at', 'is_featured' => 'featured',
    'author_name' => 'author', 'image' => 'image_prompt', 'cover_prompt' => 'image_prompt',
    'alt' => 'image_alt', 'cover_alt' => 'image_alt',
];

/** Words left out when two titles are compared, so "How to" does not hide a twin. */
const BLOG_TITLE_FILLER = ['a', 'an', 'the', 'to', 'for', 'of', 'in', 'on', 'and', 'or', 'with', 'how',
    'what', 'why', 'is', 'are', 'your', 'you', 'best', 'guide', 'complete', 'ultimate', 'step', 'by',
    '2024', '2025', '2026', '2027'];

// ===========================================================================
// Reading the file
// ===========================================================================

/**
 * Rows of a CSV, keyed by our column names.
 *
 * Reads what spreadsheets and chat windows actually produce: a byte-order
 * mark, a semicolon or tab instead of a comma (Excel in half the world),
 * quoted HTML across several lines, a ```csv fence pasted along with it.
 *
 * @return array{rows: array<int, array<string, string>>, columns: string[], unknown: string[], error: string}
 */
function blog_csv_read(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $text = trim(preg_replace('/^\s*```[a-z]*\s*$/mi', '', $text) ?? $text);
    if ($text === '') {
        return ['rows' => [], 'columns' => [], 'unknown' => [], 'error' => 'The file is empty.'];
    }

    $firstLine = strtok($text, "\n");
    $delimiter = ',';
    foreach ([';', "\t", '|'] as $candidate) {
        if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
            $delimiter = $candidate;
        }
    }

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $text);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter, '"', '');
    if (!$header) {
        return ['rows' => [], 'columns' => [], 'unknown' => [], 'error' => 'The first line should name the columns.'];
    }
    $columns = [];
    $unknown = [];
    foreach ($header as $index => $name) {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $name))), '_');
        $key = BLOG_CSV_ALIASES[$key] ?? $key;
        if (isset(BLOG_CSV_COLUMNS[$key])) {
            $columns[$index] = $key;
        } elseif ($key !== '') {
            $unknown[] = (string) $name;
        }
    }
    if (!in_array('title', $columns, true) || !in_array('content', $columns, true)) {
        fclose($stream);
        return ['rows' => [], 'columns' => array_values($columns), 'unknown' => $unknown,
                'error' => 'The file needs a "title" and a "content" column. The first line it has is: '
                         . mb_substr($firstLine, 0, 160)];
    }

    $rows = [];
    while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
        if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
            continue;
        }
        $row = array_fill_keys(array_keys(BLOG_CSV_COLUMNS), '');
        foreach ($columns as $index => $key) {
            $row[$key] = trim((string) ($cells[$index] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($stream);

    return ['rows' => $rows, 'columns' => array_values($columns), 'unknown' => $unknown,
            'error' => $rows ? '' : 'There are column names but no posts under them.'];
}

// ===========================================================================
// Cleaning what came in
// ===========================================================================

/**
 * The article body, made safe to print and shaped like ours.
 *
 * Post content is printed as written, which is fine for what an admin types
 * in the editor and not for a file that came out of a chat window: scripts,
 * frames, forms and event handlers go. An <h1> becomes an <h2> - the title is
 * the page's one <h1>. Text with no tags at all is taken for Markdown, which
 * is what an AI writes when it forgets it was asked for HTML.
 */
function blog_csv_clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (!preg_match('~<(p|h[1-6]|ul|ol|div|table|br)\b~i', $html)) {
        $html = blog_markdown_html($html);
    }
    $html = preg_replace('~<(script|style|iframe|object|embed|form|noscript|svg|math)\b.*?</\1\s*>~is', '', $html) ?? $html;
    $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta|base)\b[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html) ?? $html;
    $html = preg_replace('~(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'>\s]*\2~i', '$1="#"', $html) ?? $html;
    $html = preg_replace('~</?(html|body|head|article|main)\b[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<h1\b([^>]*)>(.*?)</h1>~is', '<h2$1>$2</h2>', $html) ?? $html;
    // A link out of the shop does not pass on our ranking or our reader.
    $html = preg_replace_callback('~<a\b([^>]*\bhref\s*=\s*["\']https?://[^"\']+["\'][^>]*)>~i', static function (array $m): string {
        if (blog_link_is_ours(blog_attribute($m[1], 'href'))) {
            return $m[0];
        }
        $attributes = preg_replace('~\s+rel\s*=\s*("[^"]*"|\'[^\']*\')~i', '', $m[1]) ?? $m[1];
        return '<a' . $attributes . ' rel="noopener" target="_blank">';
    }, $html) ?? $html;
    return trim($html);
}

/** Just enough Markdown for an article: headings, lists, bold, links, paragraphs. */
function blog_markdown_html(string $text): string
{
    $inline = static function (string $line): string {
        $line = e($line);
        $line = preg_replace('~\*\*(.+?)\*\*~', '<strong>$1</strong>', $line) ?? $line;
        $line = preg_replace('~(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])~', '<em>$1</em>', $line) ?? $line;
        return preg_replace('~\[([^\]]+)\]\(([^)\s]+)\)~', '<a href="$2">$1</a>', $line) ?? $line;
    };
    $out  = [];
    $list = null;
    $para = [];
    $flush = static function () use (&$out, &$para, &$list, $inline): void {
        if ($para) {
            $out[] = '<p>' . $inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
        if ($list) {
            $out[] = '</' . $list . '>';
            $list = null;
        }
    };
    foreach (preg_split('/\R/', $text) ?: [] as $raw) {
        $line = trim($raw);
        if ($line === '') {
            $flush();
            continue;
        }
        if (preg_match('/^(#{2,4})\s+(.+)$/', $line, $m) || preg_match('/^#\s+(.+)$/', $line, $one)) {
            $flush();
            $level = isset($m[1]) ? min(3, strlen($m[1])) : 2;
            $out[] = '<h' . $level . '>' . $inline($m[2] ?? $one[1]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^([-*]|\d+[.)])\s+(.+)$/', $line, $m)) {
            if ($para) {
                $out[] = '<p>' . $inline(implode(' ', $para)) . '</p>';
                $para = [];
            }
            $kind = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $kind) {
                if ($list) {
                    $out[] = '</' . $list . '>';
                }
                $out[] = '<' . $kind . '>';
                $list = $kind;
            }
            $out[] = '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        if ($list) {
            $out[] = '</' . $list . '>';
            $list = null;
        }
        $para[] = $line;
    }
    $flush();
    return implode("\n", $out);
}

/** One attribute's value out of a tag's attribute string. */
function blog_attribute(string $attributes, string $name): string
{
    return preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $m)
        ? html_entity_decode($m[2], ENT_QUOTES) : '';
}

/** Is this link to a page on this shop - relative, or on our own host? */
function blog_link_is_ours(string $href): bool
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#' || preg_match('~^(mailto|tel):~i', $href)) {
        return false;
    }
    if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) && !str_starts_with($href, '//')) {
        return true;
    }
    $host = strtolower((string) parse_url(str_starts_with($href, '//') ? 'https:' . $href : $href, PHP_URL_HOST));
    $ours = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
    return $host !== '' && ($host === $ours || $host === 'www.' . $ours || 'www.' . $host === $ours);
}

/** A link to this shop, as the path after the base: "blog/x", "whatsapp/pakistan", "". */
function blog_link_path(string $href): string
{
    $path = (string) parse_url(str_starts_with(trim($href), '//') ? 'https:' . trim($href) : trim($href), PHP_URL_PATH);
    $base = rtrim((string) parse_url(base_url(), PHP_URL_PATH), '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return trim($path, '/');
}

/** Lower-case words of a title with the filler out, for spotting the same post twice. */
function blog_title_words(string $title): array
{
    $words = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_values(array_unique(array_diff($words, BLOG_TITLE_FILLER)));
    sort($words);
    return $words;
}

/**
 * How much two titles say the same thing: shared words over all words.
 *
 * Word overlap rather than spelling, because a rewritten title keeps its
 * nouns - "WhatsApp without a SIM card" and "Get a WhatsApp code without a
 * SIM card" are one article twice, and a ranking split between them.
 */
function blog_title_overlap(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
}

/** Overlap from which two titles are one article twice, and from which they are worth a look. */
const BLOG_TITLE_SAME = 0.75;
const BLOG_TITLE_CLOSE = 0.5;

/** yes / true / 1 / y, as a spreadsheet writes it. */
function blog_csv_yes(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 'on', 'featured'], true);
}

// ===========================================================================
// What the shop already has
// ===========================================================================

/**
 * Every path a post may link to, so a link to a page that does not exist is
 * caught before the post goes live rather than by a crawler afterwards.
 *
 * @return array<string, true>
 */
function blog_known_paths(): array
{
    $paths = ['' => true, 'blog' => true, 'faq' => true, 'contact' => true, 'track' => true,
              'login' => true, 'register' => true, 'temporary-email' => true, 'rent-number' => true];
    foreach (all('SELECT slug FROM posts') as $row) {
        $paths['blog/' . $row['slug']] = true;
    }
    foreach (all('SELECT slug FROM post_categories WHERE is_active = 1') as $row) {
        $paths['blog/category/' . $row['slug']] = true;
    }
    foreach (all('SELECT slug FROM pages WHERE is_active = 1') as $row) {
        $paths[$row['slug']] = true;
    }
    $services = all('SELECT slug FROM services WHERE is_active = 1');
    $countries = all('SELECT slug FROM countries WHERE is_active = 1');
    foreach ($services as $row) {
        $paths[$row['slug']] = true;
        $paths['temporary-email/' . $row['slug']] = true;
    }
    foreach ($countries as $row) {
        $paths['country/' . $row['slug']] = true;
        $paths['rent-number/' . $row['slug']] = true;
    }
    return $paths;
}

/** Is this one of ours: a known page, or an app-and-country landing page? */
function blog_path_exists(string $path, array $known, array $serviceSlugs, array $countrySlugs): bool
{
    $path = strtolower($path);
    if (isset($known[$path])) {
        return true;
    }
    $parts = explode('/', $path);
    return count($parts) === 2 && isset($serviceSlugs[$parts[0]], $countrySlugs[$parts[1]]);
}

// ===========================================================================
// Checking every row
// ===========================================================================

/**
 * What would happen to each row, and why - nothing written yet.
 *
 * A problem keeps a row out; a warning lets it in and says what to fix. The
 * line between them: a duplicate, a missing body, a date nobody can read, or
 * a slug that is taken would each put something wrong on the site, so they
 * stop; a short meta description or a link to nowhere is worth fixing, and
 * not worth refusing a good article over.
 *
 * @param array{mode?: string, start?: string, every_hours?: int, update?: bool} $options
 *        mode: as_file (each row's own status and date), live (all now),
 *              schedule (one every `every_hours` from `start`), draft
 * @return array<int, array{line: int, data: array, problems: string[], warnings: string[], action: string, existing_id: ?int}>
 */
function blog_csv_check(array $rows, array $options = []): array
{
    $mode   = in_array($options['mode'] ?? '', ['as_file', 'live', 'schedule', 'draft'], true) ? $options['mode'] : 'as_file';
    $every  = max(1, (int) ($options['every_hours'] ?? 24));
    $start  = strtotime((string) ($options['start'] ?? '')) ?: strtotime('tomorrow 10:00');
    $update = !empty($options['update']);
    $now    = time();

    $existing = [];
    $titleWords = [];
    $keywords = [];
    foreach (all('SELECT id, slug, title, focus_keyword, status, published_at FROM posts') as $post) {
        $existing[$post['slug']] = $post;
        $titleWords[$post['slug']] = blog_title_words((string) $post['title']);
        if (trim((string) $post['focus_keyword']) !== '') {
            $keywords[mb_strtolower(trim((string) $post['focus_keyword']))] = $post['slug'];
        }
    }
    $known        = blog_known_paths();
    $serviceSlugs = array_fill_keys(array_column(all('SELECT slug FROM services WHERE is_active = 1'), 'slug'), true);
    $countrySlugs = array_fill_keys(array_column(all('SELECT slug FROM countries WHERE is_active = 1'), 'slug'), true);
    $categories   = [];
    foreach (all('SELECT id, name, slug FROM post_categories') as $category) {
        $categories[mb_strtolower($category['name'])] = (int) $category['id'];
        $categories[$category['slug']] = (int) $category['id'];
    }

    // The batch's own slugs count as pages a post may link to: a set written
    // together is meant to point at each other.
    $batchSlugs = [];
    foreach ($rows as $row) {
        $slug = mb_substr(slugify($row['slug'] !== '' ? $row['slug'] : $row['title']), 0, 120);
        if ($slug !== '') {
            $known['blog/' . $slug] = true;
            $batchSlugs[] = $slug;
        }
    }

    $seenSlugs = [];
    $batchTitles = [];
    $seenKeywords = [];
    $scheduled = 0;
    $out = [];

    foreach ($rows as $index => $row) {
        $problems = [];
        $warnings = [];
        $title = mb_substr(trim(strip_tags($row['title'])), 0, 190);
        $slug  = mb_substr(slugify($row['slug'] !== '' ? $row['slug'] : $title), 0, 120);
        $html  = blog_csv_clean_html($row['content']);
        $plain = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES)));
        $words = str_word_count($plain);
        $existingId = null;

        if ($title === '') {
            $problems[] = 'No title.';
        }
        if ($plain === '') {
            $problems[] = 'No article in "content".';
        } elseif ($words < 300) {
            $warnings[] = 'Only ' . $words . ' words - thin for a page meant to rank. 900+ is the usual floor.';
        }
        if ($slug === '') {
            $problems[] = 'The slug has no letters or numbers.';
        } elseif (isset($seenSlugs[$slug])) {
            $problems[] = 'Same slug as line ' . $seenSlugs[$slug] . ' in this file.';
        } elseif (isset($existing[$slug])) {
            if ($update) {
                $existingId = (int) $existing[$slug]['id'];
                $warnings[] = 'Replaces the post already at /blog/' . $slug . '.';
            } else {
                $problems[] = 'A post already lives at /blog/' . $slug . '. Tick "Replace posts with the same slug" to overwrite it.';
            }
        }
        $titleTerms = blog_title_words($title);
        if ($titleTerms && $existingId === null) {
            [$closest, $score] = ['', 0.0];
            foreach ($titleWords as $otherSlug => $other) {
                $overlap = blog_title_overlap($titleTerms, $other);
                if ($otherSlug !== $slug && $overlap > $score) {
                    [$closest, $score] = ['/blog/' . $otherSlug, $overlap];
                }
            }
            foreach ($batchTitles as $otherLine => $other) {
                $overlap = blog_title_overlap($titleTerms, $other);
                if ($overlap > $score) {
                    [$closest, $score] = ['line ' . $otherLine . ' in this file', $overlap];
                }
            }
            if ($score >= BLOG_TITLE_SAME) {
                $problems[] = 'Looks like a duplicate of ' . $closest . ' - the titles say the same thing.';
            } elseif ($score >= BLOG_TITLE_CLOSE) {
                $warnings[] = 'Close to ' . $closest . ' - make sure it answers a different question.';
            }
        }

        $keyword = mb_substr(trim($row['focus_keyword']), 0, 120);
        $keywordKey = mb_strtolower($keyword);
        if ($keyword === '') {
            $warnings[] = 'No focus keyword.';
        } else {
            if (isset($keywords[$keywordKey]) && $keywords[$keywordKey] !== $slug) {
                $warnings[] = 'Focus keyword "' . $keyword . '" is already the target of /blog/' . $keywords[$keywordKey]
                    . ' - two posts chasing one phrase split its ranking.';
            } elseif (isset($seenKeywords[$keywordKey])) {
                $warnings[] = 'Same focus keyword as line ' . $seenKeywords[$keywordKey] . '.';
            }
            if ($plain !== '' && mb_stripos($plain, $keyword) === false) {
                $warnings[] = 'The focus keyword never appears in the article.';
            }
        }

        // Links: inside the shop they must lead somewhere; out of it, few.
        $internal = 0;
        $broken = [];
        $external = 0;
        if (preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $links)) {
            foreach ($links[2] as $href) {
                if (blog_link_is_ours($href)) {
                    $internal++;
                    $path = blog_link_path($href);
                    if (!blog_path_exists($path, $known, $serviceSlugs, $countrySlugs)) {
                        $broken[] = '/' . $path;
                    }
                } elseif (preg_match('~^https?://~i', trim($href))) {
                    $external++;
                }
            }
        }
        if ($plain !== '' && $internal === 0) {
            $warnings[] = 'No links to other pages of the shop. 3-5 internal links help both readers and ranking.';
        }
        if ($broken) {
            $warnings[] = 'Links to pages that do not exist: ' . implode(', ', array_slice(array_unique($broken), 0, 5)) . '.';
        }
        if ($external > 3) {
            $warnings[] = $external . ' links out of the shop - keep it to one or two sources worth citing.';
        }

        $metaTitle = mb_substr(trim($row['meta_title']), 0, 190);
        if ($metaTitle === '') {
            $metaTitle = mb_substr($title, 0, 60);
        } elseif (mb_strlen($metaTitle) > 60) {
            $warnings[] = 'Meta title is ' . mb_strlen($metaTitle) . ' characters; Google shows about 60.';
        }
        $metaDescription = mb_substr(trim($row['meta_description']), 0, 300);
        if ($metaDescription === '') {
            $metaDescription = mb_substr($plain, 0, 155);
            $warnings[] = 'No meta description - the opening of the article stands in.';
        } elseif (mb_strlen($metaDescription) < 110 || mb_strlen($metaDescription) > 165) {
            $warnings[] = 'Meta description is ' . mb_strlen($metaDescription) . ' characters; 140-160 reads best in results.';
        }

        // When it goes out.
        $rowStatus = strtolower(trim($row['status']));
        $rowStatus = match (true) {
            in_array($rowStatus, ['published', 'publish', 'live', 'public', 'now'], true) => 'published',
            in_array($rowStatus, ['scheduled', 'schedule', 'future', 'later'], true)      => 'scheduled',
            default                                                                       => 'draft',
        };
        $publishAt = null;
        if ($row['publish_at'] !== '') {
            $stamp = strtotime($row['publish_at']);
            if ($stamp === false) {
                $problems[] = 'The publish_at date "' . $row['publish_at'] . '" could not be read. Write it as 2026-11-05 10:00.';
            } else {
                $publishAt = $stamp;
            }
        }
        switch ($mode) {
            case 'live':
                [$status, $publishAt] = ['published', $now];
                break;
            case 'draft':
                $status = 'draft';
                break;
            case 'schedule':
                [$status, $publishAt] = ['published', $start + $scheduled * $every * 3600];
                $scheduled++;
                break;
            default:
                if ($rowStatus === 'scheduled' && $publishAt === null && $row['publish_at'] === '') {
                    $problems[] = 'Marked scheduled but has no publish_at date.';
                }
                $status = $rowStatus === 'draft' ? 'draft' : 'published';
                if ($status === 'published' && $publishAt === null) {
                    $publishAt = $now;
                }
        }

        $categoryName = mb_substr(trim($row['category']), 0, 120);
        $categoryId = $categoryName !== ''
            ? ($categories[mb_strtolower($categoryName)] ?? $categories[slugify($categoryName)] ?? null) : null;
        if ($categoryName !== '' && $categoryId === null) {
            $warnings[] = 'New category "' . $categoryName . '" will be created.';
        }
        if (trim($row['image_prompt']) === '') {
            $warnings[] = 'No image prompt - the cover will need one written by hand.';
        }

        $line = $index + 2;   // the header is line 1
        if ($slug !== '') {
            $seenSlugs[$slug] = $line;
        }
        if ($titleTerms) {
            $batchTitles[$line] = $titleTerms;
        }
        if ($keywordKey !== '') {
            $seenKeywords[$keywordKey] = $line;
        }

        $data = [
            'title'            => $title,
            'slug'             => $slug,
            'excerpt'          => mb_substr(trim(strip_tags($row['excerpt'])), 0, 400),
            'content'          => $html,
            'meta_title'       => $metaTitle,
            'meta_description' => $metaDescription,
            'focus_keyword'    => $keyword,
            'tags'             => implode(',', post_tags($row['tags'])),
            'author_name'      => mb_substr(trim($row['author']), 0, 120),
            'status'           => $status,
            'is_featured'      => blog_csv_yes($row['featured']) ? 1 : 0,
            'noindex'          => 0,
            'published_at'     => $status === 'published' && $publishAt !== null ? date('Y-m-d H:i:s', $publishAt) : null,
            'image_prompt'     => trim($row['image_prompt']),
            'image_alt'        => mb_substr(trim(strip_tags($row['image_alt'])), 0, 190),
            'category_name'    => $categoryName,
            'category_id'      => $categoryId,
            'words'            => $words,
            'links'            => $internal,
        ];
        $out[] = [
            'line'        => $line,
            'data'        => $data,
            'problems'    => $problems,
            'warnings'    => $warnings,
            'action'      => $problems ? 'skip' : ($existingId ? 'replace' : 'create'),
            'existing_id' => $existingId,
            'state'       => $status === 'draft' ? 'draft' : ($publishAt !== null && $publishAt > $now ? 'scheduled' : 'published'),
        ];
    }
    return $out;
}

/**
 * Write the rows that passed, in one transaction.
 *
 * All or nothing: a batch that half-lands is a batch whose links point at
 * posts that never arrived. Categories named in the file are created on the
 * way, once each.
 *
 * @return array{created: int, replaced: int, skipped: int, ids: int[]}
 */
function blog_csv_save(array $checked): array
{
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $made = [];
    $result = ['created' => 0, 'replaced' => 0, 'skipped' => 0, 'ids' => []];

    $pdo->beginTransaction();
    try {
        foreach ($checked as $item) {
            if ($item['action'] === 'skip') {
                $result['skipped']++;
                continue;
            }
            $data = $item['data'];
            $categoryId = $data['category_id'];
            if ($categoryId === null && $data['category_name'] !== '') {
                $key = mb_strtolower($data['category_name']);
                if (!isset($made[$key])) {
                    $slug = slugify($data['category_name']) ?: 'blog';
                    $made[$key] = (int) (col('SELECT id FROM post_categories WHERE slug = ?', [$slug])
                        ?: insert_row('post_categories', ['slug' => $slug, 'name' => $data['category_name'],
                            'description' => '', 'meta_title' => '', 'meta_description' => '',
                            'sort_order' => 0, 'is_active' => 1]));
                }
                $categoryId = $made[$key];
            }
            $row = array_diff_key($data, ['category_name' => 1, 'words' => 1, 'links' => 1]);
            $row['category_id'] = $categoryId;
            $row['updated_at']  = $now;

            if ($item['action'] === 'replace' && $item['existing_id']) {
                update_row('posts', $row, 'id = ?', [$item['existing_id']]);
                $result['replaced']++;
                $result['ids'][] = (int) $item['existing_id'];
            } else {
                $row += ['cover_path' => '', 'created_at' => $now, 'views' => 0];
                $result['ids'][] = insert_row('posts', $row);
                $result['created']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $result;
}

// ===========================================================================
// The prompt
// ===========================================================================

/**
 * What the prompt tells the writer about the shop, read from the shop.
 *
 * The posts already written (so nothing is written twice and the new ones
 * link to the old), the pages worth linking to with their real addresses,
 * and the categories. Built fresh each time the screen opens, so a prompt
 * copied today knows about yesterday's import.
 */
function blog_prompt_facts(): array
{
    $site = (string) setting('site_name', 'OTP Flash');
    $base = rtrim(base_url(), '/');

    $posts = [];
    foreach (all('SELECT slug, title, focus_keyword, status, published_at FROM posts ORDER BY COALESCE(published_at, created_at) DESC LIMIT 300') as $post) {
        $posts[] = '- ' . $post['title'] . ' | /blog/' . $post['slug']
            . ($post['focus_keyword'] !== '' ? ' | keyword: ' . $post['focus_keyword'] : '')
            . (post_state($post) === 'scheduled' ? ' | scheduled' : (post_state($post) === 'draft' ? ' | draft' : ''));
    }

    $landing = [];
    $services = array_slice(store_services('numbers'), 0, 20);
    foreach ($services as $service) {
        $landing[] = '- ' . $service['name'] . ' virtual numbers, from ' . money((float) $service['from_price']) . ' | /' . $service['slug'];
    }
    foreach (array_slice($services, 0, 6) as $service) {
        foreach (array_slice(store_countries('numbers', (int) $service['id']), 0, 3) as $country) {
            $landing[] = '- ' . $service['name'] . ' number for ' . $country['name'] . ', from '
                . money((float) $country['from_price']) . ' | /' . $service['slug'] . '/' . $country['slug'];
        }
    }
    foreach (array_slice(store_countries('numbers'), 0, 12) as $country) {
        $landing[] = '- Virtual numbers for ' . $country['name'] . ' | /country/' . $country['slug'];
    }
    $tabs = store_tabs();
    if (isset($tabs['email'])) {
        $landing[] = '- Temporary email for sign-up codes | /temporary-email';
    }
    if (isset($tabs['rental'])) {
        $landing[] = '- Rent a number for days or weeks | /rent-number';
    }
    foreach (all('SELECT slug, title FROM pages WHERE is_active = 1 ORDER BY sort_order') as $page) {
        $landing[] = '- ' . $page['title'] . ' | /' . $page['slug'];
    }
    $landing[] = '- Frequently asked questions | /faq';
    $landing[] = '- Track an order | /track';

    $products = [];
    foreach ($tabs as $info) {
        $products[] = $info['label'];
    }

    return [
        'site'       => $site,
        'base'       => $base,
        'currency'   => base_currency_symbol(),
        'products'   => implode(', ', $products ?: ['Virtual numbers']),
        'posts'      => $posts,
        'landing'    => $landing,
        'categories' => array_column(all('SELECT name FROM post_categories WHERE is_active = 1 ORDER BY sort_order, name'), 'name'),
        'timezone'   => date_default_timezone_get(),
        'today'      => date('Y-m-d'),
    ];
}

/**
 * The whole prompt, ready to paste into any AI chat.
 *
 * {{COUNT}}, {{MODE}} and the rest are filled in by the import screen's
 * options as the admin changes them (blog_prompt_fill() on the server, the
 * same replacement in admin.js), so one template is the truth either way.
 */
function blog_prompt_template(): string
{
    $facts = blog_prompt_facts();
    $columns = [];
    foreach (BLOG_CSV_COLUMNS as $name => $what) {
        $columns[] = '- ' . $name . ': ' . str_replace('{site}', $facts['site'], $what);
    }

    $lines = [];
    $lines[] = 'You are a senior SEO content writer and editor. Write {{COUNT}} new blog articles for ' . $facts['site']
        . ' (' . $facts['base'] . ') and return them as ONE CSV file I can import.';
    $lines[] = '';
    $lines[] = '## About the site';
    $lines[] = $facts['site'] . ' sells ' . strtolower($facts['products']) . ' for receiving one-time passwords (OTP) and '
        . 'verification codes online: the customer picks an app and a country, pays, gets a number on screen and the '
        . 'code appears on the same page within seconds. No SIM card, no contract. Unused numbers are refunded or '
        . 'replaced. Prices are in ' . trim($facts['currency']) . '. Readers are ordinary people and small businesses who '
        . 'need to verify an account, keep their personal number private, or test an app.';
    $lines[] = 'Audience and region: {{AUDIENCE}}. Language: {{LANGUAGE}}.';
    $lines[] = '';
    $lines[] = '## Before you write: check the site';
    $lines[] = 'These posts already exist (title | address | keyword). Do NOT write about the same topic or target the same '
        . 'keyword again - pick angles, questions and keywords none of them covers:';
    $lines[] = $facts['posts'] ? implode("\n", $facts['posts']) : '- (no posts yet - start with the most-searched basics)';
    $lines[] = '';
    $lines[] = 'Pages you may link to (description | address). Use these addresses exactly; never invent one:';
    $lines[] = implode("\n", $facts['landing']);
    $lines[] = '';
    $lines[] = 'Existing categories: ' . ($facts['categories'] ? implode(', ', $facts['categories']) : '(none yet - create 2-4 sensible ones)') . '.';
    $lines[] = '{{TOPICS}}';
    $lines[] = '';
    $lines[] = '## How to choose the topics';
    $lines[] = '- Each article answers one real search: a question people type into Google about verifying an app, '
        . 'receiving an SMS code online, a country\'s numbers, privacy, or a problem like "code not received".';
    $lines[] = '- Mix intents: how-to guides, problem/fix articles, comparisons, country or app guides, and beginner explainers.';
    $lines[] = '- Every article gets its own focus keyword - a long-tail phrase of 3-6 words with clear intent. No two '
        . 'articles in this batch, and none of the existing posts above, may share a focus keyword or a topic.';
    $lines[] = '';
    $lines[] = '## How to write each article';
    $lines[] = '- 1,200-1,800 words of original writing. Not rewritten from any other site: your own structure, examples and wording.';
    $lines[] = '- Sound like an experienced person explaining to a friend: plain words, short paragraphs (2-4 sentences), '
        . 'varied sentence length, contractions, concrete examples and numbers. Second person ("you").';
    $lines[] = '- Avoid AI filler and cliches: no "In today\'s digital world", "delve", "unlock", "navigate the landscape", '
        . '"game-changer", "seamless", "elevate", "in conclusion", "it\'s important to note". No emojis.';
    $lines[] = '- Open with the answer in the first two or three sentences, then explain. Put the focus keyword in the '
        . 'first 100 words, in one <h2>, and naturally 3-6 times in total - never stuffed.';
    $lines[] = '- Structure: a short intro, 4-7 <h2> sections with <h3> where useful, at least one list or step-by-step '
        . '<ol>, a small comparison <table> where it helps, and a "Frequently asked questions" <h2> with 3-5 <h3> questions '
        . 'and short answers. End with a short wrap-up that tells the reader what to do next, without the heading "Conclusion".';
    $lines[] = '- Internal links: 3-5 per article to the pages listed above, with descriptive anchor text (for example '
        . '<a href=\'/whatsapp/pakistan\'>WhatsApp number for Pakistan</a>), spread through the text - not all at the end. '
        . 'Link to one or two existing posts where relevant, and to other articles in this batch by their slug (/blog/their-slug).';
    $lines[] = '- External links: at most one or two, only to official help pages (for example an app\'s own help centre). No competitors.';
    $lines[] = '- Be accurate and honest: do not promise that every app will accept every number, do not invent statistics '
        . 'or quotes, and say plainly when something depends on the app. Never mention where the numbers come from, '
        . 'suppliers, APIs or wholesalers - to the reader the numbers are simply ' . $facts['site'] . '\'s.';
    $lines[] = '- Stay on the right side of the rules: privacy, testing and legitimate sign-ups are fine; never explain how to '
        . 'commit fraud, evade bans, create fake accounts at scale or get around a platform\'s rules.';
    $lines[] = '- Mention ' . $facts['site'] . ' naturally where it genuinely helps (one or two times), not as an advert in every paragraph.';
    $lines[] = '';
    $lines[] = '## The cover image';
    $lines[] = '- image_prompt: a detailed prompt for an AI image generator (Midjourney, DALL-E, Ideogram) for a 16:9 cover: '
        . 'the subject, setting, lighting, style (clean modern photo or flat illustration), colours that suit a violet brand. '
        . 'No text, no letters, no real logos or brand marks, no real people\'s faces.';
    $lines[] = '- image_alt: a plain description of that picture for screen readers, under 120 characters.';
    $lines[] = '';
    $lines[] = '## Publishing';
    $lines[] = '{{SCHEDULE}}';
    $lines[] = 'Dates are in the shop\'s time zone (' . $facts['timezone'] . '); today is ' . $facts['today'] . '.';
    $lines[] = '';
    $lines[] = '## The CSV format (follow exactly)';
    $lines[] = 'Columns, in this order:';
    $lines[] = implode("\n", $columns);
    $lines[] = '';
    $lines[] = 'Rules that keep the file importable:';
    $lines[] = '- The first line is exactly: ' . implode(',', array_keys(BLOG_CSV_COLUMNS));
    $lines[] = '- One article per line. Wrap EVERY field in double quotes. Inside a field, write a double quote as two double quotes ("").';
    $lines[] = '- In the HTML use single quotes for attributes (<a href=\'/faq\'>) so there is nothing to escape, and keep '
        . 'the whole article on one line (no line breaks inside the content field).';
    $lines[] = '- No <h1>, no <html>/<body>, no <script>, no <style>, no inline styles, no images in the content.';
    $lines[] = '- Give me only the CSV inside one ```csv code block - no commentary before or after.';
    $lines[] = '- If {{COUNT}} complete articles do not fit in one reply, write as many complete rows as fit, then stop; '
        . 'when I say "continue", carry on with the next rows WITHOUT repeating the header line, and keep every new '
        . 'article different from the ones already written.';
    return implode("\n", $lines);
}

/** The template with the screen's options written in. */
function blog_prompt_fill(string $template, array $options): string
{
    $count = max(1, min(30, (int) ($options['count'] ?? 5)));
    $mode  = (string) ($options['mode'] ?? 'schedule');
    $start = (string) ($options['start'] ?? date('Y-m-d', strtotime('+1 day')) . ' 10:00');
    $every = max(1, (int) ($options['every_hours'] ?? 24));
    $schedule = match ($mode) {
        'live'  => 'Set status to published for every article and leave publish_at empty - they go live as soon as I import them.',
        'draft' => 'Set status to draft for every article and leave publish_at empty - I will review them before they go live.',
        default => 'Set status to scheduled for every article. The first goes out at ' . $start . ', then one every '
                 . $every . ' hour' . ($every === 1 ? '' : 's') . ' after it (write each publish_at as YYYY-MM-DD HH:MM).',
    };
    $topics = trim((string) ($options['topics'] ?? ''));
    return strtr($template, [
        '{{COUNT}}'    => (string) $count,
        '{{SCHEDULE}}' => $schedule,
        '{{AUDIENCE}}' => trim((string) ($options['audience'] ?? '')) ?: 'Pakistan first, and English-speaking readers worldwide',
        '{{LANGUAGE}}' => trim((string) ($options['language'] ?? '')) ?: 'English',
        '{{TOPICS}}'   => $topics !== '' ? "\nTopics or keywords I want covered in this batch:\n" . $topics : '',
    ]);
}
