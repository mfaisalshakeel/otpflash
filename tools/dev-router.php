<?php
/**
 * Router for PHP's built-in server, so local development behaves like Apache
 * with the shipped .htaccess.
 *
 *   php -S 127.0.0.1:8000 tools/dev-router.php
 *
 * Without it, php -S answers a request with a file extension (/sitemap.xml)
 * straight from disk and never reaches index.php. Development only - it is
 * not used, and not needed, on a real server.
 */

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rawurldecode($path);

// Mirror the deny rules in .htaccess so local testing is honest about them.
if (preg_match('~^/(app|config|storage)(/|$)~', $path)
    || preg_match('~^/install/.*\.(sql|log|lock)$~', $path)
    || preg_match('~^/themes/[^/]+/(views/|theme\.php$)~', $path)
    || preg_match('~^/uploads/.*\.(php|phtml|phps|php[0-9]|phar|pl|py|cgi|sh|lua|rb|htaccess)$~i', $path)) {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

// A real file (css, js, an uploaded image) is served as-is.
$file = $root . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// Everything else is the application's. Apache rewrites those to the front
// controller, so say the same thing here - php -S would otherwise report the
// directory index it resolved and the app would read the URL differently
// under the two servers.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';

require $root . '/index.php';
