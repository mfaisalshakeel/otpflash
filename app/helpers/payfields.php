<?php
/**
 * The questions a manual payment method asks the customer.
 *
 * An admin builds the list - "Transaction ID", "Sender name", "Screenshot" -
 * and the payment page renders it. Two rules keep that safe:
 *
 *   1. The browser never says what the fields are. Every answer is matched
 *      against the definition stored on the method, and anything not in it is
 *      dropped. A posted field nobody defined does not exist.
 *   2. An uploaded file is accepted on what it contains, not on what it is
 *      called, and it is written where the web server will not serve it.
 *      A payment screenshot carries a bank balance and a name; a guessable
 *      URL under uploads/ would hand that to anyone who tried.
 */

/** The types an admin may choose, and how each one behaves. */
function payfield_types(): array
{
    return [
        'text'     => ['label' => 'Short text',        'input' => 'text'],
        'number'   => ['label' => 'Number',            'input' => 'number'],
        'email'    => ['label' => 'Email address',     'input' => 'email'],
        'tel'      => ['label' => 'Phone number',      'input' => 'tel'],
        'textarea' => ['label' => 'Long text',         'input' => 'textarea'],
        'select'   => ['label' => 'Pick from a list',  'input' => 'select'],
        'image'    => ['label' => 'Image upload (screenshot)', 'input' => 'file'],
    ];
}

/** Longest a typed answer may be, per type. */
const PAYFIELD_MAX = ['text' => 190, 'number' => 32, 'email' => 190, 'tel' => 40,
                      'textarea' => 2000, 'select' => 190];

/** Biggest screenshot we accept. */
const PAYFIELD_IMAGE_BYTES = 4 * 1024 * 1024;

/** Where proofs are kept: under storage/, which .htaccess denies outright. */
function payfield_store(): string
{
    return BASE_PATH . '/storage/payment-proof';
}

/**
 * What this method asks for.
 *
 * A method that has never been given any fields keeps the behaviour it had
 * before they existed - one transaction id - so an install that upgrades into
 * this feature does not suddenly ask its customers nothing.
 */
function payfields(array $method): array
{
    $config = json_decode((string) ($method['config'] ?? ''), true);
    $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];

    $clean = [];
    foreach ($fields as $field) {
        $field = payfield_clean(is_array($field) ? $field : []);
        if ($field !== null) {
            $clean[$field['key']] = $field;
        }
    }

    if (!$clean && (string) ($method['driver'] ?? 'manual') === 'manual') {
        $clean['trx_id'] = payfield_clean([
            'key'      => 'trx_id',
            'label'    => 'Transaction ID',
            'type'     => 'text',
            'required' => setting('require_trx_id', '1') === '1',
            'hint'     => 'The reference from your payment receipt.',
            'reference'=> true,
        ]);
    }

    return $clean;
}

/**
 * Put one definition into a shape the rest of the code can rely on.
 *
 * Returns null for anything unusable, so a half-filled row in the builder is
 * simply not a field rather than a field with no label.
 */
function payfield_clean(array $field): ?array
{
    $label = trim((string) ($field['label'] ?? ''));
    if ($label === '') {
        return null;
    }

    $type = (string) ($field['type'] ?? 'text');
    if (!isset(payfield_types()[$type])) {
        $type = 'text';
    }

    // The key is ours, derived from the label, never taken from the browser:
    // it ends up in an array index and in a filename, and a key the customer
    // could choose is a key that could be "../".
    $key = (string) ($field['key'] ?? '');
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', $key)));
    if ($key === '') {
        $key = preg_replace('/[^a-z0-9_]/', '',
            strtolower(str_replace([' ', '-'], '_', $label)));
    }
    $key = trim((string) $key, '_');
    if ($key === '' || is_numeric($key[0])) {
        $key = 'f' . substr(md5($label), 0, 8);
    }

    // Options arrive two ways: a textarea from the builder, and an array when
    // a stored field is read back and cleaned again. Casting the second to a
    // string turns the whole list into the word "Array".
    $raw = $field['options'] ?? '';
    $lines = is_array($raw) ? $raw : (preg_split('/\r\n|\r|\n/', (string) $raw) ?: []);

    $options = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $options[] = mb_substr($line, 0, 120);
        }
    }

    return [
        'key'       => mb_substr($key, 0, 40),
        'label'     => mb_substr($label, 0, 120),
        'type'      => $type,
        'hint'      => mb_substr(trim((string) ($field['hint'] ?? '')), 0, 190),
        'required'  => !empty($field['required']),
        'reference' => !empty($field['reference']),
        'options'   => $type === 'select' ? $options : [],
    ];
}

/**
 * Read the form against the definition.
 *
 * @return array{ok: bool, errors: array<string,string>, answers: array, reference: string}
 */
function payfields_collect(array $method, array $post, array $files, string $orderCode): array
{
    $fields    = payfields($method);
    $errors    = [];
    $answers   = [];
    $reference = '';

    foreach ($fields as $key => $field) {
        if ($field['type'] === 'image') {
            [$stored, $error] = payfield_take_image($files['pf_' . $key] ?? null, $field, $orderCode);
            if ($error !== '') {
                $errors[$key] = $error;
            } elseif ($stored !== '') {
                $answers[$key] = ['label' => $field['label'], 'type' => 'image', 'value' => $stored];
            }
            continue;
        }

        $value = trim((string) ($post['pf_' . $key] ?? ''));
        $max   = PAYFIELD_MAX[$field['type']] ?? 190;

        if ($value === '') {
            if ($field['required']) {
                $errors[$key] = $field['label'] . ' is needed.';
            }
            continue;
        }
        if (mb_strlen($value) > $max) {
            $errors[$key] = $field['label'] . ' is too long (max ' . $max . ' characters).';
            continue;
        }
        if ($field['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[$key] = $field['label'] . ' does not look like an email address.';
            continue;
        }
        if ($field['type'] === 'number' && !is_numeric(str_replace([',', ' '], '', $value))) {
            $errors[$key] = $field['label'] . ' should be a number.';
            continue;
        }
        // A select may only answer with something it offered. Otherwise the
        // list is a suggestion and the field holds whatever was posted.
        if ($field['type'] === 'select' && $field['options']
            && !in_array($value, $field['options'], true)) {
            $errors[$key] = 'Choose one of the options for ' . $field['label'] . '.';
            continue;
        }

        $answers[$key] = ['label' => $field['label'], 'type' => $field['type'], 'value' => $value];

        if ($field['reference'] && $reference === '') {
            $reference = $value;
        }
    }

    // Nothing marked as the reference: the first text-ish answer stands in, so
    // the orders list still has something to show.
    if ($reference === '') {
        foreach ($answers as $answer) {
            if ($answer['type'] !== 'image') {
                $reference = (string) $answer['value'];
                break;
            }
        }
    }

    // A refused submission leaves nothing behind. The image has to be written
    // before the rest of the form is read - that is while the upload still
    // exists as a temporary file - so a failure anywhere means taking it back
    // out, or every retry adds an orphan nobody will look at or delete.
    if ($errors) {
        foreach ($answers as $answer) {
            if (($answer['type'] ?? '') === 'image') {
                $path = payfield_image_path((string) $answer['value']);
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }
        $answers = [];
    }

    return ['ok' => !$errors, 'errors' => $errors, 'answers' => $answers,
            'reference' => mb_substr($reference, 0, 120)];
}

/**
 * Accept a screenshot, or say why not.
 *
 * getimagesize() is the test, not the name and not the browser's content
 * type: both are written by whoever is uploading. The extension we save with
 * is the one we chose from what GD actually read.
 *
 * @return array{0: string, 1: string}  [stored path, error]
 */
function payfield_take_image($file, array $field, string $orderCode): array
{
    $missing = !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;

    if ($missing) {
        return ['', $field['required'] ? $field['label'] . ' is needed.' : ''];
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['', $field['label'] . ' did not upload. Try again, or use a smaller image.'];
    }
    if (($file['size'] ?? 0) > PAYFIELD_IMAGE_BYTES) {
        return ['', $field['label'] . ' is over ' . (PAYFIELD_IMAGE_BYTES / 1024 / 1024) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['', 'That upload could not be verified.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['', $field['label'] . ' has to be an image.'];
    }

    $allowed = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $extension = $allowed[$info[2]] ?? null;
    if ($extension === null) {
        return ['', 'Use a PNG, JPG, GIF or WebP image for ' . $field['label'] . '.'];
    }

    $folder = payfield_store();
    if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
        return ['', 'Could not save the image. Check storage/ is writable.'];
    }

    // The name carries no customer input at all: order code (ours), field key
    // (ours) and random bytes.
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', $orderCode) . '-' . $field['key']
          . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
        return ['', 'Could not save the image. Check storage/ is writable.'];
    }
    @chmod($folder . '/' . $name, 0644);

    return [$name, ''];
}

/** The answers stored on an order, ready to show. */
function payfields_stored(array $order): array
{
    $answers = json_decode((string) ($order['payment_details'] ?? ''), true);
    return is_array($answers) ? $answers : [];
}

/**
 * The file behind one stored image answer.
 *
 * basename() because the stored value is the only part of this that has ever
 * been near a request; everything else is built here.
 */
function payfield_image_path(string $stored): ?string
{
    $name = basename($stored);
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        return null;
    }
    $path = payfield_store() . '/' . $name;

    return is_file($path) ? $path : null;
}
