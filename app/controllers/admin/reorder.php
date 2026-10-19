<?php
/**
 * Putting a list in the order the admin dragged it into.
 *
 * One endpoint for every screen that sorts, because the work is identical
 * wherever it happens: take the ids in their new order and write that order
 * into `sort_order`. A screen that did this for itself would be the same
 * twenty lines seven times over, and the seventh would be the one that
 * forgot to check the table name.
 *
 * The table is matched against a list written here, never taken as given -
 * it goes straight into SQL, so it is ours or it is nothing. Same rule the
 * CRUD engine follows about dynamic column names.
 */

/** The tables whose order is the admin's to choose. */
const REORDERABLE = [
    'services', 'countries', 'currencies', 'payment_methods',
    'faqs', 'pages', 'post_categories',
];

$table = (string) ($_POST['table'] ?? '');
$ids   = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

// Where this page starts in the whole list. Dragging on page two must not
// hand rows the numbers page one is already using.
$start = max(0, (int) ($_POST['start'] ?? 0));

$answer = static function (array $payload): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $answer(['ok' => false, 'error' => 'Reordering is a POST.']);
}

if (!in_array($table, REORDERABLE, true)) {
    http_response_code(400);
    $answer(['ok' => false, 'error' => 'That list cannot be reordered.']);
}

if (!$ids) {
    $answer(['ok' => false, 'error' => 'Nothing to put in order.']);
}

// One statement per row, in a transaction: a half-applied order is a list
// that reads differently from the one the admin just looked at.
db()->beginTransaction();

try {
    foreach ($ids as $position => $id) {
        update_row($table, ['sort_order' => $start + $position + 1], 'id = ?', [$id]);
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    log_line('Reorder of ' . $table . ' failed: ' . $e->getMessage());
    http_response_code(500);
    $answer(['ok' => false, 'error' => 'That order could not be saved.']);
}

require_once APP_PATH . '/helpers/audit.php';
audit($table . '.reordered', [
    'entity'   => $table,
    'severity' => 'info',
    'summary'  => qty_fmt(count($ids)) . ' ' . str_replace('_', ' ', $table) . ' put in a new order',
]);

$answer(['ok' => true, 'saved' => count($ids)]);
