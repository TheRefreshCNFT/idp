<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out($ok, $data = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok], $data));
  exit;
}

$action   = $_POST['action']   ?? '';
$username = trim($_POST['username'] ?? '');
$collKey  = trim($_POST['collectionKey'] ?? '');
$type     = trim($_POST['type'] ?? ''); // 'thumb' or 'banner'

if (!$username || !$collKey || !$type) out(false, ['error' => 'Missing parameters'], 400);
if (!in_array($type, ['thumb', 'banner'], true)) out(false, ['error' => 'Invalid type'], 400);

// Sanitize
$username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
$collKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $collKey);
if (!$username || !$collKey) out(false, ['error' => 'Invalid parameters'], 400);

$idpRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/idp';
$collDir = $idpRoot . '/' . $username . '/mint/' . $collKey;

if (!is_dir($collDir)) out(false, ['error' => 'Collection not found'], 404);

$prefix = ($type === 'thumb') ? 'fbThumb' : 'fbBanner';

// Helper: remove any existing file with this prefix
function removeExisting($dir, $prefix) {
    $pattern = $dir . '/' . $prefix . '.*';
    foreach (glob($pattern) as $f) {
        if (is_file($f)) unlink($f);
    }
}

// === UPLOAD ===
if ($action === 'upload') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        out(false, ['error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['image'];
    if ($file['size'] > 5 * 1024 * 1024) out(false, ['error' => 'File too large (max 5MB)'], 400);

    // Validate image by extension (mime_content_type not available on all hosts)
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','gif','webp','svg','bmp','ico','avif'];
    if (!in_array($origExt, $allowed, true)) out(false, ['error' => 'File must be an image (png, jpg, gif, webp, svg, avif)'], 400);

    // Remove old file, save new
    removeExisting($collDir, $prefix);
    $newName = $prefix . '.' . $origExt;
    $dest = $collDir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        out(false, ['error' => 'Failed to save file'], 500);
    }

    // Update collection.json
    $collJsonPath = $collDir . '/collection.json';
    $collData = [];
    if (is_file($collJsonPath)) {
        $collData = json_decode(file_get_contents($collJsonPath), true) ?: [];
    }
    if ($type === 'thumb') {
        $collData['collectionThumbInput'] = $newName;
    } else {
        $collData['bannerImage'] = $newName;
    }
    file_put_contents($collJsonPath, json_encode($collData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    out(true, ['filename' => $newName]);
}

// === DELETE ===
if ($action === 'delete') {
    removeExisting($collDir, $prefix);

    // Update collection.json
    $collJsonPath = $collDir . '/collection.json';
    $collData = [];
    if (is_file($collJsonPath)) {
        $collData = json_decode(file_get_contents($collJsonPath), true) ?: [];
    }
    if ($type === 'thumb') {
        unset($collData['collectionThumbInput']);
    } else {
        unset($collData['bannerImage']);
    }
    file_put_contents($collJsonPath, json_encode($collData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    out(true, ['deleted' => $prefix]);
}

// === SET URL ===
if ($action === 'setUrl') {
    $url = trim($_POST['url'] ?? '');
    if (!$url) out(false, ['error' => 'Missing URL'], 400);

    // Remove any previously uploaded file since we're using a URL now
    removeExisting($collDir, $prefix);

    // Update collection.json
    $collJsonPath = $collDir . '/collection.json';
    $collData = [];
    if (is_file($collJsonPath)) {
        $collData = json_decode(file_get_contents($collJsonPath), true) ?: [];
    }
    if ($type === 'thumb') {
        $collData['collectionThumbInput'] = $url;
    } else {
        $collData['bannerImage'] = $url;
    }
    file_put_contents($collJsonPath, json_encode($collData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    out(true, ['url' => $url]);
}

out(false, ['error' => 'Unknown action'], 400);
