<?php
/**
 * /track - find an order by its code.
 *
 * With a code it goes straight to the order page, which is the number and
 * its code. Without one, or with one that matches nothing, it is a page of
 * its own with the box and a plain answer - never a bounce to another page
 * the customer has to work out.
 *
 * The code is read from ?code=, from /track/CODE, and from a bare
 * /track?CODE, which is what people type when they edit the address.
 */
$bare = array_key_first($_GET);
$code = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? $params[0]
    ?? ($bare !== null && ($_GET[$bare] ?? '') === '' ? $bare : ''))));
$error = '';

if ($code !== '') {
    if (preg_match('/^[A-Z0-9-]{4,24}$/', $code) && col('SELECT 1 FROM orders WHERE code = ?', [$code])) {
        redirect('order/' . rawurlencode($code));
    }
    $error = 'We could not find an order with the code "' . mb_substr($code, 0, 30) . '". '
           . 'Check it against your receipt - it starts with '
           . (preg_replace('/[^A-Z0-9]/', '', strtoupper((string) setting('order_prefix', 'OF'))) ?: 'OF') . '-.';
}

view('track', [
    'seo'   => ['title' => 'Track an order', 'robots' => 'noindex, follow', 'canonical' => url('track')],
    'code'  => $code,
    'error' => $error,
]);
