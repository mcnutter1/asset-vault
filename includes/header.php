<?php
require_once __DIR__ . '/../lib/Util.php';
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
  <div class="app-bar">
    <div class="inner">
      <div class="brand">Asset Vault</div>
      <nav class="nav">
        <a href="<?= Util::baseUrl('index.php?page=dashboard') ?>" class="<?= $page==='dashboard'?'active':'' ?>">Dashboard</a>
        <a href="<?= Util::baseUrl('index.php?page=assets') ?>" class="<?= $page==='assets'?'active':'' ?>">Assets</a>
        <a href="<?= Util::baseUrl('index.php?page=policies') ?>" class="<?= $page==='policies'?'active':'' ?>">Policies</a>
        <a href="<?= Util::baseUrl('index.php?page=coverages') ?>" class="<?= $page==='coverages'?'active':'' ?>">Coverages</a>
        <a href="<?= Util::baseUrl('index.php?page=locations') ?>" class="<?= $page==='locations'?'active':'' ?>">Locations</a>
      </nav>
    </div>
  </div>
  <div class="container">
