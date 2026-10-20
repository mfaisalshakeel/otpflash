<?php
/**
 * Images for the editor.
 *
 *   POST /admin/media/upload   one image -> {ok, url}
 *
 * The same rules as every other upload: only a real image, decided by what
 * GD can read, saved with an extension we chose into uploads/, where nothing
 * is ever executed.
 */
require_once APP_PATH . '/helpers/upload.php';
require_once APP_PATH . '/helpers/audit.php';

header('Content-Type: application/json; charset=utf-8');

if (($params[0] ?? '') !== 'upload' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

$stored = store_image_upload('file', 'content', '', ['webp' => true]);

if (!$stored['ok']) {
    echo json_encode(['ok' => false, 'error' => $stored['error']]);
    exit;
}

audit('media.uploaded', ['entity' => 'media', 'summary' => 'Image uploaded: ' . $stored['path']]);
echo json_encode(['ok' => true, 'url' => url($stored['path']), 'path' => $stored['path']]);
