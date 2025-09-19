<?php
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Database.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$cfg = Util::config();
$base = rtrim($cfg['app']['base_url'], '/');
$page = $_GET['page'] ?? 'dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asset Vault</title>
  <link rel="stylesheet" href="<?= Util::baseUrl('assets/css/style.css') ?>">
  <link rel="icon" href="data:,">
</head>
<body>
  <?php
  // Ensure trash columns exist on files table (idempotent, cheap)
  if (!function_exists('av_ensure_files_trash')) {
    function av_ensure_files_trash(){
      try {
        $pdo = Database::get();
        $col = $pdo->query("SHOW COLUMNS FROM files LIKE 'is_trashed'")->fetch();
        if (!$col) {
          $pdo->exec("ALTER TABLE files ADD COLUMN is_trashed TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN trashed_at TIMESTAMP NULL DEFAULT NULL");
        }
        // Ensure caption column exists (used to label person document slots)
        $cap = $pdo->query("SHOW COLUMNS FROM files LIKE 'caption'")->fetch();
        if (!$cap) {
          $pdo->exec("ALTER TABLE files ADD COLUMN caption VARCHAR(255) NULL AFTER content");
        }
      } catch (Throwable $e) {
        // ignore; schema may be read-only — features will degrade gracefully
      }
    }
  }
  av_ensure_files_trash();
  // Ensure policy_assets supports multiple coverages per asset by adding coverage columns
  if (!function_exists('av_ensure_policy_assets_cov')) {
    function av_ensure_policy_assets_cov(){
      try {
        $pdo = Database::get();
        $col1 = $pdo->query("SHOW COLUMNS FROM policy_assets LIKE 'coverage_definition_id'")->fetch();
        if (!$col1) { $pdo->exec("ALTER TABLE policy_assets ADD COLUMN coverage_definition_id INT NULL AFTER applies_to_children"); }
        $col2 = $pdo->query("SHOW COLUMNS FROM policy_assets LIKE 'children_coverage_definition_id'")->fetch();
        if (!$col2) { $pdo->exec("ALTER TABLE policy_assets ADD COLUMN children_coverage_definition_id INT NULL AFTER coverage_definition_id"); }
        // Adjust unique index to include coverage_definition_id so multiple coverages can be linked
        $idx = $pdo->query("SHOW INDEX FROM policy_assets WHERE Key_name='uniq_policy_asset'")->fetchAll();
        if ($idx) {
          // Drop and recreate
          $pdo->exec("ALTER TABLE policy_assets DROP INDEX uniq_policy_asset");
        }
        // Create new unique index if not already there
        $idx2 = $pdo->query("SHOW INDEX FROM policy_assets WHERE Key_name='uniq_policy_asset'")->fetchAll();
        if (!$idx2) {
          $pdo->exec("ALTER TABLE policy_assets ADD UNIQUE KEY uniq_policy_asset (policy_id, asset_id, coverage_definition_id)");
        }
      } catch (Throwable $e) {
        // ignore if permissions restricted
      }
    }
  }
  av_ensure_policy_assets_cov();
  // Ensure ACV flag on policy_coverages
  if (!function_exists('av_ensure_policy_acv')) {
    function av_ensure_policy_acv(){
      try {
        $pdo = Database::get();
        $col = $pdo->query("SHOW COLUMNS FROM policy_coverages LIKE 'is_acv'")->fetch();
        if (!$col) {
          $pdo->exec("ALTER TABLE policy_coverages ADD COLUMN is_acv TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
  }
  av_ensure_policy_acv();
  // Ensure dynamic asset properties tables exist (idempotent)
  if (!function_exists('av_ensure_asset_properties')) {
    function av_ensure_asset_properties(){
      try {
        $pdo = Database::get();
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_property_defs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          category_id INT NULL,
          name_key VARCHAR(100) NOT NULL,
          display_name VARCHAR(150) NOT NULL,
          input_type ENUM('text','date','number','checkbox') NOT NULL DEFAULT 'text',
          show_on_view TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_cat_key (category_id, name_key),
          CONSTRAINT fk_propdefs_category FOREIGN KEY (category_id) REFERENCES asset_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_property_values (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          asset_id INT NOT NULL,
          property_def_id INT NOT NULL,
          value_text TEXT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_asset_prop (asset_id, property_def_id),
          CONSTRAINT fk_propvals_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
          CONSTRAINT fk_propvals_def FOREIGN KEY (property_def_id) REFERENCES asset_property_defs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } catch (Throwable $e) { /* ignore if perms restricted */ }
    }
  }
  av_ensure_asset_properties();
  // Ensure policy_types lookup table exists
  if (!function_exists('av_ensure_policy_types')) {
    function av_ensure_policy_types(){
      try {
        $pdo = Database::get();
        $pdo->exec("CREATE TABLE IF NOT EXISTS policy_types (
          id INT AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(50) NOT NULL UNIQUE COLLATE utf8mb4_unicode_ci,
          name VARCHAR(100) NOT NULL COLLATE utf8mb4_unicode_ci,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Ensure built-in defaults are present (idempotent upsert)
        $defs = [
          ['home','Home'],['auto','Auto'],['boat','Boat'],['flood','Flood'],['umbrella','Umbrella'],['jewelry','Jewelry'],['electronics','Electronics'],['other','Other']
        ];
        $ins=$pdo->prepare('INSERT IGNORE INTO policy_types(code,name,sort_order,is_active) VALUES (?,?,?,1)');
        $i=0; foreach ($defs as $d){ try { $ins->execute([$d[0],$d[1],$i++]); } catch (Throwable $e) { /* ignore */ } }
      } catch (Throwable $e) { /* ignore; non-critical */ }
    }
  }
  av_ensure_policy_types();
  // Migrate policies.policy_type from ENUM to VARCHAR to support custom types
  if (!function_exists('av_migrate_policy_type_varchar')) {
    function av_migrate_policy_type_varchar(){
      try {
        $pdo = Database::get();
        $row = $pdo->query("SHOW COLUMNS FROM policies LIKE 'policy_type'")->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['Type']) && stripos($row['Type'], 'enum(') !== false) {
          $pdo->exec("ALTER TABLE policies MODIFY COLUMN policy_type VARCHAR(50) NOT NULL");
        }
      } catch (Throwable $e) { /* ignore if perms restricted */ }
    }
  }
  av_migrate_policy_type_varchar();
  // Migrate coverage_definitions.applicable_types from SET to VARCHAR for custom types
  if (!function_exists('av_migrate_coverage_types_col')) {
    function av_migrate_coverage_types_col(){
      try {
        $pdo = Database::get();
        $row = $pdo->query("SHOW COLUMNS FROM coverage_definitions LIKE 'applicable_types'")->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['Type']) && stripos($row['Type'], 'set(') !== false) {
          $pdo->exec("ALTER TABLE coverage_definitions MODIFY COLUMN applicable_types VARCHAR(500) NOT NULL");
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_migrate_coverage_types_col();
  // Ensure People-related additional schema for details and policy links
  if (!function_exists('av_ensure_policy_people_cov')) {
    function av_ensure_policy_people_cov(){
      try {
        $pdo = Database::get();
        // Add optional coverage_definition_id to policy_people and relax unique index
        $col = $pdo->query("SHOW COLUMNS FROM policy_people LIKE 'coverage_definition_id'")->fetch();
        if (!$col) {
          $pdo->exec("ALTER TABLE policy_people ADD COLUMN coverage_definition_id INT NULL AFTER role");
        }
        // Update unique index to include coverage_definition_id
        $idx = $pdo->query("SHOW INDEX FROM policy_people WHERE Key_name='uniq_policy_person'")->fetchAll();
        if ($idx) { $pdo->exec("ALTER TABLE policy_people DROP INDEX uniq_policy_person"); }
        $pdo->exec("ALTER TABLE policy_people ADD UNIQUE KEY uniq_policy_person (policy_id, person_id, role, coverage_definition_id)");
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_ensure_policy_people_cov();
  // Ensure dynamic person documents schema (types + fields + values)
  if (!function_exists('av_ensure_person_docs')) {
    function av_ensure_person_docs(){
      try {
        $pdo = Database::get();
        // Master: document types for people
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_types (
          id INT AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(50) NOT NULL UNIQUE,
          name VARCHAR(150) NOT NULL,
          allow_front_photo TINYINT(1) NOT NULL DEFAULT 1,
          allow_back_photo TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Field definitions for a document type
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_fields (
          id INT AUTO_INCREMENT PRIMARY KEY,
          doc_type_id INT NOT NULL,
          name_key VARCHAR(100) NOT NULL,
          display_name VARCHAR(150) NOT NULL,
          input_type ENUM('text','date','number','checkbox') NOT NULL DEFAULT 'text',
          sort_order INT NOT NULL DEFAULT 0,
          is_required TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_doc_name (doc_type_id, name_key),
          CONSTRAINT fk_pdf_doc FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Active docs added to a person (one per type by default)
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_docs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          doc_type_id INT NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_person_doctype (person_id, doc_type_id),
          CONSTRAINT fk_pdocs_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
          CONSTRAINT fk_pdocs_doctype FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Values entered for a person's document
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_values (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          doc_type_id INT NOT NULL,
          field_id INT NOT NULL,
          value_text TEXT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_person_doc_field (person_id, doc_type_id, field_id),
          CONSTRAINT fk_pdval_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
          CONSTRAINT fk_pdval_type FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE,
          CONSTRAINT fk_pdval_field FOREIGN KEY (field_id) REFERENCES person_doc_fields(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed built-in default doc types and fields (idempotent)
        $defs = [
          'dl'       => ["Driver's License", 1, 1, [ ['number','License Number','text',0,1], ['dl_state','State Issued','text',1,0], ['dl_expiration','Expiration Date','date',2,0] ]],
          'passport' => ['Passport', 1, 1, [ ['passport_number','Passport Number','text',0,1], ['passport_country','Country Issued','text',1,0], ['passport_expiration','Expiration Date','date',2,0] ]],
          'ssn'      => ['Social Security', 1, 0, [ ['ssn','SSN','text',0,0] ]],
          'birth'    => ['Birth Certificate', 1, 1, [ ['birth_certificate_number','Certificate #','text',0,0] ]],
          'global'   => ['Global Entry', 1, 0, [ ['global_entry_number','Global Entry #','text',0,0], ['global_entry_expiration','Expiration Date','date',1,0] ]],
          'boat'     => ['Boat License', 1, 1, [ ['boat_license_number','Boat License #','text',0,0], ['boat_license_expiration','Expiration Date','date',1,0] ]],
        ];
        foreach ($defs as $code=>$def){
          [$name,$front,$back,$fields] = $def;
          $stmt = $pdo->prepare('INSERT IGNORE INTO person_doc_types(code,name,allow_front_photo,allow_back_photo,sort_order,is_active) VALUES (?,?,?,?,?,1)');
          $stmt->execute([$code,$name,$front,$back,0]);
          // Fetch id
          $id = $pdo->prepare('SELECT id FROM person_doc_types WHERE code=?'); $id->execute([$code]); $typeId = (int)($id->fetchColumn() ?: 0);
          if ($typeId){
            foreach ($fields as $f){
              [$key,$dname,$itype,$sort,$req] = $f;
              $ins = $pdo->prepare('INSERT IGNORE INTO person_doc_fields(doc_type_id,name_key,display_name,input_type,sort_order,is_required) VALUES (?,?,?,?,?,?)');
              $ins->execute([$typeId,$key,$dname,$itype,$sort,$req]);
            }
          }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_ensure_person_docs();
  if (!function_exists('av_ensure_person_private_fields')) {
    function av_ensure_person_private_fields(){
      try {
        $pdo = Database::get();
        $cols = [
          'dl_number VARCHAR(64) NULL',
          'dl_state VARCHAR(64) NULL',
          'dl_expiration DATE NULL',
          'passport_country VARCHAR(64) NULL',
          'passport_expiration DATE NULL',
          'global_entry_number VARCHAR(64) NULL',
          'global_entry_expiration DATE NULL',
          'birth_certificate_number VARCHAR(64) NULL',
          'boat_license_number VARCHAR(64) NULL',
          'boat_license_expiration DATE NULL'
        ];
        foreach ($cols as $def){
          preg_match('/^(\w+)/',$def,$m); $name=$m[1] ?? null; if (!$name) continue;
          $has = $pdo->query("SHOW COLUMNS FROM person_private LIKE '".$name."'")->fetch();
          if (!$has) { $pdo->exec("ALTER TABLE person_private ADD COLUMN $def"); }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_ensure_person_private_fields();
  // Ensure People-related schema (and files enum includes 'person')
  if (!function_exists('av_ensure_people')) {
    function av_ensure_people(){
      try {
        $pdo = Database::get();
        // files.entity_type add 'person'
        try { $pdo->exec("ALTER TABLE files MODIFY COLUMN entity_type ENUM('asset','policy','person') NOT NULL"); } catch (Throwable $e) { /* ignore if already done */ }
        // person_contacts
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_contacts (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          contact_type ENUM('phone','email','other') NOT NULL DEFAULT 'phone',
          label VARCHAR(50) NULL,
          contact_value VARCHAR(200) NOT NULL,
          is_primary TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_pc_person (person_id),
          CONSTRAINT fk_pc_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // person_socials
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_socials (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          platform ENUM('twitter','instagram','facebook','linkedin','tiktok','website','other') NOT NULL DEFAULT 'other',
          handle_or_url VARCHAR(255) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_ps_person (person_id),
          CONSTRAINT fk_ps_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // person_private
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_private (
          person_id INT PRIMARY KEY,
          ssn VARCHAR(32) NULL,
          driver_license VARCHAR(64) NULL,
          passport_number VARCHAR(64) NULL,
          medical_notes TEXT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_pp_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // person_assets
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_assets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          asset_id INT NOT NULL,
          role ENUM('owner','user','resident','other') NOT NULL DEFAULT 'other',
          UNIQUE KEY uniq_person_asset (person_id, asset_id, role),
          CONSTRAINT fk_pa_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
          CONSTRAINT fk_pa_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // person_relations
        $pdo->exec("CREATE TABLE IF NOT EXISTS person_relations (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          related_person_id INT NOT NULL,
          relation ENUM('spouse','child','parent','sibling','partner','other') NOT NULL DEFAULT 'other',
          UNIQUE KEY uniq_relation (person_id, related_person_id, relation),
          CONSTRAINT fk_pr_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
          CONSTRAINT fk_pr_related FOREIGN KEY (related_person_id) REFERENCES people(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add gender column to people if missing
        $col = $pdo->query("SHOW COLUMNS FROM people LIKE 'gender'")->fetch();
        if (!$col) { $pdo->exec("ALTER TABLE people ADD COLUMN gender ENUM('male','female','nonbinary','other','prefer_not') NULL AFTER last_name"); }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_ensure_people();
  // Migrate legacy person_private values into person_doc_values (idempotent)
  if (!function_exists('av_migrate_person_docs')) {
    function av_migrate_person_docs(){
      try {
        $pdo = Database::get();
        // Map doc code => fields mapping: legacy person_private column => doc field key
        $map = [
          'dl' => [ 'driver_license' => 'number', 'dl_state' => 'dl_state', 'dl_expiration' => 'dl_expiration' ],
          'passport' => [ 'passport_number' => 'passport_number', 'passport_country' => 'passport_country', 'passport_expiration' => 'passport_expiration' ],
          'ssn' => [ 'ssn' => 'ssn' ],
          'birth' => [ 'birth_certificate_number' => 'birth_certificate_number' ],
          'global' => [ 'global_entry_number' => 'global_entry_number', 'global_entry_expiration' => 'global_entry_expiration' ],
          'boat' => [ 'boat_license_number' => 'boat_license_number', 'boat_license_expiration' => 'boat_license_expiration' ],
        ];
        // Preload type/field IDs
        $types = [];
        foreach ($pdo->query('SELECT id, code FROM person_doc_types') as $r){ $types[$r['code']] = (int)$r['id']; }
        $fieldIds = [];
        foreach ($pdo->query('SELECT id, doc_type_id, name_key FROM person_doc_fields') as $r){ $fieldIds[(int)$r['doc_type_id'].'|'.$r['name_key']] = (int)$r['id']; }
        // Iterate person_private rows and migrate non-null values
        foreach ($pdo->query('SELECT * FROM person_private') as $row){
          $pid = (int)$row['person_id']; if ($pid<=0) continue;
          foreach ($map as $code=>$fields){
            $typeId = $types[$code] ?? 0; if (!$typeId) continue;
            $hasAny = false; foreach ($fields as $legacyCol=>$newKey){ if (isset($row[$legacyCol]) && $row[$legacyCol]!==null && $row[$legacyCol] !== '') { $hasAny = true; break; } }
            if (!$hasAny) continue;
            // Ensure person_docs row exists
            $pdo->prepare('INSERT IGNORE INTO person_docs(person_id, doc_type_id) VALUES (?,?)')->execute([$pid,$typeId]);
            // Upsert each value
            foreach ($fields as $legacyCol=>$newKey){
              $val = $row[$legacyCol] ?? null; if ($val===null || $val==='') continue;
              $fid = $fieldIds[$typeId.'|'.$newKey] ?? 0; if (!$fid) continue;
              $stmt = $pdo->prepare('INSERT INTO person_doc_values(person_id,doc_type_id,field_id,value_text) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
              $stmt->execute([$pid,$typeId,$fid,$val]);
            }
          }
        }
        // Also create person_docs from existing captioned files if missing
        foreach ($types as $code=>$typeId){
          $cap1 = $code.'_front'; $cap2 = $code.'_back';
          $sql = "SELECT DISTINCT entity_id AS person_id FROM files WHERE entity_type='person' AND (caption=? OR caption=?)";
          $st = $pdo->prepare($sql); $st->execute([$cap1,$cap2]);
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $pid=(int)$r['person_id']; if($pid>0){ $pdo->prepare('INSERT IGNORE INTO person_docs(person_id, doc_type_id) VALUES (?,?)')->execute([$pid,$typeId]); } }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  av_migrate_person_docs();
  ?>
  <div class="app-bar">
    <div class="inner">
      <div class="brand">Asset Vault</div>
  <button class="nav-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" data-nav-toggle>
        <span></span><span></span><span></span>
      </button>
      <div class="nav-wrap" id="nav-wrap">
        <nav class="nav">
          <a href="<?= Util::baseUrl('index.php?page=dashboard') ?>" class="<?= $page==='dashboard'?'active':'' ?>">Dashboard</a>
          <a href="<?= Util::baseUrl('index.php?page=assets') ?>" class="<?= $page==='assets'?'active':'' ?>">Assets</a>
          <a href="<?= Util::baseUrl('index.php?page=people') ?>" class="<?= $page==='people'?'active':'' ?>">People</a>
          <a href="<?= Util::baseUrl('index.php?page=policies') ?>" class="<?= $page==='policies'?'active':'' ?>">Policies</a>
          <a href="<?= Util::baseUrl('index.php?page=settings') ?>" class="<?= $page==='settings'?'active':'' ?>">Settings</a>
        </nav>
        <a class="btn sm outline logout" href="<?= Util::baseUrl('auth.php?logout=1') ?>">Logout</a>
      </div>
    </div>
  </div>
  <div class="container">
