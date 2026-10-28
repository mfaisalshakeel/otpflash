<?php
// Published only where the shop publishes landing pages at all. With them
// off this address does not exist - the tab lives on the home page, which is
// where every sale was made from anyway.
require_once APP_PATH . '/helpers/storefront.php';
if (!landing_pages_on()) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}
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
