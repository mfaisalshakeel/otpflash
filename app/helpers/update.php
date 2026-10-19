<?php
/**
 * Keeping an installed site in step with the code.
 *
 * The code carries APP_VERSION; the database carries its own copy in the
 * `app_version` setting. Uploading a newer release leaves the two out of
 * step, and that is the signal the admin acts on - there is nothing to
 * download and nothing to unzip from inside the panel, because on shared
 * hosting the files arrive by FTP or cPanel and only the database is left
 * to bring forward.
 *
 * The work is handed back as a list of steps rather than done in one call.
 * A shared host can cut a request off at thirty seconds, and a migration
 * that rewrites a large table is exactly the kind of thing that gets cut;
 * one step per request also means the browser has something honest to put
 * in a progress bar.
 */

require_once APP_PATH . '/helpers/migrate.php';

/** The version of the code on disk. */
function app_version(): string
{
    return defined('APP_VERSION') ? APP_VERSION : '1.0.0';
}

/**
 * The version the database was last brought up to.
 *
 * Sites installed before this setting existed have no row, and 1.0.0 is the
 * right answer for them: everything since is pending.
 */
function installed_version(): string
{
    $stored = trim((string) setting('app_version', ''));
    return $stored !== '' ? $stored : '1.0.0';
}

/**
 * Migrations that have not run yet.
 *
 * Asked on every admin page load, so the answer is kept for the request.
 * A database too old to have a `migrations` table still answers - the table
 * is created on the way past.
 */
function update_pending_migrations(bool $fresh = false): array
{
    static $pending = null;

    if ($pending === null || $fresh) {
        try {
            $pending = migrations_pending();
        } catch (Throwable $e) {
            // Nothing to offer if we cannot even read the bookkeeping table -
            // but say so in the log. Swallowed in silence, this reads exactly
            // like "you are up to date", which is the one thing it is not.
            log_line('Could not work out pending migrations: ' . $e->getMessage());
            $pending = [];
        }
    }

    return $pending;
}

function update_available(): bool
{
    return update_pending_migrations() !== []
        || version_compare(installed_version(), app_version(), '<');
}

/**
 * The steps an update runs, in order, one per request.
 *
 * @return array<int, array{key: string, label: string, detail: string}>
 */
function update_steps(): array
{
    $steps = [];

    foreach (update_pending_migrations() as $id) {
        $steps[] = [
            'key'    => 'migration:' . $id,
            'label'  => migration_label($id),
            'detail' => $id,
        ];
    }

    $steps[] = [
        'key'    => 'cache',
        'label'  => 'Clear cached provider catalogues',
        'detail' => 'storage/cache',
    ];
    $steps[] = [
        'key'    => 'finish',
        'label'  => 'Record version ' . app_version(),
        'detail' => 'app_version',
    ];

    return $steps;
}

/**
 * Run one step.
 *
 * Always answers; a failure comes back as `ok => false` with the message, so
 * the browser can show which step broke instead of a dead request.
 *
 * @return array{ok: bool, note: string, errors: string[]}
 */
function update_run_step(string $key): array
{
    try {
        if (str_starts_with($key, 'migration:')) {
            $id = substr($key, strlen('migration:'));

            if (migration_is_applied($id)) {
                return ['ok' => true, 'note' => 'Already applied', 'errors' => []];
            }

            $result = migration_apply($id);
            return [
                'ok'     => $result['errors'] === [],
                'note'   => $result['errors'] === []
                    ? ($result['skipped'] ? $result['skipped'] . ' already in place' : 'Applied')
                    : 'Failed',
                'errors' => $result['errors'],
            ];
        }

        if ($key === 'cache') {
            $cleared = update_clear_cache();
            return [
                'ok'     => true,
                'note'   => $cleared ? $cleared . ' file' . ($cleared === 1 ? '' : 's') . ' removed' : 'Nothing cached',
                'errors' => [],
            ];
        }

        if ($key === 'finish') {
            set_setting('app_version', app_version());
            settings_all(true);
            log_line('Updated to version ' . app_version());
            return ['ok' => true, 'note' => 'Now on ' . app_version(), 'errors' => []];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'note' => 'Failed', 'errors' => [$e->getMessage()]];
    }

    return ['ok' => false, 'note' => 'Unknown step', 'errors' => ["No such update step: {$key}"]];
}

/**
 * Throw away the cached provider catalogues.
 *
 * A release can change what we read out of a catalogue, and a stale cached
 * copy would be read with the new code for up to half an hour.
 */
function update_clear_cache(): int
{
    $removed = 0;
    foreach (glob(STORAGE_PATH . '/cache/*.json') ?: [] as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}

/** What the update screen reports about the site, whether or not anything is pending. */
function update_environment(): array
{
    return [
        'Code version'      => app_version(),
        'Database version'  => installed_version(),
        'Database driver'   => db_driver() === 'sqlite' ? 'SQLite' : 'MySQL / MariaDB',
        'Migrations run'    => (string) count(migrations_all()) . ' total, '
                             . count(update_pending_migrations()) . ' pending',
        'PHP'               => PHP_VERSION,
        'Active theme'      => (string) setting('active_theme', 'default'),
    ];
}
