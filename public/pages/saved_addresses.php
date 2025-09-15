<?php
$pdo = Database::get();

if (($_POST['action'] ?? '') === 'add_saved_addr') {
  Util::checkCsrf();
  $name = trim($_POST['name'] ?? '');
  $type = $_POST['address_type'] ?? 'storage';
  $line1 = trim($_POST['line1'] ?? '');
  $line2 = trim($_POST['line2'] ?? '');
  $city = trim($_POST['city'] ?? '');
  $state = trim($_POST['state'] ?? '');
  $postal = trim($_POST['postal_code'] ?? '');
  $country = trim($_POST['country'] ?? '');
  $lat = $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
  $lng = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
  $notes = trim($_POST['notes'] ?? '');
  if ($name && $line1 && $city) {
    $stmt = $pdo->prepare('INSERT INTO saved_addresses(name,address_type,line1,line2,city,state,postal_code,country,latitude,longitude,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name,$type,$line1,$line2,$city,$state,$postal,$country,$lat,$lng,$notes]);
  }
  Util::redirect('index.php?page=settings&tab=addresses');
}

if (($_POST['action'] ?? '') === 'delete_saved_addr') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $pdo->prepare('DELETE FROM saved_addresses WHERE id=?')->execute([$id]);
  Util::redirect('index.php?page=settings&tab=addresses');
}

$addrs = $pdo->query('SELECT * FROM saved_addresses ORDER BY name')->fetchAll();
?>

<div class="row">
  <div class="col-7">
    <h2>Address Book</h2>
    <table>
      <thead><tr><th>Name</th><th>Type</th><th>Address</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($addrs as $a): ?>
          <tr>
            <td><?= Util::h($a['name']) ?></td>
            <td><?= Util::h($a['address_type']) ?></td>
            <td class="small"><?= Util::h($a['line1']) ?><?= $a['line2']? (', '.Util::h($a['line2'])):'' ?>, <?= Util::h($a['city']) ?>, <?= Util::h($a['state']) ?> <?= Util::h($a['postal_code']) ?></td>
            <td>
              <form method="post" onsubmit="return confirmAction('Delete saved address?')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="delete_saved_addr">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn ghost danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="col-5">
    <h2>Add Address</h2>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
      <input type="hidden" name="action" value="add_saved_addr">
      <div class="col-12"><label>Name</label><input name="name" required></div>
      <div class="col-6"><label>Type</label>
        <select name="address_type">
          <option value="storage">Storage</option>
          <option value="mailing">Mailing</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="col-12"><label>Address Line 1</label><input name="line1" required></div>
      <div class="col-12"><label>Address Line 2</label><input name="line2"></div>
      <div class="col-4"><label>City</label><input name="city" required></div>
      <div class="col-4"><label>State</label><input name="state"></div>
      <div class="col-4"><label>Postal Code</label><input name="postal_code"></div>
      <div class="col-6"><label>Country</label><input name="country"></div>
      <div class="col-3"><label>Lat</label><input name="latitude" type="number" step="0.0000001"></div>
      <div class="col-3"><label>Lng</label><input name="longitude" type="number" step="0.0000001"></div>
      <div class="col-12"><label>Notes</label><input name="notes"></div>
      <div class="col-12 actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </div>
</div>

