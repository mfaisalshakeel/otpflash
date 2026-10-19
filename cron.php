<?php
/**
 * Scheduled work, run from the command line.
 *
 *   php /home/you/public_html/cron.php
 *
 * Every minute is the right schedule: a number lives for twenty minutes. Each task
 * checks whether it has anything to do. Hosts without CLI cron can call
 * /cron/{cron_key} instead; both run app/helpers/cron.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', __DIR__);
require BASE_PATH . '/app/core/bootstrap.php';
require APP_PATH . '/helpers/cron.php';

// `php cron.php check_numbers` runs one task; no argument runs them all.
$only = $argv[1] ?? null;

if ($only !== null && !isset(cron_tasks()[$only])) {
    fwrite(STDERR, 'Unknown task: ' . $only . PHP_EOL
        . 'Known tasks: ' . implode(', ', array_keys(cron_tasks())) . PHP_EOL);
    exit(1);
}

echo run_cron_tasks($only, 'cli');
