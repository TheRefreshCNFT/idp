<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$BASE_DEFAULT = __DIR__;

function out($ok, $data = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok], $data));
  exit;
}

function read_json_body() {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function slugify($s) {
  $s = trim((string)$s);
  $s = preg_replace('/\s+/', '-', $s);
  $s = preg_replace('/[^a-zA-Z0-9\-_]/', '', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  return $s;
}

function safe_join($base, $slug) {
  $p = $base . DIRECTORY_SEPARATOR . $slug;
  $rb = realpath($base);
  $rp = realpath(dirname($p));
  if (!$rb || !$rp || strpos($rp, $rb) !== 0) return null;
  return $p;
}

// Helper: Recursive Remove Directory
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object))
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                else
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
            }
        }
        rmdir($dir);
    }
}

function resolve_user_mint_base($identity, $address, $username = '') {
  $idpRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/idp';
  if (!is_dir($idpRoot)) return null;

  // Optional direct username path (only if it has a profile.json)
  if ($username !== '') {
    $u = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
    if ($u === '') return null;

    $profile = $idpRoot . '/' . $u . '/profile.json';
    if (!is_file($profile)) return null;

    $p = json_decode(file_get_contents($profile), true);
    if (is_array($p)) {
      if ($identity !== '' && !empty($p['identity']) && $p['identity'] !== $identity) return null;
      if ($identity === '' && $address !== '' && !empty($p['address']) && $p['address'] !== $address) return null;
    }

    return $idpRoot . '/' . $u . '/mint';
  }

  // Scan /idp/*/profile.json like check_user.php
  $dirs = glob($idpRoot . '/*', GLOB_ONLYDIR) ?: [];
  foreach ($dirs as $d) {
    $profile = $d . '/profile.json';
    if (!is_file($profile)) continue;

    $p = json_decode(file_get_contents($profile), true);
    if (!is_array($p)) continue;

    if ($identity !== '' && !empty($p['identity']) && $p['identity'] === $identity) {
      return $d . '/mint';
    }
    if ($identity === '' && $address !== '' && !empty($p['address']) && $p['address'] === $address) {
      return $d . '/mint';
    }
  }

  return null;
}

$action = $_GET['action'] ?? '';

// Read inputs ONCE (JSON + form + querystring)
$body = read_json_body();
if (!empty($_POST) && is_array($_POST)) $body = array_merge($_POST, $body);
if (!empty($_GET)  && is_array($_GET))  $body = array_merge($_GET,  $body);

$identity = trim((string)($body['identity'] ?? ''));
$address  = trim((string)($body['address'] ?? ''));
$username = trim((string)($body['username'] ?? ''));

// Resolve BASE to: /idp/<username>/mint
$BASE = resolve_user_mint_base($identity, $address, $username);
if (!$BASE) out(false, ['error' => 'User not found (missing/unknown identity/address)'], 401);

// Ensure the user mint dir exists (THIS is the only mkdir you want)
if (!is_dir($BASE) && !@mkdir($BASE, 0755, true)) {
  out(false, ['error' => 'Failed to create user mint directory'], 500);
}

if ($action === 'list') {
  $dirs = glob($BASE . '/*', GLOB_ONLYDIR) ?: [];
  $collections = [];

  foreach ($dirs as $d) {
    $key = basename($d);
    if ($key === '.' || $key === '..') continue;

    $collFile = $d . '/collection.json';
    $polFile  = $d . '/policy.json';

    $coll = null;
    $pol  = null;

    if (is_file($collFile)) {
      $tmp = json_decode(file_get_contents($collFile), true);
      if (is_array($tmp)) $coll = $tmp;
    }
    if (is_file($polFile)) {
      $tmp = json_decode(file_get_contents($polFile), true);
      if (is_array($tmp)) $pol = $tmp;
    }

    $label = $coll['displayName'] ?? $key;

    $collections[] = [
      'key' => $key,
      'label' => $label,
      'policy' => $pol
    ];
  }

  out(true, ['collections' => $collections]);
}

if ($action === 'create') {
$name = trim((string)($body['name'] ?? ''));
 if ($name === '') out(false, ['error' => 'Missing name'], 400);
$policyType = (($body['policyType'] ?? 'open') === 'timelock') ? 'timelock' : 'open';
$lockUntil  = trim((string)($body['lockUntil'] ?? ''));
$maxSupply  = (int)($body['maxSupply'] ?? 0);

  // folder key
  $key = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($name));
  $key = trim($key, '-');
  if ($key === '') out(false, ['error' => 'Invalid name'], 400);

  $dir = safe_join($BASE, $key);
  if (file_exists($dir)) out(false, ['error' => 'Collection exists'], 409);
  mkdir($dir, 0755, true);

  $createdAt = gmdate('c');

  // ===== OFFICIAL POLICY INPUT (sent from studio) =====
  $policyId = strtolower(trim((string)($body['policyId'] ?? '')));
  $policyId = preg_replace('/[^0-9a-f]/', '', $policyId);
  if (strlen($policyId) !== 56) out(false, ['error' => 'Invalid policyId'], 400);

  $ownerKeyHash = strtolower(trim((string)($body['ownerKeyHash'] ?? '')));
  $ownerKeyHash = preg_replace('/[^0-9a-f]/', '', $ownerKeyHash);
  if ($ownerKeyHash !== '' && strlen($ownerKeyHash) !== 56) out(false, ['error' => 'Invalid ownerKeyHash'], 400);

  $lockUntilSlot = trim((string)($body['lockUntilSlot'] ?? ''));
  $lockUntilSlot = preg_replace('/[^0-9]/', '', $lockUntilSlot);
  if ($policyType === 'timelock' && $lockUntilSlot === '') out(false, ['error' => 'Missing lockUntilSlot'], 400);

  $nativeScript = $body['nativeScript'] ?? null;
  if (!is_array($nativeScript)) out(false, ['error' => 'Missing nativeScript'], 400);

  $policy = [
    'policyId' => $policyId,
    'type' => $policyType,
    'lockUntil' => ($policyType === 'timelock' ? $lockUntil : null),
    'lockUntilSlot' => ($policyType === 'timelock' ? $lockUntilSlot : null),
    'ownerKeyHash' => ($ownerKeyHash !== '' ? $ownerKeyHash : null),
    'nativeScript' => $nativeScript,
    'maxSupply' => ($maxSupply > 0 ? $maxSupply : 0),
    'createdAt' => $createdAt
  ];

  file_put_contents("$dir/policy.json", json_encode($policy, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

  file_put_contents("$dir/collection.json", json_encode([
    'name' => $name,
    'key' => $key,
    'createdAt' => $createdAt
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

  out(true, [
    'collection' => ['name' => $name, 'key' => $key],
    'policy' => $policy,
    'key' => $key,
    'label' => $name
  ]);
}

if ($action === 'delete') {
  $key = slugify($body['key'] ?? '');
  if (!$key) out(false, ['error' => 'Missing key'], 400);

  $path = safe_join($BASE, $key);
  if (!$path || !is_dir($path)) out(false, ['error' => 'Collection not found'], 404);

  rrmdir($path);
  out(true, ['deleted' => $key]);
}

out(false, ['error' => 'Unknown action'], 400);