<?php
/** Contact form messages. */

$action = $params[0] ?? 'index';

if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    q('UPDATE messages SET is_read = 1 - is_read WHERE id = ?', [$id]);
    redirect('admin/messages');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    delete_row('messages', 'id = ?', [(int) ($_POST['id'] ?? 0)]);
    flash('success', 'Message deleted.');
    redirect('admin/messages');
}

if ($action === 'read-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $changed = q('UPDATE messages SET is_read = 1 WHERE is_read = 0')->rowCount();
    flash('success', $changed ? "Marked {$changed} message(s) as read." : 'Nothing was unread.');
    redirect('admin/messages');
}

$filter = (string) ($_GET['state'] ?? '');
$where  = $filter === 'unread' ? ' WHERE is_read = 0' : ($filter === 'read' ? ' WHERE is_read = 1' : '');

require_once APP_PATH . '/helpers/paging.php';

$pages = paging((int) col("SELECT COUNT(*) FROM messages{$where}", [], 0));

view('admin/messages', [
    'title'    => 'Messages',
    'subtitle' => 'From the contact form',
    'messages' => all("SELECT * FROM messages{$where} ORDER BY created_at DESC, id DESC"
                    . ' LIMIT ' . $pages['per'] . ' OFFSET ' . $pages['offset']),
    'filter'   => $filter,
    'paging'   => $pages,
    'unread'   => (int) col('SELECT COUNT(*) FROM messages WHERE is_read = 0', [], 0),
], 'layouts/admin');
