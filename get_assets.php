<?php
// get_assets.php

header('Content-Type: application/json');

// 1. Load the secret key (same working path as before)
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

// 2. Get the Policy ID from request (supports GET and JSON POST)
$policyId = '';

if (isset($_GET['policy_id'])) {
    $policyId = $_GET['policy_id'];
} else {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['policy_id'])) {
        $policyId = $data['policy_id'];
    }
}

$policyId = preg_replace('/[^a-zA-Z0-9]/', '', $policyId);

if ($policyId === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing Policy ID"]);
    exit;
}

// 3. Paginate over all assets for this policy
$all     = [];
$page    = 1;
$perPage = 100;
$maxPages = 50; // up to 5000 assets if ever needed

while (true) {
    $url = "https://cardano-mainnet.blockfrost.io/api/v0/assets/policy/" .
           $policyId . "?page=" . $page . "&count=" . $perPage;

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

    if ($httpCode !== 200) {
        http_response_code($httpCode ?: 500);
        echo $response !== false
            ? $response
            : json_encode([
                "error"  => "Blockfrost policy fetch failed",
                "status" => $httpCode,
                "page"   => $page,
            ]);
        exit;
    }

    $chunk = json_decode($response, true);

    if (!is_array($chunk) || !count($chunk)) {
        // No more pages
        break;
    }

    $all = array_merge($all, $chunk);

    if (count($chunk) < $perPage) {
        // Last page
        break;
    }

    $page++;
    if ($page > $maxPages) {
        break;
    }
}

// 4. Return the combined array of assets
http_response_code(200);
echo json_encode($all);
