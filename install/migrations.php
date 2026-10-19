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
 * 1.0.0 is the first release, so schema.sql already carries everything and
 * the list starts empty. The next change to the database goes here, with the
 * version in app/core/version.php bumped in the same commit.
 */

return [
];
