<?php
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Database.php';

// Router
$page = $_GET['page'] ?? 'dashboard';

include __DIR__ . '/../includes/header.php';

switch ($page) {
  case 'dashboard':
    include __DIR__ . '/pages/dashboard.php';
    break;
  case 'assets':
    include __DIR__ . '/pages/assets.php';
    break;
  case 'asset_edit':
    include __DIR__ . '/pages/asset_edit.php';
    break;
  case 'policies':
    include __DIR__ . '/pages/policies.php';
    break;
  case 'policy_edit':
    include __DIR__ . '/pages/policy_edit.php';
    break;
  case 'coverages':
    include __DIR__ . '/pages/coverages.php';
    break;
  default:
    echo '<div class="card"><h1>Not Found</h1></div>';
}

include __DIR__ . '/../includes/footer.php';
