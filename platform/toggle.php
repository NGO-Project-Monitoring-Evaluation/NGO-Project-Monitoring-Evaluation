<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_platform_login();
if(!is_post()) { http_response_code(405); exit('Method not allowed'); }
csrf_verify(); $id=(int)post('organisation_id');
$st=platform_db()->prepare('SELECT organization_id,status FROM organisations WHERE organization_id=?'); $st->execute([$id]); $org=$st->fetch();
if(!$org) { http_response_code(404); exit('Organisation not found'); }
$new=$org['status']==='Active'?'Suspended':'Active';
$platform=platform_db(); $platform->prepare('UPDATE organizations SET status=? WHERE organization_id=?')->execute([$new,$id]);
platform_audit('Organization '.$new,$id,'Organisation status changed to '.$new.'.');
flash('success','Organization status updated.'); redirect('platform/index.php');
