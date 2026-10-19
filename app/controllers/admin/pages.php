<?php
/** Content pages reachable at /{slug}. */
require_once APP_PATH . '/helpers/crud.php';

crud_handle([
    'table'    => 'pages',
    'base'     => 'admin/pages',
    'title'    => 'Pages',
    'single'   => 'Page',
    'subtitle' => 'About, policies and anything else',
    'order'    => 'sort_order, id',
    'sortable' => true,
    'search'   => ['title', 'slug'],
    'search_hint' => 'Search page title',
    'toggle'   => 'is_active',
    'empty'    => 'No pages yet.',
    'form_note'=> 'The content box accepts HTML and is rendered as written, so only paste markup you trust.',
    'columns'  => [
        ['label' => 'Page', 'render' => fn($r) =>
            '<b>' . e($r['title']) . '</b><small class="sub">/' . e($r['slug']) . '</small>'],
        ['label' => 'In footer', 'render' => fn($r) => $r['show_in_footer'] ? 'yes' : 'no'],
        ['label' => 'State', 'render' => fn($r) =>
            '<span class="st st-' . ($r['is_active'] ? 'completed' : 'cancelled') . '">'
            . ($r['is_active'] ? 'live' : 'draft') . '</span>'],
    ],
    'fields' => [
        'title'   => ['label' => 'Title', 'required' => true],
        'slug'    => ['label' => 'URL slug', 'required' => true, 'rules' => ['slug', 'unique'],
                      'hint' => 'The page lives at /your-slug'],
        'content' => ['label' => 'Content (HTML)', 'type' => 'textarea', 'rows' => 14],
        'meta_title'       => ['label' => 'Meta title'],
        'meta_description' => ['label' => 'Meta description', 'type' => 'textarea', 'rows' => 2],
        'show_in_footer'   => ['label' => 'Show in footer', 'type' => 'checkbox', 'default' => 1],
        'sort_order'       => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'is_active'        => ['label' => 'Published', 'type' => 'checkbox', 'default' => 1],
    ],
], $params);
