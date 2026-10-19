<?php
/**
 * Applying schema changes to a site that is already installed.
 *
 * Migrations are listed in install/migrations.php. Each is applied once and
 * its id recorded, so running this again is a no-op.
 *
 * A statement that fails because the column or row is already there is
 * skipped rather than treated as an error: SQLite has no
 * "ADD COLUMN IF NOT EXISTS", and this is how both drivers end up behaving
 * the same way.
 */

/** Make sure the bookkeeping table exists. */
function migrations_table(): void
{
    if (db_driver() === 'sqlite') {
        q('CREATE TABLE IF NOT EXISTS "migrations" (
             "id" TEXT NOT NULL PRIMARY KEY,
             "applied_at" TEXT NOT NULL
           )');
        return;
    }

    q('CREATE TABLE IF NOT EXISTS `migrations` (
         `id` VARCHAR(100) NOT NULL,
         `applied_at` DATETIME NOT NULL,
         PRIMARY KEY (`id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migrations_all(): array
{
    static $all = null;
    if ($all === null) {
        $all = require BASE_PATH . '/install/migrations.php';
    }
    return $all;
}

/** Ids that have not run yet, in order. */
function migrations_pending(): array
{
    migrations_table();

    $applied = [];
    foreach (all('SELECT id FROM migrations') as $row) {
        $applied[$row['id']] = true;
    }

    return array_keys(array_diff_key(migrations_all(), $applied));
}

/**
 * Apply everything outstanding.
 *
 * @return array{applied: string[], skipped: int, errors: string[]}
 */
function migrations_run(): array
{
    $result = ['applied' => [], 'skipped' => 0, 'errors' => []];

    foreach (migrations_pending() as $id) {
        $one = migration_apply($id);
        $result['applied'][] = $id;
        $result['skipped'] += $one['skipped'];
        $result['errors']   = array_merge($result['errors'], $one['errors']);
    }

    if ($result['applied']) {
        settings_all(true);
        log_line('Migrations applied: ' . implode(', ', $result['applied']));
    }

    return $result;
}

/**
 * Apply one migration and record it.
 *
 * **Recorded only when every statement got through.** A migration that half
 * ran and was written down anyway is a migration that can never be offered
 * again: the panel says there is nothing to update while the table it was
 * supposed to add is missing, and the only way back is an admin editing the
 * bookkeeping table by hand. Left pending, it is simply offered again, and
 * the statements that did run are skipped by migration_already_done() the
 * second time - which is what that list of phrases is for.
 *
 * @return array{skipped: int, errors: string[]}
 */
function migration_apply(string $id): array
{
    $all = migrations_all();
    if (!isset($all[$id])) {
        return ['skipped' => 0, 'errors' => ["Unknown migration: {$id}"]];
    }

    migrations_table();

    $driver     = db_driver();
    $migration  = $all[$id];
    $statements = array_merge($migration[$driver] ?? [], $migration['both'] ?? []);
    $result     = ['skipped' => 0, 'errors' => []];

    foreach ($statements as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            if (migration_already_done($e)) {
                $result['skipped']++;
                continue;
            }
            $result['errors'][] = $e->getMessage();
        }
    }

    if (!$result['errors'] && !migration_is_applied($id)) {
        insert_row('migrations', ['id' => $id, 'applied_at' => date('Y-m-d H:i:s')]);
    }

    settings_all(true);
    log_line($result['errors']
        ? 'Migration ' . $id . ' finished with errors: ' . implode(' | ', $result['errors'])
        : 'Migration ' . $id . ' applied');

    return $result;
}

function migration_is_applied(string $id): bool
{
    migrations_table();
    return (int) col('SELECT COUNT(*) FROM migrations WHERE id = ?', [$id], 0) > 0;
}

/** The human-readable line for a migration id. */
function migration_label(string $id): string
{
    $all = migrations_all();
    return (string) ($all[$id]['label'] ?? $id);
}

/** Is this failure just "that already exists"? */
function migration_already_done(PDOException $e): bool
{
    $message = strtolower($e->getMessage());

    foreach ([
        'duplicate column',          // MySQL and SQLite, ADD COLUMN
        'duplicate entry',           // MySQL, a seed row already there
        'duplicate key',
        'already exists',            // SQLite, table or index
        'unique constraint failed',  // SQLite, a seed row already there
    ] as $phrase) {
        if (str_contains($message, $phrase)) {
            return true;
        }
    }
    return false;
}
