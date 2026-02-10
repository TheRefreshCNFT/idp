<?php
// idp/remove_policy.php
session_start();

// Security: Only allow if logged in via the Admin Dashboard
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $targetPolicyId = trim($input['policy_id'] ?? '');

    if (empty($targetPolicyId)) {
        throw new Exception('Policy ID is required.');
    }

    // Locate claims file (handled robustly like other scripts)
    $claimsFile = 'data/policy_claims.json';
    if (!file_exists($claimsFile) && file_exists(__DIR__ . '/data/policy_claims.json')) {
        $claimsFile = __DIR__ . '/data/policy_claims.json';
    }

    if (!file_exists($claimsFile)) {
        throw new Exception('Policy claims file not found on server.');
    }

    $claimsJson = file_get_contents($claimsFile);
    $claimsData = json_decode($claimsJson, true);

    if (!is_array($claimsData)) {
        throw new Exception('Invalid JSON structure in claims file.');
    }

    $initialCount = count($claimsData);

    // Filter: Keep items that DO NOT match the target Policy ID
    $filteredClaims = array_filter($claimsData, function($claim) use ($targetPolicyId) {
        // We compare strict string values to be safe
        return !isset($claim['policyId']) || (string)$claim['policyId'] !== (string)$targetPolicyId;
    });

    // Re-index array to remove gaps (ensures JSON stays an array, not an object)
    $filteredClaims = array_values($filteredClaims);
    $newCount = count($filteredClaims);

    if ($initialCount === $newCount) {
        throw new Exception('Policy ID not found in claims records.');
    }

    // Save changes back to file
    if (file_put_contents($claimsFile, json_encode($filteredClaims, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to write changes to claims file.');
    }

    echo json_encode([
        'status' => 'success', 
        'message' => "Successfully removed claim for Policy ID: $targetPolicyId"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>