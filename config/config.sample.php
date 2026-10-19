<?php
/**
 * Copy of the configuration the installer writes to config/config.php.
 *
 * The installer generates this file for you - you only edit it by hand when
 * moving the site to another server or changing database credentials.
 */
return [
    // --- database ---------------------------------------------------------
    // 'mysql' or 'sqlite'. With sqlite, db_name is the file (relative to the
    // project) and the host, user and password are ignored.
    'db_driver'  => 'mysql',
    'db_host'    => 'localhost',
    'db_name'    => 'smm_panel',
    'db_user'    => 'smm_user',
    'db_pass'    => '',
    'db_charset' => 'utf8mb4',

    // --- application ------------------------------------------------------
    // Random 64-character key written by the installer. Changing it logs
    // everyone out and invalidates existing CSRF tokens.
    'app_key'    => '',

    // Secret used in the cron URL: /cron/{cron_key}
    'cron_key'   => '',

    // Absolute site URL, no trailing slash. Leave empty to detect it from
    // the request (fine for most shared hosts).
    'base_url'   => '',

    // Show PHP errors on screen. Keep false on a live site.
    'debug'      => false,
];
