<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_funds');
$types = ['Foundation', 'Government', 'Corporate', 'Multilateral', 'Individual', 'Other'];
$id = int_or_null(get('id')); $d = $id ? fetch_one('SELECT * FROM donors WHERE donor_id = ?', [$id]) : null; if ($id && !$d) { not_found('donor'); }
$data = $d ?: ['name' => '', 'donor_type' => 'Foundation', 'contact_person' => '', 'email' => '', 'phone' => '']; $errors = [];
if (is_post()) {
    csrf_verify();
    $data = ['name' => post('name'), 'donor_type' => post('donor_type'), 'contact_person' => post('contact_person'), 'email' => post('email'), 'phone' => normalise_phone(post('phone'))];
    if ($data['name'] === '' || mb_strlen($data['name']) > 150) $errors[] = 'Enter the donor name (150 characters at most).';
    if (!in_array($data['donor_type'], $types, true)) $errors[] = 'Choose the donor type.';
    if ($data['email'] !== '' && !valid_email($data['email'])) $errors[] = 'Enter a valid email address.';
    if ($data['phone'] !== '' && !valid_phone($data['phone'])) $errors[] = 'Enter a valid phone number.';
    if (!$errors && fetch_value('SELECT 1 FROM donors WHERE name = ? AND donor_id <> ?', [$data['name'], $id ?? 0])) $errors[] = 'A donor with that name already exists.';
    if (!$errors) {
        $v = [$data['name'], $data['donor_type'], $data['contact_person'] ?: null, $data['email'] ?: null, $data['phone'] ?: null];
        if ($d) { q('UPDATE donors SET name=?, donor_type=?, contact_person=?, email=?, phone=? WHERE donor_id=?', [...$v, $id]); audit('Update', 'donors', $id, 'Donor updated: ' . $data['name']); }
        else { q('INSERT INTO donors (name, donor_type, contact_person, email, phone) VALUES (?,?,?,?,?)', $v); audit('Create', 'donors', (int) db()->lastInsertId(), 'Donor created: ' . $data['name']); }
        flash('success', 'The donor was saved.'); redirect('finance/funds.php');
    }
}
render_header($d ? 'Edit donor' : 'New donor', 'funds', [['Donors and funds', 'finance/funds.php'], [$d ? 'Edit donor' : 'New donor']]); show_errors($errors);
?>
<div class="card" style="max-width:700px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-md-8"><label class="form-label required" for="name">Donor name</label><input class="form-control" id="name" name="name" maxlength="150" value="<?= e($data['name']) ?>" required></div>
  <div class="col-md-4"><label class="form-label required" for="donor_type">Type</label><select class="form-select" id="donor_type" name="donor_type"><?= simple_options($types, $data['donor_type']) ?></select></div>
  <div class="col-md-4"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" maxlength="100" value="<?= e($data['contact_person']) ?>"></div>
  <div class="col-md-4"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" maxlength="100" value="<?= e($data['email']) ?>"></div>
  <div class="col-md-4"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($data['phone']) ?>"></div>
</div><div class="mt-4"><button class="btn btn-primary" type="submit">Save donor</button> <a class="btn btn-outline-secondary" href="funds.php">Cancel</a></div></form></div></div>
<?php render_footer();
