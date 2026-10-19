<?php
/** Nothing matched the URL. */
http_response_code(404);

view('_404', [
    'seo' => ['title' => 'Page not found', 'robots' => 'noindex, follow'],
]);
