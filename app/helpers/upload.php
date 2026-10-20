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

/** The widest an image for a page is kept: wider is bytes nobody's screen shows. */
const UPLOAD_MAX_WIDTH = 1600;

/** How hard WebP squeezes: 82 is where photos stop looking better and only grow. */
const UPLOAD_WEBP_QUALITY = 82;

/**
 * @param  string $input   the file input's name
 * @param  string $folder  under uploads/, e.g. 'branding'
 * @param  string $replace a path this one replaces, deleted when it is ours
 * @param  array{webp?: bool, share?: bool} $options
 *         webp:  turn a photo into WebP, no wider than UPLOAD_MAX_WIDTH - for
 *                images on pages, which are most of what a blog page weighs.
 *                Logos and favicons are not asked to: they are small already
 *                and some mail clients cannot show WebP.
 *         share: also write a 1200px JPEG beside it (same name, .jpg) for
 *                link previews, which some apps still cannot read as WebP.
 * @return array{ok: bool, path: string, error: string}
 */
function store_image_upload(string $input, string $folder, string $replace = '', array $options = []): array
{
    $webp = !empty($options['webp']) && upload_can_webp();
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
    // A photo that will be re-encoded may arrive bigger - it leaves a fraction
    // of the size - but one stored as it came is stored at its own weight.
    $limit = $webp ? 10 : 2;
    if ($file['size'] > $limit * 1024 * 1024) {
        return $fail('keep images under ' . $limit . ' MB.');
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

    $base = $input . '-' . bin2hex(random_bytes(6));
    $name = $base . '.' . $extension;

    // GIF is left alone: re-encoding one keeps its first frame and drops the
    // animation, which is the reason anyone uploads a GIF.
    $converted = $webp && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        && image_to_webp($file['tmp_name'], $info, $target . '/' . $base . '.webp');
    if ($converted) {
        $name = $base . '.webp';
        if (!empty($options['share'])) {
            image_to_jpeg($target . '/' . $name, $target . '/' . $base . '.jpg', 1200);
        }
    } elseif (!move_uploaded_file($file['tmp_name'], $target . '/' . $name)) {
        return $fail('could not write to uploads/' . $folder . '/. Check the folder is writable.');
    }
    @chmod($target . '/' . $name, 0644);

    // Only ours, and only from this folder: a path from anywhere else is not
    // something this function put there and not something it may delete.
    $prefix = 'uploads/' . $folder . '/';
    if ($replace !== '' && str_starts_with($replace, $prefix)
        && is_file(BASE_PATH . '/' . $replace)) {
        @unlink(BASE_PATH . '/' . $replace);
        $twin = image_share_path($replace);
        if ($twin !== $replace && is_file(BASE_PATH . '/' . $twin)) {
            @unlink(BASE_PATH . '/' . $twin);
        }
    }

    return ['ok' => true, 'path' => $prefix . $name, 'error' => ''];
}

/** Can this PHP write WebP at all? Without it images are kept as they came. */
function upload_can_webp(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatetruecolor')
        && !empty(gd_info()['WebP Support']);
}

/**
 * Re-encode an image as WebP, shrunk to UPLOAD_MAX_WIDTH, transparency kept.
 *
 * Refuses a picture too big to open in a shared host's memory rather than
 * dying halfway through: the caller then keeps the original, which is still
 * a working upload. A WebP that came out bigger than what was sent (a tiny,
 * already-optimised PNG can) is thrown away for the same reason.
 */
function image_to_webp(string $source, array $info, string $target): bool
{
    [$width, $height] = [(int) $info[0], (int) $info[1]];
    if ($width < 1 || $height < 1 || $width * $height > 40_000_000) {
        return false;
    }
    $image = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default        => false,
    };
    if (!$image) {
        return false;
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }
    // A phone photo is stored on its side with a note saying which way is up;
    // the re-encode would drop the note and leave it sideways.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $turn = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($source)['Orientation'] ?? 1)] ?? 0;
        if ($turn) {
            $rotated = imagerotate($image, $turn, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
                [$width, $height] = [imagesx($image), imagesy($image)];
            }
        }
    }
    if ($width > UPLOAD_MAX_WIDTH) {
        $newHeight = (int) round($height * UPLOAD_MAX_WIDTH / $width);
        $resized   = imagecreatetruecolor(UPLOAD_MAX_WIDTH, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, UPLOAD_MAX_WIDTH, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
        $width = UPLOAD_MAX_WIDTH;
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $written = imagewebp($image, $target, UPLOAD_WEBP_QUALITY);
    imagedestroy($image);

    if (!$written || !is_file($target)) {
        @unlink($target);
        return false;
    }
    $shrunk = $width < (int) $info[0];
    if (!$shrunk && $info[2] !== IMAGETYPE_WEBP && filesize($target) >= filesize($source)) {
        @unlink($target);
        return false;
    }
    return true;
}

/** A JPEG copy for link previews, on white where the original was clear. */
function image_to_jpeg(string $source, string $target, int $maxWidth): bool
{
    $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
    if (!$image) {
        return false;
    }
    [$width, $height] = [imagesx($image), imagesy($image)];
    $newWidth  = min($width, $maxWidth);
    $newHeight = (int) round($height * $newWidth / $width);
    $canvas    = imagecreatetruecolor($newWidth, $newHeight);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $written = imagejpeg($canvas, $target, 84);
    imagedestroy($image);
    imagedestroy($canvas);
    if ($written) {
        @chmod($target, 0644);
    }
    return (bool) $written;
}

/**
 * The image to hand a link preview: the JPEG twin of a WebP where one was
 * written, else the image itself.
 */
function image_share_path(string $path): string
{
    if (!str_ends_with($path, '.webp')) {
        return $path;
    }
    $twin = substr($path, 0, -5) . '.jpg';
    return is_file(BASE_PATH . '/' . $twin) ? $twin : $path;
}
