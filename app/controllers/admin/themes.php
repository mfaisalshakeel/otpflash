<?php
/** Installed front-site themes, and which one is live. */

$action = $params[0] ?? 'index';

if ($action === 'activate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug      = (string) ($_POST['theme'] ?? '');
    $available = themes_available(true);

    if (!isset($available[$slug])) {
        flash('error', 'That theme is not installed.');
        redirect('admin/themes');
    }

    $wasBrand = theme_brand(active_theme());
    $nowBrand = theme_brand($slug);
    $adopted  = false;

    set_setting('active_theme', $slug);

    // A theme is drawn around one accent, so a new one should look like
    // itself straight away. Only replace a colour that is still the old
    // theme's own: anything the admin chose themselves is left alone.
    if ($nowBrand !== '' && $nowBrand !== $wasBrand
        && strcasecmp((string) setting('theme_color', ''), $wasBrand) === 0) {
        set_setting('theme_color', $nowBrand);
        $adopted = true;
    }

    flash('success', $available[$slug]['name'] . ' is now live on the front site.'
        . ($adopted ? ' Its own colour has been applied - change it in Settings.' : ''));
    redirect('admin/themes');
}

view('admin/themes', [
    'title'    => 'Themes',
    'subtitle' => 'How the front site looks',
    'themes'   => themes_available(true),
    'active'   => active_theme(),
], 'layouts/admin');
