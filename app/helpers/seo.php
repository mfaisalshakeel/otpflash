<?php
/**
 * What search engines and link previews are told about a page.
 *
 * Every public controller hands its view a `seo` array and the layout prints
 * it through seo_head() - one function, so no page can forget its canonical
 * or print two descriptions, and the structured data on every page is built
 * by the same few functions here rather than typed into templates.
 *
 *   'seo' => [
 *       'title'       => 'WhatsApp number for Pakistan',  // the page's own words
 *       'description' => '...',
 *       'canonical'   => url('whatsapp/pakistan'),
 *       'image'       => url('uploads/...'),               // og:image, optional
 *       'type'        => 'website' | 'article' | 'product',
 *       'robots'      => 'noindex, nofollow',              // optional
 *       'schema'      => [ [...json-ld...], ... ],         // optional
 *       'published'   => '2026-10-01 10:00:00',            // articles
 *   ]
 *
 * The site name is added by the title format in SEO settings, so a page
 * never writes it into its own title and never gets it twice.
 */

/** The <title>, through the admin's format. */
function seo_title(string $title): string
{
    $site = (string) setting('site_name', 'OTP Flash');
    if ($title === '' || $title === $site) {
        $tagline = trim((string) setting('site_tagline', ''));
        return $tagline !== '' ? $site . ' - ' . $tagline : $site;
    }
    $format = (string) setting('seo_title_format', '{title} | {site}');
    if (!str_contains($format, '{title}')) {
        $format = '{title} | {site}';
    }
    return strtr($format, ['{title}' => $title, '{site}' => $site]);
}

/** A description clipped where a search result clips it, on a word. */
function seo_clip(string $text, int $length = 158): string
{
    // Tags out before entities are decoded, and as spaces, so "a</p><p>b"
    // stays two words and an escaped "&lt;b&gt;" stays text.
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' ', $text)), ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $cut = mb_substr($text, 0, $length - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
}

/** The image a shared link shows: the page's, else the site default, else the logo. */
function seo_image(?string $image = null): string
{
    foreach ([$image, setting('seo_og_image', ''), setting('logo_path', '')] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        return preg_match('~^https?://~i', $candidate) ? $candidate : url($candidate);
    }
    return '';
}

/**
 * Every tag a page's <head> needs, ready to print.
 *
 * Escaped here, so the layout prints the result as it is.
 */
function seo_head(array $seo): string
{
    $site        = (string) setting('site_name', 'OTP Flash');
    $title       = seo_title((string) ($seo['title'] ?? ''));
    $description = seo_clip((string) ($seo['description'] ?? '') ?: (string) setting('seo_description', ''));
    $canonical   = (string) ($seo['canonical'] ?? '');
    $image       = seo_image($seo['image'] ?? null);
    $type        = (string) ($seo['type'] ?? 'website');
    $robots      = (string) ($seo['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1');

    $tags   = [];
    $tags[] = '<title>' . e($title) . '</title>';
    if ($description !== '') {
        $tags[] = '<meta name="description" content="' . e($description) . '">';
    }
    $tags[] = '<meta name="robots" content="' . e($robots) . '">';
    if ($canonical !== '') {
        $tags[] = '<link rel="canonical" href="' . e($canonical) . '">';
    }
    foreach (['prev', 'next'] as $rel) {
        if (!empty($seo[$rel])) {
            $tags[] = '<link rel="' . $rel . '" href="' . e((string) $seo[$rel]) . '">';
        }
    }

    $tags[] = '<meta property="og:site_name" content="' . e($site) . '">';
    $tags[] = '<meta property="og:title" content="' . e((string) ($seo['title'] ?? '') ?: $title) . '">';
    $tags[] = '<meta property="og:type" content="' . e($type === 'product' ? 'website' : $type) . '">';
    if ($description !== '') {
        $tags[] = '<meta property="og:description" content="' . e($description) . '">';
    }
    if ($canonical !== '') {
        $tags[] = '<meta property="og:url" content="' . e($canonical) . '">';
    }
    if ($image !== '') {
        $tags[] = '<meta property="og:image" content="' . e($image) . '">';
    }
    $tags[] = '<meta property="og:locale" content="en_US">';
    if ($type === 'article' && !empty($seo['published'])) {
        $tags[] = '<meta property="article:published_time" content="' . e(date('c', strtotime((string) $seo['published']))) . '">';
        if (!empty($seo['modified'])) {
            $tags[] = '<meta property="article:modified_time" content="' . e(date('c', strtotime((string) $seo['modified']))) . '">';
        }
    }

    $tags[] = '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '">';
    $handle = ltrim(trim((string) setting('seo_twitter', '')), '@');
    if ($handle !== '' && preg_match('/^\w{1,30}$/', $handle)) {
        $tags[] = '<meta name="twitter:site" content="@' . e($handle) . '">';
    }

    foreach (['seo_google_verify' => 'google-site-verification', 'seo_bing_verify' => 'msvalidate.01'] as $key => $name) {
        $value = trim((string) setting($key, ''));
        if ($value !== '' && ($seo['home'] ?? false)) {
            $tags[] = '<meta name="' . $name . '" content="' . e($value) . '">';
        }
    }

    foreach ((array) ($seo['schema'] ?? []) as $graph) {
        if (is_array($graph) && $graph) {
            // JSON in a script tag: </ must not close it early, so it is
            // escaped, and unicode is kept readable for the crawler.
            $tags[] = '<script type="application/ld+json">'
                . json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
                . '</script>';
        }
    }

    return implode("\n", $tags);
}

// ===========================================================================
// Structured data
// ===========================================================================

/** Who runs the site. On every page, because it is what ties them together. */
function schema_organization(): array
{
    $name = trim((string) setting('seo_org_name', '')) ?: (string) setting('site_name', 'OTP Flash');
    $org  = ['@context' => 'https://schema.org', '@type' => 'Organization', '@id' => url('') . '#org',
             'name' => $name, 'url' => url('')];

    $logo = trim((string) setting('logo_path', ''));
    if ($logo !== '') {
        $org['logo'] = url($logo);
    }
    $email = trim((string) setting('support_email', ''));
    if ($email !== '') {
        $org['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $email];
    }
    $same = array_values(array_filter(array_map(static function (string $key): string {
        $channel = chat_channel($key);
        return $channel && $key !== 'email' ? $channel['url'] : '';
    }, ['whatsapp', 'telegram'])));
    // The shop's own profiles elsewhere, from SEO settings: what lets a search
    // engine join this site to its Facebook page and its X account.
    foreach (preg_split('/\s+/', (string) setting('seo_same_as', '')) ?: [] as $profile) {
        if (preg_match('~^https://[^\s]+$~i', $profile)) {
            $same[] = $profile;
        }
    }
    if ($same) {
        $org['sameAs'] = $same;
    }
    return $org;
}

/** The site, with the search box Google can show under its result. */
function schema_website(): array
{
    return [
        '@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => url('') . '#site',
        'name' => (string) setting('site_name', 'OTP Flash'), 'url' => url(''),
        'publisher' => ['@id' => url('') . '#org'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('') . '?q={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** @param array<int, array{0: string, 1: string}> $trail [name, url] pairs, home first */
function schema_breadcrumbs(array $trail): array
{
    $items = [];
    foreach (array_values($trail) as $i => [$name, $link]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $link];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** @param array<int, array{question: string, answer: string}> $faqs */
function schema_faq(array $faqs): array
{
    if (!$faqs) {
        return [];
    }
    return [
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(static fn (array $faq): array => [
            '@type' => 'Question', 'name' => (string) $faq['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $faq['answer']],
        ], array_values($faqs)),
    ];
}

/**
 * A number we sell, as a product with a price range - which is what lets a
 * search result show "from Rs 45".
 */
function schema_product(string $name, string $description, string $link, float $low, float $high, int $offers, string $image = ''): array
{
    if ($offers < 1 || $low <= 0) {
        return [];
    }
    $code = strtoupper((string) (function_exists('base_currency') ? base_currency()['code'] : 'PKR'));
    $product = [
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $name,
        'description' => seo_clip($description, 300), 'url' => $link,
        'brand' => ['@type' => 'Brand', 'name' => (string) setting('site_name', 'OTP Flash')],
        'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => $code,
                     'lowPrice' => number_format($low, 2, '.', ''), 'highPrice' => number_format(max($low, $high), 2, '.', ''),
                     'offerCount' => $offers, 'availability' => 'https://schema.org/InStock'],
    ];
    if ($image !== '') {
        $product['image'] = $image;
    }
    return $product;
}

/** A blog post. */
function schema_article(array $post, string $link): array
{
    $article = [
        '@context' => 'https://schema.org', '@type' => 'BlogPosting',
        'headline' => mb_substr((string) $post['title'], 0, 110),
        'description' => seo_clip((string) ($post['meta_description'] ?: $post['excerpt'] ?: $post['content'])),
        'mainEntityOfPage' => $link, 'url' => $link,
        'datePublished' => date('c', strtotime((string) ($post['published_at'] ?: $post['created_at']))),
        'dateModified' => date('c', strtotime((string) ($post['updated_at'] ?: $post['published_at'] ?: $post['created_at']))),
        'author' => ['@type' => 'Person', 'name' => (string) ($post['author_name'] ?: setting('site_name', 'OTP Flash'))],
        'publisher' => ['@id' => url('') . '#org'],
    ];
    if (!empty($post['cover_path'])) {
        $article['image'] = url((string) $post['cover_path']);
    }
    $words = str_word_count(strip_tags((string) $post['content']));
    if ($words > 0) {
        $article['wordCount'] = $words;
    }
    return $article;
}

// ===========================================================================
// The editor's checklist
// ===========================================================================

/**
 * How a post or page stands against the basics, as a list of passes and
 * misses for the edit screen. Advice, never a gate: a post can be published
 * whatever this says.
 *
 * @return array<int, array{ok: bool, text: string}>
 */
function seo_checklist(string $title, string $metaTitle, string $metaDescription, string $content,
                       string $keyword, string $slug): array
{
    $seoTitle = $metaTitle !== '' ? $metaTitle : $title;
    $text     = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<[^>]+>/', ' ', $content))));
    $words    = $text === '' ? 0 : count(preg_split('/\s+/', $text));
    $keyword  = mb_strtolower(trim($keyword));
    $checks   = [];

    $length = mb_strlen($seoTitle);
    $checks[] = ['ok' => $length >= 30 && $length <= 60,
                 'text' => 'Search title is ' . $length . ' characters (aim for 30-60).'];
    $length = mb_strlen($metaDescription);
    $checks[] = ['ok' => $length >= 70 && $length <= 160,
                 'text' => $length === 0 ? 'No meta description - search engines will pick a sentence for you.'
                                         : 'Meta description is ' . $length . ' characters (aim for 70-160).'];
    $checks[] = ['ok' => $words >= 300, 'text' => $words . ' words of content (300+ ranks better).'];
    $checks[] = ['ok' => (bool) preg_match('/<h2[\s>]/i', $content), 'text' => 'Uses H2 subheadings to break up the text.'];
    $images   = preg_match_all('/<img\b[^>]*>/i', $content, $found);
    $missing  = 0;
    foreach ($found[0] ?? [] as $img) {
        if (!preg_match('/\balt\s*=\s*"[^"]+"/i', $img)) {
            $missing++;
        }
    }
    $checks[] = ['ok' => $images > 0 && $missing === 0,
                 'text' => $images === 0 ? 'No images yet.' : ($missing ? $missing . ' image(s) without alt text.' : 'Every image has alt text.')];
    $checks[] = ['ok' => (bool) preg_match('/<a\s[^>]*href=/i', $content), 'text' => 'Links to another page.'];
    $checks[] = ['ok' => mb_strlen($slug) <= 75 && !preg_match('/\d{4,}/', $slug), 'text' => 'Short, readable address.'];

    if ($keyword !== '') {
        $checks[] = ['ok' => str_contains(mb_strtolower($seoTitle), $keyword), 'text' => 'Focus keyword in the search title.'];
        $checks[] = ['ok' => str_contains(mb_strtolower($metaDescription), $keyword), 'text' => 'Focus keyword in the meta description.'];
        $checks[] = ['ok' => str_contains(mb_strtolower(mb_substr($text, 0, 600)), $keyword), 'text' => 'Focus keyword in the first paragraph.'];
        $checks[] = ['ok' => str_contains($slug, slugify($keyword)), 'text' => 'Focus keyword in the address.'];
        $count   = $words > 0 ? substr_count(mb_strtolower($text), $keyword) : 0;
        $density = $words > 0 ? round($count / $words * 100, 1) : 0;
        $checks[] = ['ok' => $density >= 0.5 && $density <= 3,
                     'text' => 'Focus keyword density ' . $density . '% (aim for 0.5-3%).'];
    } else {
        $checks[] = ['ok' => false, 'text' => 'No focus keyword set - add one to get keyword checks.'];
    }

    return $checks;
}
