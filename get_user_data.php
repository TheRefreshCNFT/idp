<?php
// idp/get_user_data.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';

if (empty($username)) { echo json_encode(['error' => 'No username']); exit; }

// Match folder case-insensitively
$safeUser = preg_replace('/[^a-zA-Z0-9]/', '', $username);
$userDir = $safeUser;
$dirs = glob('*', GLOB_ONLYDIR);
foreach($dirs as $d) {
    if (strtolower($d) === strtolower($safeUser)) {
        $userDir = $d; 
        break;
    }
}

$data = ['profile' => null, 'collections' => [], 'notifications' => []];

// 1. Profile
if (file_exists("$userDir/profile.json")) {
    $data['profile'] = json_decode(file_get_contents("$userDir/profile.json"), true);
}

// 2. Collections
$polDir = "$userDir/policies";
if (is_dir($polDir)) {
    foreach (glob("$polDir/*.json") as $file) {
        $json = json_decode(file_get_contents($file), true);
        if ($json) {
            $thumb = '../fre5hfence/RF5.png';
            $assets = $json['assets_cache'] ?? $json['assets'] ?? []; // Check both keys
            
            if (!empty($assets)) {
                $rand = $assets[array_rand($assets)];
                // Try finding image
                $img = $rand['image'] ?? ($rand['onchain_metadata']['image'] ?? '');
                if (is_array($img)) $img = implode('', $img);
                if (strpos($img, 'ipfs://') === 0) $thumb = 'https://ipfs.io/ipfs/' . substr($img, 7);
                elseif (!empty($img)) $thumb = $img;
            }

            $data['collections'][] = [
                'id' => $json['policy']['policyId'] ?? basename($file, '.json'),
                'name' => $json['meta']['name'] ?? 'Untitled',
                'count' => count($assets),
                'thumb' => $thumb
            ];
        }
    }
}

// 3. Notifications
$claimsFile = 'data/policy_claims.json';
if (file_exists($claimsFile)) {
    $claims = json_decode(file_get_contents($claimsFile), true);
    foreach ($claims as $c) {
        if (strtolower($c['user']) === strtolower($username)) {
            if ($c['status'] === 'denied') $data['notifications'][] = ['type'=>'error', 'msg'=>"Policy {$c['policyId']} Denied."];
            elseif ($c['status'] === 'approved') $data['notifications'][] = ['type'=>'success', 'msg'=>"Policy {$c['policyId']} Approved!"];
        }
    }
}

echo json_encode($data);
?>