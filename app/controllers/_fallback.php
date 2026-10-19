<?php
/**
 * The first URL segment was not a controller. It can still be:
 *
 *   /whatsapp              a service's landing page
 *   /whatsapp/pakistan     a service in one country
 *   /refund-policy         a content page
 *
 * Anything else is a 404. Pages are looked at before services, so an admin's
 * page called "telegram" wins over the service of that name - the admin made
 * it on purpose, and the service is still reachable from the picker.
 */

$slug = $params[0] ?? '';
$sub  = $params[1] ?? null;

if (isset($params[2])) {          // nothing lives deeper than two segments
    require CONTROLLER_PATH . '/_404.php';
    return;
}

// A content page.
if ($sub === null) {
    $page = one('SELECT * FROM pages WHERE slug = ? AND is_active = 1', [$slug]);
    if ($page) {
        require_once APP_PATH . '/helpers/seo.php';
        view('page', [
            'seo' => [
                'title'       => $page['meta_title'] !== '' ? $page['meta_title'] : $page['title'],
                'description' => $page['meta_description'] !== '' ? $page['meta_description'] : excerpt($page['content'], 160),
                'canonical'   => url($page['slug']),
                'image'       => $page['og_image'] ?: null,
                'robots'      => $page['noindex'] ? 'noindex, follow' : null,
                'schema'      => [schema_breadcrumbs([['Home', url('')], [$page['title'], url($page['slug'])]])],
            ],
            'page' => $page,
        ]);
    }
}

// A service landing page, optionally narrowed to a country.
if (setting('seo_landing_pages', '1') === '1' || $sub === null) {
    $service = one('SELECT * FROM services WHERE slug = ? AND is_active = 1', [$slug]);
    if ($service) {
        $country = null;
        if ($sub !== null) {
            $country = one('SELECT * FROM countries WHERE slug = ? AND is_active = 1', [$sub]);
            if (!$country) {
                require CONTROLLER_PATH . '/_404.php';
                return;
            }
        }
        $GLOBALS['__picker'] = ['tab' => 'numbers', 'service' => $service, 'country' => $country];
        require CONTROLLER_PATH . '/home.php';
        return;
    }
}

require CONTROLLER_PATH . '/_404.php';
