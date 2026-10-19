<?php
/**
 * Countries: one row per country, whichever server sells it.
 *
 * Made by the sync, flagged from what OTPGet calls them. A name the sync
 * could not place arrives with no flag - give it one here by setting its
 * two-letter code. Switching a country off hides every offer in it.
 */
require_once APP_PATH . '/helpers/crud.php';
require_once APP_PATH . '/helpers/catalog.php';

$offerCounts = [];
foreach (all('SELECT country_id, COUNT(*) AS n, MIN(CASE WHEN price > 0 THEN price END) AS low
                FROM offers WHERE is_active = 1 AND missing = 0 AND country_id IS NOT NULL
            GROUP BY country_id') as $row) {
    $offerCounts[(int) $row['country_id']] = $row;
}

$isoOptions = ['' => 'No flag'];
foreach (country_catalogue() as $iso => $known) {
    $isoOptions[$iso] = $known['name'] . ' (' . strtoupper($iso) . ')';
}
asort($isoOptions);

crud_handle([
    'table'    => 'countries',
    'base'     => 'admin/countries',
    'title'    => 'Countries',
    'single'   => 'Country',
    'subtitle' => 'Flags, names and which countries are on sale',
    'order'    => 'is_popular DESC, sort_order, name',
    'sortable' => true,
    'search'   => ['name', 'slug', 'iso2', 'dial_code'],
    'search_hint' => 'Search name, code or +dial',
    'toggle'   => 'is_active',
    'impact'   => true,
    'empty'    => 'No countries yet. They are created by the catalogue sync on the OTPGet screen.',
    'filters'  => [
        'is_popular' => ['column' => 'is_popular', 'all' => 'Popular or not', 'options' => ['1' => 'Popular', '0' => 'Not popular']],
    ],
    'columns'  => [
        ['label' => 'Country', 'render' => fn($r) =>
            '<span style="display:inline-flex;align-items:center;gap:10px"><img src="' . e(flag_url($r['iso2'])) . '" alt="" width="28" height="21" style="border-radius:4px;box-shadow:0 0 0 1px rgba(0,0,0,.08)">'
            . '<span><b>' . e($r['name']) . '</b><small class="sub">' . ($r['dial_code'] !== '' ? '+' . e($r['dial_code']) : 'no dial code')
            . ($r['iso2'] !== '' ? ' &middot; ' . e(strtoupper($r['iso2'])) : ' &middot; <span class="warn">no flag</span>') . '</small></span></span>'],
        ['label' => 'Offers', 'render' => function ($r) use ($offerCounts) {
            $c = $offerCounts[(int) $r['id']] ?? null;
            return $c ? qty_fmt((int) $c['n']) . '<small class="sub">from ' . e(money((float) $c['low'])) . '</small>'
                      : '<span class="muted">none</span>';
        }],
        ['label' => 'Page', 'render' => fn($r) => '<a class="lnk" target="_blank" rel="noopener" href="'
            . e(url('country/' . $r['slug'])) . '">View</a>'],
        ['label' => 'Popular', 'render' => fn($r) => $r['is_popular'] ? '<span class="st st-completed">popular</span>' : '<span class="muted">-</span>'],
    ],
    'fields' => [
        'name'       => ['label' => 'Name', 'required' => true],
        'slug'       => ['label' => 'Address', 'required' => true, 'rules' => ['slug', 'unique'],
                         'hint' => 'Used in /country/this and /whatsapp/this.'],
        'iso2'       => ['label' => 'Flag', 'type' => 'select', 'options' => $isoOptions, 'search' => true,
                         'hint' => 'The two-letter country code that picks the flag.'],
        'dial_code'  => ['label' => 'Dial code', 'placeholder' => '92', 'hint' => 'Digits only, no plus. Lets customers copy a number without it.'],
        'is_popular' => ['label' => 'Popular - shown first', 'type' => 'checkbox'],
        'is_active'  => ['label' => 'On sale', 'type' => 'checkbox', 'default' => 1],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
    ],
    'before_save' => function (array $data, int $id): array {
        $data['iso2']      = strtolower(substr(preg_replace('/[^a-z]/i', '', (string) ($data['iso2'] ?? '')), 0, 2));
        $data['dial_code'] = substr(preg_replace('/\D/', '', (string) ($data['dial_code'] ?? '')), 0, 8);
        if ($data['dial_code'] === '' && $data['iso2'] !== '') {
            $data['dial_code'] = (string) (country_catalogue()[$data['iso2']]['dial'] ?? '');
        }
        if ($id === 0) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        return $data;
    },
], $params);
