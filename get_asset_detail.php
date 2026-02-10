<?php
// get_asset_detail.php
header('Content-Type: application/json');

// 1. Load Blockfrost key from the same config as get_assets.php
$configPath = '../../secrets/config.json';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration error: Key file missing"]);
    exit;
}

$config = json_decode(file_get_contents($configPath), true);
$apiKey = $config['blockfrost_project_id'] ?? '';

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration error: Blockfrost key missing"]);
    exit;
}

// 2. Read asset ID from GET or JSON POST
$asset = '';

if (isset($_GET['asset'])) {
    $asset = $_GET['asset'];
} else {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['asset'])) {
        $asset = $data['asset'];
    }
}

// basic sanitize: hex only
$asset = preg_replace('/[^0-9a-fA-F]/', '', $asset);

if ($asset === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing asset"]);
    exit;
}

// 3. Call Blockfrost assets/{asset}
$url = "https://cardano-mainnet.blockfrost.io/api/v0/assets/" . $asset;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "project_id: " . $apiKey,
        "Content-Type: application/json",
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode ?: 500);
echo $response !== false
    ? $response
    : json_encode(["error" => "Blockfrost asset fetch failed"]);
