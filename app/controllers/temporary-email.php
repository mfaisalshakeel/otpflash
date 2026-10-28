<?php
// Published only where the shop publishes landing pages at all. With them
// off this address does not exist - the tab lives on the home page, which is
// where every sale was made from anyway.
require_once APP_PATH . '/helpers/storefront.php';
if (!landing_pages_on()) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}
/** /temporary-email and /temporary-email/instagram - the email OTP tab. */
$service = null;
if (isset($params[0])) {
    $service = one('SELECT * FROM services WHERE slug = ? AND is_active = 1', [(string) $params[0]]);
    if (!$service || isset($params[1])) {
        require CONTROLLER_PATH . '/_404.php';
        return;
    }
}

$GLOBALS['__picker'] = ['tab' => 'email', 'service' => $service];
require CONTROLLER_PATH . '/home.php';
