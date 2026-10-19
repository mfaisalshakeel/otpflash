<?php
/**
 * OTP Flash - single entry point.
 *
 * Every request that is not a real file on disk lands here (see .htaccess),
 * so this file only has two jobs: boot the application and hand the request
 * to the resolver.
 */

define('BASE_PATH', __DIR__);

require BASE_PATH . '/app/core/bootstrap.php';

// /install is served by the installer, not by a controller - it has to work
// before there is a database to route against.
if (IS_INSTALL_ROUTE) {
    define('INSTALL_VIA_APP', true);
    require BASE_PATH . '/install/index.php';
    exit;
}

require BASE_PATH . '/app/core/resolver.php';

resolve_request();
