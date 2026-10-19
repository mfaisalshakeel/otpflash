<?php
/**
 * Keeping two requests from doing the same piece of work twice.
 *
 * Everything money touches in this panel used to be check-then-act: read a
 * row, decide, call an API, write the row back. Between the read and the
 * write another request can read the same row and reach the same decision,
 * and then the provider is paid twice for one order. The two tools here are
 * the fix.
 *
 * `claim()` is the one to reach for. It does the deciding inside a single
 * UPDATE, so the database - not our code - picks the winner, and the loser
 * finds out by being told it changed nothing.
 *
 * `with_lock()` is for work that spans several statements and an API call,
 * where there is no one row to claim: a whole cron run, a rate refresh.
 */

/** How long a lock is honoured before it is treated as abandoned. */
const LOCK_TTL_DEFAULT = 300;

/**
 * Take a row and say so, in one statement.
 *
 * The caller passes the condition that makes the row available. Exactly one
 * concurrent caller can see rowCount() === 1, because the database applies
 * the UPDATEs one after another and the second one no longer matches.
 *
 * @param  string $table   the table to claim in
 * @param  array  $set     what to write when the claim succeeds
 * @param  string $where   the condition - must include what makes it unclaimed
 * @param  array  $params  bindings for $where
 * @return bool            true only for the caller that got it
 */
function claim(string $table, array $set, string $where, array $params = []): bool
{
    $assignments = [];
    $values      = [];
    foreach ($set as $column => $value) {
        $assignments[] = '`' . $column . '` = ?';
        $values[]      = $value;
    }

    $statement = q(
        'UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE ' . $where,
        array_merge($values, $params)
    );

    return $statement->rowCount() === 1;
}

/**
 * Hold a named lock for the length of one callable.
 *
 * Built on a table rather than on MySQL's GET_LOCK, because the same code has
 * to work on SQLite. The row carries an expiry so a request that dies
 * mid-flight - a timeout on a shared host is routine - does not leave the job
 * blocked forever.
 *
 * Returns null when the lock was already held: the caller decides whether
 * that is a reason to complain or simply to do nothing, which is usually the
 * right answer for a cron job that is already running.
 */
function with_lock(string $name, callable $work, int $ttlSeconds = LOCK_TTL_DEFAULT)
{
    if (!lock_acquire($name, $ttlSeconds)) {
        return null;
    }
    try {
        return $work();
    } finally {
        lock_release($name);
    }
}

/**
 * Take a named lock.
 *
 * The INSERT is what makes this safe: `locks.name` is unique, so two callers
 * racing to create the same row means one of them gets a constraint
 * violation. Taking over an expired lock is a conditional UPDATE for the same
 * reason - whoever matches the "expired" condition first is the only one who
 * can.
 */
function lock_acquire(string $name, int $ttlSeconds = LOCK_TTL_DEFAULT): bool
{
    $now     = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + max(5, $ttlSeconds));
    $owner   = lock_owner();

    try {
        insert_row('locks', [
            'name'       => $name,
            'owner'      => $owner,
            'acquired_at'=> $now,
            'expires_at' => $expires,
        ]);
        return true;
    } catch (PDOException $e) {
        if (!is_duplicate_key($e)) {
            throw $e;
        }
    }

    // Somebody holds it. Take it over only if theirs has run out.
    return claim('locks',
        ['owner' => $owner, 'acquired_at' => $now, 'expires_at' => $expires],
        '`name` = ? AND `expires_at` < ?',
        [$name, $now]
    );
}

function lock_release(string $name): void
{
    q('DELETE FROM locks WHERE `name` = ? AND `owner` = ?', [$name, lock_owner()]);
}

/** Who holds a lock - enough to tell two requests apart in the log. */
function lock_owner(): string
{
    static $owner = null;
    if ($owner === null) {
        $owner = (PHP_SAPI === 'cli' ? 'cli' : 'web') . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    }
    return $owner;
}

/**
 * Is this exception a unique-key collision?
 *
 * SQLSTATE 23000 covers it on both drivers, but MySQL also uses 23000 for a
 * NOT NULL violation, so the message is checked as well. Both wordings have
 * to be here for the same reason `migration_already_done()` carries both.
 */
function is_duplicate_key(PDOException $e): bool
{
    if (($e->getCode() !== '23000') && ($e->getCode() !== '23505')) {
        return false;
    }
    $message = strtolower($e->getMessage());

    return str_contains($message, 'duplicate entry')
        || str_contains($message, 'duplicate key')
        || str_contains($message, 'unique constraint failed')
        || str_contains($message, 'unique violation');
}

/**
 * Drop locks whose holder never came back.
 *
 * Expired rows are already ignored by lock_acquire(), so this is only
 * housekeeping; it runs from cron rather than on every request.
 */
function locks_prune(): int
{
    return q('DELETE FROM locks WHERE expires_at < ?', [date('Y-m-d H:i:s')])->rowCount();
}
