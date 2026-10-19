<?php
/**
 * The record of what happened and who did it.
 *
 * Separate from `order_logs`, which is the story of one order told to whoever
 * is looking at that order, and from `log_line()`, which writes a text file
 * nobody reads until something is already wrong. This is the one place that
 * answers "who changed that, and what did it used to be" - for money, for
 * settings, for prices, for sign-ins.
 *
 * Nothing here is allowed to break what it is recording: a failed write is
 * swallowed into the text log. An audit row is evidence, not a dependency.
 */

/** Rows older than this are pruned by cron. Zero keeps everything. */
const AUDIT_KEEP_DAYS_DEFAULT = 180;

/**
 * Record one thing that happened.
 *
 * @param string $action  a dotted name: order.paid, setting.changed, rate.refused
 * @param array  $about   entity => 'order', id => 12, summary => '...',
 *                        before => [...], after => [...], severity => 'info|warn|alert'
 */
function audit(string $action, array $about = []): void
{
    try {
        $before = $about['before'] ?? null;
        $after  = $about['after']  ?? null;

        // Only what actually moved. A diff of forty unchanged columns buries
        // the one that matters, which is the whole reason to keep this.
        if (is_array($before) && is_array($after)) {
            [$before, $after] = audit_changes($before, $after);
            if (!$after && !isset($about['summary'])) {
                return;                      // nothing changed; nothing to say
            }
        }

        $actor = audit_actor();

        insert_row('audit_log', [
            'action'      => mb_substr($action, 0, 60),
            'actor_type'  => $actor['type'],
            'actor_id'    => $actor['id'],
            'actor_name'  => mb_substr($actor['name'], 0, 80),
            'entity'      => mb_substr((string) ($about['entity'] ?? ''), 0, 40),
            'entity_id'   => (int) ($about['entity_id'] ?? 0),
            'summary'     => mb_substr((string) ($about['summary'] ?? ''), 0, 300),
            'severity'    => in_array($about['severity'] ?? 'info', ['info', 'warn', 'alert'], true)
                               ? ($about['severity'] ?? 'info') : 'info',
            'before_json' => $before === null ? '' : mb_substr(audit_json($before), 0, 2000),
            'after_json'  => $after === null ? '' : mb_substr(audit_json($after), 0, 2000),
            'ip'          => mb_substr(client_ip(), 0, 45),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        log_line('audit(' . $action . ') failed: ' . $e->getMessage());
    }
}

/**
 * Who is doing this.
 *
 * A signed-in admin by name, otherwise the context: cron and the installer
 * act on nobody's behalf, and a guest placing an order has no account.
 */
function audit_actor(): array
{
    if (function_exists('is_admin') && is_admin()) {
        $admin = admin_user();
        return ['type' => 'admin', 'id' => (int) $admin['id'], 'name' => (string) $admin['username']];
    }
    if (PHP_SAPI === 'cli') {
        return ['type' => 'cron', 'id' => 0, 'name' => 'cron'];
    }
    if (function_exists('current_user') && ($user = current_user())) {
        return ['type' => 'customer', 'id' => (int) $user['id'], 'name' => (string) $user['email']];
    }
    return ['type' => 'customer', 'id' => 0, 'name' => 'guest'];
}

/**
 * The columns that differ, as [before, after].
 *
 * Compared as strings because a value that went to the database as 10 comes
 * back as '10', and a diff that reports every numeric column on every write
 * is a diff nobody will read.
 */
function audit_changes(array $before, array $after): array
{
    $wasChanged = [];
    $nowIs      = [];

    foreach ($after as $key => $value) {
        $old = $before[$key] ?? null;
        if ((string) $old === (string) $value) {
            continue;
        }
        $wasChanged[$key] = audit_mask($key, $old);
        $nowIs[$key]      = audit_mask($key, $value);
    }

    return [$wasChanged, $nowIs];
}

/**
 * Keep secrets out of the record.
 *
 * An audit log is read by whoever can reach the admin area, and a provider's
 * API key written into it is a key that has leaked into a second place.
 */
function audit_mask(string $key, $value): string
{
    $key   = strtolower($key);
    $value = (string) $value;

    foreach (['api_key', 'password', 'pass', 'secret', 'token', 'recovery_codes', 'smtp_pass'] as $needle) {
        if (str_contains($key, $needle)) {
            return $value === '' ? '' : '********';
        }
    }
    return mb_substr($value, 0, 300);
}

function audit_json($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Note a change to a row, reading it back so the diff is of what was stored.
 *
 * Callers that already hold the old row pass it; the new one is re-read,
 * because what the database kept is the truth and what we sent it is only
 * what we asked for.
 */
function audit_row(string $action, string $table, int $id, ?array $before, array $about = []): void
{
    $after = one('SELECT * FROM `' . $table . '` WHERE id = ?', [$id]) ?: [];
    audit($action, $about + [
        'entity'    => $table,
        'entity_id' => $id,
        'before'    => $before ?? [],
        'after'     => $after,
    ]);
}

/** Housekeeping, from cron. */
function audit_prune(): int
{
    $days = (int) setting('audit_keep_days', AUDIT_KEEP_DAYS_DEFAULT);
    if ($days <= 0) {
        return 0;
    }
    // Worked out in PHP and bound: the two drivers disagree about INTERVAL.
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

    return q('DELETE FROM audit_log WHERE created_at < ? AND severity = ?',
        [$cutoff, 'info'])->rowCount();
}
