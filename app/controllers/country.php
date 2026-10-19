<?php
/**
 * /country/pakistan - every app with a number in one country.
 *
 * The other way into the picker: some customers know the country they need
 * before they know which of our services matches their app.
 */
$country = one('SELECT * FROM countries WHERE slug = ? AND is_active = 1', [(string) ($params[0] ?? '')]);

if (!$country || isset($params[1])) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

$GLOBALS['__picker'] = ['tab' => 'numbers', 'country' => $country];
require CONTROLLER_PATH . '/home.php';
