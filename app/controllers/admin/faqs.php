<?php
/** FAQ entries, shown on the home page and the FAQ page (and as FAQ rich results). */
require_once APP_PATH . '/helpers/crud.php';

crud_handle([
    'table'    => 'faqs',
    'base'     => 'admin/faqs',
    'title'    => 'FAQs',
    'single'   => 'FAQ',
    'subtitle' => 'Questions shown on the front page, and to search engines as FAQ results',
    'order'    => 'sort_order, id',
    'sortable' => true,
    'search'   => ['question', 'answer'],
    'search_hint' => 'Search questions',
    'toggle'   => 'is_active',
    'empty'    => 'No FAQs yet.',
    'columns'  => [
        ['label' => 'Question', 'render' => fn($r) =>
            '<b>' . e($r['question']) . '</b><small class="sub">' . e(excerpt($r['answer'], 70)) . '</small>'],
        ['label' => 'State', 'render' => fn($r) =>
            '<span class="st st-' . ($r['is_active'] ? 'completed' : 'cancelled') . '">'
            . ($r['is_active'] ? 'live' : 'hidden') . '</span>'],
    ],
    'fields' => [
        'question'    => ['label' => 'Question', 'required' => true],
        'answer'      => ['label' => 'Answer', 'type' => 'textarea', 'rows' => 5, 'required' => true],
        'sort_order'  => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'is_active'   => ['label' => 'Published', 'type' => 'checkbox', 'default' => 1],
    ],
], $params);
