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

<div class="settings-wrap">
  <aside class="settings-nav">
    <div class="section-title">Settings</div>
    <nav class="nav">
      <div class="nav-group">
        <div class="group-title">Household</div>
        <a class="<?= $tab==='general'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=general') ?>">General</a>
        <a class="<?= $tab==='coverages'?'active':'' ?>" href="<?= Util::baseUrl('index.php?page=settings&tab=coverages') ?>">Coverages</a>
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
    <?php else: ?>
      <section class="settings-card"><h1>Unknown Settings</h1></section>
    <?php endif; ?>
  </main>
</div>
