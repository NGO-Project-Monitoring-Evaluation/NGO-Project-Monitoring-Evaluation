<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/demo_data.php';

$lock = APP_ROOT . '/config/install.lock';
$errors = [];

/** Connect to the configured database, creating it first if it does not exist (works with XAMPP). */
function installer_connect(): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $dsn = fn(string $db) => sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', cfg('db_host'), (int) cfg('db_port', 3306), $db !== '' ? 'dbname=' . $db . ';' : '');
    try {
        return new PDO($dsn((string) cfg('db_name')), (string) cfg('db_user'), (string) cfg('db_pass'), $opts);
    } catch (PDOException $ex) {
        if (strpos($ex->getMessage(), '1049') === false) { throw $ex; }
        $server = new PDO($dsn(''), (string) cfg('db_user'), (string) cfg('db_pass'), $opts);
        $name = str_replace('`', '', (string) cfg('db_name'));
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return new PDO($dsn($name), (string) cfg('db_user'), (string) cfg('db_pass'), $opts);
    }
}

function installer_run_schema(PDO $pdo): void
{
    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        if (trim($stmt) !== '') { $pdo->exec($stmt); }
    }
}

if (file_exists($lock)) {
    render_header('Installation');
    echo '<div class="card"><div class="card-body"><h2 class="h5">Already installed</h2><p>The system has already been set up. ' .
         'To protect it, this installer is switched off.</p><a class="btn btn-primary" href="' . e(url('login.php')) . '">Go to the login page</a></div></div>';
    render_footer();
    exit;
}

// Requirements check
$checks = [
    ['PHP 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), 'Installed version: ' . PHP_VERSION, true],
    ['PDO MySQL extension (pdo_mysql)', extension_loaded('pdo_mysql'), 'Needed to talk to MySQL', true],
    ['Multibyte strings (mbstring)', extension_loaded('mbstring'), 'Needed for names with special characters', true],
    ['Zip extension (zip)', class_exists('ZipArchive'), 'Needed for Excel exports; CSV files are used instead if it is missing', false],
    ['Folder config/ is writable', is_writable(APP_ROOT . '/config'), 'Needed to switch the installer off when finished', false],
];
$blocking = array_filter($checks, fn($c) => $c[3] && !$c[1]);

$pdo = null; $dbError = '';
if (!$blocking) {
    try { $pdo = installer_connect(); } catch (Throwable $ex) { $dbError = $ex->getMessage(); }
}

$data = ['full_name' => '', 'email' => '', 'demo' => ''];
if (is_post() && $pdo && !$blocking) {
    csrf_verify();
    $data = ['full_name' => post('full_name'), 'email' => strtolower(post('email')), 'demo' => isset($_POST['demo']) ? '1' : ''];
    $pw = (string) ($_POST['password'] ?? ''); $pw2 = (string) ($_POST['password2'] ?? '');
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 100) $errors[] = 'Enter the administrator\'s full name.';
    if (!valid_email($data['email'])) $errors[] = 'Enter a valid email address for the administrator.';
    if ($p = password_problem($pw)) $errors[] = $p;
    if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';
    if (!$errors) {
        try {
            installer_run_schema($pdo);
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $errors[] = 'This database already contains user accounts. If you really want to start again, empty the database first.';
            } else {
                $st = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role_id, is_active) VALUES (?,?,?,1,1)');
                $st->execute([$data['full_name'], $data['email'], password_hash($pw, PASSWORD_DEFAULT)]);
                $adminId = (int) $pdo->lastInsertId();
                audit('Create', 'users', $adminId, 'Installation: administrator account created', $adminId);
                if ($data['demo']) { load_demo_data($adminId); }
                $wrote = @file_put_contents($lock, 'Installed on ' . date('c') . "\n");
                render_header('Installation complete');
                echo '<div class="card"><div class="card-body"><h2 class="h5 text-success">Installation complete</h2>'
                   . '<p>The database tables were created and the administrator account is ready.' . ($data['demo'] ? ' Sample data was loaded (see the setup guide for the demonstration accounts).' : '') . '</p>'
                   . ($wrote === false ? '<div class="alert alert-warning">The installer could not switch itself off. Please delete <code>install.php</code> from the server now.</div>' : '<p class="text-muted small">The installer has been switched off. You may also delete <code>install.php</code>.</p>')
                   . '<a class="btn btn-primary" href="' . e(url('login.php')) . '">Go to the login page</a></div></div>';
                render_footer();
                exit;
            }
        } catch (Throwable $ex) {
            error_log('Installer error: ' . $ex->getMessage());
            $errors[] = 'The installation failed: ' . $ex->getMessage();
        }
    }
}

render_header('Install the system');
show_errors($errors);
?>
<div class="card mb-3"><div class="card-header">1. Requirements</div><ul class="list-group list-group-flush">
<?php foreach ($checks as [$label, $ok, $note]): ?>
  <li class="list-group-item d-flex justify-content-between"><span><?= e($label) ?><span class="d-block small text-muted"><?= e($note) ?></span></span>
    <span class="badge text-bg-<?= $ok ? 'success' : 'danger' ?> align-self-center"><?= $ok ? 'OK' : 'Missing' ?></span></li>
<?php endforeach; ?>
  <li class="list-group-item d-flex justify-content-between"><span>Database connection<span class="d-block small text-muted"><?= e(cfg('db_user')) ?>@<?= e(cfg('db_host')) ?> / <?= e(cfg('db_name')) ?></span></span>
    <span class="badge text-bg-<?= $pdo ? 'success' : 'danger' ?> align-self-center"><?= $pdo ? 'Connected' : 'Failed' ?></span></li>
</ul></div>
<?php if ($blocking): ?>
  <div class="alert alert-danger">A required PHP extension is missing. Enable it in <code>php.ini</code> (in XAMPP, open Config &rarr; PHP (php.ini) and remove the semicolon before the extension), restart Apache and reload this page.</div>
<?php elseif (!$pdo): ?>
  <div class="alert alert-danger">The database connection failed. Start MySQL (in XAMPP, press Start next to MySQL), then check the values in <code>config/config.php</code> and reload this page.
    <div class="small mt-2"><code><?= e($dbError) ?></code></div></div>
<?php else: ?>
<div class="card"><div class="card-header">2. Create the administrator account</div><div class="card-body">
  <form method="post" novalidate><?= csrf_field() ?>
    <div class="mb-3"><label class="form-label required" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= e($data['full_name']) ?>" required></div>
    <div class="mb-3"><label class="form-label required" for="email">Email address (used to log in)</label><input class="form-control" type="email" id="email" name="email" value="<?= e($data['email']) ?>" required></div>
    <div class="mb-3"><label class="form-label required" for="password">Password</label><input class="form-control" type="password" id="password" name="password" autocomplete="new-password" required>
      <div class="form-text">At least 8 characters, with a letter and a number.</div></div>
    <div class="mb-3"><label class="form-label required" for="password2">Confirm password</label><input class="form-control" type="password" id="password2" name="password2" autocomplete="new-password" required></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="demo" name="demo" value="1" <?= $data['demo'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="demo">Also load sample data (two projects, activities, 40 beneficiaries, indicators, donors, grants, budgets, expenditures, purchase orders and seven demonstration accounts, one for each other role). Tick this for a demonstration; leave it unticked for real use.</label></div>
    <button class="btn btn-primary w-100" type="submit">Install</button>
  </form></div></div>
<?php endif; ?>
<?php render_footer();
