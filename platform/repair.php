<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_platform_login();
if (!is_post()) { http_response_code(405); exit('Method not allowed'); }
csrf_verify();
$id = (int) post('organisation_id');
$adminName = post('admin_name'); $adminEmail = strtolower(post('admin_email'));
$password = (string) ($_POST['admin_password'] ?? ''); $password2 = (string) ($_POST['admin_password2'] ?? '');
$problem = null;
if ($adminName === '' || mb_strlen($adminName) > 100) $problem = 'Enter the administrator name.';
elseif (!valid_email($adminEmail)) $problem = 'Enter a valid administrator email address.';
elseif ($p = password_problem($password)) $problem = $p;
elseif ($password !== $password2) $problem = 'The administrator passwords do not match.';
if ($problem === null) {
    try {
        [$old, $new] = repair_organisation_database($id, $adminName, $adminEmail, $password);
        flash('success', 'The organisation now has its own clean database (' . $new . '). The previous database (' . $old . ') was left untouched. Sign in to it with the new administrator account.');
    } catch (OrganisationException $e) {
        $problem = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Organisation repair failed: ' . $e->getMessage());
        $problem = 'The organisation could not be repaired. Nothing was changed. Check the database permissions and server error log.';
    }
}
if ($problem !== null) flash('danger', $problem);
redirect('platform/index.php');
