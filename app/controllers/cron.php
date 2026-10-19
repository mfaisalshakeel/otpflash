<?php
/**
 * URL version of cron, for hosts that cannot run a CLI job:
 *
 *   /cron/{cron_key}
 *
 * The key comes from config/config.php. A wrong key, a missing key, or /cron
 * on its own is a plain 404, so the endpoint cannot be probed for.
 */

$given    = $params[0] ?? '';
$expected = (string) cfg('cron_key', '');

if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

require_once APP_PATH . '/helpers/cron.php';

// /cron/{key}/sync_services runs one task; /cron/{key} runs them all.
$only = $params[1] ?? null;
if ($only !== null && !isset(cron_tasks()[$only])) {
    require CONTROLLER_PATH . '/_404.php';
    return;
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
echo run_cron_tasks($only, 'url');
