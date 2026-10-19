<?php
/** The cron screen: what runs, when it last ran, and how to schedule it. */
require_once APP_PATH . '/helpers/cron.php';
require_once APP_PATH . '/helpers/cronjoborg.php';

$action = $params[0] ?? 'index';

// --- run one task by hand ---------------------------------------------------
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = (string) ($_POST['task'] ?? '');

    if ($key === 'all') {
        foreach (array_keys(cron_tasks()) as $taskKey) {
            cron_run_task($taskKey, 'admin', true);
        }
        flash('success', 'Ran every task.');
    } elseif (isset(cron_tasks()[$key])) {
        $result = cron_run_task($key, 'admin', true);
        flash($result['ok'] ? 'success' : 'error',
            cron_tasks()[$key]['label'] . ': ' . $result['summary']);
    } else {
        flash('error', 'Unknown task.');
    }

    redirect('admin/cron');
}

// --- cron-job.org -----------------------------------------------------------
if ($action === 'cronjoborg' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $what = (string) ($_POST['do'] ?? '');

    if ($what === 'save_key') {
        set_setting('cronjob_org_key', trim((string) ($_POST['api_key'] ?? '')));
        flash('success', 'API key saved.');
    } elseif ($what === 'create') {
        $result = cronjoborg_save(url('cron/' . cfg('cron_key', '')));
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($what === 'delete') {
        $result = cronjoborg_delete();
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    }

    redirect('admin/cron');
}

// --- the screen -------------------------------------------------------------
$tasks = [];
foreach (cron_tasks() as $key => $task) {
    $last = cron_last_run($key);
    $tasks[$key] = $task + [
        'key'      => $key,
        'last'     => $last,
        'disabled' => isset($task['enabled']) && setting($task['enabled'], '1') !== '1',
    ];
}

view('admin/cron', [
    'title'        => 'Cron',
    'subtitle'     => 'Scheduled work, and how to run it',
    'tasks'        => $tasks,
    'cronKey'      => (string) cfg('cron_key', ''),
    'cronUrl'      => url('cron/' . cfg('cron_key', '')),
    'cronCommand'  => 'php ' . BASE_PATH . '/cron.php',
    'recent'       => all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 25'),
    'orgConfigured'=> cronjoborg_configured(),
    'orgJob'       => cronjoborg_configured() ? cronjoborg_job() : null,
    'orgHistory'   => cronjoborg_configured() ? cronjoborg_history(8) : [],
], 'layouts/admin');
