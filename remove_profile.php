<?php
// remove_profile.php - Reverses profile creation based on Stake Key
session_start();

// Security: Only allow if logged in via the Admin Dashboard
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Recursive deletion helper
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $targetIdentity = trim($input['stake_key'] ?? '');
    $forceMode = $input['force'] ?? false; // New Parameter

    if (empty($targetIdentity)) {
        throw new Exception('Stake Key is required.');
    }

    $foundFolder = null;
    $foundName = '';
    
    // --- 1. FOLDER DELETION LOGIC (Skip if Force Mode) ---
    if (!$forceMode) {
        // Get all directories in the current path
        $dirs = glob('*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            if ($dir === 'data' || $dir === 'fx' || $dir === 'img' || $dir === 'css' || $dir === 'js') continue;

            $profilePath = $dir . '/profile.json';
            
            if (file_exists($profilePath)) {
                $jsonContent = file_get_contents($profilePath);
                $data = json_decode($jsonContent, true);

                if (isset($data['identity']) && $data['identity'] === $targetIdentity) {
                    $foundFolder = $dir;
                    $foundName = $data['displayName'] ?? $dir;
                    break;
                }
            }
        }

        if (!$foundFolder) {
            // RETURN SPECIAL STATUS instead of Exception
            echo json_encode([
                'status' => 'profile_not_found', 
                'message' => 'No profile folder found matching this Stake Key.'
            ]);
            exit;
        }

        // Perform Deletion
        if (!deleteDirectory($foundFolder)) {
            throw new Exception("Found profile ($foundName), but failed to delete folder. Check permissions.");
        }
    }

    // --- 2. CLEAN UP POLICY CLAIMS ---
    $claimsFile = 'data/policy_claims.json';
    if (!file_exists($claimsFile) && file_exists(__DIR__ . '/data/policy_claims.json')) {
        $claimsFile = __DIR__ . '/data/policy_claims.json';
    }

    $claimsRemoved = 0;

    if (file_exists($claimsFile)) {
        $claimsJson = file_get_contents($claimsFile);
        $claimsData = json_decode($claimsJson, true);

        if (is_array($claimsData)) {
            $initialCount = count($claimsData);
            
            // Filter out ANY claim that matches the target identity
            $filteredClaims = array_filter($claimsData, function($claim) use ($targetIdentity) {
                return !isset($claim['identity']) || $claim['identity'] !== $targetIdentity;
            });

            if (count($filteredClaims) !== $initialCount) {
                $claimsRemoved = $initialCount - count($filteredClaims);
                file_put_contents($claimsFile, json_encode(array_values($filteredClaims), JSON_PRETTY_PRINT));
            }
        }
    }
    
    // Success Messages differ based on mode
    if ($forceMode) {
        $msg = "Cleanup Complete: Policy claims checked. Removed $claimsRemoved claims. (Folder check skipped)";
    } else {
        $msg = "Successfully removed profile: $foundName. Removed $claimsRemoved associated claims.";
    }

    echo json_encode(['status' => 'success', 'message' => $msg]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>