<?php
/**
 * Services: the apps a number is bought for.
 *
 * Rows are made by the sync, named and iconed from what OTPGet calls them;
 * this screen is where an admin renames one, gives it a better icon, marks
 * it popular, switches it off, or writes the words its landing page ranks
 * with. Switching one off hides every offer for it at once.
 */
require_once APP_PATH . '/helpers/crud.php';
require_once APP_PATH . '/helpers/catalog.php';

$icons = ['' => 'Letter on its colour'];
foreach (service_catalogue() as $slug => $known) {
    if ($known['icon'] !== '') {
        $icons[$known['icon']] = $known['name'];
    }
}
asort($icons);

$offerCounts = [];
foreach (all('SELECT service_id, COUNT(*) AS n, MIN(CASE WHEN price > 0 THEN price END) AS low
                FROM offers WHERE is_active = 1 AND missing = 0 AND service_id IS NOT NULL
            GROUP BY service_id') as $row) {
    $offerCounts[(int) $row['service_id']] = $row;
}

crud_handle([
    'table'    => 'services',
    'base'     => 'admin/services',
    'title'    => 'Services',
    'single'   => 'Service',
    'subtitle' => 'The apps numbers are sold for - names, icons, and their landing pages',
    'order'    => 'is_popular DESC, sort_order, name',
    'sortable' => true,
    'search'   => ['name', 'slug'],
    'search_hint' => 'Search services',
    'toggle'   => 'is_active',
    'impact'   => true,
    'timestamps' => false,
    'empty'    => 'No services yet. They are created by the catalogue sync on the OTPGet screen.',
    'filters'  => [
        'is_popular' => ['column' => 'is_popular', 'all' => 'Popular or not', 'options' => ['1' => 'Popular', '0' => 'Not popular']],
        'is_active'  => ['column' => 'is_active', 'all' => 'On or off', 'options' => ['1' => 'On sale', '0' => 'Switched off']],
    ],
    'columns'  => [
        ['label' => 'Service', 'render' => fn($r) =>
            '<span style="display:inline-flex;align-items:center;gap:10px">' . service_icon_html($r, 'svc-ico')
            . '<span><b>' . e($r['name']) . '</b><small class="sub mono">/' . e($r['slug']) . '</small></span></span>'],
        ['label' => 'Offers', 'render' => function ($r) use ($offerCounts) {
            $c = $offerCounts[(int) $r['id']] ?? null;
            return $c ? qty_fmt((int) $c['n']) . '<small class="sub">from ' . e(money((float) $c['low'])) . '</small>'
                      : '<span class="muted">none</span>';
        }],
        ['label' => 'Page', 'render' => fn($r) => '<a class="lnk" target="_blank" rel="noopener" href="'
            . e(url($r['slug'])) . '">View</a>' . (trim((string) $r['intro']) !== '' ? '<small class="sub">has SEO text</small>' : '')],
        ['label' => 'Popular', 'render' => fn($r) => $r['is_popular'] ? '<span class="st st-completed">popular</span>' : '<span class="muted">-</span>'],
    ],
    'fields' => [
        'name'        => ['label' => 'Name', 'required' => true, 'hint' => 'What customers see. The sync never renames a service you have renamed.'],
        'slug'        => ['label' => 'Address', 'required' => true, 'rules' => ['slug', 'unique'],
                          'hint' => 'The landing page lives at /this-address. Changing it breaks links people already have.'],
        'icon'        => ['label' => 'Icon', 'type' => 'select', 'options' => $icons, 'search' => true],
        'color'       => ['label' => 'Colour', 'type' => 'color', 'default' => '#6c4df6',
                          'hint' => 'Behind a letter icon, and the tint behind a brand icon.'],
        'site_domain' => ['label' => 'Website', 'placeholder' => 'instagram.com',
                          'hint' => 'For temporary email: inboxes sold for this website are filed under this service.'],
        'is_popular'  => ['label' => 'Popular - shown first', 'type' => 'checkbox'],
        'is_active'   => ['label' => 'On sale', 'type' => 'checkbox', 'default' => 1],
        'sort_order'  => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'meta_title'  => ['label' => 'Search title', 'placeholder' => 'Buy WhatsApp Virtual Number for OTP Verification',
                          'hint' => 'Optional. 30-60 characters. The site name is added for you.'],
        'meta_description' => ['label' => 'Meta description', 'type' => 'textarea', 'rows' => 3,
                          'hint' => 'Optional. 70-160 characters - the grey text under the title in search results.'],
        'intro'       => ['label' => 'Landing page text', 'type' => 'richtext', 'height' => 320,
                          'hint' => 'Shown under the picker on this service\'s page. A few paragraphs about using a virtual number for this app is what makes the page rank.'],
    ],
    'before_save' => function (array $data, int $id): array {
        if (($data['color'] ?? '') === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $data['color'])) {
            $data['color'] = service_color_for((string) ($data['name'] ?? ''));
        }
        $data['site_domain'] = strtolower(trim(preg_replace('~^https?://(www\.)?~i', '', (string) ($data['site_domain'] ?? '')), '/ '));
        if ($id === 0) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        return $data;
    },
], $params);
