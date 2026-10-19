<?php
/**
 * Saving an uploaded image.
 *
 * Shared because two screens take one: branding on Settings, and a logo on
 * each payment method. The rules are the same wherever it is used - only a
 * real image, decided by what GD can read rather than by the name or the
 * type the browser claimed, written with an extension we chose, into a
 * folder where uploads/.htaccess has turned the PHP engine off.
 *
 * The result says which it is. Returning the path on success and the message
 * on failure, both as plain strings, is how the logo once ended up set to
 * "That file is not an image." - the caller could not tell them apart.
 */

/** Image types we accept, and the extension each is written with. */
const UPLOAD_IMAGE_TYPES = [
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
    IMAGETYPE_ICO  => 'ico',
];

/**
 * @param  string $input   the file input's name
 * @param  string $folder  under uploads/, e.g. 'branding'
 * @param  string $replace a path this one replaces, deleted when it is ours
 * @return array{ok: bool, path: string, error: string}
 */
function store_image_upload(string $input, string $folder, string $replace = ''): array
{
    $fail = static fn(string $why): array => ['ok' => false, 'path' => '', 'error' => $why];
    $file = $_FILES[$input] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        // The two worth naming: the rest are rare and the number is enough.
        return $fail(match ((int) ($file['error'] ?? -1)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'that file is larger than this server accepts.',
            UPLOAD_ERR_NO_FILE => 'no file came through.',
            default => 'the file did not upload (error ' . ($file['error'] ?? '?') . ').',
        });
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return $fail('keep images under 2 MB.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('that upload could not be verified.');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return $fail('that file is not an image, whatever it is named.');
    }

    $extension = UPLOAD_IMAGE_TYPES[$info[2]] ?? null;
    if ($extension === null) {
        return $fail('use a PNG, JPG, GIF, WebP or ICO image.');
    }

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder);
    $target = UPLOAD_PATH . '/' . $folder;

    if (!is_dir($target) && !@mkdir($target, 0755, true)) {
        return $fail('uploads/' . $folder . '/ does not exist and could not be created.');
    }

    $name = $input . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], $target . '/' . $name)) {
        return $fail('could not write to uploads/' . $folder . '/. Check the folder is writable.');
    }
    @chmod($target . '/' . $name, 0644);

    // Only ours, and only from this folder: a path from anywhere else is not
    // something this function put there and not something it may delete.
    $prefix = 'uploads/' . $folder . '/';
    if ($replace !== '' && str_starts_with($replace, $prefix)
        && is_file(BASE_PATH . '/' . $replace)) {
        @unlink(BASE_PATH . '/' . $replace);
    }

    return ['ok' => true, 'path' => $prefix . $name, 'error' => ''];
}
