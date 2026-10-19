<?php
/**
 * /track - find an order by its code.
 *
 * With a code it goes straight to the order page, which is where everything
 * about an order lives. Without one it opens the tracker on the home page;
 * with the script that is a dialog, and without it the same box is on /faq.
 */
$code = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? $params[0] ?? '')));

if ($code !== '') {
    if (!preg_match('/^[A-Z0-9-]{4,24}$/', $code) || !col('SELECT 1 FROM orders WHERE code = ?', [$code])) {
        flash('error', 'We could not find an order with that code. Check it and try again.');
        redirect('faq#track');
    }
    redirect('order/' . rawurlencode($code));
}

redirect('faq#track');
