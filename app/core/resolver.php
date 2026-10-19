<?php
/**
 * Request resolver.
 *
 * Routing is file-based: the URL path names a controller file under
 * app/controllers/. There is no route table to keep in sync.
 *
 *   /                      -> app/controllers/home.php
 *   /track                 -> app/controllers/track.php
 *   /sitemap.xml           -> app/controllers/sitemap.php
 *   /robots.txt            -> app/controllers/robots.php
 *   /admin                 -> app/controllers/admin/index.php
 *   /admin/services        -> app/controllers/admin/services.php
 *   /instagram             -> app/controllers/_fallback.php   (platform or page)
 *   /instagram/followers   -> app/controllers/_fallback.php   (platform + category)
 *   anything else          -> app/controllers/_404.php
 *
 * Every controller under app/controllers/admin/ is preceded by
 * app/controllers/admin/_middleware.php, which is what enforces the login.
 *
 * Controllers receive the remaining path segments in $params.
 */

function resolve_request(): void
{
    $path     = current_path();

    // Two well-known files carry a dot, which the slug rule below rejects, so
    // they are named here rather than loosening that rule for everything.
    $wellKnown = ['sitemap.xml' => 'sitemap', 'robots.txt' => 'robots'];
    if (isset($wellKnown[$path])) {
        dispatch(CONTROLLER_PATH . '/' . $wellKnown[$path] . '.php', []);
        return;
    }

    $segments = $path === '' ? [] : explode('/', $path);

    // A segment that is not a plain slug can never name a controller file.
    // This is what stops ../ and friends from reaching the filesystem.
    foreach ($segments as $segment) {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
            dispatch(CONTROLLER_PATH . '/_404.php', $segments);
            return;
        }
    }

    // --- home -------------------------------------------------------------
    if ($segments === []) {
        dispatch(CONTROLLER_PATH . '/home.php', []);
        return;
    }

    // --- admin area -------------------------------------------------------
    if ($segments[0] === 'admin') {
        $name = $segments[1] ?? 'index';
        $file = CONTROLLER_PATH . '/admin/' . $name . '.php';

        // _middleware and other underscore files are includes, not routes.
        if (str_starts_with($name, '_') || !is_file($file)) {
            dispatch(CONTROLLER_PATH . '/_404.php', $segments);
            return;
        }

        $params = array_slice($segments, 2);

        // The middleware needs to know which admin page was asked for; pass it
        // explicitly rather than letting the include read our locals.
        $GLOBALS['__admin_action'] = $name;
        require CONTROLLER_PATH . '/admin/_middleware.php';
        dispatch($file, $params);
        return;
    }

    // --- a top-level controller of that name ------------------------------
    $first = $segments[0];
    $file  = CONTROLLER_PATH . '/' . $first . '.php';

    if (!str_starts_with($first, '_') && is_file($file)) {
        dispatch($file, array_slice($segments, 1));
        return;
    }

    // --- otherwise it is a platform, a category or a page -----------------
    dispatch(CONTROLLER_PATH . '/_fallback.php', $segments);
}

/**
 * Run a controller file with its parameters.
 *
 * Controllers are plain PHP files rather than classes; they see $params and
 * the helper functions, nothing else.
 */
function dispatch(string $file, array $params): void
{
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found.';
        return;
    }
    require $file;
}
