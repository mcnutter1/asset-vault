<?php
require_once __DIR__ . '/../../lib/Settings.php';

$tab = $_GET['tab'] ?? 'general';

// Handle General save
if ($tab === 'general' && ($_POST['action'] ?? '') === 'save_general') {
  Util::checkCsrf();
  $key = trim($_POST['openai_api_key'] ?? '');
  Settings::set('openai_api_key', $key !== '' ? $key : null);
  echo '<div class="card"><div class="small">General settings saved.</div></div>';
}

// For prefill, prefer DB; fallback to config if DB empty
$dbKey = Settings::get('openai_api_key');
$cfgKey = (Util::config()['openai']['api_key'] ?? '');
$prefillKey = $dbKey !== null ? $dbKey : $cfgKey;
?>

<div class="row">
  <div class="col-3">
    <div class="card" style="position:sticky;top:76px">
      <div class="list">
        <a class="btn ghost <?= $tab==='general'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=general') ?>">General</a>
        <a class="btn ghost <?= $tab==='coverages'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=coverages') ?>">Coverages</a>
      </div>
    </div>
  </div>
  <div class="col-9">
    <?php if ($tab==='general'): ?>
      <div class="card">
        <h1>General</h1>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
          <input type="hidden" name="action" value="save_general">
          <div class="col-12"><label>OpenAI API Key</label>
            <input name="openai_api_key" type="password" value="<?= Util::h($prefillKey) ?>" placeholder="sk-...">
            <div class="small muted">Stored securely in the database (app_settings). Overrides any key in config.</div>
          </div>
          <div class="col-12 actions" style="margin-top:8px"><button class="btn" type="submit">Save</button></div>
        </form>
      </div>
    <?php elseif ($tab==='coverages'): ?>
      <?php include __DIR__ . '/coverages.php'; ?>
    <?php else: ?>
      <div class="card"><h1>Unknown Settings</h1></div>
    <?php endif; ?>
  </div>
</div>

