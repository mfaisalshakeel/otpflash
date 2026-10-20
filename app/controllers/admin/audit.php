<?php
/**
 * The record: who did what, and what it used to be.
 *
 *   /admin/audit        the list, filterable
 *   /admin/audit/row/7  one entry, with the before and after side by side
 */
require_once APP_PATH . '/helpers/audit.php';

$action = $params[0] ?? '';

if ($action === 'row') {
    $row = one('SELECT * FROM audit_log WHERE id = ?', [(int) ($params[1] ?? 0)]);
    if (!$row) {
        flash('error', 'That entry no longer exists.');
        redirect('admin/audit');
    }

    view('admin/audit-row', [
        'title'    => $row['action'],
        'subtitle' => when($row['created_at'], 'd M Y, H:i:s'),
        'row'      => $row,
        'before'   => json_decode($row['before_json'] ?: '{}', true) ?: [],
        'after'    => json_decode($row['after_json'] ?: '{}', true) ?: [],
    ], 'layouts/admin');
    return;
}

// Filters are built as a list so the WHERE and the bindings cannot drift
// apart - the dashboard bug was a clause and its placeholders getting out of
// step, and this is the same shape of code.
$where  = [];
$params_ = [];

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $where[]   = '(summary LIKE ? OR action LIKE ? OR actor_name LIKE ?)';
    $like      = '%' . $search . '%';
    $params_[] = $like;
    $params_[] = $like;
    $params_[] = $like;
}

$severity = (string) ($_GET['severity'] ?? '');
if (in_array($severity, ['info', 'warn', 'alert'], true)) {
    $where[]   = 'severity = ?';
    $params_[] = $severity;
}

$actor = (string) ($_GET['actor'] ?? '');
if (in_array($actor, ['admin', 'customer', 'cron', 'system'], true)) {
    $where[]   = 'actor_type = ?';
    $params_[] = $actor;
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Two statements, one clause each. Putting the same conditions twice in one
// query shifts every later binding by position and the count comes back wrong
// with no error at all.
require_once APP_PATH . '/helpers/paging.php';
$paging = paging((int) col('SELECT COUNT(*) FROM audit_log' . $clause, $params_, 0), 60);
$rows   = all('SELECT * FROM audit_log' . $clause . ' ORDER BY id DESC LIMIT '
    . $paging['per'] . ' OFFSET ' . $paging['offset'], $params_);

view('admin/audit', [
    'title'    => 'Audit log',
    'subtitle' => 'Who changed what, and what it used to be',
    'rows'     => $rows,
    'paging'   => $paging,
    'search'   => $search,
    'severity' => $severity,
    'actor'    => $actor,
    'alerts'   => (int) col("SELECT COUNT(*) FROM audit_log WHERE severity = 'alert'", [], 0),
], 'layouts/admin');
