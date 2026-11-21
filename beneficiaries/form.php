<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_beneficiaries');

$id = int_or_null(get('id'));
$ben = $id ? fetch_one('SELECT * FROM beneficiaries WHERE beneficiary_id = ?', [$id]) : null;
if ($id && !$ben) { not_found('beneficiary'); }
if ($ben && !can_view_beneficiary((int)$id)) { deny_access(); }

$data = $ben ?: ['full_name' => '', 'sex' => '', 'date_of_birth' => '', 'phone' => '', 'community' => '', 'state' => '', 'consent_given' => 0];
$data['project_id'] = int_or_null(get('project_id'));
$errors = [];

if (is_post()) {
    csrf_verify();
    $data = [
        'full_name'     => preg_replace('/\s+/', ' ', post('full_name')),
        'sex'           => post('sex'),
        'date_of_birth' => post('date_of_birth'),
        'phone'         => normalise_phone(post('phone')),
        'community'     => post('community'),
        'state'         => post('state'),
        'consent_given' => isset($_POST['consent_given']) ? 1 : 0,
        'project_id'    => int_or_null(post('project_id')),
    ];
    if ($data['full_name'] === '')            $errors[] = 'Enter the beneficiary\'s full name.';
    if (mb_strlen($data['full_name']) > 100)  $errors[] = 'The name must not be longer than 100 characters.';
    if ($data['sex'] !== '' && !in_array($data['sex'], ['Female', 'Male', 'Other'], true)) $errors[] = 'Choose a valid sex.';
    if ($data['date_of_birth'] !== '') {
        if (!valid_date($data['date_of_birth']))       $errors[] = 'Enter a valid date of birth.';
        elseif ($data['date_of_birth'] > today())      $errors[] = 'The date of birth cannot be in the future.';
    }
    if ($data['phone'] !== '' && !valid_phone($data['phone'])) $errors[] = 'Enter a valid phone number (7 to 15 digits, with an optional + at the start).';
    if (mb_strlen($data['community']) > 100)  $errors[] = 'The community name is too long.';
    if ($data['state'] !== '' && !in_array($data['state'], NIGERIAN_STATES, true)) $errors[] = 'Choose a valid state.';
    if (!$data['consent_given'])              $errors[] = 'The record cannot be saved until consent has been confirmed with the beneficiary.';
    if ($data['project_id'] && (!fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$data['project_id']]) || !can_view_project((int)$data['project_id']))) $errors[] = 'Choose a valid project.';

    // Duplicate check: same name, date of birth and phone number (FR11).
    if (!$errors) {
        $dup = fetch_one('SELECT beneficiary_id, beneficiary_code FROM beneficiaries
                           WHERE LOWER(full_name) = LOWER(?) AND date_of_birth <=> ? AND phone <=> ? AND beneficiary_id <> ?',
            [$data['full_name'], $data['date_of_birth'] ?: null, $data['phone'] ?: null, $id ?? 0]);
        if ($dup) {
            $errors[] = 'A beneficiary with the same name, date of birth and phone number is already registered (code ' . $dup['beneficiary_code'] . '). Open that record instead of creating a new one.';
        }
    }

    if (!$errors) {
        $vals = [$data['full_name'], $data['sex'] ?: null, $data['date_of_birth'] ?: null, $data['phone'] ?: null, $data['community'] ?: null, $data['state'] ?: null];
        try {
            if ($ben) {
                q('UPDATE beneficiaries SET full_name=?, sex=?, date_of_birth=?, phone=?, community=?, state=?, consent_given=1 WHERE beneficiary_id=?', [...$vals, $id]);
                audit('Update', 'beneficiaries', $id, 'Updated beneficiary ' . $ben['beneficiary_code']);
                flash('success', 'The beneficiary record was updated.');
                redirect('beneficiaries/view.php?id=' . $id);
            }
            $pdo = db();
            $pdo->beginTransaction();
            q('INSERT INTO beneficiaries (beneficiary_code, full_name, sex, date_of_birth, phone, community, state, consent_given, consent_date, registered_by)
               VALUES (?,?,?,?,?,?,?,1,?,?)', [substr('T' . bin2hex(random_bytes(5)), 0, 12), ...$vals, today(), $user['user_id']]);
            $newId = (int) $pdo->lastInsertId();
            $code = beneficiary_code_from_id($newId);
            q('UPDATE beneficiaries SET beneficiary_code = ? WHERE beneficiary_id = ?', [$code, $newId]);
            if ($data['project_id']) {
                q('INSERT INTO project_beneficiaries (project_id, beneficiary_id, enrolled_on) VALUES (?,?,?)', [$data['project_id'], $newId, today()]);
            }
            $pdo->commit();
            audit('Create', 'beneficiaries', $newId, 'Registered beneficiary ' . $code . ($data['project_id'] ? ' and enrolled in project ' . $data['project_id'] : ''));
            flash('success', 'The beneficiary was registered with the code ' . $code . '.');
            redirect('beneficiaries/view.php?id=' . $newId);
        } catch (PDOException $ex) {
            if (db()->inTransaction()) { db()->rollBack(); }
            if ($ex->getCode() === '23000') {
                $errors[] = 'This beneficiary is already registered (the database refused a duplicate record).';
            } else { throw $ex; }
        }
    }
}

render_header($ben ? 'Edit beneficiary' : 'Register beneficiary', 'beneficiaries', $ben
    ? [['Beneficiaries', 'beneficiaries/index.php'], [$ben['beneficiary_code'] . ' ' . $ben['full_name'], 'beneficiaries/view.php?id=' . $id], ['Edit']]
    : [['Beneficiaries', 'beneficiaries/index.php'], ['Register beneficiary']]);
show_errors($errors);
?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label required" for="full_name">Full name</label>
      <input class="form-control" id="full_name" name="full_name" maxlength="100" value="<?= e($data['full_name']) ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="sex">Sex</label>
      <select class="form-select" id="sex" name="sex"><?= simple_options(['Female', 'Male', 'Other'], $data['sex'], 'Select') ?></select></div>
    <div class="col-md-4"><label class="form-label" for="date_of_birth">Date of birth</label>
      <input class="form-control" type="date" id="date_of_birth" name="date_of_birth" max="<?= e(today()) ?>" value="<?= e($data['date_of_birth']) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="phone">Phone number</label>
      <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($data['phone']) ?>" placeholder="08012345678"></div>
    <div class="col-md-4"><label class="form-label" for="community">Community</label>
      <input class="form-control" id="community" name="community" maxlength="100" value="<?= e($data['community']) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="state">State</label>
      <select class="form-select" id="state" name="state"><?= simple_options(NIGERIAN_STATES, $data['state'], 'Select') ?></select></div>
    <?php if (!$ben): ?>
    <div class="col-md-6"><label class="form-label" for="project_id">Enrol in project</label>
      <select class="form-select" id="project_id" name="project_id"><?= options(projects_list(), 'project_id', 'title', $data['project_id'], 'Not now') ?></select></div>
    <?php endif; ?>
    <div class="col-12"><div class="form-check">
      <input class="form-check-input" type="checkbox" id="consent_given" name="consent_given" value="1" <?= $data['consent_given'] ? 'checked' : '' ?>>
      <label class="form-check-label required" for="consent_given">Consent to the collection and use of this information for the programme has been confirmed with the beneficiary</label></div></div>
  </div>
  <div class="alert alert-light border mt-3 small mb-0">Collect only what the programme needs. A unique code (for example NGBN001) is generated when the record is saved, and a record that duplicates an existing one is rejected.</div>
  <div class="mt-4"><button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-outline-secondary" href="<?= e($ben ? 'view.php?id=' . $id : 'index.php') ?>">Cancel</a></div>
</form></div></div>
<?php render_footer();
