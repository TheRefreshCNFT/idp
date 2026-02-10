<?php
// check_user.php
header('Content-Type: application/json');

// 1. Get the Stake Key (Identity) or Address
$input = json_decode(file_get_contents('php://input'), true);

// Accept both bech32 + hex (support multiple key names for safety)
$targetIdentity    = trim((string)($input['identity'] ?? $input['stake'] ?? $input['stakeKey'] ?? ''));
$targetIdentityHex = trim((string)($input['identityHex'] ?? $input['stakeHex'] ?? $input['stakeKeyHex'] ?? ''));

$targetAddress     = trim((string)($input['address'] ?? ''));
$targetAddressHex  = trim((string)($input['addressHex'] ?? ''));

if (!$targetIdentity && !$targetIdentityHex && !$targetAddress && !$targetAddressHex) {
    echo json_encode(['found' => false]);
    exit;
}

// 2. Scan all subdirectories for profile.json
// Note: In production with 10k+ users, you'd want a master JSON index or DB.
// For now, directory scanning is fine.
$dirs = glob('*', GLOB_ONLYDIR); 

foreach ($dirs as $dir) {
    $jsonPath = $dir . '/profile.json';
    if (file_exists($jsonPath)) {
        $data = json_decode(file_get_contents($jsonPath), true);
        
        // Check for Match (Prefer Identity/StakeKey, fallback to Address)
$match = false;

// Stored values (support both bech32 + hex in profile.json)
$storedIdentity    = isset($data['identity']) ? trim((string)$data['identity']) : '';
$storedIdentityHex = isset($data['identityHex']) ? trim((string)$data['identityHex']) : '';

$storedAddress     = isset($data['address']) ? trim((string)$data['address']) : '';
$storedAddressHex  = isset($data['addressHex']) ? trim((string)$data['addressHex']) : '';

// Prefer identity matches first (bech32 or hex), then address matches (bech32 or hex)
if ($storedIdentity && $targetIdentity && $storedIdentity === $targetIdentity) {
    $match = true;
} elseif ($storedIdentityHex && $targetIdentityHex && strcasecmp($storedIdentityHex, $targetIdentityHex) === 0) {
    $match = true;
} elseif ($storedAddress && $targetAddress && $storedAddress === $targetAddress) {
    $match = true;
} elseif ($storedAddressHex && $targetAddressHex && strcasecmp($storedAddressHex, $targetAddressHex) === 0) {
    $match = true;
}
        
        if ($match) {
            // FOUND USER!
            echo json_encode([
                'found' => true,
                'username' => $dir, // The folder name is the "username" ID
                'data' => $data
            ]);
            exit;
        }
    }
}

// 3. No match found
echo json_encode(['found' => false]);
?>