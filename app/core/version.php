<?php

/**
 * The one place the release version lives.
 *
 * bootstrap.php turns it into APP_VERSION and the installer reads this same
 * file without booting the app. The database keeps its own copy in the
 * app_version setting; when the two differ, or a migration has not run, the
 * admin offers an update - so this is bumped in the same commit as the
 * migration that needs it.
 *
 * It must return a string. An empty file returns int 1, which never equals
 * the version in the database, and the panel then shows "Update available"
 * for ever with nothing to apply.
 */

return '1.0.5';
