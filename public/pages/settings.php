<?php
require_once __DIR__ . '/../../lib/Settings.php';

$tab = $_GET['tab'] ?? 'general';

// Handle General save
if ($tab === 'general' && ($_POST['action'] ?? '') === 'save_general') {
  Util::checkCsrf();
  $key = trim($_POST['openai_api_key'] ?? '');
  Settings::set('openai_api_key', $key !== '' ? $key : null);
  $model = trim($_POST['openai_model'] ?? '');
  Settings::set('openai_model', $model !== '' ? $model : 'gpt-4.1');
  $rebuild = trim($_POST['rebuild_cost_per_sqft'] ?? '350');
  if ($rebuild === '' || !is_numeric($rebuild)) { $rebuild = '350'; }
  Settings::set('rebuild_cost_per_sqft', $rebuild);
  echo '<div class="card"><div class="small">General settings saved.</div></div>';
}

// For prefill, prefer DB; fallback to config if DB empty
$dbKey = Settings::get('openai_api_key');
$cfgKey = (Util::config()['openai']['api_key'] ?? '');
$prefillKey = $dbKey !== null ? $dbKey : $cfgKey;
$prefillModel = Settings::get('openai_model', 'gpt-4.1');
$prefillRebuild = Settings::get('rebuild_cost_per_sqft', '350');
?>

<div class="settings-wrap">
  <aside class="settings-nav">
    <div class="section-title">Settings</div>
    <nav class="nav">
      <div class="nav-group">
        <div class="group-title">Household</div>
        <a class="<?= $tab==='general'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=general') ?>">General</a>
        <a class="<?= $tab==='coverages'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=coverages') ?>">Coverages</a>
        <a class="<?= $tab==='addresses'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=addresses') ?>">Addresses</a>
        <a class="<?= $tab==='trash'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=trash') ?>">Trash</a>
      </div>
    </nav>
  </aside>
  <main class="settings-content">
    <?php if ($tab==='general'): ?>
      <section class="settings-card">
        <h1>Household</h1>
        <div class="settings-section">
          <h2>AI Integration</h2>
          <p class="settings-description">Connect your AI provider so valuations and suggestions work inside Asset Vault.</p>
          <form method="post" class="row">
            <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
            <input type="hidden" name="action" value="save_general">
            <div class="col-12">
              <label for="openai_api_key">OpenAI API Key</label>
              <input id="openai_api_key" name="openai_api_key" type="password" value="<?= Util::h($prefillKey) ?>" placeholder="sk-...">
              <div class="small muted">Stored securely in the database (app_settings). Overrides any key in config.</div>
            </div>
            <div class="col-6">
              <label for="openai_model">OpenAI Model</label>
              <?php $models = ['gpt-4.1','gpt-4.1-mini','gpt-4o','gpt-4o-mini']; ?>
              <select id="openai_model" name="openai_model">
                <?php foreach ($models as $m): ?>
                  <option value="<?= $m ?>" <?= $prefillModel===$m?'selected':'' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label for="rebuild_cost_per_sqft">Default Rebuild Cost ($/sq ft)</label>
              <input id="rebuild_cost_per_sqft" name="rebuild_cost_per_sqft" type="number" step="1" min="50" max="1500" value="<?= Util::h($prefillRebuild) ?>">
              <div class="small muted">Used when square footage is known but rebuild cost isn’t.</div>
            </div>
            <div class="col-12">
              <hr class="divider" />
              <button class="btn block" type="submit">Save Changes</button>
            </div>
          </form>
        </div>
      </section>
    <?php elseif ($tab==='coverages'): ?>
      <section class="settings-card">
        <h1>Coverage Settings</h1>
        <?php include __DIR__ . '/coverages.php'; ?>
      </section>
    <?php elseif ($tab==='addresses'): ?>
      <section class="settings-card">
        <h1>Saved Addresses</h1>
        <?php include __DIR__ . '/saved_addresses.php'; ?>
      </section>
    <?php elseif ($tab==='trash'): ?>
      <section class="settings-card">
        <h1>Trash</h1>
        <?php
          $pdo = Database::get();
          if (($_POST['action'] ?? '') === 'restore_file') { Util::checkCsrf(); $fid=(int)($_POST['file_id']??0); $pdo->prepare('UPDATE files SET is_trashed=0, trashed_at=NULL WHERE id=?')->execute([$fid]); echo '<div class="small">Restored.</div>'; }
          if (($_POST['action'] ?? '') === 'delete_file') { Util::checkCsrf(); $fid=(int)($_POST['file_id']??0); $pdo->prepare('DELETE FROM files WHERE id=?')->execute([$fid]); echo '<div class="small">Deleted permanently.</div>'; }
          $trashed = $pdo->query("SELECT id, entity_type, entity_id, filename, mime_type, size, uploaded_at, trashed_at FROM files WHERE is_trashed=1 ORDER BY trashed_at DESC")->fetchAll();
        ?>
        <?php if (!$trashed): ?>
          <div class="small muted">Trash is empty.</div>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Preview</th><th>Name</th><th>Type</th><th>Size</th><th>Trashed</th><th>Linked To</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($trashed as $t): ?>
                <tr>
                  <td>
                    <?php if (strpos($t['mime_type'],'image/')===0): ?>
                      <img data-file-id="<?= (int)$t['id'] ?>" data-filename="<?= Util::h($t['filename']) ?>" data-size="<?= (int)$t['size'] ?>" data-uploaded="<?= Util::h($t['uploaded_at']) ?>" src="<?= Util::baseUrl('file.php?id='.(int)$t['id']) ?>" alt="" style="width:60px; height:60px; object-fit:cover; border-radius:8px; border:1px solid var(--border);">
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td><?= Util::h($t['filename']) ?></td>
                  <td><?= Util::h($t['mime_type']) ?></td>
                  <td><?= number_format((int)$t['size']) ?> bytes</td>
                  <td><?= Util::h($t['trashed_at']) ?></td>
                  <td><?= Util::h($t['entity_type']) ?> #<?= (int)$t['entity_id'] ?></td>
                  <td class="actions">
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                      <input type="hidden" name="action" value="restore_file">
                      <input type="hidden" name="file_id" value="<?= (int)$t['id'] ?>">
                      <button class="btn sm">Restore</button>
                    </form>
                    <form method="post" onsubmit="return confirmAction('Permanently delete this file? This cannot be undone.')">
                      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                      <input type="hidden" name="action" value="delete_file">
                      <input type="hidden" name="file_id" value="<?= (int)$t['id'] ?>">
                      <button class="btn sm danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="settings-card"><h1>Unknown Settings</h1></section>
    <?php endif; ?>
  </main>
</div>
