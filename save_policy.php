<?php
// idp/save_policy.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// --- DEBUG LOGGER ---
$logFile = __DIR__ . '/sync_debug.log';
function debugLog($msg) {
    global $logFile;
    // Append to log with timestamp
    file_put_contents($logFile, "[".date('c')."] " . $msg . "\n", FILE_APPEND);
}

function sendJson($data) { echo json_encode($data); exit; }
function sendError($msg) { 
    // Log the error before exiting so we know why it died
    debugLog("ERROR: " . $msg);
    http_response_code(400); 
    sendJson(['status'=>'error', 'message'=>$msg]); 
}

function safeReadJson($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function safeWriteJson($path, $arr) {
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode($arr, JSON_PRETTY_PRINT));
    @rename($tmp, $path);
}
// --- NEW HELPERS FOR SERVER-SIDE SYNC ---

// 1. Extract Traits (PHP version of your JS function)
function phpExtractTraits($meta) {
    $traits = [];
    $skip = [
        'image','src','files','mediatype','name','description',
        'assetname','asset_name','asset','policy_id','policyid',
        'collection','project','publisher','copyright',
        'artist','artists','creator','creators','author',
        'twitter','website','social','discord','instagram'
    ];

    if (!is_array($meta)) return $traits;

    foreach ($meta as $k => $v) {
        $lowerK = strtolower(trim($k));
        if (in_array($lowerK, $skip)) continue;
        if (is_array($v) || is_object($v)) continue;
        $traits[$k] = (string)$v;
    }
    return $traits;
}

// 2. Fetch JSON from Blockfrost
function bfGetJson($url, $projectId) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["project_id: {$projectId}"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

// 3. Centralized Key Lookup
function getBlockfrostKey() {
    $key = trim((string)(getenv('BLOCKFROST_PROJECT_ID') ?: ''));
    if ($key !== '') return $key;

    $candidates = [
        dirname(__DIR__) . '/../secrets/config.json',
        __DIR__ . '/../secrets/config.json',
        dirname(__DIR__) . '/secrets/config.json',
    ];

    foreach ($candidates as $cfgPath) {
        if (is_file($cfgPath)) {
            $cfg = json_decode((string)@file_get_contents($cfgPath), true);
            if (is_array($cfg) && !empty($cfg['blockfrost_project_id'])) {
                return trim((string)$cfg['blockfrost_project_id']);
            }
        }
    }
    return '';
}

function walletDir($userDir) {
    $d = $userDir . "/wallet";
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function walletJobFile($userDir) {
    return walletDir($userDir) . "/wallet_sync_job.json";
}

function walletLockFile($userDir) {
    return walletDir($userDir) . "/wallet_sync.lock";
}


// Helper: Secure Random Hex
function genHex($len) { return bin2hex(random_bytes($len/2)); }

// --- INPUT HANDLING ---
$input = json_decode(file_get_contents('php://input'), true);

// Check if running in CLI mode (Background Process)
$isCli = (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD']));

if ($isCli) {
    chdir(__DIR__); // Fix relative paths
    
    // 1. Robust Argument Gathering (Fix for 'register_argc_argv' being off)
    $rawArgs = [];
    if (isset($argv)) $rawArgs = $argv;
    elseif (isset($_SERVER['argv'])) $rawArgs = $_SERVER['argv'];

    // debugLog("CLI Start. Raw Args: " . json_encode($rawArgs));

    // 2. Parse arguments if input is empty
    if (empty($input)) {
        // Skip the filename (index 0), process the rest
        $argsToParse = array_slice($rawArgs, 1);
        foreach ($argsToParse as $arg) {
            // Split "key=value" string
            $e = explode('=', $arg, 2);
            if (count($e) === 2) {
                $key = $e[0];
                $val = urldecode($e[1]);
                $input[$key] = $val;
                // debugLog("Parsed Arg: $key = $val");
            }
        }
    }
}

$action = $input['action'] ?? '';
$username = $input['username'] ?? '';
$identity = $input['identity'] ?? '';

// Debug: If in CLI mode, verify we got the identity
if ($isCli) {
    if (empty($identity)) {
        debugLog("CLI FAILURE: Identity empty. Input array: " . json_encode($input));
    } else {
        // debugLog("CLI SUCCESS: Identity found: " . substr($identity, 0, 10) . "...");
    }
}

if (empty($username) || empty($identity)) sendError('User identity missing.');

// Folder Resolution
$safeUser = preg_replace('/[^a-zA-Z0-9]/', '', $username);
$userDir = $safeUser;
$dirs = glob('*', GLOB_ONLYDIR);
foreach($dirs as $d) {
    if (strtolower($d) === strtolower($safeUser)) { $userDir = $d; break; }
}
if (!is_dir($userDir)) sendError("User folder '$userDir' not found.");

// --- CREATE POLICY (NEW) ---
if ($action === 'create_policy') {
    $type = $input['type'] ?? 'open';
    $name = $input['name'] ?? 'Untitled Collection';

    // 1. Generate Crypto Keys (Simulated Valid Structure)
    $vk = genHex(64); 
    $sk = genHex(128); 
    $keyHash = genHex(56); 
    
    // 2. Build Script
    $scripts = [];
    
    // Time-lock logic
    $expiresAt = null;
    if ($type === 'timelock') {
        $targetTime = $input['lockDate'] ?? (time() + 31536000);
        $expiresAt = (int)$targetTime;
        $currentSlotAnchor = 113000000; 
        $timeDiff = $expiresAt - time();
        $expirySlot = $currentSlotAnchor + $timeDiff;
        
        $scripts[] = [ "slot" => $expirySlot, "type" => "before" ];
    }
    
    $scripts[] = [ "keyHash" => $keyHash, "type" => "sig" ];

    $policyScript = [ "type" => "all", "scripts" => $scripts ];

    // Simulate Policy ID (hash of script)
    $policyId = genHex(56); 

    // 3. PREPARE SECURE DATA (Keys + Script)
    $secureData = [
        'meta' => [
            'name' => $name,
            'addedAt' => time(),
            'type' => 'created', 
            'lockType' => $type,
            'expiresAt' => $expiresAt
        ],
        'keys' => [
            'type' => 'PaymentSigningKeyShelley_ed25519',
            'description' => 'Policy Signing Key',
            'cborHex' => "5820" . substr($sk, 0, 64) 
        ],
        'script' => $policyScript,
        'policyId' => $policyId
    ];

    // 4. PREPARE PUBLIC DATA (ID + Empty Cache)
    $publicData = [
        'meta' => [
            'name' => $name,
            'addedAt' => time(),
            'type' => 'created',
            'lockType' => $type,
            'expiresAt' => $expiresAt
        ],
        'policy' => [ 'policyId' => $policyId ],
        'assets_cache' => [] 
    ];

    // 5. SAVE - Secure Directory
    $createdDir = "$userDir/created";
    if (!is_dir($createdDir)) mkdir($createdDir, 0755, true);
    if (!file_exists("$createdDir/.htaccess")) file_put_contents("$createdDir/.htaccess", "Deny from all");

    if (!file_put_contents("$createdDir/$policyId.json", json_encode($secureData, JSON_PRETTY_PRINT))) {
        sendError('Failed to save secure keys.');
    }

    // 6. SAVE - Public Directory
    $polDir = "$userDir/policies";
    if (!is_dir($polDir)) mkdir($polDir, 0755, true);

    if (!file_put_contents("$polDir/$policyId.json", json_encode($publicData, JSON_PRETTY_PRINT))) {
        sendError('Failed to save public policy.');
    }

    sendJson([
        'status' => 'success', 
        'policyId' => $policyId,
        'expiresAt' => $expiresAt
    ]);
}

// --- READ POLICY (PUBLIC) ---
elseif ($action === 'read_policy') {
    $policyId = $input['policyId'] ?? '';
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID.');
    $policyId = preg_replace('/[^a-fA-F0-9]/', '', $policyId);
    $file = "$userDir/policies/$policyId.json";

    if (!file_exists($file)) {
        http_response_code(404);
        sendJson(['status' => 'error', 'message' => 'Policy file not found.']);
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) sendError("Policy JSON invalid.");

    sendJson(['status' => 'success', 'policy' => $data]);
}

// --- SAVE BUILDER CHANGES (name + assets_cache) ---
elseif ($action === 'save_builder') {
    $policyId = $input['policyId'] ?? '';
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID.');
    $policyId = preg_replace('/[^a-fA-F0-9]/', '', $policyId);

    $name = $input['name'] ?? null;
    if (is_string($name)) $name = trim($name);
    $assets = $input['assets_cache'] ?? null;

    $file = "$userDir/policies/$policyId.json";
    if (!file_exists($file)) sendError("Policy file not found.");

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) sendError("Policy JSON invalid.");

    // persist builder UI settings
    $builder = $input['builder'] ?? null;
    if (is_array($builder)) {
        if (!isset($data['meta']) || !is_array($data['meta'])) $data['meta'] = [];
        $data['meta']['builder'] = $builder;
    }

    // Update name
    if (is_string($name) && $name !== '') {
        if (!isset($data['meta']) || !is_array($data['meta'])) $data['meta'] = [];
        $data['meta']['name'] = $name;
    }

    // Update assets_cache
    if (is_array($assets)) {
        $data['assets_cache'] = $assets;
    }

    if (!file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
        sendError("Failed to save policy.");
    }

    // Sync secure 'created' file meta.name
    $secureFile = "$userDir/created/$policyId.json";
    if (is_file($secureFile) && is_string($name) && $name !== '') {
        $sec = json_decode(file_get_contents($secureFile), true);
        if (is_array($sec)) {
            if (!isset($sec['meta']) || !is_array($sec['meta'])) $sec['meta'] = [];
            $sec['meta']['name'] = $name;
            file_put_contents($secureFile, json_encode($sec, JSON_PRETTY_PRINT));
        }
    }

    sendJson(['status' => 'success']);
}
// --- DEEP SCAN COLLECTION (Server-Side Sync) ---
elseif ($action === 'deep_scan_collection') {
    ini_set('max_execution_time', 300); // Allow 5 minutes

    $policyId = preg_replace('/[^a-zA-Z0-9]/', '', $input['policyId'] ?? '');
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID.');

    // 1. Get API Key
    $BLOCKFROST = getBlockfrostKey();
    if ($BLOCKFROST === '') sendError('Server configuration error: Missing API Key.');

    // 2. Fetch ALL Assets in Policy
    $allUnits = [];
    $page = 1;
    $keepGoing = true;

    while ($keepGoing) {
        $url = "https://cardano-mainnet.blockfrost.io/api/v0/assets/policy/{$policyId}?page={$page}&count=100";
        $raw = bfGetJson($url, $BLOCKFROST);

        if (!is_array($raw)) {
            if ($page === 1) sendError('Failed to fetch assets from provider.');
            $keepGoing = false;
        } elseif (count($raw) === 0) {
            $keepGoing = false;
        } else {
            foreach ($raw as $item) {
                if (isset($item['asset'])) $allUnits[] = $item['asset'];
            }
            $page++;
            if ($page > 50) $keepGoing = false; // Safety cap
        }
    }

    if (empty($allUnits)) sendError('No assets found for this policy.');

    // 3. Deep Scan Details
    $enrichedAssets = [];
    
    foreach ($allUnits as $unit) {
        $detailUrl = "https://cardano-mainnet.blockfrost.io/api/v0/assets/" . $unit;
        $d = bfGetJson($detailUrl, $BLOCKFROST);

        if (is_array($d)) {
            $meta = is_array($d['onchain_metadata'] ?? null) 
                ? $d['onchain_metadata'] 
                : (is_array($d['metadata'] ?? null) ? $d['metadata'] : []);
            
            $traits = phpExtractTraits($meta);

            $enrichedAssets[] = [
                'asset' => $unit,
                'policy_id' => $policyId,
                'asset_name' => $d['asset_name'] ?? '',
                'onchain_metadata' => $meta,
                'traits' => $traits
            ];
        }
        
        usleep(50000); // Throttle (50ms)
    }

    // 4. Save
    $file = "$userDir/policies/$policyId.json";
    
    $existingData = [];
    if (file_exists($file)) {
        $existingData = json_decode(file_get_contents($file), true) ?: [];
    }

    $saveData = [
        'meta' => $existingData['meta'] ?? ['name' => 'Imported Collection', 'addedAt' => time(), 'type' => 'cached'],
        'policy' => ['policyId' => $policyId],
        'assets_cache' => $enrichedAssets
    ];

    if (file_put_contents($file, json_encode($saveData, JSON_PRETTY_PRINT))) {
        sendJson(['status' => 'success', 'count' => count($enrichedAssets)]);
    } else {
        sendError('Failed to save file to disk.');
    }
}

// --- DEEP SCAN COLLECTION (Server-Side Sync) ---
elseif ($action === 'deep_scan_collection') {
    // Increase execution time for this heavy task (5 minutes)
    ini_set('max_execution_time', 300);

    $policyId = preg_replace('/[^a-zA-Z0-9]/', '', $input['policyId'] ?? '');
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID.');

    // 1. Setup Blockfrost
    // (Reuse the logic from wallet_sync to find the key)
    $BLOCKFROST = trim((string)(getenv('BLOCKFROST_PROJECT_ID') ?: ''));
    if ($BLOCKFROST === '') {
        $candidates = [
            dirname(__DIR__) . '/../secrets/config.json',
            __DIR__ . '/../secrets/config.json',
            dirname(__DIR__) . '/secrets/config.json',
        ];
        foreach ($candidates as $cfgPath) {
            if (is_file($cfgPath)) {
                $cfg = json_decode((string)@file_get_contents($cfgPath), true);
                if (is_array($cfg) && !empty($cfg['blockfrost_project_id'])) {
                    $BLOCKFROST = trim((string)$cfg['blockfrost_project_id']);
                    break;
                }
            }
        }
    }
    if ($BLOCKFROST === '') sendError('Server configuration error: Missing API Key.');

    // 2. Fetch ALL Assets in Policy (Handle Pagination)
    $allUnits = [];
    $page = 1;
    $keepGoing = true;

    while ($keepGoing) {
        // Blockfrost Policy Assets Endpoint
        $url = "https://cardano-mainnet.blockfrost.io/api/v0/assets/policy/{$policyId}?page={$page}&count=100";
        $raw = bfGetJson($url, $BLOCKFROST);

        if (!is_array($raw)) {
            // If fetch failed on page 1, it's a hard error. If later, maybe just done.
            if ($page === 1) sendError('Failed to fetch assets from provider.');
            $keepGoing = false;
        } elseif (count($raw) === 0) {
            $keepGoing = false;
        } else {
            foreach ($raw as $item) {
                if (isset($item['asset'])) $allUnits[] = $item['asset'];
            }
            $page++;
            // Safety break for huge collections to prevent timeout
            if ($page > 50) $keepGoing = false; 
        }
    }

    if (empty($allUnits)) sendError('No assets found for this policy.');

    // 3. Deep Scan: Fetch Details for Each Asset
    $enrichedAssets = [];
    
    foreach ($allUnits as $unit) {
        $detailUrl = "https://cardano-mainnet.blockfrost.io/api/v0/assets/" . $unit;
        $d = bfGetJson($detailUrl, $BLOCKFROST);

        if (is_array($d)) {
            $meta = is_array($d['onchain_metadata'] ?? null) 
                ? $d['onchain_metadata'] 
                : (is_array($d['metadata'] ?? null) ? $d['metadata'] : []);
            
            $traits = phpExtractTraits($meta);

            $enrichedAssets[] = [
                'asset' => $unit,
                'policy_id' => $policyId,
                'asset_name' => $d['asset_name'] ?? '',
                'onchain_metadata' => $meta,
                'traits' => $traits
            ];
        }
        
        // Throttling: Sleep 50ms to prevent rate limiting (20 requests/sec max)
        usleep(50000); 
    }

    // 4. Save to File
    $file = "$userDir/policies/$policyId.json";
    
    // Read existing file to preserve meta name/type, update cache only
    $existingData = [];
    if (file_exists($file)) {
        $existingData = json_decode(file_get_contents($file), true) ?: [];
    }

    $saveData = [
        'meta' => $existingData['meta'] ?? ['name' => 'Imported Collection', 'addedAt' => time(), 'type' => 'cached'],
        'policy' => ['policyId' => $policyId],
        'assets_cache' => $enrichedAssets
    ];

    if (file_put_contents($file, json_encode($saveData, JSON_PRETTY_PRINT))) {
        sendJson(['status' => 'success', 'count' => count($enrichedAssets)]);
    } else {
        sendError('Failed to save file to disk.');
    }
}

// --- IMPORT (UPDATED WITH CLAIMS SAVE) ---
elseif ($action === 'import') {
    $policyData = $input['policyData'] ?? [];
    $name = $input['name'] ?? 'Untitled';
    
    // Normalize Input (handle standard format vs simple ID)
    $policyId = $policyData['policyId'] ?? ($policyData['id'] ?? ''); 
    
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID length.');

    // 1. SECURITY & DUPLICATE CHECK
    $claimsFile = 'data/policy_claims.json';
    $claims = [];
    
    if (!is_dir('data')) mkdir('data', 0755, true);
    if (file_exists($claimsFile)) {
        $claims = json_decode(file_get_contents($claimsFile), true);
        if (!is_array($claims)) $claims = [];
        
        foreach ($claims as $c) {
            // If already claimed/imported by ANYONE, block it
            if ($c['policyId'] === $policyId) {
                sendError('This Policy ID is already registered/claimed by another user.');
            }
        }
    }

    // 2. SAVE SECURE KEYS (The Vault)
    $secureDir = "$userDir/import";
    if (!is_dir($secureDir)) mkdir($secureDir, 0755, true);
    if (!file_exists("$secureDir/.htaccess")) file_put_contents("$secureDir/.htaccess", "Deny from all");

    $fileData = [
        'meta' => ['name' => $name, 'addedAt' => time(), 'type' => 'owned'], // Yellow Badge
        'policy' => $policyData,
        'assets_cache' => []
    ];
    
    if (!file_put_contents("$secureDir/$policyId.json", json_encode($fileData, JSON_PRETTY_PRINT))) {
        sendError('Write to secure storage failed.');
    }

    // 3. SAVE PUBLIC FILE
    $polDir = "$userDir/policies";
    if (!is_dir($polDir)) mkdir($polDir, 0755, true);

    $safeData = [
        'meta' => ['name' => $name, 'addedAt' => time(), 'type' => 'owned'], // Yellow Badge
        'policy' => ['policyId' => $policyId],
        'assets_cache' => []
    ];
    
if(file_put_contents("$polDir/$policyId.json", json_encode($safeData, JSON_PRETTY_PRINT))) {
        
        // --- RESTORED: Register as Approved Claim ---
        $claims = file_exists($claimsFile) ? json_decode(file_get_contents($claimsFile), true) : [];
        $claims[] = [
            'id' => uniqid('clm_'),
            'user' => $username,
            'identity' => $identity,
            'policyId' => $policyId,
            'status' => 'approved',
            'timestamp' => time()
        ];
        file_put_contents($claimsFile, json_encode($claims, JSON_PRETTY_PRINT));
        // --------------------------------------------

        sendJson(['status' => 'success', 'message' => 'Policy Imported']);
    } else {
        sendError('Write to public storage failed.');
    }
}

// --- CLAIM ---
elseif ($action === 'claim') {
    $policyId = $input['policyId'] ?? '';
    if (strlen($policyId) !== 56) sendError('Invalid Policy ID.');

    $claimsFile = 'data/policy_claims.json';
    if (!is_dir('data')) mkdir('data', 0755, true);
    
    $claims = file_exists($claimsFile) ? json_decode(file_get_contents($claimsFile), true) : [];
    
    foreach ($claims as $c) {
        // Check for Pending OR Approved
        if ($c['policyId'] === $policyId) {
             if ($c['status'] === 'pending') sendError('Claim already pending.');
             if ($c['status'] === 'approved') sendError('This Policy ID has already been claimed.');
        }
    }
    
    $claims[] = [
        'id' => uniqid('clm_'),
        'user' => $username,
        'identity' => $identity,
        'policyId' => $policyId,
        'status' => 'pending',
        'timestamp' => time()
    ];
    
    file_put_contents($claimsFile, json_encode($claims, JSON_PRETTY_PRINT));
    sendJson(['status' => 'success', 'message' => 'Claim submitted.']);
}

// --- CACHE ---
elseif ($action === 'cache_assets') {
    $policyId = $input['policyId'] ?? '';
    $assets = $input['assets'] ?? [];
    
    $file = "$userDir/policies/$policyId.json";
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        $data['assets_cache'] = $assets;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        sendJson(['status' => 'success']);
    } else {
        $polDir = "$userDir/policies";
        if (!is_dir($polDir)) mkdir($polDir, 0755, true);
        
        $newData = [
            'meta' => ['name' => 'Imported Collection', 'addedAt' => time(), 'type' => 'cached'],
            'policy' => ['policyId' => $policyId],
            'assets_cache' => $assets
        ];
        file_put_contents($file, json_encode($newData, JSON_PRETTY_PRINT));
        sendJson(['status' => 'success', 'message' => 'Created & Cached']);
    }
}

// --- CACHE WALLET ASSETS (For My Wallet Tab) ---
elseif ($action === 'cache_wallet_assets') {
    $policyId = $input['policyId'] ?? '';
    $assets = $input['assets'] ?? [];
    $dirType = $input['directory'] ?? ''; 

    // Security check: ensure we are writing to the wallet folder
    if ($dirType !== 'wallet') sendError('Invalid directory type.');

    // Create wallet directory if it doesn't exist
    $walletDir = "$userDir/wallet";
    if (!is_dir($walletDir)) mkdir($walletDir, 0755, true);

    $file = "$walletDir/$policyId.json";

    // Structure the data (similar to standard cache)
    $data = [
        'meta' => ['name' => 'Wallet Collection', 'addedAt' => time(), 'type' => 'wallet'],
        'policy' => ['policyId' => $policyId],
        'assets_cache' => $assets
    ];

    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
        sendJson(['status' => 'success']);
    } else {
        sendError('Failed to save wallet asset cache.');
    }
}

// --- SAVE WALLET INDEX (Main list of wallet collections) ---
elseif ($action === 'save_wallet_index') {
    $data = $input['data'] ?? [];
    $walletDir = "$userDir/wallet";
    if (!is_dir($walletDir)) mkdir($walletDir, 0755, true);
    
    // Save the main wallet.json file
    if (file_put_contents("$walletDir/wallet.json", json_encode($data, JSON_PRETTY_PRINT))) {
        sendJson(['status' => 'success']);
    } else {
        sendError('Failed to save wallet index.');
    }
}


// --- START WALLET SYNC (Backend Job) ---
elseif ($action === 'start_wallet_sync') {
    $stake = trim((string)($input['stake'] ?? ''));
    $addr  = trim((string)($input['address'] ?? ''));
    $force = $input['force'] ?? false; // Allow frontend to force restart

    if ($stake === '' && $addr === '') sendError('Missing stake or address for wallet sync.');

    $wDir = walletDir($userDir);
    $jobPath  = walletJobFile($userDir);
    $pidFile  = $wDir . "/sync_process.pid";

// 1. CHECK EXISTING JOB STATE
    $job = safeReadJson($jobPath);
    if (is_array($job)) {
        // Only block if currently RUNNING. 
        // If "complete", we allow it to proceed so it checks for new updates.
        if (!$force && $job['status'] === 'running') {
            // Check if process is actually alive before blocking
            if (file_exists($pidFile)) {
                $oldPid = trim(file_get_contents($pidFile));
                if (posix_kill($oldPid, 0)) {
                    echo json_encode(['status' => 'success', 'message' => 'Sync already running']);
                    exit;
                }
            }
            // If we got here, it says running but PID is dead. Allow restart.
        }
    }


// 2. CHECK PROCESS LOCK
    if (file_exists($pidFile)) {
        $oldPid = trim(file_get_contents($pidFile));
        if (posix_kill($oldPid, 0)) {
            echo json_encode(['status' => 'success', 'message' => 'Background process active']);
            exit;
        } else {
            @unlink($pidFile); 
        }
    }

    // 3. INITIALIZE NEW JOB
    $job = [
        'status'    => 'running',
        'page'      => 1,
        'done'      => false,
        'message'   => 'Starting scan...',
        'startedAt' => time(),
        'updatedAt' => time(),
        'stake'     => $stake,
        'address'   => $addr
    ];
    
    safeWriteJson($jobPath, $job);

    // --- FIX: ENSURE WALLET.JSON EXISTS ---
    // If the index is missing (404 error), create an empty one so the frontend doesn't crash.
    $walletIndex = walletDir($userDir) . "/wallet.json";
    if (!file_exists($walletIndex) || $force) {
        safeWriteJson($walletIndex, []);
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Wallet sync started',
    ]);

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    // 4. SPAWN PROCESS
    $scriptPath = __FILE__;
    $logFile = __DIR__ . '/sync_debug.log';
    
    $cmd = "nohup " . PHP_BINARY . " " . escapeshellarg($scriptPath) . 
           " username=" . escapeshellarg($username) . 
           " identity=" . escapeshellarg($identity) . 
           " action=run_background_loop >> " . escapeshellarg($logFile) . " 2>&1 &";
    
    exec($cmd);
    exit;
}

// --- WALLET SYNC STATUS ---
elseif ($action === 'wallet_sync_status') {
    $job = safeReadJson(walletJobFile($userDir));
    if (!is_array($job)) {
        sendJson(['status'=>'success','job'=>['status'=>'idle','done'=>true]]);
    }
    sendJson(['status'=>'success','job'=>$job]);
}

// --- [NEW] BACKGROUND LOOP (Runs until done) ---
// --- [NEW] BACKGROUND LOOP (Runs until done) ---
elseif ($action === 'run_background_loop') {
    ignore_user_abort(true);
    set_time_limit(0);

    // 1. REGISTER PID
    $myPid = getmypid();
    $wDir = walletDir($userDir);
    $pidFile = $wDir . "/sync_process.pid";
    file_put_contents($pidFile, $myPid);

    // Debug Log
    $log = __DIR__ . '/sync_debug.log';
    // file_put_contents($log, "[".date('c')."] Loop Started PID:$myPid\n", FILE_APPEND);

    $maxLoops = 2000; 
    $i = 0;
    
    while ($i < $maxLoops) {
        $i++;
        
        $jobPath = walletJobFile($userDir);
        
        // Safety: If user deleted folder or file
        if (!file_exists($jobPath)) break;

        $job = safeReadJson($jobPath);
        
        // Stop if marked done
        if (!is_array($job) || ($job['done'] ?? false) === true) {
            // file_put_contents($log, "[".date('c')."] Job done. Exiting PID:$myPid\n", FILE_APPEND);
            break; 
        }

        // Run batch
        walletSyncRunSlice($userDir, 6);
        
        // Sleep to prevent CPU hogging
        sleep(2);
    }

    // CLEANUP
    @unlink($pidFile); // Remove PID file so next run can start
    exit;
}

// --- WALLET SYNC TICK (process next slice; cron-friendly) ---
elseif ($action === 'wallet_sync_tick') {
    // Process a small slice (3–8 seconds) so it can be called frequently.
    walletSyncRunSlice($userDir, 6);
    $job = safeReadJson(walletJobFile($userDir));
    sendJson(['status'=>'success','job'=>$job ?: ['status'=>'idle','done'=>true]]);
}


// --- DELETE POLICIES ---
elseif ($action === 'delete_policies') {
    $ids = $input['policyIds'] ?? [];
    if (!is_array($ids) || empty($ids)) sendError('No collections selected.');

    $deletedCount = 0;

    foreach ($ids as $pid) {
        $pid = preg_replace('/[^a-zA-Z0-9]/', '', $pid);
        if (strlen($pid) < 10) continue; 

        $publicFile = "$userDir/policies/$pid.json";
        $createdFile = "$userDir/created/$pid.json"; 
        $importFile  = "$userDir/import/$pid.json"; 

        if (file_exists($publicFile)) { unlink($publicFile); $deletedCount++; }
        if (file_exists($createdFile)) unlink($createdFile);
        if (file_exists($importFile)) unlink($importFile);
    }

    sendJson(['status' => 'success', 'deleted' => $deletedCount]);
}

// --- CLEANUP WALLET FILES (One-Time Fix) ---
elseif ($action === 'cleanup_wallet_files') {
    $walletDir = "$userDir/wallet";
    if (!is_dir($walletDir)) sendError('Wallet directory not found.');

    // 1. Get all JSON files in wallet dir
    $files = glob("$walletDir/*.json");
    $validCollections = [];
    $cleanedCount = 0;
    $deletedFiles = 0;

    foreach ($files as $file) {
        if (basename($file) === 'wallet.json') continue; // Skip the index itself

        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        // Skip invalid files
        if (!$data || !isset($data['assets_cache'])) continue;

        $assets = $data['assets_cache'];
        $newAssets = [];
        $hasChanges = false;

        foreach ($assets as $asset) {
            $meta = $asset['onchain_metadata'] ?? $asset['metadata'] ?? [];
            
            // --- A. CHECK FOR 721 / RICH MEDIA ---
            $hasMedia = false;
            if (!empty($meta['image'])) $hasMedia = true;
            if (!empty($meta['files']) && is_array($meta['files'])) $hasMedia = true;
            
            if (!$hasMedia) {
                $hasChanges = true; 
                continue; // REMOVE (Skip adding to newAssets)
            }

            // --- B. CHECK NAME EXCLUSIONS ---
            // Get the best name available
            $checkName = '';
            if (isset($meta['name']) && is_string($meta['name'])) {
                $checkName = $meta['name'];
            } else {
                $hex = $asset['asset_name'] ?? '';
                // Try to decode hex if valid, otherwise use as is
                if (ctype_xdigit($hex) && strlen($hex) % 2 == 0) {
                    $checkName = hex2bin($hex);
                } else {
                    $checkName = $hex;
                }
            }

            // Normalize: remove spaces, lowercase
            $n = strtolower(trim($checkName));
            
            // 1. Check reserved words (decimals, ticker) anywhere in string
            if (strpos($n, 'decimals') !== false || strpos($n, 'ticker') !== false) {
                $hasChanges = true; continue; // REMOVE
            }

            // 2. Check if ends with "token" (covers "Leaf Token", "LeafToken")
            // Remove spaces from the name for the suffix check to catch "Leaf Token" as "leaftoken"
            $nNoSpace = str_replace(' ', '', $n);
            if (substr($nNoSpace, -5) === 'token') {
                $hasChanges = true; continue; // REMOVE
            }

            // Keep Asset
            $newAssets[] = $asset;
        }

        // --- C. SAVE OR DELETE FILE ---
        if (count($newAssets) === 0) {
            // If all assets were removed, delete the policy file
            unlink($file);
            $deletedFiles++;
        } else {
            // If changes happened, save the file
            if ($hasChanges) {
                $data['assets_cache'] = $newAssets;
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
                $cleanedCount++;
            }

            // Add to the master Index
            // We rebuild the index based on what survives the cleanup
            $thumb = '';
            // Try to find a thumb in the first few assets
            foreach($newAssets as $a) {
                $m = $a['onchain_metadata'] ?? [];
                if(isset($m['image'])) { $thumb = $m['image']; break; }
            }

            $validCollections[] = [
                'id' => $data['policy']['policyId'] ?? basename($file, '.json'),
                'name' => $data['meta']['name'] ?? 'Collection',
                'count' => count($newAssets),
                'thumb' => $thumb,
                'type' => 'wallet'
            ];
        }
    }

    // --- D. REGENERATE WALLET.JSON ---
    file_put_contents("$walletDir/wallet.json", json_encode($validCollections, JSON_PRETTY_PRINT));

    sendJson([
        'status' => 'success', 
        'message' => "Cleaned $cleanedCount files, deleted $deletedFiles empty files.",
        'index_count' => count($validCollections)
    ]);
}

function walletSyncRunSlice($userDir, $maxSeconds = 6) {
    $start = microtime(true);

    $jobPath  = walletJobFile($userDir);
    $lockPath = walletLockFile($userDir);

    // 1. Lock (avoid parallel runs)
    $lockFp = @fopen($lockPath, 'c+');
    if (!$lockFp) return;
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) { fclose($lockFp); return; }

    $job = safeReadJson($jobPath);
    if (!is_array($job) || ($job['done'] ?? false) === true) {
        flock($lockFp, LOCK_UN); fclose($lockFp); return;
    }

    $stake = trim((string)($job['stake'] ?? ''));
    if ($stake === '') {
        $job['status'] = 'error'; $job['message'] = 'No stake address.';
        safeWriteJson($jobPath, $job);
        flock($lockFp, LOCK_UN); fclose($lockFp); return;
    }

    $BLOCKFROST = getBlockfrostKey(); 
    if ($BLOCKFROST === '') {
        $job['status']='error'; $job['message']='Missing API Key'; $job['done']=true;
        safeWriteJson($jobPath, $job); flock($lockFp, LOCK_UN); fclose($lockFp); return;
    }

    // 2. Fetch current page from Blockfrost
    $page = (int)($job['page'] ?? 1);
    $countPerPage = 100;

if (strpos($stake, 'stake') === 0) {
        // It is a Stake Address
        $assetsUrl = "https://cardano-mainnet.blockfrost.io/api/v0/accounts/" . rawurlencode($stake) . "/addresses/assets?page={$page}&count={$countPerPage}";
    } else {
        // It is likely a Payment Address (addr1...)
        $assetsUrl = "https://cardano-mainnet.blockfrost.io/api/v0/addresses/" . rawurlencode($stake) . "/assets?page={$page}&count={$countPerPage}";
    }
    $rows = bfGetJson($assetsUrl, $BLOCKFROST);

    if ($rows === null) {
        // API Error: Release lock and retry next tick
        flock($lockFp, LOCK_UN); fclose($lockFp); return;
    }

    // 3. Check for Completion (Empty Page)
    if (count($rows) === 0) {
        $job['done'] = true;
        $job['status'] = 'complete';
        $job['message'] = 'Wallet sync complete';
        $job['updatedAt'] = time();
        safeWriteJson($jobPath, $job);
        flock($lockFp, LOCK_UN); fclose($lockFp); return;
    }


// 4. Pre-Sort Blockfrost Data by Policy
    $bfAssetsByPolicy = [];
    foreach ($rows as $r) {
        $unit = (string)($r['unit'] ?? '');
        if (strlen($unit) < 56) continue;
        $pid = substr($unit, 0, 56);
        if (!isset($bfAssetsByPolicy[$pid])) $bfAssetsByPolicy[$pid] = [];
        
        // FIX: Store as array so later code can access ['unit'] and ['qty']
        $bfAssetsByPolicy[$pid][] = [
            'unit' => $unit,
            'qty' => $r['quantity'] ?? '1'
        ];
    }

    // 5. Identify MISSING (Unenriched) Assets
    // We check local files to see which of these 100 assets we already have.
    $wDir = walletDir($userDir);
    $enrichQueue = []; // List of assets that need data
    $pDataCache = [];  // Memory cache of policy files we open
    
    // We also track index updates here to prevent flicker
    $walletIndexPath = $wDir . "/wallet.json";
    $idx = safeReadJson($walletIndexPath);
    if (!is_array($idx)) $idx = [];
    $idxMap = [];
    foreach ($idx as $it) { if (!empty($it['id'])) $idxMap[$it['id']] = $it; }
    $indexDirty = false;

foreach ($bfAssetsByPolicy as $pid => $items) {
        // 1. DEFINE EXISTING INDEX (Fixes the Undefined Variable Error)
        $existingIdx = $idxMap[$pid] ?? null;

        $policyFile = $wDir . "/" . $pid . ".json";
        if (isset($pDataCache[$pid])) {
            $pData = $pDataCache[$pid];
        } else {
            $pData = safeReadJson($policyFile);
            if (!is_array($pData)) {
                $pData = [
                    'meta' => ['name' => 'Wallet Collection', 'addedAt' => time(), 'type' => 'wallet'],
                    'policy' => ['policyId' => $pid],
                    'assets_cache' => []
                ];
            }
            $pDataCache[$pid] = $pData;
        }

        $existingSet = [];
        foreach (($pData['assets_cache'] ?? []) as $ea) {
            if (isset($ea['asset'])) {
                $existingSet[$ea['asset']] = true;
            }
        }

        foreach ($items as $item) {
            if (!isset($existingSet[$item['unit']])) {
                $enrichQueue[] = ['unit' => $item['unit'], 'pid' => $pid, 'qty' => $item['qty']];
            }
        }

        // --- CATEGORIZATION LOGIC (Runs if collection is new to index) ---
        if (!$existingIdx) {
            $cat = 'NFT'; 
            
            // Look at the first item in the batch to guess category
            $firstItem = $items[0] ?? [];
            $qty = (float)($firstItem['qty'] ?? 1);
            
            // We don't have metadata yet (enrichment happens later), 
            // but we can flag it for update or make a basic guess based on qty.
            if ($qty > 1 && $qty <= 1000) $cat = 'EDITION';
            if ($qty > 1000) $cat = 'RICH_FT'; // Provisional, will refine in enrichment

            $idxMap[$pid] = [
                'id' => $pid,
                'name' => 'Wallet Collection', 
                'count' => count($items), 
                'thumb' => '',
                'type' => 'wallet',
                'category' => $cat, 
                'updatedAt' => time()
            ];
            // We do NOT set indexDirty=true here. We wait for enrichment loop (below) 
            // to populate the real name/thumb/category and save then.
        }
    }
    
    // 6. PROCESS ENRICHMENT QUEUE (Max 20 per tick)
    $processedCount = 0;
    $maxProcess = 20;
    $filesToSave = []; // pid => true

    foreach ($enrichQueue as $item) {
        if ($processedCount >= $maxProcess) break;
        if ((microtime(true) - $start) > $maxSeconds) break;

        $unit = $item['unit'];
        $pid  = $item['pid'];
        $qtyRaw = $item['qty'];

        // Fetch Metadata
        $assetUrl = "https://cardano-mainnet.blockfrost.io/api/v0/assets/" . rawurlencode($unit);
        $a = bfGetJson($assetUrl, $BLOCKFROST);
        
// REPLACE THE 'if (is_array($a))' BLOCK WITH THIS:
        if (is_array($a)) {
            // Validate metadata is an array
            $meta = [];
            if (isset($a['onchain_metadata']) && is_array($a['onchain_metadata'])) {
                $meta = $a['onchain_metadata'];
            } elseif (isset($a['metadata']) && is_array($a['metadata'])) {
                $meta = $a['metadata'];
            }

            $pDataCache[$pid]['assets_cache'][] = [
                'asset' => $unit,
                'quantity' => $qtyRaw,
                'policy_id' => $pid,
                'asset_name' => substr($unit, 56),
                'onchain_metadata' => $meta
            ];
            
            $filesToSave[$pid] = true;

// FIND THIS BLOCK inside the bfAssetsByPolicy loop:
        if (!isset($idxMap[$pid])) {
            $idxMap[$pid] = [
                'id' => $pid,
                // ... (other fields) ...
                'updatedAt' => time()
            ];
        }

        if (!isset($idxMap[$pid])) {
            // Try to read meta from the cached file to populate the index immediately
            $cachedName = 'Wallet Collection';
            $cachedThumb = '';
            $cachedCat = 'NFT';
            
            if (isset($pData['meta']['name'])) $cachedName = $pData['meta']['name'];
            if (isset($pData['assets_cache'][0]['onchain_metadata']['image'])) $cachedThumb = $pData['assets_cache'][0]['onchain_metadata']['image'];
            // (You could replicate the full category logic here, or wait for enrichment)

            $idxMap[$pid] = [
                'id' => $pid,
                'name' => $cachedName,
                'count' => count($items), // Update count based on current scan
                'thumb' => $cachedThumb,
                'type' => 'wallet',
                'category' => 'NFT', // Default, update later if needed
                'updatedAt' => time()
            ];
            
            // CRITICAL FIX: FORCE SAVE so wallet.json gets populated
            $indexDirty = true; 
        } else {
            // Even if it exists, update the count if it changed
            if ($idxMap[$pid]['count'] !== count($items)) {
                $idxMap[$pid]['count'] = count($items);
                $indexDirty = true;
            }
        }

            // --- CATEGORIZATION LOGIC (Inside Enrichment) ---
            $idxEntry = $idxMap[$pid];
            
            // Only update details if name is placeholder or thumb missing
            if (($idxEntry['name'] === 'Wallet Collection') || empty($idxEntry['thumb'])) {
                $cat = 'NFT';
                
                // Safety Checks
                $fName = '';
                if (isset($meta['name']) && is_string($meta['name'])) {
                    $fName = strtolower($meta['name']);
                }
                
                $qty = (float)$qtyRaw;

                $hasTokenWord = (strpos($fName, 'token') !== false || strpos($fName, 'coin') !== false);
                
                $hasTicker = (isset($meta['ticker']) || isset($meta['symbol']));
                
                $hasFiles = (!empty($meta['files']) && is_array($meta['files']));
                
                $hasIpfsImage = (isset($meta['image']) && is_string($meta['image']) && 
                                (strpos($meta['image'], 'ipfs') !== false || strpos($meta['image'], 'http') !== false));
                                
                $isSerialized = false;
                if (isset($meta['name']) && is_string($meta['name'])) {
                    $isSerialized = (preg_match('/#\d+$/', $meta['name']) === 1);
                }

                // Logic
                if (($hasTicker || $hasTokenWord) && !$hasFiles) $cat = 'FT';
                else if ($qty > 1000 && !$hasTicker && !$hasTokenWord && ($hasFiles || $hasIpfsImage)) $cat = 'RICH_FT';
                else if ($qty > 1 && $qty <= 1000 && !$isSerialized) $cat = 'EDITION';
                else $cat = 'NFT';

                // Update Index
                if (isset($meta['name']) && is_string($meta['name'])) $idxMap[$pid]['name'] = $meta['name'];
                if (isset($meta['image']) && is_string($meta['image'])) $idxMap[$pid]['thumb'] = $meta['image'];
                $idxMap[$pid]['category'] = $cat;
                $indexDirty = true;
            }
            
            // Increment Count
            $idxMap[$pid]['count'] = ($idxMap[$pid]['count'] ?? 0) + 1;
            $indexDirty = true;

            $processedCount++;
        }
    }

 // 7. SAVE CHANGED FILES
    foreach ($filesToSave as $pid => $bool) {
        $pData = $pDataCache[$pid];
        safeWriteJson($wDir . "/" . $pid . ".json", $pData);
    }

    // Anti-Flicker: Only write wallet.json if we actually added/changed an entry
    if ($indexDirty) {
        // Sort before saving to keep UI stable
        usort($idxMap, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        safeWriteJson($walletIndexPath, array_values($idxMap));
    }

    // 8. DECIDE NEXT STEP
    // Only advance page if we have drained the enrichment queue
    if (count($enrichQueue) === 0) {
        $job['page'] = $page + 1;
        $job['message'] = "Scanning page " . ($page + 1) . "...";
    } else {
        $remaining = count($enrichQueue) - $processedCount;
        $job['message'] = "Processing assets... ({$remaining} remaining)";
    }

    $job['updatedAt'] = time();
    $job['status'] = 'running';
    safeWriteJson($jobPath, $job);

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

?>