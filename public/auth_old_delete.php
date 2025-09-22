<?php
$config = require __DIR__.'/config.php';
function b64url_encode_c(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function hmac_sign_c(string $payloadJson, string $secret): string { return b64url_encode_c(hash_hmac('sha256', $payloadJson, $secret, true)); }
function verify_hmac_c(string $payloadJson, string $secret, string $sig): bool { return hash_equals(hmac_sign_c($payloadJson,$secret), $sig); }

function set_cookie_c($name,$val,$ttl,$domain){
  setcookie($name,$val,[
    'expires'=>time()+$ttl, 'path'=>'/', 'domain'=>$domain, 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax'
  ]);
}
function ensure_authenticated(){
  global $config;
  $cookie = $_COOKIE[$config['cookie_name']] ?? null;
  if($cookie){
    $data = json_decode($cookie, true);
    if($data && isset($data['session_token'])){
      // Always validate session token with SSO to catch revoked sessions
      if(revalidate($data['session_token'])){
        $new = $_COOKIE[$config['cookie_name']] ?? null;
        return $new ? (json_decode($new, true) ?: $data) : $data;
      }
      // If invalid, clear cookie and continue to redirect
      set_cookie_c($config['cookie_name'], '', -3600, $config['cookie_domain']);
    }
  }
  $return = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  $qs = http_build_query(['return_url'=>$return, 'app_id'=>$config['app_id']]);
  header('Location: '.$config['login_base'].'/?'.$qs); exit;
}
function handle_sso_callback(){
  global $config;
  if(!isset($_GET['payload'], $_GET['sig'], $_GET['app_id'])) return;
  if($_GET['app_id'] !== $config['app_id']) die('App ID mismatch');
  $payloadJson = $_GET['payload']; $sig = $_GET['sig'];
  if(!verify_hmac_c($payloadJson, $config['app_secret'], $sig)){
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $return = $scheme.'://'.$host.$_SERVER['REQUEST_URI'];
    header('Location: '.rtrim($config['login_base'],'/').'/access_denied.php?reason=invalid_signature&app_id='.urlencode($config['app_id']).'&return_url='.urlencode($return));
    exit;
  }
  $payload = json_decode($payloadJson,true);
  if(($payload['exp']??0) <= time()){
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $return = $scheme.'://'.$host.$_SERVER['REQUEST_URI'];
    header('Location: '.rtrim($config['login_base'],'/').'/access_denied.php?reason=expired&app_id='.urlencode($config['app_id']).'&return_url='.urlencode($return));
    exit;
  }
  $cookieData = ['identity'=>$payload['identity'],'roles'=>$payload['roles'],'session_token'=>$payload['session_token'],'exp'=>$payload['exp']];
  set_cookie_c($config['cookie_name'], json_encode($cookieData), $config['ttl_sec'], $config['cookie_domain']);
  // Redirect to same URL without SSO params so cookie is available next request
  $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'];
  $path = strtok($_SERVER['REQUEST_URI'], '?');
  $params = $_GET; unset($params['payload'],$params['sig'],$params['app_id']);
  $qs = http_build_query($params);
  $dest = $scheme.'://'.$host.$path.($qs?('?'.$qs):'');
  header('Location: '.$dest); exit;
}
function revalidate(string $sessionToken){
  global $config;
  $resp = @file_get_contents($config['validate_endpoint'].'?token='.urlencode($sessionToken).'&app_id='.urlencode($config['app_id']));
  if(!$resp) return false;
  $data = json_decode($resp,true);
  if(!($data['ok']??false)) return false;
  if(!verify_hmac_c(json_encode($data['payload'], JSON_UNESCAPED_SLASHES), $config['app_secret'], $data['sig'])) return false;
  $pl = $data['payload'];
  set_cookie_c($config['cookie_name'], json_encode(['identity'=>$pl['identity'],'roles'=>$pl['roles'],'session_token'=>$pl['session_token'],'exp'=>$pl['exp']]), $config['ttl_sec'], $config['cookie_domain']);
  return true;
}

// Ensure the authenticated user holds required role(s).
// $required may be a string role (e.g., 'admin') or an array of roles.
// $mode controls matching when an array is provided: 'any' (default) or 'all'.
// If authorization fails, redirects to the main login site's access_denied page.
function ensure_role($required, string $mode = 'any'){
  global $config;
  $auth = ensure_authenticated();
  $roles = $auth['roles'] ?? [];

  $hasAccess = false;
  if (is_array($required)) {
    $required = array_values(array_unique(array_map('strval', $required)));
    if ($mode === 'all') {
      $hasAccess = !array_diff($required, $roles);
    } else { // any
      $hasAccess = (bool)array_intersect($required, $roles);
    }
  } else {
    $hasAccess = in_array((string)$required, $roles, true);
  }

  if ($hasAccess) {
    return $auth; // pass through auth payload for convenience
  }

  // Not authorized: redirect to central access_denied with context
  $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'];
  $return = $scheme.'://'.$host.$_SERVER['REQUEST_URI'];
  $base = rtrim($config['login_base'],'/');

  $q = [
    'reason'     => 'not_authorized',
    'app_id'     => $config['app_id'],
    'return_url' => $return,
  ];
  if (is_array($required)) {
    $q['required_role'] = implode(',', $required);
    $q['mode'] = ($mode === 'all') ? 'all' : 'any';
  } else {
    $q['required_role'] = (string)$required;
  }

  header('Location: '.$base.'/access_denied.php?'.http_build_query($q));
  exit;
}
function logout_everywhere(){
  global $config;
  $cookie = $_COOKIE[$config['cookie_name']] ?? null;
  if($cookie){ $data=json_decode($cookie,true); @file_get_contents($config['logout_endpoint'].'?token='.urlencode($data['session_token'])); }
  set_cookie_c($config['cookie_name'], '', -3600, $config['cookie_domain']);
}

// Start the SSO logout flow using the server's logout confirmation UI.
// If a session token exists, redirect to {login_base}/logout_confirm.php with token and return_url.
// Otherwise, clear local cookie and redirect back to the provided return URL (or app base path).
function initiate_logout(string $returnUrl = null){
  global $config;
  if($returnUrl === null){
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    if($base === '' || $base === '.') $base = '/';
    $returnUrl = $scheme.'://'.$host.$base.'/';
  }
  $cookie = $_COOKIE[$config['cookie_name']] ?? null;
  if($cookie){
    $data = json_decode($cookie,true);
    if(isset($data['session_token']) && $data['session_token']){
      $dest = rtrim($config['login_base'],'/').'/logout_confirm.php?token='.urlencode($data['session_token']).'&return_url='.urlencode($returnUrl);
      header('Location: '.$dest); exit;
    }
  }
  set_cookie_c($config['cookie_name'], '', -3600, $config['cookie_domain']);
  header('Location: '.$returnUrl); exit;
}

function handle_logout_request(){
  $ret = $_GET['return_url'] ?? null;
  initiate_logout($ret);
}

// Auto-handle logout when invoked with ?logout
if(isset($_GET['logout'])){
  handle_logout_request(); // exits
}
