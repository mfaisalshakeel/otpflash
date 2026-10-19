<?php
/**
 * The update screen.
 *
 * GET  /admin/update        the screen
 * POST /admin/update/plan   the list of steps, as JSON
 * POST /admin/update/step   runs one step, as JSON
 *
 * The plan is fetched rather than printed into the page so that pressing
 * "Run the update" always works against the database as it is right now,
 * not as it was when the page was opened - two admins with the tab open at
 * the same time would otherwise each run the same migration.
 */
require_once APP_PATH . '/helpers/update.php';

$action = $params[0] ?? 'index';

/** Answer an AJAX step and stop. */
$respond = static function (array $payload): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
};

// --- the plan ---------------------------------------------------------------
if ($action === 'plan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    update_pending_migrations(true);

    $respond([
        'ok'    => true,
        'from'  => installed_version(),
        'to'    => app_version(),
        'steps' => update_steps(),
    ]);
}

// --- one step ---------------------------------------------------------------
if ($action === 'step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Long-running work must not be abandoned halfway because the browser
    // tab was closed; a half-applied migration is worse than a slow one.
    ignore_user_abort(true);
    @set_time_limit(120);

    $key    = (string) ($_POST['key'] ?? '');
    $result = update_run_step($key);

    $respond([
        'ok'     => $result['ok'],
        'key'    => $key,
        'note'   => $result['note'],
        'errors' => $result['errors'],
    ]);
}

// --- the no-JavaScript path -------------------------------------------------
//
// Everything above is the nice version. This is the same work in one POST,
// for a browser that cannot run the stepper.
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    foreach (update_steps() as $step) {
        $result = update_run_step($step['key']);
        $errors = array_merge($errors, $result['errors']);
    }

    if ($errors) {
        flash('error', 'Update finished with problems: ' . implode(' | ', $errors));
    } else {
        flash('success', 'Updated to version ' . app_version() . '.');
    }

    redirect('admin/update');
}

// --- the screen -------------------------------------------------------------
update_pending_migrations(true);

$applied = [];
try {
    $applied = all('SELECT id, applied_at FROM migrations ORDER BY id DESC');
} catch (Throwable $e) {
    flash('error', 'Could not read the migration history: ' . $e->getMessage());
}

view('admin/update', [
    'title'       => 'Update',
    'subtitle'    => update_available()
        ? 'Version ' . app_version() . ' is ready to apply'
        : 'Everything is up to date',
    'available'   => update_available(),
    'codeVersion' => app_version(),
    'dbVersion'   => installed_version(),
    'steps'       => update_steps(),
    'pending'     => update_pending_migrations(),
    'applied'     => $applied,
    'environment' => update_environment(),
], 'layouts/admin');
