<?php
/**
 * Front-site themes.
 *
 * A theme is a folder under themes/ with a theme.php manifest beside a views/
 * and an assets/ folder. Dropping one in is all it takes - nothing here keeps
 * a list, so adding a theme never means editing a file that already exists.
 *
 *   themes/
 *     default/
 *       theme.php          name, description, author, version, screenshot
 *       views/             overrides for anything under app/views
 *       assets/            css, js, images, served as themes/default/assets/...
 *
 * A theme only ships what it changes. render() looks in the active theme
 * first, then the default theme, then app/views - so a theme that only wants
 * a different home page writes one file.
 *
 * Admin screens are never themed; they live in app/views/admin and resolve
 * through the last fallback.
 */

const THEME_FALLBACK = 'default';

/** Every installed theme, keyed by folder name. */
function themes_available(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }

    $cache = [];
    $root  = BASE_PATH . '/themes';

    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);

        // A folder name has to be safe to put in a path and a URL.
        if (!preg_match('/^[a-z0-9_-]+$/', $slug) || !is_file($dir . '/theme.php')) {
            continue;
        }

        $manifest = require $dir . '/theme.php';
        if (!is_array($manifest)) {
            continue;
        }

        $cache[$slug] = $manifest + [
            'slug'        => $slug,
            'name'        => $slug,
            'description' => '',
            'author'      => '',
            'version'     => '',
            'brand'       => '',
            'screenshot'  => is_file($dir . '/screenshot.png') ? 'screenshot.png' : '',
        ];
    }

    ksort($cache);
    return $cache;
}

/** The theme in use. Falls back when the saved one has been deleted. */
function active_theme(): string
{
    static $slug = null;
    if ($slug !== null) {
        return $slug;
    }

    $saved     = (string) setting('active_theme', THEME_FALLBACK);
    $available = themes_available();

    if (isset($available[$saved])) {
        return $slug = $saved;
    }
    if (isset($available[THEME_FALLBACK])) {
        return $slug = THEME_FALLBACK;
    }

    // No themes at all: let render() fall through to app/views.
    return $slug = $available ? array_key_first($available) : THEME_FALLBACK;
}

/**
 * Where to look for a view, best first.
 *
 * @return string[] absolute directories
 */
function theme_view_paths(): array
{
    $paths  = [];
    $active = active_theme();

    $paths[] = BASE_PATH . '/themes/' . $active . '/views';
    if ($active !== THEME_FALLBACK) {
        $paths[] = BASE_PATH . '/themes/' . THEME_FALLBACK . '/views';
    }
    $paths[] = VIEW_PATH;

    return $paths;
}

/**
 * URL for a file in the active theme's assets folder.
 *
 * Falls back to the default theme, so a theme that does not ship its own
 * app.js still gets one.
 */
function theme_asset(string $file): string
{
    $file   = ltrim($file, '/');
    $active = active_theme();

    foreach (array_unique([$active, THEME_FALLBACK]) as $slug) {
        $path = 'themes/' . $slug . '/assets/' . $file;
        if (is_file(BASE_PATH . '/' . $path)) {
            return asset($path);
        }
    }

    return url('themes/' . $active . '/assets/' . $file);
}

/**
 * The colour a theme was designed around, if it says.
 *
 * A theme is drawn to suit one accent, and the site has one accent setting.
 * Switching theme therefore adopts the new theme's colour - but only while
 * the colour on record is still the old theme's own, so a colour the admin
 * chose is never overwritten. See controllers/admin/themes.php.
 */
function theme_brand(string $slug): string
{
    $brand = (string) (themes_available()[$slug]['brand'] ?? '');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $brand) ? $brand : '';
}

/** URL for a theme's screenshot, or '' when it has none. */
function theme_screenshot(array $theme): string
{
    if ($theme['screenshot'] === '') {
        return '';
    }
    $path = 'themes/' . $theme['slug'] . '/' . ltrim($theme['screenshot'], '/');
    return is_file(BASE_PATH . '/' . $path) ? url($path) : '';
}
