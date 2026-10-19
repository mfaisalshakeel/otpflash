<?php
/** Blog categories: each one is a page of its own, at /blog/category/{slug}. */
require_once APP_PATH . '/helpers/crud.php';

crud_handle([
    'table'    => 'post_categories',
    'base'     => 'admin/post-categories',
    'title'    => 'Blog categories',
    'single'   => 'Category',
    'subtitle' => 'Group posts, and give each group a page search engines can find',
    'order'    => 'sort_order, id',
    'sortable' => true,
    'search'   => ['name', 'slug'],
    'search_hint' => 'Search categories',
    'toggle'   => 'is_active',
    'empty'    => 'No categories yet.',
    'columns'  => [
        ['label' => 'Category', 'render' => fn($r) =>
            '<b>' . e($r['name']) . '</b><small class="sub">/blog/category/' . e($r['slug']) . '</small>'],
        ['label' => 'Posts', 'render' => fn($r) =>
            qty_fmt((int) col('SELECT COUNT(*) FROM posts WHERE category_id = ?', [(int) $r['id']], 0))],
        ['label' => 'State', 'render' => fn($r) =>
            '<span class="st st-' . ($r['is_active'] ? 'completed' : 'cancelled') . '">'
            . ($r['is_active'] ? 'live' : 'hidden') . '</span>'],
    ],
    'fields' => [
        'name'        => ['label' => 'Name', 'required' => true],
        'slug'        => ['label' => 'URL slug', 'required' => true, 'rules' => ['slug', 'unique'],
                          'hint' => 'The category lives at /blog/category/your-slug'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 3,
                          'hint' => 'Shown under the heading on the category page - a sentence or two of real text helps it rank.'],
        'meta_title'       => ['label' => 'Search title', 'hint' => 'Defaults to the name.'],
        'meta_description' => ['label' => 'Meta description', 'type' => 'textarea', 'rows' => 2],
        'sort_order'  => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'is_active'   => ['label' => 'Shown on the blog', 'type' => 'checkbox', 'default' => 1],
    ],
], $params);
