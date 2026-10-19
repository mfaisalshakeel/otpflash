<?php
/**
 * Admin markup that uses a class the admin stylesheet never defines.
 *
 *   php tools/check-admin-css.php
 *
 * A class with no rule behind it renders as nothing at all, which is invisible
 * in a diff and obvious on the screen. That is how the Notifications empty
 * state shipped as loose text beside its own box and the whole Themes screen
 * as an unstyled column.
 *
 * Exits non-zero when something is missing, so it can gate a release.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$css  = $root . '/assets/css/admin.css';

$views = array_merge(
    glob($root . '/app/views/admin/*.php') ?: [],
    glob($root . '/app/views/admin/**/*.php') ?: [],
    glob($root . '/app/views/layouts/admin*.php') ?: []
);

$styles = (string) file_get_contents($css);
// A view may carry its own <style> block; those rules count as defined too.
foreach ($views as $view) {
    if (preg_match_all('~<style[^>]*>(.*?)</style>~s', (string) file_get_contents($view), $blocks)) {
        $styles .= implode("\n", $blocks[1]);
    }
}
$styles = preg_replace('~/\*.*?\*/~s', '', $styles);

preg_match_all('/\.([a-zA-Z][\w-]*)/', (string) $styles, $found);
$defined = array_flip($found[1]);

$ignore = ['icon' => true];     // drawn by the sprite, not by a rule
$missing = [];

foreach ($views as $view) {
    $markup = (string) file_get_contents($view);
    if (!preg_match_all('/class="([^"]*)"/', $markup, $attributes)) {
        continue;
    }
    foreach ($attributes[1] as $attribute) {
        // Drop PHP interpolation but keep the literal words around it, so a
        // class attribute built as "alert alert-" plus an echoed variable
        // still checks "alert" and the prefix "alert-".
        //
        // The closing tag is not written out in this comment on purpose: PHP
        // ends its own mode at one even inside a // comment, which took the
        // rest of this file with it.
        $attribute = preg_replace('~<\?.*?\?>~s', ' ', $attribute);

        foreach (preg_split('/\s+/', (string) $attribute) as $name) {
            if ($name === '' || !preg_match('/^[a-zA-Z][\w-]*$/', $name)) {
                continue;
            }
            if (isset($defined[$name]) || isset($ignore[$name])) {
                continue;
            }
            // A trailing dash came from interpolation: satisfied by any rule
            // that starts with it.
            if (str_ends_with($name, '-')) {
                foreach ($defined as $known => $_) {
                    if (str_starts_with((string) $known, $name)) {
                        continue 2;
                    }
                }
            }
            $missing[$name][basename($view)] = true;
        }
    }
}

ksort($missing);
foreach ($missing as $name => $where) {
    printf("  .%-22s %s\n", $name, implode(', ', array_keys($where)));
}

echo $missing
    ? "\n" . count($missing) . " class(es) used in admin markup with no rule in assets/css/admin.css\n"
    : "every class in the admin markup has a rule behind it\n";

exit($missing ? 1 : 0);
