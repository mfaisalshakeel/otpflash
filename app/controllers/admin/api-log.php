<?php
/**
 * Every call to OTPGet and the payment gateways, across all orders.
 *
 *   /admin/api-log                  newest first
 *   ?source=payment|otpget          one side only
 *   ?result=failed                  only what went wrong
 *   ?q=OF-8F42KD3M                  one order, or words in the reply
 *
 * The order page shows its own calls; this is for "why did payments fail
 * this morning" - a question about many orders at once.
 */
$where   = [];
$binding = [];

$source = (string) ($_GET['source'] ?? '');
if (in_array($source, ['payment', 'otpget'], true)) {
    $where[]   = 'c.source = ?';
    $binding[] = $source;
}

$result = (string) ($_GET['result'] ?? '');
if ($result === 'failed') {
    $where[] = 'c.ok = 0';
}

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $where[]   = '(o.code = ? OR c.summary LIKE ? OR c.party LIKE ?)';
    $binding[] = strtoupper($search);
    $binding[] = '%' . $search . '%';
    $binding[] = '%' . $search . '%';
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$from   = ' FROM order_calls c LEFT JOIN orders o ON o.id = c.order_id';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = 50;

// Two statements, one clause each - the count and the page cannot share
// bindings without shifting them.
$total = (int) col('SELECT COUNT(*)' . $from . $clause, $binding, 0);
$calls = all('SELECT c.*, o.code AS order_code' . $from . $clause . ' ORDER BY c.id DESC LIMIT '
    . $per . ' OFFSET ' . (($page - 1) * $per), $binding);

$since  = date('Y-m-d H:i:s', time() - 86400);
view('admin/api-log', [
    'title'    => 'API log',
    'subtitle' => 'What OTPGet and the payment gateways actually said',
    'calls'    => $calls,
    'total'    => $total,
    'page'     => $page,
    'pages'    => max(1, (int) ceil($total / $per)),
    'source'   => $source,
    'result'   => $result,
    'search'   => $search,
    'failedPayments' => (int) col("SELECT COUNT(*) FROM order_calls WHERE source = 'payment' AND ok = 0 AND created_at >= ?", [$since], 0),
    'failedOtpget'   => (int) col("SELECT COUNT(*) FROM order_calls WHERE source = 'otpget' AND ok = 0 AND created_at >= ?", [$since], 0),
], 'layouts/admin');
