<?php
/**
 * Blog posts.
 *
 *   GET  /admin/posts             the list
 *   GET  /admin/posts/new         a blank editor
 *   GET  /admin/posts/edit/7      the editor
 *   POST /admin/posts/save        create or update
 *   POST /admin/posts/delete      remove one
 *   POST /admin/posts/duplicate   copy one as a new draft
 *   GET  /admin/posts/import      the AI prompt and the CSV upload
 *   POST /admin/posts/import/check  read a CSV and show what it would do
 *   POST /admin/posts/import/save   write the rows that passed
 *
 * Written by hand rather than through the CRUD engine because a post is more
 * than its columns: a cover image, a publish time that can be in the future,
 * and an SEO checklist recounted from what was just typed.
 */
require_once APP_PATH . '/helpers/blog.php';
require_once APP_PATH . '/helpers/seo.php';
require_once APP_PATH . '/helpers/upload.php';
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/paging.php';

$action = $params[0] ?? 'index';

// ---------------------------------------------------------------- import --
if ($action === 'import') {
    require_once APP_PATH . '/helpers/blogcsv.php';
    // The import writes the image prompt, which arrives with a migration.
    if (!table_has_column('posts', 'image_prompt')) {
        flash('error', 'The CSV import arrives with the database update, which has not run yet. Run it here first.');
        redirect('admin/update');
    }

    $step    = $params[1] ?? '';
    $options = [
        'mode'        => in_array($_POST['mode'] ?? '', ['as_file', 'live', 'schedule', 'draft'], true) ? $_POST['mode'] : 'as_file',
        'start'       => trim((string) ($_POST['start'] ?? '')),
        'every_hours' => max(1, min(24 * 30, (int) ($_POST['every_hours'] ?? 24))),
        'update'      => !empty($_POST['update']),
    ];
    // The file is kept between the preview and the save, so what is saved is
    // exactly what was shown - checked again, since another import or an
    // edit may have taken a slug in between.
    $held = static function (string $token): string {
        return STORAGE_PATH . '/cache/blog-import-' . $token . '.csv';
    };
    foreach (glob(STORAGE_PATH . '/cache/blog-import-*.csv') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }

    if ($step === 'check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $text = trim((string) ($_POST['paste'] ?? ''));
        $file = $_FILES['csv'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            if ($file['size'] > 8 * 1024 * 1024) {
                flash('error', 'Keep a CSV under 8 MB - split a bigger batch in two.');
                redirect('admin/posts/import');
            }
            $text = (string) file_get_contents($file['tmp_name']);
        }
        if ($text === '') {
            flash('error', 'Choose a CSV file, or paste what the AI gave you into the box.');
            redirect('admin/posts/import');
        }
        // A spreadsheet saved by Excel on Windows is not UTF-8, and the first
        // accented letter would break every comparison after it.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $read = blog_csv_read($text);
        if ($read['error'] !== '') {
            flash('error', $read['error']);
            redirect('admin/posts/import');
        }
        $token = bin2hex(random_bytes(16));
        file_put_contents($held($token), $text, LOCK_EX);

        view('admin/post-import-check', [
            'title'    => 'Check the import',
            'subtitle' => 'Blog',
            'checked'  => blog_csv_check($read['rows'], $options),
            'unknown'  => $read['unknown'],
            'token'    => $token,
            'options'  => $options,
        ], 'layouts/admin');
    }

    if ($step === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($held($token))) {
            flash('error', 'That import has expired. Upload the file again.');
            redirect('admin/posts/import');
        }
        $read    = blog_csv_read((string) file_get_contents($held($token)));
        $checked = blog_csv_check($read['rows'], $options);
        $done    = blog_csv_save($checked);
        @unlink($held($token));

        $scheduled = count(array_filter($checked, static fn (array $item): bool => $item['action'] !== 'skip' && $item['state'] === 'scheduled'));
        audit('post.imported', ['entity' => 'posts', 'summary' => 'CSV import: ' . $done['created'] . ' created, '
            . $done['replaced'] . ' replaced, ' . $done['skipped'] . ' skipped']);
        flash('success', qty_fmt($done['created']) . ' post' . ($done['created'] === 1 ? '' : 's') . ' added'
            . ($done['replaced'] ? ', ' . $done['replaced'] . ' replaced' : '')
            . ($scheduled ? ' (' . $scheduled . ' scheduled)' : '')
            . ($done['skipped'] ? '. ' . $done['skipped'] . ' left out, for the reasons the check screen gave.' : '.')
            . ' Open each one to add its cover: the image prompt is waiting beside it.');
        redirect('admin/posts');
    }

    // The prompt's options come in the query, so the form works without the
    // script and the script only asks for the text again (step "prompt").
    $ask = [
        'count'       => max(1, min(30, (int) ($_GET['count'] ?? 5))),
        'mode'        => in_array($_GET['mode'] ?? '', ['schedule', 'live', 'draft'], true) ? $_GET['mode'] : 'schedule',
        'start'       => trim((string) ($_GET['start'] ?? '')) ?: date('Y-m-d', strtotime('+1 day')) . ' 10:00',
        'every_hours' => max(1, min(24 * 30, (int) ($_GET['every_hours'] ?? 24))),
        'audience'    => mb_substr(trim((string) ($_GET['audience'] ?? '')), 0, 200) ?: 'Pakistan first, and English-speaking readers worldwide',
        'language'    => mb_substr(trim((string) ($_GET['language'] ?? '')), 0, 60) ?: 'English',
        'topics'      => mb_substr(trim((string) ($_GET['topics'] ?? '')), 0, 2000),
    ];
    $ask['start'] = date('Y-m-d H:i', strtotime(str_replace('T', ' ', $ask['start'])) ?: strtotime('tomorrow 10:00'));
    $prompt = blog_prompt_fill(blog_prompt_template(), $ask);

    if ($step === 'prompt') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $prompt;
        exit;
    }

    view('admin/post-import', [
        'title'    => 'Import posts',
        'subtitle' => 'Blog',
        'prompt'   => $prompt,
        'ask'      => $ask,
        'posts'    => (int) col('SELECT COUNT(*) FROM posts', [], 0),
    ], 'layouts/admin');
}

/** The categories for the select, id => name. */
$categories = static fn (): array => array_column(all('SELECT id, name FROM post_categories ORDER BY sort_order, name'), 'name', 'id');

// ------------------------------------------------------------------ save --
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $before = $id ? one('SELECT * FROM posts WHERE id = ?', [$id]) : null;
    if ($id && !$before) {
        flash('error', 'That post no longer exists.');
        redirect('admin/posts');
    }

    $text   = static fn (string $name, int $max = 0): string => $max
        ? mb_substr(trim((string) ($_POST[$name] ?? '')), 0, $max) : trim((string) ($_POST[$name] ?? ''));
    $errors = [];

    $title = $text('title', 190);
    if ($title === '') {
        $errors['title'] = 'Give the post a title.';
    }

    $slug = slugify($text('slug') !== '' ? $text('slug') : $title);
    $slug = mb_substr($slug, 0, 120);
    if ($slug === '') {
        $errors['slug'] = 'The address needs at least one letter or number.';
    } elseif (col('SELECT 1 FROM posts WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $errors['slug'] = 'Another post already lives at /blog/' . $slug . '.';
    }

    $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

    // The time the admin typed is local wall-clock time, which is the same
    // clock everything else here is written in.
    $when = $text('published_at');
    $publishedAt = $before['published_at'] ?? null;
    if ($when !== '') {
        $stamp = strtotime($when);
        if ($stamp === false) {
            $errors['published_at'] = 'That date could not be read.';
        } else {
            $publishedAt = date('Y-m-d H:i:s', $stamp);
        }
    } elseif ($status === 'published' && !$publishedAt) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if ($categoryId && !isset($categories()[$categoryId])) {
        $categoryId = 0;
    }

    $data = [
        'title'            => $title,
        'slug'             => $slug,
        'category_id'      => $categoryId ?: null,
        'excerpt'          => $text('excerpt', 400),
        // Admin-written HTML, printed as written on the post page - the same
        // trust as page content and head code, and for the same reason.
        'content'          => (string) ($_POST['content'] ?? ''),
        'meta_title'       => $text('meta_title', 190),
        'meta_description' => $text('meta_description', 300),
        'focus_keyword'    => $text('focus_keyword', 120),
        'tags'             => implode(',', post_tags($text('tags', 500))),
        'author_name'      => $text('author_name', 120),
        'status'           => $status,
        'is_featured'      => isset($_POST['is_featured']) ? 1 : 0,
        'noindex'          => isset($_POST['noindex']) ? 1 : 0,
        'published_at'     => $publishedAt,
        'updated_at'       => date('Y-m-d H:i:s'),
    ];
    // Columns that arrive with a migration are written only once they exist:
    // the editor has to save on a database that is behind the code.
    if (table_has_column('posts', 'image_prompt')) {
        $data['image_prompt'] = $text('image_prompt', 4000);
        $data['image_alt']    = $text('image_alt', 190);
    }

    if (!empty($_FILES['cover']['name'])) {
        $stored = store_image_upload('cover', 'content', (string) ($before['cover_path'] ?? ''), ['webp' => true, 'share' => true]);
        if ($stored['ok']) {
            $data['cover_path'] = $stored['path'];
        } else {
            $errors['cover'] = 'Cover image: ' . $stored['error'];
        }
    } elseif (isset($_POST['remove_cover'])) {
        $data['cover_path'] = '';
    }

    if ($errors) {
        keep_old($_POST);
        $_SESSION['_post_errors'] = $errors;
        flash('error', reset($errors));
        redirect($id ? 'admin/posts/edit/' . $id : 'admin/posts/new');
    }

    if ($id) {
        update_row('posts', $data, 'id = ?', [$id]);
        audit_row('post.updated', 'posts', $id, $before, ['summary' => 'Post "' . $title . '" saved']);
    } else {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['cover_path'] = $data['cover_path'] ?? '';
        $id = insert_row('posts', $data);
        audit('post.created', ['entity' => 'posts', 'entity_id' => $id, 'summary' => 'Post "' . $title . '" created']);
    }

    $state = post_state($data);
    flash('success', match ($state) {
        'published' => 'Saved and live.',
        'scheduled' => 'Saved. It goes live ' . when($publishedAt, 'd M Y, H:i') . '.',
        default     => 'Saved as a draft.',
    });
    redirect('admin/posts/edit/' . $id);
}

// ---------------------------------------------------------------- delete --
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['id'] ?? 0);
    $post = one('SELECT * FROM posts WHERE id = ?', [$id]);
    if ($post) {
        delete_row('posts', 'id = ?', [$id]);
        audit('post.deleted', ['entity' => 'posts', 'entity_id' => $id, 'severity' => 'warn',
            'summary' => 'Post "' . $post['title'] . '" deleted', 'before' => $post]);
        flash('success', 'Deleted.');
    }
    redirect('admin/posts');
}

// ------------------------------------------------------------- duplicate --
if ($action === 'duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = one('SELECT * FROM posts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if (!$post) {
        redirect('admin/posts');
    }
    $slug = $post['slug'] . '-copy';
    for ($n = 2; col('SELECT 1 FROM posts WHERE slug = ?', [$slug]); $n++) {
        $slug = $post['slug'] . '-copy-' . $n;
    }
    unset($post['id']);
    $id = insert_row('posts', array_merge($post, [
        'slug' => $slug, 'title' => $post['title'] . ' (copy)', 'status' => 'draft', 'views' => 0,
        'published_at' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]));
    flash('success', 'Copied as a draft.');
    redirect('admin/posts/edit/' . $id);
}

// ---------------------------------------------------------------- editor --
if ($action === 'new' || ($action === 'edit' && ctype_digit((string) ($params[1] ?? '')))) {
    $post = $action === 'edit' ? one('SELECT * FROM posts WHERE id = ?', [(int) $params[1]]) : null;
    if ($action === 'edit' && !$post) {
        flash('error', 'That post no longer exists.');
        redirect('admin/posts');
    }
    $post ??= ['id' => 0, 'title' => '', 'slug' => '', 'category_id' => null, 'excerpt' => '', 'content' => '',
               'cover_path' => '', 'meta_title' => '', 'meta_description' => '', 'focus_keyword' => '',
               'tags' => '', 'status' => 'draft', 'is_featured' => 0, 'noindex' => 0,
               'author_name' => '', 'views' => 0, 'published_at' => null]
             + (table_has_column('posts', 'image_prompt') ? ['image_prompt' => '', 'image_alt' => ''] : []);

    $errors = $_SESSION['_post_errors'] ?? [];
    unset($_SESSION['_post_errors']);

    // What was just typed wins over the row, so a refused save comes back
    // with the words still in it.
    foreach (['title', 'slug', 'excerpt', 'content', 'meta_title', 'meta_description', 'focus_keyword',
              'tags', 'author_name', 'status', 'image_prompt', 'image_alt'] as $name) {
        if (array_key_exists($name, $post)) {
            $post[$name] = old($name, (string) $post[$name]);
        }
    }

    view('admin/post-form', [
        'title'      => $post['id'] ? 'Edit post' : 'New post',
        'subtitle'   => 'Blog',
        'post'       => $post,
        'errors'     => $errors,
        'categories' => $categories(),
        'checks'     => seo_checklist((string) $post['title'], (string) $post['meta_title'], (string) $post['meta_description'],
                                      (string) $post['content'], (string) $post['focus_keyword'], (string) $post['slug']),
        'state'      => $post['id'] ? post_state($post) : 'draft',
    ], 'layouts/admin');
}

// ------------------------------------------------------------------ list --
$filters = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'state'    => (string) ($_GET['state'] ?? ''),
    'category' => (string) ($_GET['category'] ?? ''),
];
$where = [];
$binds = [];
if ($filters['q'] !== '') {
    $where[] = '(p.title LIKE ? OR p.slug LIKE ? OR p.tags LIKE ?)';
    array_push($binds, ...array_fill(0, 3, '%' . $filters['q'] . '%'));
}
$now = date('Y-m-d H:i:s');
if ($filters['state'] === 'draft') {
    $where[] = "p.status <> 'published'";
} elseif ($filters['state'] === 'published') {
    $where[] = "p.status = 'published' AND p.published_at <= ?";
    $binds[] = $now;
} elseif ($filters['state'] === 'scheduled') {
    $where[] = "p.status = 'published' AND p.published_at > ?";
    $binds[] = $now;
}
if (ctype_digit($filters['category'])) {
    $where[] = 'p.category_id = ?';
    $binds[] = (int) $filters['category'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$paging = paging((int) col("SELECT COUNT(*) FROM posts p $whereSql", $binds, 0));
$posts  = all(
    "SELECT p.id, p.slug, p.title, p.status, p.published_at, p.updated_at, p.created_at, p.views, p.is_featured,
            p.noindex, p.cover_path, p.focus_keyword, c.name AS category_name
       FROM posts p LEFT JOIN post_categories c ON c.id = p.category_id
       $whereSql
   ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
      LIMIT {$paging['per']} OFFSET {$paging['offset']}",
    $binds
);

[$live, $liveBinds] = post_live_sql('');
view('admin/posts', [
    'title'      => 'Blog posts',
    'subtitle'   => qty_fmt((int) col("SELECT COUNT(*) FROM posts WHERE $live", $liveBinds, 0)) . ' live',
    'posts'      => $posts,
    'filters'    => $filters,
    'paging'     => $paging,
    'categories' => $categories(),
], 'layouts/admin');
