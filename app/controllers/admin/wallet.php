<?php
/**
 * The wallet ledger: every line on every customer's statement, in one place.
 *
 * Read-only. Money moves through a customer's own screen (Adjust the wallet)
 * or through an order, so each line here already has a reason attached; a
 * second place to change balances would be a second way for them to drift.
 */
require_once APP_PATH . '/helpers/wallet.php';
require_once APP_PATH . '/helpers/paging.php';

$filters = [
    'q'    => trim((string) ($_GET['q'] ?? '')),
    'type' => (string) ($_GET['type'] ?? ''),
    'user' => (string) ($_GET['user'] ?? ''),
    'date' => (string) ($_GET['date'] ?? ''),
];

$where = [];
$binds = [];
if ($filters['q'] !== '') {
    $where[] = '(u.email LIKE ? OR u.name LIKE ? OR w.note LIKE ? OR o.code LIKE ?)';
    array_push($binds, ...array_fill(0, 4, '%' . $filters['q'] . '%'));
}
if (isset(WALLET_TYPES[$filters['type']])) {
    $where[] = 'w.type = ?';
    $binds[] = $filters['type'];
}
if (ctype_digit($filters['user'])) {
    $where[] = 'w.user_id = ?';
    $binds[] = (int) $filters['user'];
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
    // A range rather than DATE(): the two databases disagree about date functions.
    $where[] = 'w.created_at >= ? AND w.created_at < ?';
    array_push($binds, $filters['date'] . ' 00:00:00', date('Y-m-d', strtotime($filters['date'] . ' +1 day')) . ' 00:00:00');
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from     = 'FROM wallet_transactions w
             LEFT JOIN users u  ON u.id = w.user_id
             LEFT JOIN orders o ON o.id = w.order_id';

$totals = one("SELECT COUNT(*) AS n,
                      COALESCE(SUM(CASE WHEN w.amount > 0 THEN w.amount ELSE 0 END), 0) AS money_in,
                      COALESCE(SUM(CASE WHEN w.amount < 0 THEN -w.amount ELSE 0 END), 0) AS money_out
                 $from $whereSql", $binds);

$paging = paging((int) $totals['n']);
$lines  = all(
    "SELECT w.*, u.name AS user_name, u.email AS user_email, o.code AS order_code, a.username AS admin_name
       $from
       LEFT JOIN admins a ON a.id = w.admin_id
       $whereSql
   ORDER BY w.id DESC
      LIMIT {$paging['per']} OFFSET {$paging['offset']}",
    $binds
);

view('admin/wallet', [
    'title'    => 'Wallet ledger',
    'subtitle' => money((float) col('SELECT COALESCE(SUM(balance), 0) FROM users', [], 0)) . ' held in customer wallets',
    'lines'    => $lines,
    'totals'   => $totals,
    'filters'  => $filters,
    'paging'   => $paging,
    'who'      => ctype_digit($filters['user']) ? one('SELECT id, name, email FROM users WHERE id = ?', [(int) $filters['user']]) : null,
], 'layouts/admin');
