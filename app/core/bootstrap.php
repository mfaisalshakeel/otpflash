<?php
/**
 * Boots the application: error handling, configuration, session, database.
 *
 * Nothing in here echoes anything - a failure raises an exception that the
 * handler below turns into a plain, non-leaking error page.
 */

if (!defined('BASE_PATH')) {
    http_response_code(500);
    exit('Bootstrap must be loaded from index.php.');
}

define('APP_PATH',     BASE_PATH . '/app');
define('CONFIG_PATH',  BASE_PATH . '/config');
define('VIEW_PATH',    APP_PATH . '/views');
define('CONTROLLER_PATH', APP_PATH . '/controllers');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH',  BASE_PATH . '/uploads');

// The version of the code on disk. The database carries its own copy in the
// `app_version` setting; when the two differ the admin offers an update.
define('APP_VERSION', (string) require APP_PATH . '/core/version.php');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// ---------------------------------------------------------------------------
// Configuration
//
// The helpers are loaded before the config is read: they only define
// functions, and the not-installed branch below needs url() and
// current_path() while there is still no config to read.
// ---------------------------------------------------------------------------
$GLOBALS['__config'] = [];
require APP_PATH . '/helpers/functions.php';
require APP_PATH . '/helpers/theme.php';
// price() is printed by storefront views the way money() is printed by
// everything else, so it has to be here rather than pulled in per controller.
require APP_PATH . '/helpers/showprice.php';

$configFile = CONFIG_PATH . '/config.php';
$installed  = is_file($configFile);

if ($installed) {
    $GLOBALS['__config'] = require $configFile;
}

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------
$debug = !empty($GLOBALS['__config']['debug']);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

set_exception_handler(function (Throwable $e) use ($debug) {
    error_log('[' . date('c') . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // On the command line there is no response to set a code on, and trying
    // to buries the real message under a headers-already-sent error - which
    // is exactly what a cron job would mail you instead of the fault.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Server error</title>'
           . '<div style="font:16px/1.6 system-ui;max-width:34rem;margin:15vh auto;padding:0 1.5rem">'
           . '<h1 style="font-size:1.4rem">Something went wrong</h1>'
           . '<p>The page could not be loaded. Please try again in a moment.</p></div>';
    }
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ---------------------------------------------------------------------------
// Session - started for every request so flash messages and CSRF work
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name('otpsess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Security headers for every dynamic response
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---------------------------------------------------------------------------
// The installer
//
// It lives at /install - no .php in the address, and the same address on the
// built-in server, Apache and nginx. index.php hands over to it when this is
// true; until the site is installed, every other URL is sent there.
// ---------------------------------------------------------------------------
define('IS_INSTALL_ROUTE', current_path() === 'install');

if (!$installed && !IS_INSTALL_ROUTE) {
    if (!is_file(BASE_PATH . '/install/index.php')) {
        http_response_code(500);
        exit('Configuration missing and the installer is gone. Restore config/config.php.');
    }
    redirect('install');
}
