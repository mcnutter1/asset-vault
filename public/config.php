<?php
// Configuration for the Test Application integrating with the SSO server
// Adjust values if your hostnames differ.
return [
  'login_base' => 'https://login.mcnutt.cloud',
  'app_id'     => 'vault',
  'app_secret' => 'ff4e49816d49dbadf01c76fa560821dd84c1531ed415b88f76dd85944879c48b',
  'cookie_name'=> 'mc_auth',
  'cookie_domain' => '.mcnutt.cloud',
  'ttl_sec'    => 7200,
  'refresh_sec'=> 1200,
  'validate_endpoint' => 'https://login.mcnutt.cloud/api/validate.php',
  'logout_endpoint'   => 'https://login.mcnutt.cloud/api/logout.php'
];

