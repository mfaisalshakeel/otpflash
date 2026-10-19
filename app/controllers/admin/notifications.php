<?php
/**
 * The notification feed, and the switches that decide what reaches the admin.
 *
 *   /admin/notifications          the feed
 *   /admin/notifications/rules    which events notify, and how
 */
require_once APP_PATH . '/helpers/notify.php';

$action = $params[0] ?? '';

/** Back to the list the admin was looking at, not always to the whole feed. */
$back = static function (): string {
    $show = (string) ($_POST['show'] ?? $_GET['show'] ?? '');
    return 'admin/notifications' . ($show === 'unread' ? '?show=unread' : '');
};

if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        update_row('notifications', ['is_read' => 1], 'id = ?', [$id]);
    } else {
        q('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
        flash('success', 'All marked as read.');
    }
    redirect($back());
}

// One row, read or not. Clearing in bulk only takes the read ones, because
// that sweep cannot see what it is throwing away; this one is a row the
// admin is looking at and has asked about.
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        delete_row('notifications', 'id = ?', [$id]);
        flash('success', 'Notification deleted.');
    }
    redirect($back());
}

if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only what has been read: clearing an unread one loses something nobody
    // has seen yet.
    $removed = q('DELETE FROM notifications WHERE is_read = 1')->rowCount();
    flash('success', $removed ? 'Cleared ' . qty_fmt($removed) . ' read notification(s).'
                              : 'Nothing read to clear.');
    redirect('admin/notifications');
}

if ($action === 'rules') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (notification_events() as $event => $definition) {
            if (!empty($definition['always'])) {
                continue;               // a sign-in code is not switchable
            }
            if ($definition['to'] === 'admin') {
                set_setting('notify_' . $event . '_panel',
                    isset($_POST['panel'][$event]) ? '1' : '0');
            }
            set_setting('notify_' . $event . '_mail',
                isset($_POST['mail'][$event]) ? '1' : '0');
        }

        set_setting('notify_email', trim((string) ($_POST['notify_email'] ?? '')));
        set_setting('low_balance_threshold',
            (string) max(0, (float) ($_POST['low_balance_threshold'] ?? 0)));

        flash('success', 'Notification settings saved.');
        redirect('admin/notifications/rules');
    }

    view('admin/notification-rules', [
        'title'     => 'Notifications',
        'subtitle'  => 'What reaches you, and how',
        'events'    => notification_events(),
        'mailReady' => mail_ready(),
        'adminMail' => notification_admin_email(),
    ], 'layouts/admin');
    return;
}

$filter = ($_GET['show'] ?? '') === 'unread' ? 'unread' : 'all';
$only   = $filter === 'unread' ? ' WHERE is_read = 0' : '';

require_once APP_PATH . '/helpers/paging.php';
$pages = paging((int) col('SELECT COUNT(*) FROM notifications' . $only, [], 0));

view('admin/notifications', [
    'title'    => 'Notifications',
    'subtitle' => 'What has happened lately',
    'rows'     => all('SELECT * FROM notifications' . $only . ' ORDER BY id DESC'
                    . ' LIMIT ' . $pages['per'] . ' OFFSET ' . $pages['offset']),
    'unread'   => notifications_unread(),
    'filter'   => $filter,
    'paging'   => $pages,
], 'layouts/admin');
