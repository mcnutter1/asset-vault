<?php
require_once __DIR__ . '/../../lib/Plugins.php';
$pdo = Database::get();

$plugins = PluginManager::list();
$active = $_GET['plugin'] ?? null;
if ($active && !isset($plugins[$active])) { $active = null; }

// Save config
if (($_POST['action'] ?? '') === 'save_plugin') {
  Util::checkCsrf();
  $pid = $_POST['plugin_id'] ?? '';
  if ($pid && isset($plugins[$pid])) {
    $meta = $plugins[$pid];
    $cfg = PluginManager::getConfig($pid);
    // Enabled
    $cfg['enabled'] = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : false;
    // Generic root fields (booleans/text)
    foreach ((array)($meta['config_schema'] ?? []) as $k=>$def) {
      if ($k === 'mappings' || $k === 'enabled') continue;
      $type = $def['type'] ?? '';
      if ($type === 'boolean') { $cfg[$k] = isset($_POST[$k]) ? (bool)$_POST[$k] : false; }
      if ($type === 'text') { $cfg[$k] = trim((string)($_POST[$k] ?? '')); }
    }
    // Collect mapping selects (if any)
    $schema = (array)($meta['config_schema'] ?? []);
    if (isset($schema['mappings'])) {
      $maps = [];
      foreach ((array)($schema['mappings']['fields'] ?? []) as $mk => $def) {
        $maps[$mk] = $_POST['map_'.$mk] ?? '';
      }
      $cfg['mappings'] = $maps;
    }
    if (isset($schema['value_update'])) {
      $cfg['value_update'] = isset($_POST['value_update']) ? (bool)$_POST['value_update'] : false;
    }
    PluginManager::saveConfig($pid, $cfg);
    echo '<div class="small" style="margin:6px 0 10px">Saved plugin settings.</div>';
    // refresh meta with updated enabled
    $plugins = PluginManager::list();
  }
}

// Helper: build property options for relevant categories
function av_plugin_property_options(array $plugin): array {
  $cats = (array)($plugin['actions'][0]['applies_to_categories'] ?? []);
  $catIds = [];
  foreach ($cats as $name) { $id = PluginManager::categoryIdByName($name); if ($id) $catIds[] = $id; }
  $defs = PluginManager::propertyDefsForCategories($catIds);
  $opts = [];
  foreach ($defs as $d) {
    $opts[] = [
      'id' => (int)$d['id'],
      'cat_id' => (int)$d['category_id'],
      'label' => $d['display_name'] . ' [' . $d['name_key'] . ']'
    ];
  }
  return $opts;
}
?>

<div class="settings-section">
  <h2>Installed Plugins</h2>
  <?php if (!$plugins): ?>
    <div class="small muted">No plugins found in <code>plugins/</code>.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Plugin</th><th>Description</th><th>Version</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($plugins as $p): ?>
          <tr>
            <td><strong><?= Util::h($p['name'] ?? $p['id']) ?></strong></td>
            <td><?= Util::h($p['description'] ?? '') ?></td>
            <td><?= Util::h($p['version'] ?? '') ?></td>
            <td><?= ($p['enabled'] ? '<span class="pill primary">Enabled</span>' : '<span class="pill">Disabled</span>') ?></td>
            <td class="actions"><a class="btn sm" href="<?= Util::baseUrl('index.php?page=settings&tab=plugins&plugin='.urlencode($p['id'])) ?>">Configure</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php if ($active): $meta = $plugins[$active]; $cfg = PluginManager::getConfig($active); $propOpts = av_plugin_property_options($meta); ?>
  <div class="settings-section">
    <h2><?= Util::h($meta['name'] ?? $active) ?> Settings</h2>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
      <input type="hidden" name="action" value="save_plugin">
      <input type="hidden" name="plugin_id" value="<?= Util::h($active) ?>">
      <div class="col-12">
        <label><input type="checkbox" name="enabled" value="1" <?= ($cfg['enabled'] ?? ($meta['default_enabled'] ?? false)) ? 'checked' : '' ?>> Enabled</label>
      </div>

      <?php $schema = (array)($meta['config_schema'] ?? []); ?>
      <?php // Render basic root fields except mappings/value_update/enabled ?>
      <?php foreach ($schema as $rk=>$rdef): if (in_array($rk,['enabled','mappings','value_update'], true)) continue; $rtype=$rdef['type']??''; ?>
        <?php if ($rtype==='text'): $val=(string)($cfg[$rk]??''); ?>
          <div class="col-12">
            <label><?= Util::h($rdef['label'] ?? $rk) ?></label>
            <input name="<?= Util::h($rk) ?>" value="<?= Util::h($val) ?>" placeholder="<?= Util::h($rdef['placeholder'] ?? '') ?>">
          </div>
        <?php elseif ($rtype==='boolean'): $val=!empty($cfg[$rk]); ?>
          <div class="col-12">
            <label><input type="checkbox" name="<?= Util::h($rk) ?>" value="1" <?= $val?'checked':'' ?>> <?= Util::h($rdef['label'] ?? $rk) ?></label>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if (isset($schema['mappings'])): $fields=(array)$schema['mappings']['fields']; ?>
        <div class="col-12"><h3><?= Util::h($schema['mappings']['label'] ?? 'Mappings') ?></h3></div>
        <?php foreach ($fields as $k => $def): $sel = (string)(($cfg['mappings'][$k] ?? '')); ?>
          <div class="col-6">
            <label><?= Util::h($def['label'] ?? $k) ?></label>
            <select name="map_<?= Util::h($k) ?>">
              <option value="">-- None --</option>
              <?php foreach ($propOpts as $opt): ?>
                <option value="<?= (int)$opt['id'] ?>" <?= $sel !== '' && (int)$sel === (int)$opt['id'] ? 'selected' : '' ?>><?= Util::h($opt['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (isset($schema['value_update'])): ?>
        <div class="col-12" style="margin-top:8px">
          <label><input type="checkbox" name="value_update" value="1" <?= !empty($cfg['value_update']) ? 'checked' : '' ?>> <?= Util::h($schema['value_update']['label'] ?? 'Update Value') ?></label>
        </div>
      <?php endif; ?>

      <div class="col-12 actions" style="margin-top:8px"><button class="btn" type="submit">Save</button></div>
    </form>
    <div class="small muted" style="margin-top:6px">Settings are stored in <code>app_settings</code> under <code>plugin:<?= Util::h($active) ?></code>.</div>
  </div>
<?php endif; ?>
