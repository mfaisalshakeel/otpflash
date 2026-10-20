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
];
