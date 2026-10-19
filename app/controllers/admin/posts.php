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

    if (!empty($_FILES['cover']['name'])) {
        $stored = store_image_upload('cover', 'content', (string) ($before['cover_path'] ?? ''));
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
               'author_name' => '', 'views' => 0, 'published_at' => null];

    $errors = $_SESSION['_post_errors'] ?? [];
    unset($_SESSION['_post_errors']);

    // What was just typed wins over the row, so a refused save comes back
    // with the words still in it.
    foreach (['title', 'slug', 'excerpt', 'content', 'meta_title', 'meta_description', 'focus_keyword',
              'tags', 'author_name', 'status'] as $name) {
        $post[$name] = old($name, $post[$name]);
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
