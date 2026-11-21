<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
if (platform_registry_available()) redirect('platform/login.php');
$legacyAdmin = current_user();
if (!$legacyAdmin || !is_admin()) redirect('login.php');
$errors=[]; $data=['organisation_name'=>'','organisation_code'=>'','admin_name'=>'','admin_email'=>''];
if (is_post()) {
    csrf_verify();
    foreach ($data as $k=>$v) $data[$k]=post($k);
    $data['organisation_code']=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$data['organisation_code']));
    $password=(string)($_POST['admin_password']??''); $password2=(string)($_POST['admin_password2']??'');
    if ($data['organisation_name']==='' || mb_strlen($data['organisation_name'])>150) $errors[]='Enter the existing organisation name.';
    if (!preg_match('/^[A-Z0-9]{3,24}$/',$data['organisation_code'])) $errors[]='Use 3–24 letters or numbers for the organisation code.';
    if ($data['admin_name']==='' || mb_strlen($data['admin_name'])>100) $errors[]='Enter the new Organisation Administrator name.';
    if (!valid_email($data['admin_email'])) $errors[]='Enter a valid Organisation Administrator email.';
    if (strtolower($data['admin_email'])===strtolower($legacyAdmin['email'])) $errors[]='Use a different email for the Organisation Administrator; the existing account will become the Platform Super Administrator.';
    if ($p=password_problem($password)) $errors[]=$p;
    if ($password!==$password2) $errors[]='The Organisation Administrator passwords do not match.';
    if (!$errors) {
        $legacyPdo=db(); $platformCreated=false; $orgId=0; $newOrgAdminId=0;
        try {
            $legacyAccount=$legacyPdo->prepare('SELECT user_id,full_name,email,password_hash FROM users WHERE user_id=? AND is_active=1');
            $legacyAccount->execute([(int)$legacyAdmin['user_id']]); $legacyAccount=$legacyAccount->fetch();
            if (!$legacyAccount) throw new RuntimeException('The original administrator account could not be verified.');
            $platform=platform_db(true);
            $existingRegistryTables=$platform->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if ($existingRegistryTables) throw new RuntimeException('The configured platform database is not empty. Choose an empty database or review it before continuing.');
            $platformCreated=true; ensure_platform_schema(false); $platform=platform_db();
            if ((int)$platform->query('SELECT COUNT(*) FROM organisations')->fetchColumn()!==0) throw new RuntimeException('The platform registry is not empty. Stop and review it before continuing.');
            $legacyPdo->exec("INSERT IGNORE INTO roles (role_id,role_name) VALUES (10,'Organisation Administrator')");
            $role=(int)$legacyPdo->query("SELECT role_id FROM roles WHERE role_name='Organisation Administrator'")->fetchColumn();
            $st=$legacyPdo->prepare('INSERT INTO users (full_name,email,password_hash,role_id,is_active) VALUES (?,?,?,?,1)');
            $st->execute([$data['admin_name'],strtolower($data['admin_email']),password_hash($password,PASSWORD_DEFAULT),$role]);
            $newOrgAdminId=(int)$legacyPdo->lastInsertId();
            $st=$platform->prepare('INSERT INTO platform_admins (full_name,email,password_hash,is_active) VALUES (?,?,?,1)');
            $st->execute([$legacyAccount['full_name'],$legacyAccount['email'],$legacyAccount['password_hash']]);
            $st=$platform->prepare('INSERT INTO organisations (organisation_name,organisation_code,database_name,status) VALUES (?,?,?,\'Active\')');
            $st->execute([$data['organisation_name'],$data['organisation_code'],(string)cfg('db_name')]);
            $orgId=(int)$platform->lastInsertId();
            $platform->prepare('INSERT INTO platform_audit_log (platform_admin_id,action,organisation_id,details) VALUES (?,?,?,?)')->execute([1,'Initial migration',$orgId,'Existing database registered as the first organisation; original administrator retained in the tenant and registered as Platform Super Administrator.']);
            // Keep the original administrator active in the original organisation.
            // Platform and tenant sessions are now separate, so both dashboards can be used independently.
            if (file_put_contents(APP_ROOT . '/config/platform.lock', 'Multi-organisation mode enabled on ' . date('c')) === false) { throw new RuntimeException('Could not write the platform mode lock file.'); }
            unset($_SESSION['uid'], $_SESSION['org_id'], $_SESSION['org_code'], $_SESSION['schema_checked_v6']);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            // Platform administration has its own session cookie. Ask the administrator
            // to sign in there rather than writing a platform identity into the tenant session.
            redirect('platform/login.php');
        } catch (Throwable $e) {
            error_log('Multi-organisation migration failed: '.$e->getMessage());
            if ($newOrgAdminId) { try {$legacyPdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$newOrgAdminId]);} catch(Throwable $ignore){} }
            @unlink(APP_ROOT . '/config/platform.lock');
            if ($platformCreated) { try {$platform=platform_db(); if($orgId)$platform->prepare('DELETE FROM organisations WHERE organisation_id=?')->execute([$orgId]); $platform->exec('DROP TABLE IF EXISTS platform_audit_log'); $platform->exec('DROP TABLE IF EXISTS organisations'); $platform->exec('DROP TABLE IF EXISTS platform_admins');} catch(Throwable $ignore){} }
            $errors[]='The conversion could not be completed. The wizard attempted to roll back its partial records. Check the database permissions and server error log before retrying.';
        }
    }
}
render_header('Set up organisation management'); show_errors($errors); ?>
<div class="card"><div class="card-header">Convert the current installation</div><div class="card-body">
<p>This one-time process keeps the current database as the first organisation. Existing projects, beneficiaries, finance records and other records remain in that database. The current Super Administrator account is registered as the Platform Super Administrator and remains active in the original organisation; create a separate Organisation Administrator below.</p>
<form method="post"><?=csrf_field()?>
<h3 class="h6 mt-3">Existing organisation</h3>
<div class="mb-3"><label class="form-label" for="organisation_name">Organisation name</label><input class="form-control" id="organisation_name" name="organisation_name" value="<?=e($data['organisation_name'])?>" required></div>
<div class="mb-3"><label class="form-label" for="organisation_code">Organisation code</label><input class="form-control text-uppercase" id="organisation_code" name="organisation_code" maxlength="24" value="<?=e($data['organisation_code'])?>" required><div class="form-text">Staff and clients will use this code to select this organisation.</div></div>
<hr><h3 class="h6">New Organisation Administrator</h3>
<div class="mb-3"><label class="form-label" for="admin_name">Full name</label><input class="form-control" id="admin_name" name="admin_name" value="<?=e($data['admin_name'])?>" required></div>
<div class="mb-3"><label class="form-label" for="admin_email">Email address</label><input class="form-control" type="email" id="admin_email" name="admin_email" value="<?=e($data['admin_email'])?>" required></div>
<div class="mb-3"><label class="form-label" for="admin_password">Temporary password</label><input class="form-control" type="password" id="admin_password" name="admin_password" required></div>
<div class="mb-3"><label class="form-label" for="admin_password2">Confirm password</label><input class="form-control" type="password" id="admin_password2" name="admin_password2" required></div>
<div class="alert alert-warning small">Before proceeding, take a full backup of the existing database. The database account must have access to the central platform database. If PHP cannot create it, create it in your hosting control panel first and set its exact name in config/config.php.</div>
<button class="btn btn-primary" type="submit">Convert to multi-organisation setup</button>
</form></div></div>
<?php render_footer(); ?>
