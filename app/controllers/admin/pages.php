<?php
/**
 * Content pages, reachable at /{slug}: About, the policies, anything else.
 *
 *   GET  /admin/pages            the list
 *   GET  /admin/pages/new        a blank editor
 *   GET  /admin/pages/edit/3     the editor
 *   POST /admin/pages/save       create or update
 *   POST /admin/pages/delete     remove one
 *   POST /admin/pages/toggle     publish or hide
 *
 * By hand rather than through the CRUD engine for the same reasons as posts:
 * the editor, a share image, and the SEO box. A page's slug sits at the top
 * of the site, so it must not take a word the shop already uses for itself.
 */
require_once APP_PATH . '/helpers/seo.php';
require_once APP_PATH . '/helpers/upload.php';
require_once APP_PATH . '/helpers/audit.php';

$action = $params[0] ?? 'index';

/** First words of the site's own addresses, which a page may not borrow. */
$reserved = ['admin', 'api', 'account', 'order', 'track', 'blog', 'faq', 'contact', 'login', 'logout',
    'register', 'forgot', 'reset', 'country', 'temporary-email', 'rent-number', 'sitemap', 'robots', 'hook', 'cron',
    'install', 'assets', 'uploads', 'themes', 'feed'];

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $before = $id ? one('SELECT * FROM pages WHERE id = ?', [$id]) : null;
    $text   = static fn (string $name, int $max): string => mb_substr(trim((string) ($_POST[$name] ?? '')), 0, $max);
    $errors = [];

    $title = $text('title', 190);
    if ($title === '') {
        $errors['title'] = 'Give the page a title.';
    }
    $slug = slugify($text('slug', 120) !== '' ? $text('slug', 120) : $title);
    if ($slug === '') {
        $errors['slug'] = 'The address needs at least one letter or number.';
    } elseif (in_array($slug, $reserved, true)) {
        $errors['slug'] = '/' . $slug . ' is one of the shop\'s own addresses. Pick another.';
    } elseif (col('SELECT 1 FROM pages WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $errors['slug'] = 'Another page already lives at /' . $slug . '.';
    } elseif (col('SELECT 1 FROM services WHERE slug = ?', [$slug])) {
        // A page is looked up before a service, so this one would hide the
        // service's landing page - say so instead of silently winning.
        $errors['slug'] = '/' . $slug . ' is a service\'s landing page. Pick another.';
    }

    $data = [
        'title'            => $title,
        'slug'             => $slug,
        // Admin-written HTML, printed as written - deliberate, like head code.
        'content'          => (string) ($_POST['content'] ?? ''),
        'meta_title'       => $text('meta_title', 190),
        'meta_description' => $text('meta_description', 300),
        'noindex'          => isset($_POST['noindex']) ? 1 : 0,
        'show_in_footer'   => isset($_POST['show_in_footer']) ? 1 : 0,
        'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        'sort_order'       => (int) ($_POST['sort_order'] ?? 0),
    ];
    if (!empty($_FILES['og_image']['name'])) {
        $stored = store_image_upload('og_image', 'content', (string) ($before['og_image'] ?? ''));
        if ($stored['ok']) {
            $data['og_image'] = $stored['path'];
        } else {
            $errors['og_image'] = 'Share image: ' . $stored['error'];
        }
    } elseif (isset($_POST['remove_og_image'])) {
        $data['og_image'] = '';
    }

    if ($errors) {
        keep_old($_POST);
        $_SESSION['_page_errors'] = $errors;
        flash('error', reset($errors));
        redirect($id ? 'admin/pages/edit/' . $id : 'admin/pages/new');
    }

    if ($before) {
        update_row('pages', $data, 'id = ?', [$id]);
        audit_row('page.updated', 'pages', $id, $before, ['summary' => 'Page "' . $title . '" saved']);
    } else {
        $id = insert_row('pages', $data + ['og_image' => '']);
        audit('page.created', ['entity' => 'pages', 'entity_id' => $id, 'summary' => 'Page "' . $title . '" created']);
    }
    flash('success', $data['is_active'] ? 'Saved and live.' : 'Saved (hidden).');
    redirect('admin/pages/edit/' . $id);
}

if (in_array($action, ['delete', 'toggle'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['id'] ?? 0);
    $page = one('SELECT * FROM pages WHERE id = ?', [$id]);
    if ($page && $action === 'delete') {
        delete_row('pages', 'id = ?', [$id]);
        audit('page.deleted', ['entity' => 'pages', 'entity_id' => $id, 'severity' => 'warn',
            'summary' => 'Page "' . $page['title'] . '" deleted', 'before' => $page]);
        flash('success', 'Deleted.');
    } elseif ($page) {
        update_row('pages', ['is_active' => $page['is_active'] ? 0 : 1], 'id = ?', [$id]);
        flash('success', $page['is_active'] ? 'Hidden.' : 'Published.');
    }
    redirect('admin/pages');
}

if ($action === 'new' || ($action === 'edit' && ctype_digit((string) ($params[1] ?? '')))) {
    $page = $action === 'edit' ? one('SELECT * FROM pages WHERE id = ?', [(int) $params[1]]) : null;
    if ($action === 'edit' && !$page) {
        flash('error', 'That page no longer exists.');
        redirect('admin/pages');
    }
    $page ??= ['id' => 0, 'title' => '', 'slug' => '', 'content' => '', 'meta_title' => '', 'meta_description' => '',
               'og_image' => '', 'noindex' => 0, 'show_in_footer' => 1, 'sort_order' => 0, 'is_active' => 1];
    foreach (['title', 'slug', 'content', 'meta_title', 'meta_description'] as $name) {
        $page[$name] = old($name, $page[$name]);
    }
    $errors = $_SESSION['_page_errors'] ?? [];
    unset($_SESSION['_page_errors']);

    view('admin/page-form', [
        'title'    => $page['id'] ? 'Edit page' : 'New page',
        'subtitle' => 'Pages',
        'page'     => $page,
        'errors'   => $errors,
        'checks'   => seo_checklist((string) $page['title'], (string) $page['meta_title'],
                          (string) $page['meta_description'], (string) $page['content'], '', (string) $page['slug']),
    ], 'layouts/admin');
}

require_once APP_PATH . '/helpers/paging.php';
$paging = paging((int) col('SELECT COUNT(*) FROM pages', [], 0));
view('admin/pages', [
    'title'    => 'Pages',
    'subtitle' => 'About, policies and anything else - each lives at /its-address',
    'pages'    => all('SELECT * FROM pages ORDER BY sort_order, id LIMIT ' . $paging['per'] . ' OFFSET ' . $paging['offset']),
    'paging'   => $paging,
], 'layouts/admin');
