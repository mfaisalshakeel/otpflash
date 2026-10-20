<?php
/**
 * Schema changes applied after the first install.
 *
 * The installer runs these straight after schema.sql, and an existing site
 * picks them up from Settings -> Update database. Each entry is applied once
 * and recorded in the `migrations` table.
 *
 * Statements are written per driver because MySQL and SQLite disagree about
 * ALTER TABLE. Anything that fails because it is already there is skipped -
 * SQLite has no ADD COLUMN IF NOT EXISTS, so that is how we get the same
 * behaviour on both.
 *
 * An entry looks like this:
 *
 *   '2027_01_example' => [
 *       'label'  => 'What the admin sees on the update screen',
 *       'mysql'  => ["ALTER TABLE `orders` ADD COLUMN `note` VARCHAR(190) NOT NULL DEFAULT ''"],
 *       'sqlite' => ['ALTER TABLE "orders" ADD COLUMN "note" TEXT NOT NULL DEFAULT \'\''],
 *       'both'   => ["INSERT INTO settings (`k`, `v`) VALUES ('example', '1')"],
 *   ],
 *
 * The next change to the database goes here, with the version in
 * app/core/version.php bumped in the same commit.
 */

return [
    // 1.0.1 - a service's identity for the sync, apart from its slug. The
    // slug is the admin's to change for SEO, and a sync that found services
    // by slug alone made a second WhatsApp the day someone renamed the first.
    // Rows that exist were made by the sync under that very slug, so the
    // slug is the right key to start them on.
    '2026_10_services_match_key' => [
        'label'  => 'Remember which OTPGet app each service is, so renaming one never duplicates it',
        'mysql'  => ["ALTER TABLE `services` ADD COLUMN `match_key` VARCHAR(80) NOT NULL DEFAULT '' AFTER `slug`",
                     'ALTER TABLE `services` ADD KEY `ix_services_match` (`match_key`)'],
        'sqlite' => ['ALTER TABLE "services" ADD COLUMN "match_key" TEXT NOT NULL DEFAULT \'\'',
                     'CREATE INDEX IF NOT EXISTS "ix_services_match" ON "services" ("match_key")'],
        'both'   => ["UPDATE services SET match_key = slug WHERE match_key = ''"],
    ],

    // 1.0.2 - the sync shares one connection and reads seventy countries a
    // step, so cron can read far more per run. Only the old defaults move;
    // a number an admin chose is theirs.
    '2026_10_sync_batch_default' => [
        'label' => 'Let cron read a whole batch of countries per run now that the sync is faster',
        'both'  => ["UPDATE settings SET v = '144' WHERE k = 'sync_batch' AND v IN ('8', '24')"],
    ],
    // The email box was off by default when nothing was mailed to customers;
    // now a purchase sends a receipt, so the box is on with the WhatsApp one.
    '2026_10_order_contact_fields' => [
        'label' => 'Show the optional WhatsApp and email boxes on the order form',
        'both'  => ["UPDATE settings SET v = '1' WHERE k = 'ask_email' AND v = '0'",
                    "INSERT INTO settings (`k`, `v`) VALUES ('ask_whatsapp', '1')"],
    ],
    // What OTPGet and the gateways actually answered, per order.
    '2026_10_order_calls' => [
        'label'  => 'Keep every OTPGet and payment gateway reply on its order',
        'mysql'  => ["CREATE TABLE IF NOT EXISTS `order_calls` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `source` VARCHAR(20) NOT NULL DEFAULT '',
            `party` VARCHAR(120) NOT NULL DEFAULT '',
            `action` VARCHAR(60) NOT NULL DEFAULT '',
            `ok` TINYINT(1) NOT NULL DEFAULT 0,
            `summary` VARCHAR(500) NOT NULL DEFAULT '',
            `request` TEXT NULL,
            `response` TEXT NULL,
            `http_status` INT NOT NULL DEFAULT 0,
            `ms` INT NOT NULL DEFAULT 0,
            `repeats` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `last_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ix_order_calls_order` (`order_id`, `id`),
            KEY `ix_order_calls_source` (`source`, `ok`, `id`),
            CONSTRAINT `fk_order_calls_order` FOREIGN KEY (`order_id`)
              REFERENCES `orders` (`id`) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],
        'sqlite' => ['CREATE TABLE IF NOT EXISTS "order_calls" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "order_id" INTEGER NOT NULL,
            "source" TEXT NOT NULL DEFAULT \'\',
            "party" TEXT NOT NULL DEFAULT \'\',
            "action" TEXT NOT NULL DEFAULT \'\',
            "ok" INTEGER NOT NULL DEFAULT 0,
            "summary" TEXT NOT NULL DEFAULT \'\',
            "request" TEXT NULL,
            "response" TEXT NULL,
            "http_status" INTEGER NOT NULL DEFAULT 0,
            "ms" INTEGER NOT NULL DEFAULT 0,
            "repeats" INTEGER NOT NULL DEFAULT 1,
            "created_at" TEXT NOT NULL,
            "last_at" TEXT NOT NULL,
            FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE CASCADE
          )',
                     'CREATE INDEX IF NOT EXISTS "ix_order_calls_order" ON "order_calls" ("order_id", "id")',
                     'CREATE INDEX IF NOT EXISTS "ix_order_calls_source" ON "order_calls" ("source", "ok", "id")'],
    ],
    // A post written elsewhere (the CSV import) arrives with the prompt for
    // its picture; the editor keeps it beside the cover to copy.
    '2026_10_post_image_prompt' => [
        'label'  => 'Keep each post\'s image prompt and the cover\'s alt text',
        'mysql'  => ["ALTER TABLE `posts` ADD COLUMN `image_prompt` TEXT NULL AFTER `focus_keyword`",
                     "ALTER TABLE `posts` ADD COLUMN `image_alt` VARCHAR(190) NOT NULL DEFAULT '' AFTER `image_prompt`"],
        'sqlite' => ['ALTER TABLE "posts" ADD COLUMN "image_prompt" TEXT NULL',
                     'ALTER TABLE "posts" ADD COLUMN "image_alt" TEXT NOT NULL DEFAULT \'\''],
    ],
];
