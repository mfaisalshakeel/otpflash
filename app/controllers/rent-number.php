<?php
/** /rent-number and /rent-number/canada - the rentals tab. */
$country = null;
if (isset($params[0])) {
    $country = one('SELECT * FROM countries WHERE slug = ? AND is_active = 1', [(string) $params[0]]);
    if (!$country || isset($params[1])) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
}

$GLOBALS['__picker'] = ['tab' => 'rental', 'country' => $country];
require CONTROLLER_PATH . '/home.php';
