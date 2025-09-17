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
      } catch (Throwable $e) {
        // ignore; schema may be read-only — features will degrade gracefully
      }
    }
  }
  av_ensure_files_trash();
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
          <a href="<?= Util::baseUrl('index.php?page=policies') ?>" class="<?= $page==='policies'?'active':'' ?>">Policies</a>
          <a href="<?= Util::baseUrl('index.php?page=settings') ?>" class="<?= $page==='settings'?'active':'' ?>">Settings</a>
        </nav>
        <a class="btn sm outline logout" href="<?= Util::baseUrl('auth.php?logout=1') ?>">Logout</a>
      </div>
    </div>
  </div>
  <div class="container">
