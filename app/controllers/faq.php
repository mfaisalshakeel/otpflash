<?php
/** Every FAQ on one page, marked up so search results can show them. */
require_once APP_PATH . '/helpers/seo.php';

$faqs = all('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id');

view('faq', [
    'seo' => [
        'title'       => 'Frequently asked questions',
        'description' => 'How virtual numbers work, how fast you get your code, what happens if the SMS '
                       . 'never arrives, refunds, rentals and payment - answered.',
        'canonical'   => url('faq'),
        'schema'      => [schema_faq($faqs),
                          schema_breadcrumbs([['Home', url('')], ['FAQ', url('faq')]])],
    ],
    'faqs' => $faqs,
]);
