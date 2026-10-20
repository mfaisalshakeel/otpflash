<?php
/**
 * The logs, and emptying them.
 *
 * Five tables only ever grow: the calls to OTPGet and the gateways, the audit
 * trail, the bell, the mail log and the cron history. On a shared host a
 * table that grows forever is a backup that stops fitting and a list screen
 * that slows down, so each can be cleared by hand - everything, or what is
 * older than so many days - and cron trims what is past `log_keep_days`.
 *
 * Order history is not here: an order's own log and its money are records,
 * not logs, and they go only with the order (Maintenance).
 */
require_once APP_PATH . '/helpers/audit.php';
require_once APP_PATH . '/helpers/paging.php';   // plural()

/** Each log: its table, the words for it, and the screen it is read on. */
const LOG_KINDS = [
    'api'           => ['table' => 'order_calls',   'label' => 'API log',       'screen' => 'admin/api-log',
                        'what'  => 'every request to OTPGet and the payment gateways, with the replies'],
    'audit'         => ['table' => 'audit_log',     'label' => 'Audit log',     'screen' => 'admin/audit',
                        'what'  => 'who changed what in the panel'],
    'notifications' => ['table' => 'notifications', 'label' => 'Notifications', 'screen' => 'admin/notifications',
                        'what'  => 'the bell\'s messages'],
    'emails'        => ['table' => 'email_log',     'label' => 'Email log',     'screen' => 'admin/emails/log',
                        'what'  => 'every email sent or refused'],
    'cron'          => ['table' => 'cron_runs',     'label' => 'Cron history',  'screen' => 'admin/cron',
                        'what'  => 'each run of each scheduled task'],
];

/** The ages a log can be cleared back to, in days; 0 is everything. */
const LOG_CLEAR_AGES = [30 => 'Older than 30 days', 7 => 'Older than 7 days', 1 => 'Older than a day', 0 => 'Everything'];

/** How many rows a log holds, and how many of them are older than $days. */
function log_count(string $kind, int $days = 0): int
{
    $table = LOG_KINDS[$kind]['table'] ?? null;
    if ($table === null) {
        return 0;
    }
    return $days > 0
        ? optional_count("SELECT COUNT(*) FROM {$table} WHERE created_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)])
        : optional_count("SELECT COUNT(*) FROM {$table}");
}

/**
 * Empty a log, or the part of it older than $days.
 *
 * The audit entry is written after the delete, so clearing the audit log
 * itself still leaves the line saying it was cleared, by whom and how much -
 * the record of a wipe is the one thing a wipe must not take with it.
 *
 * @return int rows deleted
 */
function log_clear(string $kind, int $days = 0, string $by = 'admin'): int
{
    $info = LOG_KINDS[$kind] ?? null;
    if ($info === null || !table_exists($info['table'])) {
        return 0;
    }
    $deleted = $days > 0
        ? q("DELETE FROM {$info['table']} WHERE created_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount()
        : q("DELETE FROM {$info['table']}")->rowCount();

    if ($deleted > 0 || $by === 'admin') {
        audit('log.cleared', [
            'entity'   => 'logs',
            'severity' => $by === 'admin' ? 'warn' : 'info',
            'summary'  => $info['label'] . ': ' . qty_fmt($deleted) . ' ' . plural('row', $deleted)
                        . ($days > 0 ? ' older than ' . $days . ' day' . ($days === 1 ? '' : 's') : '')
                        . ' cleared' . ($by === 'cron' ? ' by cron' : ''),
        ]);
    }
    return $deleted;
}

/**
 * Trim the logs housekeeping does not already look after.
 *
 * The audit log has its own rule (audit_prune(): routine entries go, warnings
 * and alerts stay) and the cron history its own thirty days, both in
 * cron_housekeeping(); this is the rest, past `log_keep_days`.
 *
 * @return int rows deleted
 */
function logs_prune(): int
{
    $keep = (int) setting('log_keep_days', '90');
    if ($keep <= 0) {
        return 0;
    }
    $deleted = 0;
    foreach (['api', 'notifications', 'emails'] as $kind) {
        $deleted += log_clear($kind, $keep, 'cron');
    }
    return $deleted;
}
