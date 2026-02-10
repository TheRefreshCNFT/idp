<?php
// tracker.php - Per-collection JSON Flat-file Database for counts
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$file = 'collection_stats.json';

// --- helpers ---
function clean_key($k) {
  $k = (string)$k;
  $k = preg_replace('/[^a-zA-Z0-9_\-]/', '', $k);
  if ($k === '') $k = 'new';
  return $k;
}

function read_data($file) {
  $init = ['count' => 0, 'countByCollection' => ['new' => 0]];
  if (!file_exists($file)) {
    file_put_contents($file, json_encode($init, JSON_PRETTY_PRINT), LOCK_EX);
    return $init;
  }
  $json = file_get_contents($file);
  $data = json_decode($json, true);
  if (!is_array($data)) $data = $init;
  if (!isset($data['countByCollection']) || !is_array($data['countByCollection'])) $data['countByCollection'] = ['new' => 0];
  if (!isset($data['count'])) $data['count'] = 0;
  return $data;
}

function write_data($file, $data) {
  // Keep global count consistent
  $sum = 0;
  foreach ($data['countByCollection'] as $v) $sum += intval($v);
  $data['count'] = $sum;

  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// --- main ---
$action = $_GET['action'] ?? 'get';
$coll   = clean_key($_GET['coll'] ?? 'new');

$data = read_data($file);

// ensure key exists
if (!isset($data['countByCollection'][$coll])) $data['countByCollection'][$coll] = 0;

if ($action === 'get') {
  echo json_encode([
    'count' => intval($data['count']),
    'countByCollection' => $data['countByCollection'],
    'coll' => $coll,
    'collCount' => intval($data['countByCollection'][$coll])
  ]);
  exit;
}

if ($action === 'increment') {
  $amount = intval($_GET['amount'] ?? 1);
  if ($amount < 0) $amount = 0;

  $data['countByCollection'][$coll] = intval($data['countByCollection'][$coll]) + $amount;
  write_data($file, $data);

  echo json_encode([
    'success' => true,
    'count' => intval($data['count']),
    'countByCollection' => $data['countByCollection'],
    'coll' => $coll,
    'collCount' => intval($data['countByCollection'][$coll])
  ]);
  exit;
}

// optional: reset/set (handy for delete)
if ($action === 'set') {
  $value = intval($_GET['value'] ?? 0);
  if ($value < 0) $value = 0;

  $data['countByCollection'][$coll] = $value;
  write_data($file, $data);

  echo json_encode([
    'success' => true,
    'count' => intval($data['count']),
    'countByCollection' => $data['countByCollection'],
    'coll' => $coll,
    'collCount' => intval($data['countByCollection'][$coll])
  ]);
  exit;
}

echo json_encode(['error' => 'Unknown action']);