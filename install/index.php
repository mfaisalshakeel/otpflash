<?php
/**
 * One-time installer.
 *
 * Step 1  requirements check
 * Step 2  database credentials -> writes config/config.php and runs schema.sql
 * Step 3  administrator account -> writes install/install.lock
 * Step 4  what to do next
 *
 * Steps 2 and 3 are the ones that do real work, and they do it one task per
 * request: creating twenty tables and then running every migration is more
 * than a shared host will always allow in a single thirty-second POST, and a
 * page that sits on a spinner with nothing to say is how people conclude an
 * installer has hung. The browser asks for the task list, runs it, and shows
 * each one finishing. Without JavaScript the same list runs in one request,
 * which is what the form does on its own.
 *
 * Once install.lock exists this script refuses to do anything, so leaving it
 * on the server cannot be used to reinstall over a live site.
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
define('LOCK_FILE', __DIR__ . '/install.lock');
define('CONFIG_FILE', BASE_PATH . '/config/config.php');

// This file IS the installer, and /install is its address.
//
// Every server resolves /install to this file by itself: Apache and nginx
// through DirectoryIndex, PHP's built-in server through its directory index.
// So it has to run standalone - redirecting from here would only loop. The
// root index.php can also hand over to it, which is what INSTALL_VIA_APP says.
//
// The one address worth tidying is /install/index.php: the same page with
// .php hanging off the end.
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '');
if (!defined('INSTALL_VIA_APP') && str_ends_with($requestPath, '/index.php')) {
    header('Location: ' . substr($requestPath, 0, -strlen('/index.php')), true, 301);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('otpinstall');
    session_start();
}

$step = (int) ($_GET['step'] ?? 1);
$task = (string) ($_GET['task'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

// ---------------------------------------------------------------------------
// Refuse to run twice
// ---------------------------------------------------------------------------
$justFinished = $step === 4 && !empty($_SESSION['install_done']);

// An install that is still running is not an install that is done. The
// administrator row is written in the middle of step 3, and already_installed()
// goes true the moment it lands - so a step that failed after it left the
// installer refusing to open, with the site name unsaved and no way back in
// except deleting the config. The lock file is what "finished" means; until it
// is there, the browser that started this install may carry on.
$stillRunning = !is_file(LOCK_FILE) && !empty($_SESSION['install_admin']);

if (already_installed() && !$justFinished && !$stillRunning) {
    http_response_code(403);
    if ($task !== '') {
        install_json(['ok' => false, 'error' => 'This site is already installed.']);
    }
    render_page('Already installed', 0, '
        <div class="box">
          <h2>This site is already installed</h2>
          <p class="sub">There is a config file and an administrator account, so there is
             nothing left for the installer to do.</p>
          <p class="note">If you really need to install again, delete
             <code>config/config.php</code>' . (is_file(LOCK_FILE)
                ? ' and <code>install/install.lock</code>' : '') . ' first &mdash; that
             wipes the link to your current database, so take a backup.</p>
          <p class="mt"><a class="btn" href="' . h(site_url('admin/login')) . '">Go to the admin login</a></p>
        </div>');
    exit;
}

$errors = [];

// ---------------------------------------------------------------------------
// Step 2 - database
// ---------------------------------------------------------------------------
if ($step === 2 && $post) {

    // The browser asking what the work is. Everything is validated and the
    // connection proved here, so a task list coming back means the rest is
    // very likely to succeed.
    if ($task === 'plan') {
        [$problems, $data] = validate_database($_POST);

        if ($problems) {
            install_json(['ok' => false, 'error' => implode(' ', $problems)]);
        }

        try {
            open_database($data);
        } catch (Throwable $e) {
            install_json(['ok' => false, 'error' => friendly_db_error($e, $data)]);
        }

        $_SESSION['install_input'] = $data;
        install_json(['ok' => true, 'tasks' => database_tasks()]);
    }

    if ($task !== '') {
        install_json(run_database_task($task));
    }

    // No JavaScript: the same list, in one request.
    [$errors, $data] = validate_database($_POST);
    if (!$errors) {
        $_SESSION['install_input'] = $data;
        foreach (database_tasks() as $one) {
            $result = run_database_task($one['key']);
            if (!$result['ok']) {
                $errors[] = $result['error'];
                break;
            }
        }
        if (!$errors) {
            header('Location: ?step=3');
            exit;
        }
    }
}

// ---------------------------------------------------------------------------
// Step 3 - administrator
// ---------------------------------------------------------------------------
if ($step === 3 && $post) {
    if (empty($_SESSION['install_db']) || !is_file(CONFIG_FILE)) {
        if ($task !== '') {
            install_json(['ok' => false, 'error' => 'The database step has not finished yet.']);
        }
        header('Location: ?step=2');
        exit;
    }

    if ($task === 'plan') {
        [$problems, $data] = validate_admin($_POST);

        if ($problems) {
            install_json(['ok' => false, 'error' => implode(' ', $problems)]);
        }

        $_SESSION['install_admin'] = $data;
        install_json(['ok' => true, 'tasks' => admin_tasks()]);
    }

    if ($task !== '') {
        install_json(run_admin_task($task));
    }

    [$errors, $data] = validate_admin($_POST);
    if (!$errors) {
        $_SESSION['install_admin'] = $data;
        foreach (admin_tasks() as $one) {
            $result = run_admin_task($one['key']);
            if (!$result['ok']) {
                $errors[] = $result['error'];
                break;
            }
        }
        if (!$errors) {
            header('Location: ?step=4');
            exit;
        }
    }
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
if ($step === 4) {
    $cronKey = $_SESSION['install_done']['cron_key'] ?? '';
    unset($_SESSION['install_done']);
    $cronLine = $cronKey
        ? '<p class="lbl">Add this to cron in cPanel, every minute</p>'
          . '<pre>php ' . h(BASE_PATH) . '/cron.php</pre>'
          . '<p class="lbl">Or call the URL version</p>'
          . '<pre>' . h(guess_base_url()) . '/cron/' . h($cronKey) . '</pre>'
        : '';

    render_page('Installed', 4, '
      <div class="box">
        <div class="done-tick">&#10003;</div>
        <h2>All set</h2>
        <p class="sub">Your OTP shop is installed and the installer is now locked. Log in, open <b>OTPGet</b> and press <b>Sync catalogue</b> to fill the shop with numbers.</p>
        ' . $cronLine . '
        <p class="warn"><b>One last step:</b> delete the <code>install/</code> folder from your
           server. Everything still works without it.</p>
        <p class="mt"><a class="btn" href="' . h(site_url('admin/login')) . '">Log in to the admin panel</a></p>
      </div>');
    exit;
}

if ($step === 3) {
    if (empty($_SESSION['install_db']) && !$post) {
        header('Location: ?step=2');
        exit;
    }

    render_page('Create your administrator', 3, '
      <form method="post" action="?step=3" class="box" data-run
            data-plan="?step=3&amp;task=plan" data-task="?step=3&amp;task=" data-next="?step=4">
        <h2>Step 3 &mdash; administrator account</h2>
        <p class="sub">This is the account you will use to log in at <code>/admin</code>.</p>
        ' . errors_html($errors) . '
        <div class="fields">
          <label>Site name
            <input name="site_name" value="' . h($_POST['site_name'] ?? 'OTP Flash') . '" required></label>
          <label>Username
            <input name="username" value="' . h($_POST['username'] ?? '') . '" required
                   pattern="[A-Za-z0-9_.-]{3,60}" autocomplete="username"></label>
          <label>Email
            <input type="email" name="email" value="' . h($_POST['email'] ?? '') . '" required
                   autocomplete="email"></label>
          <label>Password <span class="hint">8 characters or more</span>
            <input type="password" name="password" required minlength="8"
                   autocomplete="new-password"></label>
          <label>Repeat password
            <input type="password" name="password_confirm" required minlength="8"
                   autocomplete="new-password"></label>
          <label>OTPGet API key <span class="hint">optional &mdash; from your otpget.com dashboard,
            and you can add it later under OTPGet in the admin</span>
            <input name="otpget_api_key" value="' . h($_POST['otpget_api_key'] ?? '') . '"
                   autocomplete="off" spellcheck="false"></label>
          <label>Show prices in each visitor&rsquo;s own currency
            <span class="hint">Prices are always stored and charged in your base currency; this
              only changes what a visitor reads. Everything here can be changed later under
              Settings &rarr; Currency and region.</span>
            <select name="geo_provider">
              <option value="auto"' . (($_POST['geo_provider'] ?? 'auto') === 'auto' ? ' selected' : '') . '>
                Yes &mdash; detect their country automatically</option>
              <option value="cdn"' . (($_POST['geo_provider'] ?? '') === 'cdn' ? ' selected' : '') . '>
                Yes, but only from my CDN&rsquo;s country header</option>
              <option value="off"' . (($_POST['geo_provider'] ?? '') === 'off' ? ' selected' : '') . '>
                No &mdash; show everyone the base currency</option>
            </select></label>
          <label class="check">
            <input type="checkbox" name="geo_behind_cdn" value="1"' .
              (!empty($_POST['geo_behind_cdn']) ? ' checked' : '') . '>
            This site is behind Cloudflare or another CDN
            <span class="hint">Only tick this if it is true. With no CDN in front, the country
              header is one anybody can send and choose what country you think they are in.</span>
          </label>
        </div>
        ' . runner_html('Finish installation') . '
      </form>');
    exit;
}

if ($step === 2) {
    $chosen    = ($_POST['db_driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    $hasSqlite = extension_loaded('pdo_sqlite');
    $hasMysql  = extension_loaded('pdo_mysql');

    render_page('Database', 2, '
      <form method="post" action="?step=2" class="box" data-run
            data-plan="?step=2&amp;task=plan" data-task="?step=2&amp;task=" data-next="?step=3">
        <h2>Step 2 &mdash; database</h2>
        <p class="sub">Pick where your data lives. MySQL is the usual choice on shared hosting;
           SQLite needs no database server at all and keeps everything in one file.</p>
        ' . errors_html($errors) . config_handover() . '

        <div class="choice">
          <label class="opt' . ($chosen === "mysql" ? " on" : "") . ($hasMysql ? "" : " off") . '">
            <input type="radio" name="db_driver" value="mysql"' . ($chosen === "mysql" ? " checked" : "")
              . ($hasMysql ? "" : " disabled") . '>
            <b>MySQL / MariaDB</b>
            <small>' . ($hasMysql
                ? "Create an empty database and user in cPanel first."
                : "Not available: this server has no pdo_mysql.") . '</small>
          </label>
          <label class="opt' . ($chosen === "sqlite" ? " on" : "") . ($hasSqlite ? "" : " off") . '">
            <input type="radio" name="db_driver" value="sqlite"' . ($chosen === "sqlite" ? " checked" : "")
              . ($hasSqlite ? "" : " disabled") . '>
            <b>SQLite</b>
            <small>' . ($hasSqlite
                ? "One file, no server, nothing to set up. Good for a small shop."
                : "Not available: this server has no pdo_sqlite.") . '</small>
          </label>
        </div>

        <div class="fields">
          <div data-for="mysql">
            <label>Database host
              <input name="db_host" value="' . h($_POST['db_host'] ?? 'localhost') . '"></label>
            <label>Database name
              <input name="db_name" value="' . h($_POST['db_name'] ?? '') . '"></label>
            <label>Database user
              <input name="db_user" value="' . h($_POST['db_user'] ?? '') . '"></label>
            <label>Database password
              <input type="password" name="db_pass" value=""></label>
          </div>

          <div data-for="sqlite">
            <label>Database file <span class="hint">inside the project, kept out of the web root</span>
              <input name="sqlite_file"
                     value="' . h($_POST['sqlite_file'] ?? 'storage/database.sqlite') . '"></label>
            <p class="note">Back this file up the way you would back up a database &mdash; it
               <em>is</em> your database.</p>
          </div>

          <label>Site URL <span class="hint">leave empty to detect automatically</span>
            <input name="base_url" value="' . h($_POST['base_url'] ?? '') . '"
                   placeholder="' . h(guess_base_url()) . '"></label>
        </div>
        ' . runner_html('Create the tables') . '
      </form>

      <script>
      /* Show only the fields that belong to the chosen driver. Without this the
         form still works - both sets post, and the server reads the ones it
         needs. */
      (function () {
        function sync() {
          var picked = document.querySelector(\'input[name="db_driver"]:checked\');
          picked = picked ? picked.value : "mysql";
          document.querySelectorAll("[data-for]").forEach(function (block) {
            block.hidden = block.getAttribute("data-for") !== picked;
          });
          document.querySelectorAll(".opt").forEach(function (opt) {
            opt.classList.toggle("on", opt.querySelector("input").value === picked);
          });
        }
        document.querySelectorAll(\'input[name="db_driver"]\').forEach(function (radio) {
          radio.addEventListener("change", sync);
        });
        sync();
      })();
      </script>');
    exit;
}

// --- step 1: requirements ---------------------------------------------------
$checks  = requirement_checks();
$blocked = false;
$soft    = false;        // something optional failed, worth a word but not a stop
$rows    = '';
foreach ($checks as $check) {
    if (!$check['ok']) {
        $check['required'] ? $blocked = true : $soft = true;
    }
    $icon  = $check['ok'] ? '<span class="check-ok">&#10003;</span>' : ($check['required']
        ? '<span class="bad">&#10007;</span>' : '<span class="meh">!</span>');
    // The hint is only worth the room when the check did not pass - a wall of
    // explanation beside nine green ticks is noise.
    $hint = !$check['ok'] && !empty($check['hint'])
        ? '<small class="chint">' . h($check['hint']) . '</small>'
        : '';

    $rows .= '<tr><td>' . $icon . '</td><td>' . h($check['label']) . $hint . '</td><td class="r">'
           . h($check['value']) . '</td></tr>';
}

render_page('Requirements', 1, '
  <div class="box">
    <h2>Step 1 &mdash; server check</h2>
    <p class="sub">Everything below must pass before the panel can be installed.</p>
    <table>' . $rows . '</table>
    ' . ($blocked
        ? '<p class="warn">Fix the items marked &#10007;, then check again. The ones marked
             ! are optional - the note beside each says what you lose.</p>'
          . permission_help()
          . '<a class="btn btn-ghost" href="?step=1" data-busy="Checking&hellip;">Check again</a>'
        : ($soft
            ? '<p class="note">The items marked ! are optional. The note beside each says
                 what you lose by leaving it.</p>' . permission_help()
            : '')
          . '<a class="btn" href="?step=2" data-busy="Loading&hellip;">Continue</a>') . '
  </div>');


// ===========================================================================
// Step 2 - the work
// ===========================================================================

/**
 * Read the database form.
 *
 * @return array{0: string[], 1: array<string, mixed>}
 */
function validate_database(array $input): array
{
    $problems = [];
    $driver   = ($input['db_driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';

    $data = [
        'driver'      => $driver,
        'host'        => trim((string) ($input['db_host'] ?? 'localhost')),
        'name'        => trim((string) ($input['db_name'] ?? '')),
        'user'        => trim((string) ($input['db_user'] ?? '')),
        'pass'        => (string) ($input['db_pass'] ?? ''),
        'sqlite_file' => trim((string) ($input['sqlite_file'] ?? 'storage/database.sqlite')),
        'base_url'    => rtrim(trim((string) ($input['base_url'] ?? '')), '/'),
    ];

    if ($driver === 'sqlite') {
        if ($data['sqlite_file'] === '') {
            $data['sqlite_file'] = 'storage/database.sqlite';
        }
        if (!extension_loaded('pdo_sqlite')) {
            $problems[] = 'This server has no pdo_sqlite extension, so SQLite is not available here.';
        }
        // The file must stay out of the web root, and storage/ is already
        // denied by .htaccess. Keep it relative so the config survives a move.
        if (str_contains($data['sqlite_file'], '..')
            || preg_match('~^(/|[A-Za-z]:)~', $data['sqlite_file'])) {
            $problems[] = 'Give a path inside the project, like storage/database.sqlite';
        }
    } else {
        if ($data['name'] === '') { $problems[] = 'Database name is required.'; }
        if ($data['user'] === '') { $problems[] = 'Database user is required.'; }
    }

    // Generated once and carried through, so the config task writes the same
    // keys the connection test was made with.
    $data['app_key']  = bin2hex(random_bytes(32));
    $data['cron_key'] = bin2hex(random_bytes(16));

    return [$problems, $data];
}

/** One entry per request the browser makes during step 2. */
function database_tasks(): array
{
    $tasks = [
        ['key' => 'connect', 'label' => 'Reach the database'],
        ['key' => 'schema',  'label' => 'Create the tables'],
    ];

    foreach (migration_list() as $id => $migration) {
        $tasks[] = ['key' => 'migrate:' . $id, 'label' => (string) ($migration['label'] ?? $id)];
    }

    $tasks[] = ['key' => 'seed',   'label' => 'Record the version'];
    $tasks[] = ['key' => 'config', 'label' => 'Write config/config.php'];

    return $tasks;
}

/** @return array{ok: bool, note?: string, error?: string} */
function run_database_task(string $key): array
{
    $data = $_SESSION['install_input'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'The installer lost your answers. Go back and submit the form again.'];
    }

    // A task may be the one that rewrites a big table; do not let the browser
    // closing abandon it halfway.
    ignore_user_abort(true);
    @set_time_limit(120);

    try {
        $pdo = open_database($data);

        if ($key === 'connect') {
            return ['ok' => true, 'note' => $data['driver'] === 'sqlite' ? 'File ready' : 'Connected'];
        }

        if ($key === 'schema') {
            run_schema($pdo, __DIR__ . ($data['driver'] === 'sqlite' ? '/schema.sqlite.sql' : '/schema.sql'));
            return ['ok' => true, 'note' => 'Done'];
        }

        if (str_starts_with($key, 'migrate:')) {
            return apply_migration($pdo, (string) $data['driver'], substr($key, strlen('migrate:')));
        }

        if ($key === 'seed') {
            put_setting($pdo, 'app_version', code_version());
            return ['ok' => true, 'note' => code_version()];
        }

        if ($key === 'config') {
            $config = [
                'db_driver'  => $data['driver'],
                'db_host'    => $data['driver'] === 'sqlite' ? '' : $data['host'],
                'db_name'    => $data['driver'] === 'sqlite' ? $data['sqlite_file'] : $data['name'],
                'db_user'    => $data['driver'] === 'sqlite' ? '' : $data['user'],
                'db_pass'    => $data['driver'] === 'sqlite' ? '' : $data['pass'],
                'db_charset' => 'utf8mb4',
                'app_key'    => $data['app_key'],
                'cron_key'   => $data['cron_key'],
                'base_url'   => $data['base_url'],
                'debug'      => false,
            ];

            // Already there and already right? Then there is nothing to do.
            // This is the path back from the handover below: the admin uploads
            // the file themselves, which means it belongs to them and the web
            // user usually cannot overwrite it - so insisting on a write would
            // reject the very file we asked for.
            if (config_matches(CONFIG_FILE, $config)) {
                unset($_SESSION['install_config_text']);
                $_SESSION['install_db'] = true;
                return ['ok' => true, 'note' => 'Already in place'];
            }

            if (!write_config(CONFIG_FILE, $config)) {
                // Not a dead end: the database is already set up at this point,
                // and the only thing left is a file. Hand it over rather than
                // sending the admin away to argue with their host about a
                // permission - and keep it in the session so the step can be
                // retried once the file is there.
                $_SESSION['install_config_text'] = config_text($config);

                return ['ok' => false,
                        'config' => config_text($config),
                        'error' => 'Could not write config/config.php - the folder is not '
                                 . 'writable. Create that file yourself with exactly the '
                                 . 'contents below, upload it, then press Try again.'];
            }
            unset($_SESSION['install_config_text']);

            $_SESSION['install_db'] = true;
            return ['ok' => true, 'note' => 'Saved'];
        }
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => friendly_db_error($e, $data)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => false, 'error' => 'Unknown step: ' . $key];
}

// ===========================================================================
// Step 3 - the work
// ===========================================================================

/** @return array{0: string[], 1: array<string, string>} */
function validate_admin(array $input): array
{
    $problems = [];
    $data = [
        'username' => trim((string) ($input['username'] ?? '')),
        'email'    => trim((string) ($input['email'] ?? '')),
        'password' => (string) ($input['password'] ?? ''),
        'site'     => trim((string) ($input['site_name'] ?? 'OTP Flash')),
        // Optional: the shop installs and opens without it, it just has
        // nothing to sell until the key is there.
        'api_key'  => trim((string) ($input['otpget_api_key'] ?? '')),
        // What a visitor reads prices in. Never what they are charged.
        'geo'      => in_array($input['geo_provider'] ?? 'auto', ['auto', 'cdn', 'off'], true)
                        ? (string) $input['geo_provider'] : 'auto',
        'cdn'      => !empty($input['geo_behind_cdn']),
    ];
    $confirm = (string) ($input['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $data['username'])) {
        $problems[] = 'Username must be 3-60 characters: letters, numbers, dot, dash or underscore.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $problems[] = 'Enter a valid email address.';
    }
    if (strlen($data['password']) < 8) {
        $problems[] = 'Password must be at least 8 characters.';
    }
    if ($data['password'] !== $confirm) {
        $problems[] = 'The two passwords do not match.';
    }
    if ($data['api_key'] !== '' && !preg_match('/^\S{8,200}$/', $data['api_key'])) {
        $problems[] = 'That OTPGet API key does not look right. Leave it blank to add it later.';
    }

    return [$problems, $data];
}

function admin_tasks(): array
{
    // The site name first, because it can be written a hundred times and
    // nothing happens; the administrator second, because that is the one that
    // cannot be undone; the lock last, because it is what says the whole
    // thing finished. Done the other way round - as it was - a failure after
    // the administrator left the name unsaved and the installer shut.
    return [
        ['key' => 'settings', 'label' => 'Save your site name'],
        ['key' => 'admin',    'label' => 'Create the administrator'],
        ['key' => 'lock',     'label' => 'Lock the installer'],
    ];
}

/** @return array{ok: bool, note?: string, error?: string} */
function run_admin_task(string $key): array
{
    $data = $_SESSION['install_admin'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'The installer lost your answers. Go back and submit the form again.'];
    }

    try {
        $config = require CONFIG_FILE;
        $pdo    = connect_from_config($config);

        if ($key === 'admin') {
            // Asked for twice - a double submit, or a retry after a later
            // step failed. The account this install was going to create is
            // already there, so this step has nothing left to do and saying
            // so beats refusing and stranding the install.
            $mine = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
            $mine->execute([$data['username']]);
            if ((int) $mine->fetchColumn() > 0) {
                return ['ok' => true, 'note' => $data['username'] . ' (already created)'];
            }

            if ((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                return ['ok' => false, 'error' => 'An administrator already exists in this database.'];
            }

            // The timestamp is bound rather than written as NOW(), which
            // SQLite does not have.
            $pdo->prepare('INSERT INTO admins (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
                ->execute([
                    $data['username'],
                    $data['email'],
                    password_hash($data['password'], PASSWORD_DEFAULT),
                    date('Y-m-d H:i:s'),
                ]);

            return ['ok' => true, 'note' => $data['username']];
        }

        if ($key === 'settings') {
            if ($data['site'] !== '') {
                put_setting($pdo, 'site_name', $data['site']);
            }
            if (($data['api_key'] ?? '') !== '') {
                put_setting($pdo, 'otpget_api_key', $data['api_key']);
            }
            put_setting($pdo, 'geo_provider', (string) ($data['geo'] ?? 'auto'));
            put_setting($pdo, 'geo_behind_cdn', !empty($data['cdn']) ? '1' : '0');
            return ['ok' => true, 'note' => 'Done'];
        }

        if ($key === 'lock') {
            // Not fatal. The lock file is a convenience - already_installed()
            // works it out from the config and the admin row either way - and
            // making install/ writable just to drop a marker in it was asking
            // for a permission nobody needs.
            $locked = @file_put_contents(LOCK_FILE, 'Installed ' . date('c') . PHP_EOL) !== false;
            @chmod(CONFIG_FILE, 0640);

            unset($_SESSION['install_db'], $_SESSION['install_input'], $_SESSION['install_admin']);
            $_SESSION['install_done'] = ['cron_key' => (string) ($config['cron_key'] ?? '')];

            return ['ok' => true, 'note' => $locked ? 'Locked' : 'Installed'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => false, 'error' => 'Unknown step: ' . $key];
}

// ===========================================================================
// Helpers (installer only - the application helpers are not loaded yet)
// ===========================================================================

function install_json(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/** The release version, read from the same file the application reads. */
function code_version(): string
{
    $file = BASE_PATH . '/app/core/version.php';
    return is_file($file) ? (string) require $file : '1.0.0';
}

/** Open a connection from the answers given in step 2. */
function open_database(array $data): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    if ($data['driver'] === 'sqlite') {
        $file = BASE_PATH . '/' . ltrim((string) $data['sqlite_file'], '/');
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create ' . $data['sqlite_file'] . ' - check folder permissions.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException($dir . ' is not writable, so the database file cannot be created.');
        }

        $pdo = new PDO('sqlite:' . $file, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        @chmod($file, 0660);
        return $pdo;
    }

    return new PDO(
        "mysql:host={$data['host']};dbname={$data['name']};charset=utf8mb4",
        (string) $data['user'],
        (string) $data['pass'],
        $options
    );
}

/** Open a connection from a written config, for whichever driver it names. */
function connect_from_config(array $config): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    if (($config['db_driver'] ?? 'mysql') === 'sqlite') {
        $file = $config['db_name'];
        if (!preg_match('~^(/|[A-Za-z]:)~', $file)) {
            $file = BASE_PATH . '/' . ltrim($file, '/');
        }
        $pdo = new PDO('sqlite:' . $file, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    return new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        $options
    );
}

/**
 * Database failures are the ones people get stuck on, and PDO's own wording
 * is not much help to someone reading it in a cPanel tab.
 */
function friendly_db_error(Throwable $e, array $data): string
{
    $text = $e->getMessage();

    if (str_contains($text, 'Access denied')) {
        return 'The database refused that user and password. Check them in cPanel, and that the user is attached to the database.';
    }
    if (str_contains($text, 'Unknown database')) {
        return 'There is no database called "' . $data['name'] . '" on ' . $data['host']
             . '. Create it in cPanel first - the installer does not create databases.';
    }
    if (str_contains($text, 'getaddrinfo') || str_contains($text, "Can't connect")
        || str_contains($text, 'Connection refused')) {
        return 'Could not reach the database server at "' . $data['host']
             . '". On most shared hosting the host is localhost.';
    }

    return $text;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * The site root, and anything under it.
 *
 * Inside the app the helper library has already worked this out, so use it.
 * Standalone, the site root is the folder above this one - SCRIPT_NAME is
 * /install/index.php, or /shop/install/index.php in a subfolder.
 */
function site_url(string $path = ''): string
{
    if (function_exists('url')) {
        return url($path);
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || ($_SERVER['SERVER_PORT'] ?? '') === '443'
          || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(str_replace(chr(92), '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    return ($https ? 'https' : 'http') . '://' . $host . $dir
         . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function guess_base_url(): string
{
    return site_url();
}

/**
 * The config file, for an admin whose host will not let us write it.
 *
 * Shown only after a save has actually failed, because until then it is an
 * instruction for a problem nobody has.
 */
function config_handover(): string
{
    $text = (string) ($_SESSION['install_config_text'] ?? '');
    if ($text === '') {
        return '';
    }

    return '<div class="perm">
        <b>Create config/config.php yourself</b>
        <p class="perm-note">The database is already set up - only this file is missing.
           Make a file at <code>config/config.php</code> with exactly this in it, upload
           it, then press Install again.</p>
        <pre class="cfg">' . h($text) . '</pre>
        <button class="btn btn-ghost btn-copy" type="button"
                data-copy="' . h($text) . '">Copy the file</button>
        <p class="perm-warn">Keep it out of anyone else\'s reach: it holds your database
           password. <code>640</code> is right for it, and the shipped
           <code>.htaccess</code> already refuses to serve the folder.</p>
      </div>';
}

/**
 * What to do about a folder the web server cannot write to.
 *
 * Said here because the alternative is what people actually do: search the
 * web, find 777, and make the folder writable by every other account on the
 * server. On shared hosting that is not a shortcut, it is handing the keys
 * over - and it is never what is needed, because the files were uploaded by
 * the same account PHP runs as on almost every cPanel host.
 */
function permission_help(): string
{
    return '<div class="perm">
        <b>Making a folder writable</b>
        <ol>
          <li>In cPanel File Manager (or your FTP client) right-click the folder and
              choose <b>Permissions</b>.</li>
          <li>Set it to <code>755</code>. On nearly every host PHP runs as the account
              that owns the files, so that is enough.</li>
          <li>Still refused? Try <code>775</code>. That adds the group, which is how a
              host running PHP under a separate web-server group is set up.</li>
        </ol>
        <p class="perm-warn"><b>Do not use 777.</b> It lets every other account on the
           server write into your folder, and on shared hosting that is a lot of
           strangers. It is also never the fix - if 775 does not work, the owner or
           group is wrong and your host has to correct it.</p>
      </div>';
}

/**
 * Is this site already set up?
 *
 * The lock file is one answer and the easy one, but it needs install/ to be
 * writable and writing a marker is not worth a permission. A config file
 * pointing at a database that already has an administrator is the same fact,
 * read from what the install actually produced.
 */
function already_installed(): bool
{
    if (is_file(LOCK_FILE)) {
        return true;
    }
    if (!is_file(CONFIG_FILE)) {
        return false;
    }

    try {
        $config = require CONFIG_FILE;
        if (!is_array($config) || ($config['db_name'] ?? '') === '') {
            return false;
        }
        $pdo = connect_from_config($config);

        return (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    } catch (Throwable $e) {
        // A config that cannot be read or a database that is not there yet is
        // not an install; let the installer carry on and fix it.
        return false;
    }
}

function requirement_checks(): array
{
    $writable = static fn(string $p): bool => is_dir($p) && is_writable($p);

    return [
        ['label' => 'PHP 8.0 or newer', 'ok' => PHP_VERSION_ID >= 80000,
         'value' => PHP_VERSION, 'required' => true],
        ['label' => 'A database driver (MySQL or SQLite)',
         'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
         'value' => implode(' + ', array_filter([
             extension_loaded('pdo_mysql')  ? 'pdo_mysql'  : null,
             extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : null,
         ])) ?: 'neither', 'required' => true],
        ['label' => 'mbstring extension', 'ok' => extension_loaded('mbstring'),
         'value' => extension_loaded('mbstring') ? 'enabled' : 'missing', 'required' => true],
        ['label' => 'JSON extension', 'ok' => extension_loaded('json'),
         'value' => extension_loaded('json') ? 'enabled' : 'missing', 'required' => true],
        ['label' => 'cURL extension (OTPGet API)', 'ok' => extension_loaded('curl'),
         'value' => extension_loaded('curl') ? 'enabled' : 'missing', 'required' => true],
        // None of the four folders below stops an install any more. config/
        // was the only one that ever had to be written, and even that has a
        // way through: when the save fails the installer hands over the file
        // to create by hand, so stopping here would mean building that escape
        // hatch and never reaching it. The rest were required because it was
        // tidy, not because anything breaks - log_line() already writes with
        // @ and carries on, and the lock file is a marker already_installed()
        // can do without.
        ['label' => 'config/ is writable', 'ok' => $writable(BASE_PATH . '/config'),
         'value' => $writable(BASE_PATH . '/config') ? 'writable' : 'not writable',
         'required' => false,
         'hint' => 'Only needed to save config/config.php. Leave it as it is and the '
                 . 'installer will show you that one file to create by hand at the end '
                 . 'of the next step.'],
        ['label' => 'storage/ is writable', 'ok' => $writable(BASE_PATH . '/storage'),
         'value' => $writable(BASE_PATH . '/storage') ? 'writable' : 'not writable',
         'required' => false,
         'hint' => 'Without it the panel still runs, but it keeps no log file and no '
                 . 'cached OTPGet catalogue. Required only if you pick SQLite, which '
                 . 'keeps its database here.'],
        ['label' => 'install/ is writable', 'ok' => $writable(__DIR__),
         'value' => $writable(__DIR__) ? 'writable' : 'not writable',
         'required' => false,
         'hint' => 'Only used to drop install.lock when the install finishes. Without it '
                 . 'the installer still closes itself - it can tell from your config and '
                 . 'admin account that the job is done.'],
        ['label' => 'uploads/branding/ is writable',
         'ok' => $writable(BASE_PATH . '/uploads/branding'),
         'value' => $writable(BASE_PATH . '/uploads/branding') ? 'writable' : 'not writable',
         'required' => false,
         'hint' => 'Only needed to upload a logo and favicon from Settings.'],
        ['label' => 'GD extension (image resizing)', 'ok' => extension_loaded('gd'),
         'value' => extension_loaded('gd') ? 'enabled' : 'missing', 'required' => false],
    ];
}

function migration_list(): array
{
    $file = __DIR__ . '/migrations.php';
    return is_file($file) ? require $file : [];
}

/**
 * Apply one migration to a brand-new database and mark it done, so an install
 * made today is in the same state as one that has been updated.
 *
 * @return array{ok: bool, note?: string, error?: string}
 */
function apply_migration(PDO $pdo, string $driver, string $id): array
{
    $all = migration_list();
    if (!isset($all[$id])) {
        return ['ok' => false, 'error' => 'Unknown migration: ' . $id];
    }

    $pdo->exec($driver === 'sqlite'
        ? 'CREATE TABLE IF NOT EXISTS "migrations" ("id" TEXT NOT NULL PRIMARY KEY, "applied_at" TEXT NOT NULL)'
        : 'CREATE TABLE IF NOT EXISTS `migrations` (`id` VARCHAR(100) NOT NULL, `applied_at` DATETIME NOT NULL,
             PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $migration = $all[$id];
    $skipped   = 0;

    foreach (array_merge($migration[$driver] ?? [], $migration['both'] ?? []) as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // The fresh schema already carries some of what a migration
            // adds, so "it is already there" is the expected answer here.
            $text  = strtolower($e->getMessage());
            $known = str_contains($text, 'duplicate')
                  || str_contains($text, 'already exists')
                  || str_contains($text, 'unique constraint failed');
            if (!$known) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            $skipped++;
        }
    }

    $already = $pdo->prepare('SELECT 1 FROM migrations WHERE id = ?');
    $already->execute([$id]);
    if (!$already->fetchColumn()) {
        $pdo->prepare('INSERT INTO migrations (id, applied_at) VALUES (?, ?)')
            ->execute([$id, date('Y-m-d H:i:s')]);
    }

    return ['ok' => true, 'note' => $skipped ? $skipped . ' already in place' : 'Applied'];
}

/**
 * Write a settings row.
 *
 * Spelled out rather than using an upsert, because MySQL and SQLite word
 * theirs differently.
 */
function put_setting(PDO $pdo, string $key, string $value): void
{
    $update = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
    $update->execute([$value, $key]);

    if ($update->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
        $exists->execute([$key]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
        }
    }
}

/**
 * Execute schema.sql. Statements are split on semicolons at end of line, which
 * is enough for this file: it has no stored routines or embedded semicolons.
 */
function run_schema(PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        throw new RuntimeException(basename($file) . ' is missing from install/.');
    }

    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);

    foreach (preg_split('/;\s*[\r\n]+/', (string) $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/**
 * Does the config on disk already say what we were about to write?
 *
 * Compared on the keys that decide which database is used. The generated
 * keys differ every run, so comparing the whole file would never match and
 * the admin would be asked for it again and again.
 */
function config_matches(string $path, array $config): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    try {
        $existing = require $path;
    } catch (Throwable $e) {
        return false;
    }
    if (!is_array($existing)) {
        return false;
    }

    foreach (['db_driver', 'db_host', 'db_name', 'db_user'] as $key) {
        if ((string) ($existing[$key] ?? '') !== (string) ($config[$key] ?? '')) {
            return false;
        }
    }

    // It must also actually work, or "already in place" is a lie that only
    // shows up two steps later.
    try {
        connect_from_config($existing);
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

/** The config file as text, so it can be written or handed over to be pasted. */
function config_text(array $config): string
{
    $lines = ["<?php", "/** Written by the installer - edit by hand only when moving servers. */", "return ["];
    foreach ($config as $key => $value) {
        $lines[] = sprintf("    %-12s => %s,", var_export($key, true),
            is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true));
    }
    $lines[] = "];";

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function write_config(string $path, array $config): bool
{
    return (bool) @file_put_contents($path, config_text($config));
}

function errors_html(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $items = '';
    foreach ($errors as $error) {
        $items .= '<li>' . h($error) . '</li>';
    }
    return '<ul class="errors">' . $items . '</ul>';
}

/**
 * The progress block and submit button shared by steps 2 and 3.
 *
 * The bar and the list start hidden: without JavaScript they would never
 * fill in, and the form still posts and still works.
 */
function runner_html(string $label): string
{
    return '
      <div class="run" data-run-ui hidden>
        <div class="bar"><div class="bar-fill" data-bar></div></div>
        <p class="bar-note"><span data-bar-label></span><b data-bar-count></b></p>
        <ol class="tasks" data-task-list></ol>
      </div>
      <ul class="errors" data-run-error hidden></ul>
      <button class="btn" type="submit" data-submit data-busy="' . h($label) . '">' . h($label) . '</button>';
}

function render_page(string $title, int $step, string $body): void
{
    $steps = ['Server', 'Database', 'Administrator', 'Done'];
    $crumbs = '';
    foreach ($steps as $index => $name) {
        $number = $index + 1;
        $state  = $step > $number ? ' done' : ($step === $number ? ' on' : '');
        $crumbs .= '<li class="' . trim('crumb' . $state) . '">'
                 . '<span class="num">' . ($step > $number ? '&#10003;' : $number) . '</span>'
                 . '<span class="nm">' . h($name) . '</span></li>';
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>' . h($title) . ' &mdash; Installer</title><style>'
       . install_css()
       . '</style></head><body><div class="wrap">'
       . '<div class="head"><b>OTP Flash</b><span>Installation</span></div>'
       . ($step ? '<ol class="crumbs">' . $crumbs . '</ol>' : '')
       . $body
       . '</div><script>' . install_js() . '</script></body></html>';
}

function install_css(): string
{
    return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
:root{--acc:#4f46e5;--ink:#141726;--ink-2:#4a5068;--muted:#838aa0;--line:#e8eaf1;--line-2:#d5d9e5;
  --page:#f5f6fa;--ok:#16a34a;--bad:#dc2626}
body{margin:0;font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--page);
  color:var(--ink);padding:28px 16px}
*{scrollbar-width:thin;scrollbar-color:var(--line-2) transparent}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:var(--line-2);border-radius:999px;border:2.5px solid var(--page);
  background-clip:padding-box}
.wrap{max-width:560px;margin:0 auto}
.head{text-align:center;margin-bottom:18px}
.head b{display:block;font-size:21px;letter-spacing:-.02em}
.head span{color:var(--muted);font-size:13.5px}
.crumbs{display:flex;align-items:center;gap:6px;list-style:none;margin:0 0 18px;padding:0}
.crumb{flex:1;display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--muted);
  min-width:0}
.crumb .num{width:22px;height:22px;border-radius:50%;flex:none;display:grid;place-items:center;
  background:#e8eaf1;color:var(--muted);font-size:11.5px;font-weight:700}
.crumb .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crumb.on{color:var(--acc)}
.crumb.on .num{background:var(--acc);color:#fff}
.crumb.done{color:var(--ok)}
.crumb.done .num{background:rgba(22,163,74,.16);color:var(--ok)}
.box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px}
h2{margin:0 0 6px;font-size:19px;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin:0 0 20px}
.fields label{display:block;margin-bottom:15px;font-size:13px;font-weight:600;color:var(--ink-2)}
.hint{font-weight:400;color:#9aa0b4}
.chint{display:block;color:#6b7280;font-weight:400;font-size:12.5px;line-height:1.5;margin-top:3px;
  max-width:46ch}
.perm{background:#f8f9fc;border:1px solid #e6e8f0;border-radius:12px;padding:15px 17px;margin:14px 0}
.perm b{display:block;margin-bottom:8px;font-size:14px}
.perm ol{margin:0 0 10px;padding-left:20px;font-size:13.5px;line-height:1.7;color:#4b5563}
.perm code{background:#eef0f6;border-radius:5px;padding:1px 6px;font-size:12.5px}
.perm-warn{margin:0;background:#fdeaea;border:1px solid #f6caca;border-radius:9px;padding:11px 13px;
  font-size:13px;line-height:1.6;color:#b91c1c}
.perm-warn b{display:inline;margin:0}
.perm-note{margin:0 0 10px;font-size:13.5px;line-height:1.6;color:#4b5563}
.cfg{background:#15152a;color:#e6e6f0;border-radius:10px;padding:13px 15px;overflow-x:auto;
  font-size:12.5px;line-height:1.6;margin:0 0 10px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.btn-copy{margin-bottom:12px}
input{display:block;width:100%;margin-top:7px;padding:11px 13px;font-size:15px;font-weight:400;
  border:1px solid var(--line);border-radius:9px;background:#f9fafc;outline:none;color:var(--ink)}
input:focus{border-color:var(--acc);background:#fff}
select{display:block;width:100%;margin-top:7px;padding:11px 13px;font:inherit;font-size:15px;
  font-weight:400;border:1px solid var(--line);border-radius:9px;background:#f9fafc;color:var(--ink)}
select:focus{border-color:var(--acc);background:#fff;outline:none}
/* A tick and its words on one line: without this the box takes the full
   width every other control here gets, and reads as a broken text field. */
.fields label.check{display:flex;flex-wrap:wrap;align-items:flex-start;gap:9px}
.fields label.check input{width:auto;display:inline-block;margin:2px 0 0;flex:none}
.fields label.check .hint{flex-basis:100%;margin:0;font-size:12.5px;line-height:1.5}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;margin-top:6px;
  background:var(--acc);color:#fff;border:0;border-radius:9px;padding:12px 22px;font:inherit;
  font-size:15px;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{background:#4338ca}
.btn-ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
.btn-ghost:hover{background:var(--page)}
.btn[aria-busy="true"]{opacity:.75;pointer-events:none}
.btn-block{width:100%}
table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px}
td{padding:9px 6px;border-bottom:1px solid #f0f1f6}
td.r{text-align:right;color:var(--muted);font-size:13px}
.check-ok{color:var(--ok);font-weight:700}
.bad{color:var(--bad);font-weight:700}
.meh{color:#d97706;font-weight:700}
.choice{display:grid;gap:10px;margin-bottom:20px}
.opt{display:block;border:1.5px solid var(--line);border-radius:11px;padding:13px 15px;cursor:pointer;
  margin:0;font-weight:400;transition:border-color .15s,background .15s}
.opt.on{border-color:var(--acc);background:#f7f8ff}
.opt.off{opacity:.5;cursor:not-allowed}
.opt input{width:auto;display:inline;margin:0 8px 0 0;vertical-align:middle}
.opt b{font-size:14.5px;font-weight:600}
.opt small{display:block;color:var(--muted);font-size:12.5px;margin-top:3px}
.note{background:var(--page);border-radius:9px;padding:11px 13px;font-size:12.5px;color:var(--ink-2);
  margin:0 0 15px}
.errors{background:#fdeaea;border:1px solid #f6caca;color:#b91c1c;border-radius:9px;
  padding:12px 14px 12px 30px;margin:0 0 18px;font-size:13.5px}
.warn{background:#fff7e6;border:1px solid #f6e2b8;color:#8a5d2a;border-radius:9px;
  padding:12px 14px;font-size:13.5px;margin:0 0 14px}
pre{background:var(--page);border:1px solid var(--line);border-radius:9px;padding:11px 13px;
  font-size:12.5px;overflow-x:auto;margin:0 0 14px}
.lbl{font-size:12.5px;font-weight:700;color:var(--ink-2);margin:0 0 6px}
code{background:var(--page);border-radius:5px;padding:1px 5px;font-size:13px}
.done-tick{width:46px;height:46px;border-radius:50%;background:#e7f8ee;color:var(--ok);display:grid;
  place-items:center;font-size:24px;margin-bottom:14px}
.mt{margin-top:18px;margin-bottom:0}
.spin{display:inline-block;width:1em;height:1em;border-radius:50%;border:2px solid currentColor;
  border-right-color:transparent;animation:spin .62s linear infinite;vertical-align:-.15em}
@keyframes spin{to{transform:rotate(360deg)}}
.bar{height:9px;border-radius:999px;background:var(--line);overflow:hidden;margin-bottom:9px}
.bar-fill{height:100%;width:0;border-radius:999px;background:var(--acc);
  transition:width .35s cubic-bezier(.4,0,.2,1)}
.bar-fill.busy{background-image:linear-gradient(110deg,rgba(255,255,255,.32) 25%,transparent 25%,
  transparent 50%,rgba(255,255,255,.32) 50%,rgba(255,255,255,.32) 75%,transparent 75%);
  background-size:18px 18px;animation:barmove .7s linear infinite}
.bar-fill.bad{background:var(--bad)}
@keyframes barmove{to{background-position:18px 0}}
.bar-note{display:flex;gap:10px;font-size:13px;font-weight:600;color:var(--ink-2);margin:0 0 14px}
.bar-note b{margin-left:auto;color:var(--muted);font-variant-numeric:tabular-nums}
.tasks{list-style:none;margin:0 0 18px;padding:0;max-height:260px;overflow-y:auto}
.tasks li{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line);
  font-size:13.5px;opacity:.5;transition:opacity .2s}
.tasks li:last-child{border-bottom:0}
.tasks li.on,.tasks li.ok,.tasks li.bad{opacity:1}
.tasks .dot{width:22px;height:22px;border-radius:50%;flex:none;display:grid;place-items:center;
  background:var(--line);color:var(--muted);font-size:11px;font-weight:700}
.tasks li.on .dot{background:#eef0fe;color:var(--acc)}
.tasks li.ok .dot{background:rgba(22,163,74,.16);color:var(--ok)}
.tasks li.bad .dot{background:#fdeaea;color:var(--bad)}
.tasks .nm{flex:1;min-width:0;font-weight:600}
.tasks .st{flex:none;font-size:12px;color:var(--muted);font-weight:600}
.tasks li.bad .st{color:var(--bad)}
/* ---------------------------------------------------------------------------
   Phones. There was no media query here at all, which is how four step
   crumbs ended up sharing one flex row: at 360px each got about 85px and
   every label ellipsed away, so the one thing the bar exists to say - what
   is done and what is next - was the first thing to go.
   --------------------------------------------------------------------------- */
@media (max-width: 560px) {
  body{padding:16px 12px}
  .box{padding:18px 16px;border-radius:12px}
  .head{margin-bottom:14px}

  /* Numbers for the steps you are not on, the name only for the one you are.
     A tick, a dot and a name reads at a glance; four truncated words do not. */
  .crumbs{gap:5px;margin-bottom:14px}
  .crumb{flex:none}
  .crumb .nm{display:none}
  .crumb.on{flex:1;min-width:0}
  .crumb.on .nm{display:block}
  .crumb .num{width:26px;height:26px;font-size:12.5px}

  /* The row of ticks and values: let the label wrap instead of squeezing the
     answer off the edge. */
  table{font-size:13.5px}
  td{padding:9px 4px}
  .chint{font-size:12px}

  .btn{width:100%;margin-top:8px}
  .btn + .btn{margin-top:8px}
  .perm{padding:13px 14px}
  .perm ol{padding-left:17px;font-size:13px}
  .cfg{font-size:11.5px}
}
CSS;
}

function install_js(): string
{
    return <<<'JS'
(function () {
  /* Copy the config file over, for a host that will not let us write it.
     Without this the button is decoration, and the alternative is selecting
     twenty lines of PHP by hand on a phone. */
  document.addEventListener('click', function (event) {
    var copier = event.target.closest('[data-copy]');
    if (copier) {
      var text = copier.getAttribute('data-copy');
      var said = function () {
        var was = copier.textContent;
        copier.textContent = 'Copied';
        setTimeout(function () { copier.textContent = was; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(said, function () {});
      } else {
        /* No clipboard API without HTTPS, and a fresh install is often
           http:// on a bare domain. Select it so Ctrl+C still works. */
        var pre = document.querySelector('.cfg');
        if (pre && window.getSelection) {
          var range = document.createRange();
          range.selectNodeContents(pre);
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        }
      }
      return;
    }

    var link = event.target.closest('a[data-busy]');
    if (!link) { return; }
    link.setAttribute('aria-busy', 'true');
    link.innerHTML = '<span class="spin"></span> ' + link.getAttribute('data-busy');
  });

  var form = document.querySelector('form[data-run]');
  if (!form || !window.fetch) { return; }

  var ui      = form.querySelector('[data-run-ui]');
  var list    = form.querySelector('[data-task-list]');
  var bar     = form.querySelector('[data-bar]');
  var label   = form.querySelector('[data-bar-label]');
  var count   = form.querySelector('[data-bar-count]');
  var errBox  = form.querySelector('[data-run-error]');
  var button  = form.querySelector('[data-submit]');
  var planUrl = form.getAttribute('data-plan');
  var taskUrl = form.getAttribute('data-task');
  var nextUrl = form.getAttribute('data-next');

  function post(url, body) {
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('The server answered ' + response.status + '.'); }
        return response.json();
      });
  }

  function stop(message) {
    bar.classList.remove('busy');
    bar.classList.add('bad');
    errBox.innerHTML = '<li></li>';
    errBox.firstChild.textContent = message;
    errBox.hidden = false;
    button.removeAttribute('aria-busy');
    button.textContent = 'Try again';
  }

  form.addEventListener('submit', function (event) {
    if (!form.reportValidity()) { return; }
    event.preventDefault();

    errBox.hidden = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="spin"></span> ' + button.getAttribute('data-busy');

    post(planUrl, new FormData(form)).then(function (plan) {
      if (!plan.ok) { throw new Error(plan.error || 'That did not work.'); }

      var tasks = plan.tasks || [];
      ui.hidden = false;
      list.innerHTML = '';

      tasks.forEach(function (task, index) {
        var row = document.createElement('li');
        row.innerHTML = '<span class="dot">' + (index + 1) + '</span>'
                      + '<span class="nm"></span><span class="st"></span>';
        row.querySelector('.nm').textContent = task.label;
        list.appendChild(row);
        task.row = row;
      });

      var index = 0;

      function next() {
        count.textContent = index + ' / ' + tasks.length;
        bar.style.width = Math.round((index / tasks.length) * 100) + '%';

        if (index >= tasks.length) {
          bar.classList.remove('busy');
          label.textContent = 'Finished';
          window.location.href = nextUrl;
          return;
        }

        var task = tasks[index];
        task.row.classList.add('on');
        task.row.querySelector('.dot').innerHTML = '<span class="spin"></span>';
        label.textContent = task.label;
        bar.classList.add('busy');

        var body = new FormData();
        post(taskUrl + encodeURIComponent(task.key), body).then(function (result) {
          task.row.classList.remove('on');
          task.row.classList.add(result.ok ? 'ok' : 'bad');
          task.row.querySelector('.dot').innerHTML = result.ok ? '&#10003;' : '&#10007;';
          task.row.querySelector('.st').textContent = result.ok ? (result.note || '') : 'failed';

          if (!result.ok) {
            stop(result.error || 'That step failed.');
            /* The one failure with something to hand back: the config file we
               could not write. Rendered here because the stepper never reloads
               the page, so the copy of this block in the PHP would never be
               seen by the person who needs it. */
            if (result.config) { showConfig(result.config); }
            return;
          }

          index++;
          /* A breath between tasks, so a fast server still reads as progress
             rather than one instant jump. */
          setTimeout(next, 160);
        }).catch(function (error) {
          task.row.classList.remove('on');
          task.row.classList.add('bad');
          task.row.querySelector('.dot').innerHTML = '&#10007;';
          stop(error.message);
        });
      }

      next();
    }).catch(function (error) {
      ui.hidden = true;
      stop(error.message);
    });

    function showConfig(text) {
      if (form.querySelector('.cfg')) { return; }

      var block = document.createElement('div');
      block.className = 'perm';

      var title = document.createElement('b');
      title.textContent = 'Create config/config.php yourself';

      var note = document.createElement('p');
      note.className = 'perm-note';
      note.textContent = 'The database is already set up - only this file is missing. '
        + 'Put it at config/config.php, then press Try again.';

      var pre = document.createElement('pre');
      pre.className = 'cfg';
      pre.textContent = text;          /* textContent, so the file is shown, not run */

      var copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'btn btn-ghost btn-copy';
      copy.setAttribute('data-copy', text);
      copy.textContent = 'Copy the file';

      var warn = document.createElement('p');
      warn.className = 'perm-warn';
      warn.textContent = 'It holds your database password, so keep it to yourself: 640 is '
        + 'right for it, and the shipped .htaccess already refuses to serve that folder.';

      block.appendChild(title);
      block.appendChild(note);
      block.appendChild(pre);
      block.appendChild(copy);
      block.appendChild(warn);
      errBox.parentNode.insertBefore(block, errBox.nextSibling);
    }
  });
})();
JS;
}
