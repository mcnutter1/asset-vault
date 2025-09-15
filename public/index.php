<?php
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Database.php';

require_once __DIR__.'/auth.php';

// Handle SSO callback if arriving from login
handle_sso_callback();

// Ensure we are authenticated, otherwise redirect to SSO
$auth = ensure_authenticated();
ensure_role(['vault','admin']);
$identity = $auth['identity'];
$roles = $auth['roles'];

// Start output buffering to avoid 'headers already sent' when pages echo before redirect
if (ob_get_level() === 0) { ob_start(); }

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
  case 'asset_view':
    include __DIR__ . '/pages/asset_view.php';
    break;
  case 'policies':
    include __DIR__ . '/pages/policies.php';
    break;
  case 'policy_edit':
    include __DIR__ . '/pages/policy_edit.php';
    break;
  case 'coverages':
    // Legacy route: redirect to settings coverages tab
    header('Location: ' . Util::baseUrl('index.php?page=settings&tab=coverages'));
    exit;
  case 'settings':
    include __DIR__ . '/pages/settings.php';
    break;
  default:
    echo '<div class="card"><h1>Not Found</h1></div>';
}

include __DIR__ . '/../includes/footer.php';

// Flush buffer
if (ob_get_level() > 0) { ob_end_flush(); }
