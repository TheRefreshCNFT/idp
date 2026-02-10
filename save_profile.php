<?php
// save_profile.php - Handles Drafts vs Public Push

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // --- 1. GATHER INPUTS ---
    $address = $_POST['address'] ?? '';
    $identity = $_POST['identity'] ?? '';
    $username    = $_POST['username'] ?? '';
    $rawName = $_POST['displayName'] ?? ''; 
    $bioRaw = $_POST['bio'] ?? '';
    $locRaw = $_POST['location'] ?? '';
    $existingPfp = $_POST['existingPfp'] ?? ''; 
    $action      = $_POST['action'] ?? '';
    $role        = $_POST['role'] ?? '';

    // Signing Data (Only for Push)
    $sigKey      = $_POST['key'] ?? '';
    $sigSig      = $_POST['signature'] ?? '';
    $sigAddr     = $_POST['sigAddr'] ?? '';    
    $payload     = $_POST['payload'] ?? '';    

    // Handle Combinations
    $combinationsRaw = $_POST['combinations'] ?? '[]';
    $combinations = json_decode($combinationsRaw, true);
    if (!is_array($combinations)) $combinations = [];

    // Validation
    if (empty($address) || strlen($address) < 5) {
        throw new Exception('Wallet address is missing.');
    }

// --- 2. SETUP FOLDERS ---
// Prefer username-based folder resolution to match save_policy.php
$username = $_POST['username'] ?? '';
$username = is_string($username) ? trim($username) : '';
if (strtolower($username) === 'null' || strtolower($username) === 'undefined') $username = '';

// Normalize displayName too (prevents "null" folder creation)
$rawName = is_string($rawName) ? trim($rawName) : '';
if (strtolower($rawName) === 'null' || strtolower($rawName) === 'undefined') $rawName = '';

// 1) Resolve user folder by username (case-insensitive) if present
$targetDir = '';
if ($username !== '') {
    $safeUser = preg_replace('/[^a-zA-Z0-9]/', '', $username);
    $targetDir = $safeUser;

    // If folder exists but different case, match it
    $dirs = glob('*', GLOB_ONLYDIR);
    foreach ($dirs as $d) {
        if (strtolower($d) === strtolower($safeUser)) { $targetDir = $d; break; }
    }
}

// 2) Fallback: derive from displayName
if ($targetDir === '') {
    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $rawName);
    $folderName = strtolower($cleanName);
    if ($folderName === '') $folderName = substr($address, -6);
    $targetDir = $folderName;
}

// Ensure folder exists
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) throw new Exception("Failed to create user folder.");
}
if (!file_exists("$targetDir/policies")) {
    mkdir("$targetDir/policies", 0755, true);
}

// Use the actual folder name for URLs
$folderName = basename($targetDir);

if ($role === '' && $existingRole !== '') $role = $existingRole;
if ($role === '') $role = 'artist';



    // --- 3. HANDLE PFP ---
    $finalPfp = '/fre5hfence/RF5.png';
    if (!empty($existingPfp)) $finalPfp = $existingPfp;

    if (isset($_FILES['pfpFile']) && $_FILES['pfpFile']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['pfpFile'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','webp','gif'];
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('Invalid profile image type.');
        }

        $fileName = 'pfp_' . time() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], "$targetDir/$fileName")) {
            $finalPfp = $fileName;
        }
    }

    // --- 4. PREPARE DATA ---
    $stake = (stripos($identity, 'stake1') === 0) ? $identity : '';

    $profileData = [
        'displayName'   => $rawName,
        'bio'           => $bioRaw,
        'location'      => $locRaw,
        'pfp'           => $finalPfp,
        'address'       => $address,
        'identity'      => $identity,
        'stake'         => $stake,
        'role'          => $role,
        'combinations'  => $combinations,
        'updatedAt'     => time()
    ];

    // --- 5. LOGIC SPLIT: DRAFT VS PUBLIC ---
    
    if ($action === 'save_draft') {
        // --- DRAFT FLOW (Autosave) ---
        // firstCreated = true only if neither draft nor public profile existed BEFORE this save
        $draftPath  = "$targetDir/profile_draft.json";
        $publicPath = "$targetDir/profile.json";
        $firstCreated = (!file_exists($draftPath) && !file_exists($publicPath));

        // 1. Mark as draft
        $profileData['isDraft'] = true;
        
        // 2. Save ONLY to profile_draft.json
        if (file_put_contents("$targetDir/profile_draft.json", json_encode($profileData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Write Error: Could not save profile_draft.json");
        }

        // 3. Return Success IMMEDIATELY (Do not regenerate HTML)
        echo json_encode([
            'status' => 'success',
            'pfpUrl' => $finalPfp,
            'mode' => 'draft',
            'firstCreated' => $firstCreated
        ]);

        exit; // Stop execution here
    }

if ($action === 'push_public') {
        // --- PUBLIC PUSH FLOW ---
        
        $pushType = $_POST['type'] ?? 'both';

        // 1. Verify inputs exist
        if (strlen($sigKey) < 10 || strlen($sigSig) < 10) throw new Exception('Missing wallet signature.');
        if (empty($payload)) throw new Exception('Missing signed payload.');

        // 2. Load EXISTING Public Profile (to preserve data we aren't pushing)
        $currentPublic = [];
        if (file_exists("$targetDir/profile.json")) {
            $currentPublic = json_decode(file_get_contents("$targetDir/profile.json"), true);
        }

        // 3. Initialize Final Data with Existing Public Data (Defaults)
        $finalData = $currentPublic;
        
        // Always update system fields
        $finalData['isDraft'] = false; 
        $finalData['address']    = $address;
        $finalData['identity']   = $identity;
        $finalData['updatedAt']  = time();
        $finalData['pushPublic'] = [
            'sigAddr'    => $sigAddr,
            'payload'    => $payload,
            'key'        => $sigKey,
            'signature'  => $sigSig,
            'ts'         => time(),
        ];

        // 4. Selective Update Logic
        // IF 'profile' or 'both' -> Update Name, Bio, PFP, Role
        if ($pushType === 'profile' || $pushType === 'both') {
            $finalData['displayName'] = $rawName;
            $finalData['bio']         = $bioRaw;
            $finalData['location']    = $locRaw;
            $finalData['pfp']         = $finalPfp;
            // Only update role if it wasn't set, or if you consider role part of profile data
            $finalData['role']        = $profileData['role'] ?? ($currentPublic['role'] ?? 'artist'); 
        }

        // IF 'dashboard' or 'both' -> Update Combinations (Assets/Groups)
        if ($pushType === 'dashboard' || $pushType === 'both') {
            $finalData['combinations'] = $combinations;
            // Ensure collections array exists if needed, though usually generated dynamically
            if(isset($profileData['collections'])) $finalData['collections'] = $profileData['collections'];
        }

        // 5. Save to LIVE profile.json
        if (file_put_contents("$targetDir/profile.json", json_encode($finalData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Write Error: Could not save profile.json");
        }
        
        $rawName      = $finalData['displayName'];
        $bioRaw       = $finalData['bio'];
        $locRaw       = $finalData['location'];
        $finalPfp     = $finalData['pfp'];
if (!isset($finalData['combinations']) || !is_array($finalData['combinations'])) {
    $finalData['combinations'] = [];
}
$combinations = $finalData['combinations'];


// 6. Delete Draft File when client says nothing is pending (or pushing both)
$clearDraft = $_POST['clearDraft'] ?? '';
$clearDraft = ($clearDraft === '1' || $clearDraft === 'true' || $clearDraft === 1 || $clearDraft === true);

if ($pushType === 'both') $clearDraft = true;

if ($clearDraft) {
    $draftFile = "$targetDir/profile_draft.json";
    if (file_exists($draftFile)) unlink($draftFile);
}


    } else {
        // Fallback or Import (Treat as Draft if uncertain, or error)
throw new Exception("Unknown action: " . $action);

    }

    // --- 6. PREPARE DATA FOR JS (HIERARCHY SUPPORT) ---
    // Only runs if we are pushing public HTML
    
    function cleanUrl($src) {
        if (is_array($src)) $src = implode('', $src);
        $src = trim((string)$src);
        if (stripos($src, 'ar://') === 0) return "https://arweave.net/" . ltrim(substr($src, 5), '/');
        if (stripos($src, 'arweave://') === 0) return "https://arweave.net/" . ltrim(substr($src, 10), '/');
        if (strpos($src, 'ipfs://') === 0) return "https://ipfs.io/ipfs/" . substr($src, 7);
        return $src;
    }

    function deriveName($genericName, $assetName) {
        if ($genericName !== 'Verified Collection' && $genericName !== 'Untitled') return $genericName;
        if (preg_match('/^(.*?)(?:\s*#\d+)?\s*$/', $assetName, $m)) return trim($m[1] ?? $assetName);
        return $assetName;
    }

    $policyFiles = glob("$targetDir/policies/*.json");
    $policyCache = []; // Raw Data Keyed by ID
    $jsAllPolicies = []; // Formatted for JS

    if ($policyFiles) {
        foreach ($policyFiles as $file) {
            if(strpos(basename($file), 'group_') === 0) continue; 
            $json = file_get_contents($file);
            $data = json_decode($json, true);
            
            if ($data && isset($data['policy']['policyId'])) {
                $assets = $data['assets_cache'] ?? [];
                if (count($assets) > 0) {
                    $pid = trim((string)$data['policy']['policyId']);
                    $policyCache[$pid] = $data;

                    $randomAsset = $assets[array_rand($assets)];
                    $meta = $randomAsset['onchain_metadata'] ?? ($randomAsset['metadata'] ?? []);
                    
                    $derivedName = $data['meta']['name'] ?? 'Collection';
                    $assetName = $randomAsset['asset_name'] ?? $meta['name'] ?? '';
                    if (!preg_match('/[g-zG-Z]/', $assetName) && ctype_xdigit($assetName) && strlen($assetName)%2==0) {
                        $assetName = hex2bin($assetName);
                    }
                    $derivedName = deriveName($derivedName, $assetName);

                    $staticSrc = '/fre5hfence/RF5.png';
                    $staticIsVideo = false;
                    $htmlSrc = null;

                    if (isset($meta['files'][0])) {
                        $f = $meta['files'][0];
                        $type = strtolower($f['mediaType'] ?? '');
                        $src  = cleanUrl($f['src'] ?? '');
                        if (strpos($type, 'text/html') !== false || strpos($src, 'data:text/html') === 0) $htmlSrc = $src;
                        elseif (strpos($type, 'video/') === 0 || strpos($src, 'data:video/') === 0) { $staticSrc = $src; $staticIsVideo = true; }
                        elseif (strpos($type, 'image/') === 0 || strpos($src, 'data:image/') === 0 || preg_match('/^https?:\/\//i', $src)) $staticSrc = $src;
                    }
                    if (isset($meta['image']) && !$staticIsVideo) {
                        $imgSrc = cleanUrl($meta['image']);
                        if ($staticSrc === '/fre5hfence/RF5.png' || $htmlSrc) $staticSrc = $imgSrc;
                    }

                    $jsAllPolicies[] = [
                        'id' => $pid,
                        'name' => $derivedName,
                        'count' => count($assets),
                        'thumb' => $staticSrc,
                        'thumbIsVideo' => $staticIsVideo,
                        'htmlPreview' => $htmlSrc,
                        'type' => $data['meta']['type'] ?? 'owned'
                    ];
                }
            }
        }
    }
    
// Build a quick lookup of all group IDs (from current combinations)
$groupIdSet = [];
if (!empty($combinations) && is_array($combinations)) {
    foreach ($combinations as $g) {
        if (!empty($g['id'])) {
            $groupIdSet[(string)$g['id']] = true;
        }
    }
}



    $jsGroups = [];
    foreach ($combinations as $combo) {
        $groupId = $combo['id'];
        $groupName = $combo['name'];
        $memberIds = $combo['policies'] ?? [];
        $validMembers = [];
        $groupThumbPool = [];

        foreach ($memberIds as $rawPid) {
            $pid = trim((string)$rawPid);
            if ($pid === '') continue;

            // A) Real policy member
            if (isset($policyCache[$pid])) {
                $validMembers[] = $pid;

                $pData = $policyCache[$pid];
                $assets = $pData['assets_cache'] ?? [];
                foreach ($assets as $a) $groupThumbPool[] = $a;

                continue;
            }

            // B) Nested group member (group_*)
            if (isset($groupIdSet[$pid])) {
                $validMembers[] = $pid;
                // No thumb pool from nested groups here (optional enhancement later)
                continue;
            }
        }

        $validMembers = array_values(array_unique($validMembers));

        if (empty($validMembers)) continue;

        $staticSrc = '/fre5hfence/RF5.png';
        $staticIsVideo = false;
        
        if (!empty($groupThumbPool)) {
            $randomAsset = $groupThumbPool[array_rand($groupThumbPool)];
            $meta = $randomAsset['onchain_metadata'] ?? ($randomAsset['metadata'] ?? []);
            
            if (isset($meta['files'][0])) {
                $f = $meta['files'][0];
                $type = strtolower($f['mediaType'] ?? '');
                $src  = cleanUrl($f['src'] ?? '');
                if (strpos($type, 'video/') === 0 || strpos($src, 'data:video/') === 0) { $staticSrc = $src; $staticIsVideo = true; }
                elseif (strpos($type, 'image/') === 0 || strpos($src, 'data:image/') === 0 || preg_match('/^https?:\/\//i', $src)) $staticSrc = $src;
            }
            if (isset($meta['image']) && !$staticIsVideo) {
                $imgSrc = cleanUrl($meta['image']);
                if ($staticSrc === '/fre5hfence/RF5.png') $staticSrc = $imgSrc;
            }
        }

        $jsGroups[] = [
            'id' => $groupId,
            'name' => $groupName,
            'policies' => $validMembers,
            'thumb' => $staticSrc,
            'thumbIsVideo' => $staticIsVideo,
            'type' => 'created',
            'isGroup' => true
        ];
    }

    $allPoliciesJson = json_encode($jsAllPolicies, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $groupsJson      = json_encode($jsGroups,      JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

    if ($allPoliciesJson === false) $allPoliciesJson = '[]';
    if ($groupsJson === false) $groupsJson = '[]';

    $bioHtml = htmlspecialchars($bioRaw);
    $locHtml = !empty($locRaw) ? "<div class='loc-tag'>📍 " . htmlspecialchars($locRaw) . "</div>" : "";
    $pageUrl = "https://therefreshcnft.com/idp/" . $folderName;
    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8'); ?> - ID Page</title>
  <link rel="icon" type="image/png" href="/fre5hfence/RF5.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* ... (CSS IS UNCHANGED) ... */
    :root { --primary: #2196F3; --accent: #ffd869; --bg: #050810; --card-bg: rgba(5, 10, 24, 0.9); --border: rgba(120, 141, 255, 0.25); --input-bg: rgba(0, 0, 0, 0.4); }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: radial-gradient(circle at top left, #202738 0, #050810 55%, #020308 100%) fixed; color: #e4e8ff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; display: flex; flex-direction: column; align-items: center; overflow-x: hidden; }
/* --- HEADER CSS --- */
.main-header { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 15px 30px; background: rgba(5, 8, 16, 0.95); border-bottom: 1px solid rgba(255,255,255,0.1); position: sticky; top: 0; z-index: 2000; height: 80px; backdrop-filter: blur(10px); }
.header-left { display: flex; align-items: center; gap: 15px; text-decoration: none; color: #fff; }
.header-logo { height: 45px; filter: drop-shadow(0 0 5px var(--primary)); }
.header-title { font-weight: 800; font-size: 1.4rem; color: #fff; letter-spacing: 1px; text-transform: uppercase; }

.header-center { flex: 1; display: flex; justify-content: center; padding: 0 40px; }
.global-search { width: 100%; max-width: 500px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 99px; padding: 10px 20px; color: #fff; display: flex; align-items: center; gap: 10px; }
.search-input { background: transparent; border: none; color: #fff; width: 100%; outline: none; }

.header-right { display: flex; align-items: center; gap: 20px; position: relative; }
.nav-link { color: #b7c3ff; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: color 0.2s; white-space: nowrap;}
.nav-link:hover { color: #fff; }

.wallet-balance { font-family: monospace; color: var(--accent); border: 1px solid var(--accent); padding: 4px 10px; border-radius: 8px; font-size: 0.9rem; display: none; }

/* PROFILE MENU (Always Visible now, acts as Connect button initially) */
.profile-menu-container { position: relative; display: block; }
.header-pfp { width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--border); object-fit: cover; cursor: pointer; transition: border-color 0.2s; }
.header-pfp:hover { border-color: var(--primary); }

.profile-dropdown { position: absolute; top: 50px; right: 0; width: 220px; background: #1a202c; border: 1px solid var(--border); border-radius: 12px; padding: 10px; display: none; flex-direction: column; gap: 5px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 2500; }
.menu-item { padding: 10px; color: #e4e8ff; cursor: pointer; border-radius: 6px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; text-decoration: none; }
.menu-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
.menu-item i { width: 20px; text-align: center; color: var(--primary); }

/* WALLET SELECTOR (Hidden custom dropdown style) */
#walletSelect { display: none; position: absolute; top: 50px; right: 0; background: #1a202c; color: #fff; border: 1px solid var(--primary); padding: 10px; border-radius: 8px; z-index: 3000; width: 200px; cursor: pointer; }
#walletSelect option { padding: 10px; }

/* Mobile */
@media (max-width: 900px) { .header-title, .nav-link, .header-center { display: none; } }

    #miniModal { position: absolute; top: 50px; right: 0; width: 140px; background: #1a202c; border: 1px solid rgba(120, 141, 255, 0.25); border-radius: 12px; padding: 8px; display: none; flex-direction: column; gap: 5px; z-index: 5000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .modal-opt-btn { background: rgba(255,255,255,0.05); border: none; color: #e4e8ff; padding: 8px; border-radius: 6px; cursor: pointer; }
    .modal-opt-btn:hover { background: rgba(255,255,255,0.1); }
    .modal-opt-btn.danger { color: #ff6b6b; background: rgba(255, 107, 107, 0.1); }
    .collection-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; width: 100%; }
    .collection-card { background: rgba(255,255,255,0.05); border-radius: 12px; overflow: hidden; cursor: pointer; border: 1px solid rgba(255,255,255,0.1); transition: transform 0.2s; position: relative; }
    .col-thumb { cursor: default; }
    .col-info { cursor: pointer; }
    .collection-card:hover { transform: translateY(-5px); border-color: var(--primary); }
    .col-thumb { position: relative; height: 200px; background: #000; overflow: hidden; display: block; }
    .thumb-media-img, .thumb-media-html, .thumb-media-video { width: 100%; height: 100%; border: 0; display: block; }
    .thumb-media-img, .thumb-media-video { object-fit: contain; background: #000; }
    
/* --- THUMBNAIL SCALER (Fixes Scrollbars for Collections AND Asset Grid) --- */
    .col-thumb .html-thumb-wrapper,
    .asset-thumb-wrap .html-thumb-wrapper {
        position: absolute;
        inset: 0;
        z-index: 10;
        background: #000;
        width: 100%;
        height: 100%;
        overflow: hidden;
    }

    /* Force iframe to be huge (400%), then shrink it (0.25) */
    .col-thumb .html-thumb-wrapper iframe,
    .col-thumb iframe.thumb-media-html,
    .asset-thumb-wrap .html-thumb-wrapper iframe,
    .asset-thumb-wrap iframe.thumb-media-html {
        width: 400% !important;
        height: 400% !important;
        transform: scale(0.25);
        transform-origin: top left;
        border: 0;
        display: block;
        pointer-events: auto;
        background: #000;
        position: absolute;
        top: 0; left: 0;
    }

    .col-thumb .html-thumb-wrapper:empty,
    .asset-thumb-wrap .html-thumb-wrapper:empty { display: none; }

    /* Interaction overrides */
    .col-thumb .thumb-media-html, .asset-thumb-wrap .thumb-media-html { pointer-events: auto; }
    
    @media (pointer: coarse) {
        .col-thumb .thumb-media-html, .asset-thumb-wrap .thumb-media-html { pointer-events: none; }
        .col-thumb.preview-active .thumb-media-html, .asset-thumb-wrap.preview-active .thumb-media-html { pointer-events: auto; }
    }

    .collection-card.has-preview .col-thumb:hover .thumb-media-img { opacity: 0; }
    .col-info { padding: 15px; text-align: left; }
    .col-title { font-weight: bold; font-size: 1.1rem; margin-bottom: 5px; }
    .col-meta { font-size: 0.8rem; color: #8899ac; }
    .verified-badge { position: absolute; bottom: 10px; right: 10px; width: 28px; height: 28px; background-image: url('/fre5hfence/RF5.png?v=2'); background-size: cover; background-position: center; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 900; color: #fff; text-shadow: 0 1px 2px #000; z-index: 20; cursor: default; }
    .verified-badge::before { content: 'IDP'; }
    .verified-badge.green { box-shadow: 0 0 5px 1px #4CAF50; border: 1px solid #4CAF50; }
    .verified-badge.yellow { box-shadow: 0 0 5px 1px #FFC107; border: 1px solid #FFC107; }
    .verified-badge::after { content: attr(data-tooltip); position: absolute; bottom: 110%; right: 0; background: rgba(0,0,0,0.9); color: #fff; padding: 5px 8px; border-radius: 4px; font-size: 10px; white-space: pre; display: none; text-align: center; border: 1px solid rgba(255,255,255,0.2); line-height: 1.2; }
    .verified-badge:hover::after { display: block; }
    .browser-header-left .verified-badge { position: relative; display: inline-flex; margin-left: 12px; width: 24px; height: 24px; font-size: 8px; flex-shrink: 0; }
    .browser-header-left .verified-badge::after { left: 100%; right: auto; bottom: 50%; transform: translateY(50%); margin-left: 10px; }
    .browser-header-left { overflow: visible; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 3000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
    .modal-close { position: absolute; top: 15px; right: 15px; background: none; border:none; color: #fff; font-size: 1.5rem; cursor: pointer; z-index: 10; }
    #assetBrowserModal .modal-box { max-width: 1000px; height: 85vh; display: flex; flex-direction: column; width: 95%; padding: 20px; position: relative; background: #111625; border: 1px solid var(--border); border-radius: 16px; }
    .browser-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; padding-right: 40px; }
    .browser-header-left h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; }
    .asset-count-label { font-size: 0.8rem; color: #8899ac; }
    .browser-header-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .browser-label { font-size: 0.8rem; color: #8899ac; }
    .browser-select { background: rgba(5,10,24,0.9); border: 1px solid rgba(255,255,255,0.2); color: #e4e8ff; border-radius: 999px; padding: 4px 10px; font-size: 0.8rem; outline: none; }
    .asset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; overflow-y: auto; flex: 1; padding-right: 5px; align-content: flex-start; }
    .asset-card { background: rgba(255,255,255,0.03); border-radius: 8px; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: flex; flex-direction: column; align-items: stretch; overflow: visible; }
    .asset-card:hover { border-color: var(--accent); background: rgba(255,255,255,0.08); }
    .asset-thumb-wrap { position: relative; height: 150px; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .asset-thumb-wrap .thumb-media-html { position: static; }
    .asset-name { padding: 10px; font-size: 1rem; font-weight: 600; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .asset-traits{ padding: 8px 10px 10px; margin: 0 10px 10px; background: rgba(255,255,255,0.05); border-radius: 10px; text-align: left; }
    .asset-traits .detail-meta-row{ padding: 4px 0; font-size: 0.78rem; border-bottom: 1px solid rgba(255,255,255,0.07); }
    .asset-traits .detail-meta-row:last-child{ border-bottom: 0; }
    .asset-traits .trait-k{ color:#8899ac; }
    .asset-traits .trait-v{ font-family: monospace; color:#fff; text-align: right; margin-left: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .main-wrapper { width: 100%; max-width: 900px; padding: 0 20px; margin-top: 80px; padding-bottom: 40px; }
    .profile-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 18px; padding: 0 40px 40px; text-align: center; position: relative; margin-bottom: 30px; }
    .pfp-wrapper { width: 150px; height: 150px; margin: -75px auto 20px; border-radius: 50%; border: 4px solid #050810; background: #1a202c; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .pfp { width: 100%; height: 100%; object-fit: cover; }
    .url-bar { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 5px 5px 5px 20px; margin: 20px auto 30px; gap: 10px; max-width: 100%; box-sizing: border-box; }
    .url-text { font-family: monospace; color: var(--primary); font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 250px; }
    .icon-btn { background: rgba(255,255,255,0.1); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .icon-btn:hover { background: var(--primary); }
    .floating-actions { position: fixed; bottom: 20px; right: 20px; z-index: 1001; display: none; gap: 10px; }
    .action-btn { background: var(--primary); color: #000; padding: 10px 20px; border-radius: 99px; text-decoration: none; font-weight: bold; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 8px; font-size: 0.9rem; }
    #assetDetailModal .modal-box { background: #111625; border: 1px solid var(--border); border-radius: 16px; padding: 30px; width: 90%; max-width: 600px; height: auto; max-height: 90vh; overflow-y: auto; position: relative; text-align: center; }
    /* --- SINGLE ASSET: Title row + Fullscreen --- */
    .detail-title-row{ width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 15px; }
    #btnMediaFs{ background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.18); color: #fff; border-radius: 10px; padding: 8px 10px; cursor: pointer; line-height: 1; }
    #btnMediaFs:hover{ border-color: var(--accent); }
    #mediaFsOverlay{ position: fixed; inset: 0; display: none; z-index: 9500; background: rgba(0,0,0,0.95); }
    #mediaFsOverlay.show{ display: block; }
    #mediaFsClose{ position: absolute; top: 14px; right: 16px; width: 44px; height: 44px; border-radius: 12px; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.22); color: #fff; font-size: 30px; cursor: pointer; z-index: 9510; }
    #mediaFsClose:hover{ border-color: var(--accent); }
    #mediaFsHost{ position: absolute; inset: 0; padding: 60px 20px 20px; display: flex; align-items: center; justify-content: center; }
    #mediaFsHost .thumb-media-img, #mediaFsHost .thumb-media-video{ width: 100%; height: 100%; object-fit: contain; background: #000; }
    #mediaFsHost .thumb-media-html{ width: 100%; height: 100%; background: #000; border: 0; }
    .detail-media { max-width: 100%; max-height: 50vh; width: 100%; height: 100%; object-fit: contain; background: #000; border-radius: 12px; margin-bottom: 15px; }
    .detail-meta { width: 100%; text-align: left; background: rgba(255,255,255,0.03); padding: 20px; border-radius: 12px; }
    .detail-meta-row { display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); padding: 8px 0; font-size: 0.9rem; }
    .notif-wrapper { position: relative; cursor: pointer; margin-right: 10px; }
    .notif-bell { font-size: 1.2rem; color: #fff; transition: color 0.2s; }
    .notif-bell:hover { color: var(--accent); }
    .notif-dot { position: absolute; top: -2px; right: -2px; width: 8px; height: 8px; background: #ff6b6b; border-radius: 50%; display: none; }
    .notif-dropdown { position: absolute; top: 30px; right: 0; width: 280px; background: #1a202c; border: 1px solid var(--border); border-radius: 12px; padding: 10px; display: none; flex-direction: column; gap: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 2000; }
    @media (max-width: 600px) { .top-bar span { display: none; } .top-bar { padding: 10px 15px; } .nav-select { width: 100px; font-size: 0.8rem; padding: 0 5px; } .nav-connect-btn { width: auto; padding: 0 10px; font-size: 0.8rem; } .nav-controls { gap: 5px; } }
  </style>
</head>
<body>

<header class="main-header">
    <div class="header-left">
        <a href="/idp/"><img src="/fre5hfence/RF5.png?v=2" alt="RF5" class="header-logo"></a>
        <span class="header-title">ID Pages</span>
    </div>

    <div class="header-center">
        <div class="global-search">
            <i class="fas fa-search" style="color:#8899ac;"></i>
            <input type="text" class="search-input" placeholder="Search..." onkeydown="if(event.key==='Enter') window.location.href='/idp/?q='+this.value">
        </div>
    </div>

    <div class="header-right">
        <a href="/idp/#drops" class="nav-link">Drops</a>
        <a href="/idp/#explore" class="nav-link">Explore</a>
        <a href="/idp/#create" class="nav-link">Create</a>

        <div class="notif-wrapper" id="notifIcon" style="margin-right:5px;">
            <i class="fas fa-bell notif-bell"></i>
            <div class="notif-dot" id="notifDot"></div>
            <div class="notif-dropdown" id="notifList">
                <div style="text-align:center; color:#888;">No new notifications</div>
            </div>
        </div>

        <div id="headerBalance" class="wallet-balance">₳ 0.00</div>

<div class="profile-menu-container" id="profileMenuContainer">
    <img src="/fre5hfence/RF5.png?v=2" id="headerProfileBtn" class="header-pfp" title="Connect Wallet">
    
    <select id="walletSelect" size="5" style="display:none; position:absolute; top:60px; right:0; background:#1a202c; color:#fff; padding:5px; border:1px solid var(--primary); border-radius:8px; z-index:3000; width:200px;"></select>

    <div class="profile-dropdown" id="profileDropdown">
        </div>
</div>
    </div>
</header>

  <div class="main-wrapper">
    <div class="profile-card">
      <div class="pfp-wrapper"><img src="<?php echo $finalPfp; ?>" class="pfp"></div>
      <h1><?php echo htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8'); ?></h1>
      <p style="color:#b7c3ff; margin:10px 0;"><?php echo $bioHtml; ?></p>
      <?php echo $locHtml; ?>
      <div class="url-bar">
        <span class="url-text"><?php echo $pageUrl; ?></span>
        <button class="icon-btn" onclick="navigator.clipboard.writeText('<?php echo $pageUrl; ?>');alert('Copied!')"><i class="fas fa-copy"></i></button>
      </div>
    </div>

    <h3 style="margin-bottom:15px; color:var(--primary);">Collections</h3>
    <div class="collection-grid" id="publicGrid"></div>
  </div>

  <div id="assetBrowserModal" class="modal-overlay">
    <div class="modal-box">
      <button class="modal-close" onclick="closeModal('assetBrowserModal')">&times;</button>
      <div class="browser-header">
        <div class="browser-header-left">
            <h2 id="browserTitle">Collection</h2>
            <div id="assetCountLabel" class="asset-count-label">0 items</div>
        </div>
        <div class="browser-header-controls">
            <label class="browser-label">Filter:</label>
            <select id="propertyFilterSelect" class="browser-select"><option value="ALL">All properties</option></select>
            <label class="browser-label">Show:</label>
            <select id="assetPageSizeSelect" class="browser-select">
                <option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
            </select>
        </div>
      </div>
      <div class="asset-grid" id="assetGrid">Loading...</div>
    </div>
  </div>

  <div id="assetDetailModal" class="modal-overlay" style="z-index:3100;">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('assetDetailModal')">&times;</button>
        <div id="detailContent"></div>
    </div>
  </div>
  
    <div id="mediaFsOverlay">
    <button id="mediaFsClose" aria-label="Close fullscreen" onclick="closeMediaFullscreen()">&times;</button>
    <div id="mediaFsHost"></div>
  </div>


  <div id="ownerControls" class="floating-actions">
    <a id="dashLink" href="#" class="action-btn" style="background:#111; color:var(--primary); border:1px solid var(--primary);"><i class="fas fa-columns"></i> Dashboard</a>
    <a id="editLink" href="#" class="action-btn"><i class="fas fa-pen"></i> Edit</a>
  </div>

  <script type="module">
    import { BrowserWallet } from '/meshsdk-core-1.9.0-b62.js';

    // --- DATA INJECTION ---
    const ALL_POLICIES = <?php echo $allPoliciesJson; ?>;
    const GROUPS_CONFIG = <?php echo $groupsJson; ?>;
    
    const profile = { combinations: GROUPS_CONFIG };
    
    const PAGE_OWNER_ADDR = <?php echo json_encode($address, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

    const getEl = (id) => document.getElementById(id);
    let connectedWalletName = "";
    
    // --- RENDER LOGIC (Hierarchical) ---
    let currentGroupId = null;
    let activeThumbPreview = null;
    
    function closeActiveThumbPreview() {
      if (!activeThumbPreview) return;
      const f = activeThumbPreview.querySelector('iframe.thumb-media-html');
      if (f) f.remove();
      activeThumbPreview.classList.remove('preview-active');
      activeThumbPreview = null;
    }

    document.addEventListener('pointerdown', (e) => {
      if (!activeThumbPreview) return;
      if (activeThumbPreview.contains(e.target)) return;
      closeActiveThumbPreview();
      e.preventDefault();
      e.stopPropagation();
    }, true);


function renderPublicGrid(profile) {
        const grid = getEl('publicGrid');
        closeActiveThumbPreview();
        grid.innerHTML = '';
        
        // Safety: Ensure source data are arrays
// Safety: Ensure source data are arrays
const safeGroups = Array.isArray(profile.combinations) ? profile.combinations : [];

        const safePolicies = Array.isArray(ALL_POLICIES) ? ALL_POLICIES : [];
const groupsById = new Map((safeGroups || []).map(g => [String(g.id), g]));
const policiesById = new Map((safePolicies || []).map(p => [
  String(p.policyId || p.id || ''),
  p
]).filter(([k]) => k));


        let displayList = [];

        // 1. HELPER: Recursive Count Function (Matches Dashboard Logic)
        // Calculates total assets inside a group, digging into nested groups if needed
        function getGroupCount(group) {
            let total = 0;
            if (!group.policies || !Array.isArray(group.policies)) return 0;
            
            group.policies.forEach(rawId => {
                const id = String(rawId); // Force String for safety
                
                // A. Check if this ID is a nested group
                const childGroup = safeGroups.find(g => String(g.id) === id);
                if (childGroup) {
                    total += getGroupCount(childGroup); // Recursive add
                } else {
                    // B. Check if it's a policy
            const p = policiesById.get(id);
            if (p) total += (parseInt(p.count) || 0);

                }
            });
            return total;
        }
        function resolveHtmlPreviewForCol(col) {
  // A) Policy cards already have it
  if (col && typeof col.htmlPreview === 'string' && col.htmlPreview.trim()) {
    return col.htmlPreview.trim();
  }

  // B) Group cards: resolve from the real group object via id
  if (!col || !col.isGroup) return '';

  const rootGroup = groupsById.get(String(col.id));
  if (!rootGroup) return '';

  return getGroupHtmlPreviewFromGroup(rootGroup);
}

// Recursive: find first child policy/group with an html preview
function getGroupHtmlPreviewFromGroup(group) {
  if (!group) return '';

  // IMPORTANT: this key must match your real JSON.
  // If your group uses something else, change ONLY this line.
  const items = Array.isArray(group.policies) ? group.policies : [];

  for (const rawId of items) {
    const id = String(rawId);

    // nested group
    const childGroup = groupsById.get(id);
    if (childGroup) {
      const p = getGroupHtmlPreviewFromGroup(childGroup);
      if (p) return p;
      continue;
    }

    // policy
    const pol = policiesById.get(id);
    if (!pol) continue;

    if (typeof pol.htmlPreview === 'string' && pol.htmlPreview.trim()) return pol.htmlPreview.trim();

    // optional fallback if you store data:text/html somewhere else
    if (typeof pol.thumb === 'string' && pol.thumb.startsWith('data:text/html')) return pol.thumb;
  }

  return '';
}


// HELPER: Recursive HTML Preview Resolver (matches dashboard approach)
// Picks the first available child policy/group htmlPreview so group cards can have hover previews
function getGroupHtmlPreview(group) {
    if (!group || !Array.isArray(group.policies)) return '';

    for (const rawId of group.policies) {
        const id = String(rawId);

        // A) Nested group -> recurse
        const childGroup = safeGroups.find(g => String(g.id) === id);
        if (childGroup) {
            const p = getGroupHtmlPreview(childGroup);
            if (p) return p;
            continue;
        }

        // B) Policy -> use its htmlPreview if present, otherwise allow data:text/html thumbs if you store it there
        const p = policiesById.get(id);
        if (p) {
          if (typeof p.htmlPreview === 'string' && p.htmlPreview.trim()) return p.htmlPreview.trim();
          if (typeof p.thumb === 'string' && p.thumb.startsWith('data:text/html')) return p.thumb;
        }
    }
    return '';
}


        // 2. DETERMINE VIEW (Inside a group vs Root)
        if (currentGroupId) {
            // --- VIEWING INSIDE A GROUP ---
            const group = safeGroups.find(g => String(g.id) === String(currentGroupId));
            
            if (group && group.policies) {
                // Map IDs to actual objects (Nested Group OR Policy)
                group.policies.forEach(rawId => {
                    const id = String(rawId);
                    
                    const nestedGroup = safeGroups.find(g => String(g.id) === id);
                    if (nestedGroup) {
                        displayList.push({
                            id: nestedGroup.id,
                            name: nestedGroup.name,
                            count: getGroupCount(nestedGroup),
                            thumb: nestedGroup.thumb,
                            thumbIsVideo: nestedGroup.thumbIsVideo,
                            htmlPreview: getGroupHtmlPreview(nestedGroup),
                            type: 'created',
                            isGroup: true
                        });
                    } else {
                        const p = safePolicies.find(x => String(x.id) === id);
                        if(p) displayList.push(p);
                    }
                });

                // Back Button (Always First)
                const backCard = document.createElement('div');
                backCard.className = 'collection-card';
                backCard.innerHTML = `<div style="height:200px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#8899ac;"><i class="fas fa-arrow-left" style="font-size:2rem; margin-bottom:10px;"></i>Back</div>`;
                backCard.onclick = () => { 
                    currentGroupId = null; 
                   renderPublicGrid(profile);
                };
                grid.prepend(backCard); 

            } else {
                // Group invalid/deleted, go to root
                currentGroupId = null;
                renderPublicGrid(profile);
                return;
            }
        } else {
            // --- ROOT VIEW ---
            
            // A. Identify Hidden Items (Any Group or Policy that exists inside another Group)
            const hiddenIds = new Set();
            safeGroups.forEach(g => {
                if(g.policies && Array.isArray(g.policies)) {
                    g.policies.forEach(rawId => hiddenIds.add(String(rawId)));
                }
            });

            // B. Add Top-Level Groups (ONLY if not hidden)
            safeGroups.forEach(g => {
                if (!hiddenIds.has(String(g.id))) {
                    displayList.push({
                        id: g.id,
                        name: g.name,
                        count: getGroupCount(g), // Use Recursive Count
                        thumb: g.thumb,
                        thumbIsVideo: g.thumbIsVideo,
                        htmlPreview: getGroupHtmlPreview(g),
                        type: 'created',
                        isGroup: true
                    });
                }
            });

            // C. Add Top-Level Policies (ONLY if not hidden)
            safePolicies.forEach(p => {
                if (!hiddenIds.has(String(p.id))) displayList.push(p);
            });
        }

        // 3. RENDER THE CARDS
        if(displayList.length === 0) {
            grid.innerHTML = '<div style="color:#666; padding:20px; grid-column:1/-1; text-align:center;">No collections found.</div>';
            return;
        }

        displayList.forEach(col => {
            // Skip rendering the back button if it's already in the list (safety)
            if(!col.id && !col.name) return; 

            const card = document.createElement('div');
            // --- PREVIEW DETECTION (match dashboard behavior) ---
            const thumbUrl = (typeof col.thumb === 'string') ? col.thumb : '';
            const htmlPreviewUrl =
              (typeof col.htmlPreview === 'string' && col.htmlPreview.trim()) ? col.htmlPreview.trim() :
              (thumbUrl.startsWith('data:text/html') ? thumbUrl : ''); // optional fallback if your groups use data:text/html thumbs
            
            const resolvedPreview = resolveHtmlPreviewForCol(col);
            card.className = `collection-card${resolvedPreview ? ' has-preview' : ''}`;
            
            // Store it for hover handlers (don’t rely on `col.htmlPreview`)
            if (resolvedPreview) card.dataset.htmlPreview = resolvedPreview;
            
            
            let thumbInner;
            
            // Check if it's an HTML thumbnail (e.g. On-Chain SVG/HTML)
            const isHtmlThumb = col.thumb && col.thumb.startsWith('data:text/html');

            if (!col.thumb) {
                 thumbInner = `<img src="/fre5hfence/RF5.png" class="thumb-media thumb-media-img">`;
            } 
            else if (isHtmlThumb) {
                 // WRAP IN SCALER DIV
                 thumbInner = `
                    <div class="html-thumb-wrapper">
                        <iframe src="${col.thumb}" class="thumb-media thumb-media-html" scrolling="no"></iframe>
                    </div>`;
            }
            else if (col.thumbIsVideo) {
                 thumbInner = `<video src="${col.thumb}" class="thumb-media thumb-media-video" muted playsinline loop autoplay preload="metadata"></video>`;
            } 
            else {
                 thumbInner = `<img src="${col.thumb}" class="thumb-media thumb-media-img" loading="lazy">`;
            }

            let metaHtml = '';
            if (col.isGroup) {
                metaHtml = `${col.count} Assets (Combined)`;
            } else {
                metaHtml = `${col.count} Assets`;
            }

            card.innerHTML = `
                <div class="col-thumb">
                  ${thumbInner}
                  <div class="html-thumb-wrapper"></div>
                </div>
                <div class="col-info">
                    <div class="col-title">${col.name}</div>
                    <div class="col-meta">${metaHtml}</div>
                </div>
                ${!col.isGroup ? getBadgeHtml(col.type) : ''}
            `;

            // ... (Gateway fallback logic remains the same) ...
            const tImg = card.querySelector('.thumb-media-img');
            if (tImg && col.thumb) {
                const cid = getIPFSSuffix(col.thumb);
                if (cid) {
                    let gw = 0;
                    tImg.src = IPFS_GATEWAYS[gw] + cid;
                    tImg.onerror = () => {
                        if (gw < IPFS_GATEWAYS.length - 1) {
                            gw++;
                            tImg.src = IPFS_GATEWAYS[gw] + cid;
                        }
                    };
                }
            }

// --- HTML PREVIEW (match dashboard behavior) ---
const previewUrl = card.dataset.htmlPreview || '';
if (previewUrl) {
                const thumbDiv = card.querySelector('.col-thumb');
                const wrapper = card.querySelector('.html-thumb-wrapper'); // This wrapper exists in your HTML template
                const isCoarse = window.matchMedia('(pointer: coarse)').matches;

                const mountIframe = () => {
                    // Check if already mounted
                    if (!wrapper || wrapper.querySelector('iframe')) return;
                    
                    const iframe = document.createElement('iframe');
                    iframe.className = 'thumb-media thumb-media-html';
                    iframe.src = previewUrl;
                    iframe.setAttribute('scrolling', 'no'); // Extra safety against scrollbars
                    
                    wrapper.appendChild(iframe);
                };

    const unmountIframe = () => {
        if (!wrapper) return;
        wrapper.innerHTML = '';
    };

    if (!isCoarse) {
        thumbDiv.addEventListener('mouseenter', () => {
            mountIframe();
        });
        thumbDiv.addEventListener('mouseleave', () => {
            unmountIframe();
        });
    } else {
        thumbDiv.addEventListener('click', (ev) => {
            if (activeThumbPreview && activeThumbPreview !== thumbDiv) closeActiveThumbPreview();

            mountIframe();
            activeThumbPreview = thumbDiv;
            thumbDiv.classList.add('preview-active');

            ev.preventDefault();
            ev.stopPropagation();
        }, { passive: false });
    }
}

            // Click Handler
            card.addEventListener('click', (e) => {
                if (!e.target.closest('.col-info')) return;
                if (col.isGroup) {
                    currentGroupId = col.id; 
                    renderPublicGrid(profile);
                } else {
                    openCollection(col.id, col.name, col.type); 
                }
            });

            grid.appendChild(card);
        });
    }

    // --- UTILS ---
    const formatWalletName = (name) => {
        if (!name) return "Select";
        let n = name.replace(/ ?wallet/gi, '').trim();
        if (n.length <= 3) return n.toUpperCase();
        return n.charAt(0).toUpperCase() + n.slice(1).toLowerCase();
    };

    let currentAssets = [];
    let assetPageSize = 10;
    let assetPageIndex = 0;
    let isAssetPageLoading = false;
    let activePropertyFilterPair = 'ALL';
    const traitPairsSet = new Set();
    const TRAIT_SKIP_KEYS = ['image','src','files','mediatype','name','description','assetName','asset_name','asset','policy_id','policyId','collection','project','publisher','copyright','artist','artists','creator','creators','author','twitter','website','social','discord','instagram'];
    const IPFS_GATEWAYS = ['https://dweb.link/ipfs/','https://ipfs.io/ipfs/','https://cloudflare-ipfs.com/ipfs/'];

    const getIPFSSuffix = (url) => {
        if (!url || typeof url !== 'string') return null;
        if (url.includes('/ipfs/')) return url.split('/ipfs/')[1].split('?')[0].split('#')[0];
        if (url.match(/^(Qm[a-zA-Z0-9]+|bafy[a-zA-Z0-9]+)/)) return url.split('?')[0].split('#')[0];
        return null;
    };

    function hexToAsciiSafe(hex) {
        if (!hex || typeof hex !== 'string') return '';
        if (!/^[0-9a-fA-F]+$/.test(hex) || hex.length % 2 !== 0) return '';
        try {
            let out = '';
            for (let i = 0; i < hex.length; i += 2) {
                const code = parseInt(hex.slice(i, i + 2), 16);
                if (!Number.isFinite(code)) return '';
                out += String.fromCharCode(code);
            }
            return out;
        } catch { return ''; }
    }

    function extractTraitsFromMeta(meta) {
        const traits = {};
        if (!meta || typeof meta !== 'object') return traits;
        for (const [k, v] of Object.entries(meta)) {
            const lowerK = k.toLowerCase().trim();
            if (TRAIT_SKIP_KEYS.includes(lowerK)) continue;
            if (v === null || v === undefined) continue;
            if (typeof v === 'object') continue;
            traits[k] = String(v);
        }
        return traits;
    }

    function buildMediaPreviewInto(meta, containerEl, fallbackSrc, enableControls = false) {
      if (!containerEl) return;
      containerEl.innerHTML = '';

      const formatUrl = (url) => {
        if (!url || typeof url !== 'string') return '';
        if (url.startsWith('data:')) return url;
        if (url.startsWith('ar://')) return 'https://arweave.net/' + url.slice(5).replace(/^\/+/, '');
        if (url.startsWith('arweave://')) return 'https://arweave.net/' + url.slice(10).replace(/^\/+/, '');
        if (url.startsWith('ipfs://')) return IPFS_GATEWAYS[0] + url.replace(/^ipfs:\/\//, '').replace(/^ipfs\//, '');
        if (url.startsWith('ipfs.io/')) return 'https://' + url;
        if (url.startsWith('http://') || url.startsWith('https://')) return url;
        if (url.startsWith('Qm') || url.startsWith('bafy')) return IPFS_GATEWAYS[0] + url;
        return url;
      };

// --- SHOW IFRAME ---
      const showIframe = (src) => {
        // 1. Create the Scaler Wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'html-thumb-wrapper';

        // 2. Create the Iframe
        const iframe = document.createElement('iframe');
        iframe.src = formatUrl(src);
        iframe.loading = 'lazy';
        iframe.className = 'thumb-media thumb-media-html';
        iframe.setAttribute('scrolling', 'no'); // Extra scrollbar prevention

        // 3. Nest them
        wrapper.appendChild(iframe);
        containerEl.appendChild(wrapper);
      };

      const showImage = (src) => {
        const raw = formatUrl(src);
        const cid = getIPFSSuffix(raw);
        let gw = 0;
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.className = 'thumb-media thumb-media-img';
        if (cid) img.src = IPFS_GATEWAYS[gw] + cid;
        else img.src = raw;
        img.onerror = () => {
          if (cid && gw < IPFS_GATEWAYS.length - 1) {
            gw++;
            img.src = IPFS_GATEWAYS[gw] + cid;
          }
        };
        containerEl.appendChild(img);
      };

      const showVideo = (src) => {
        const gateways = IPFS_GATEWAYS;
        let posterUrl = fallbackSrc;
        if (meta && meta.image) {
           let imgRaw = Array.isArray(meta.image) ? meta.image.join('') : meta.image;
           posterUrl = formatUrl(imgRaw);
        }
        let currentSrc = formatUrl(src);
        const ipfsSuffix = getIPFSSuffix(currentSrc);
        if (ipfsSuffix) currentSrc = gateways[0] + ipfsSuffix;
        
        let gatewayIndex = 0;
        const v = document.createElement('video');
        v.className = 'thumb-media thumb-media-video';
        v.setAttribute('crossorigin', 'anonymous'); 
        if (posterUrl) v.setAttribute('poster', posterUrl);

        const source = document.createElement('source');
        source.src = currentSrc;
        v.appendChild(source);

        v.onerror = () => {
            if (ipfsSuffix && gatewayIndex < gateways.length - 1) {
                gatewayIndex++;
                source.src = gateways[gatewayIndex] + ipfsSuffix;
                v.load(); 
                if (!enableControls) { 
                    const p = v.play();
                    if (p && typeof p.catch === 'function') p.catch(() => {});
                }
            }
        };
        v.playsInline = true; 
        v.setAttribute('playsinline', 'true');
        if (enableControls) {
          v.controls = true; v.setAttribute('controls', ''); v.autoplay = false; v.muted = false; v.loop = true; v.setAttribute('loop', '');
        } else {
          v.controls = false; v.removeAttribute('controls'); v.autoplay = true; v.muted = true; v.loop = true; v.setAttribute('muted', ''); v.setAttribute('autoplay', ''); v.setAttribute('loop', '');
        }
        containerEl.appendChild(v);
        if (!enableControls) {
          const p = v.play();
          if (p && typeof p.catch === 'function') p.catch(() => {});
        }
      };
      
      if (!meta || typeof meta !== 'object') {
        if (fallbackSrc) showImage(fallbackSrc);
        return;
      }

      let fileSrc = '';
      let fileType = '';
      if (Array.isArray(meta.files) && meta.files.length > 0) {
        const f0 = meta.files[0];
        if (f0 && typeof f0 === 'object') {
          fileType = (f0.mediaType || '').toLowerCase();
          if (typeof f0.src === 'string') fileSrc = f0.src;
          else if (Array.isArray(f0.src)) fileSrc = f0.src.filter(v => typeof v === 'string').join('');
        }
      }

      let src;
      if (fileSrc) {
        src = fileSrc;
        if (src.startsWith('ipfs://')) src = 'https://ipfs.io/ipfs/' + src.replace(/^ipfs:\/\//, '').replace(/^ipfs\//, '');
        if (fileType.startsWith('text/html') || src.startsWith('data:text/html')) { showIframe(src); return; }
        if (fileType.startsWith('video/')) { showVideo(src); return; }
        if (fileType.startsWith('image/')) { showImage(src); return; }
      }

      let imgField = meta.image;
      if (Array.isArray(imgField)) imgField = imgField.filter(v => typeof v === 'string').join('');
      if (typeof imgField === 'string' && imgField) {
        let imgSrc = imgField;
        if (imgSrc.startsWith('ipfs://')) imgSrc = 'https://ipfs.io/ipfs/' + imgSrc.replace(/^ipfs:\/\//, '').replace(/^ipfs\//, '');
        if (imgSrc.startsWith('data:image/') || /^https?:\/\//i.test(imgSrc)) { showImage(imgSrc); return; }
      }
      if (fallbackSrc) showImage(fallbackSrc);
    }

    function getBadgeHtml(type) {
        const t = (type || 'owned').toLowerCase();
        const isGreen = (t === 'claimed' || t === 'created');
        const colorClass = isGreen ? 'green' : 'yellow';
        const tooltip = isGreen ? 'Verified' : 'Verified\nImported';
        return `<div class="verified-badge ${colorClass}" data-tooltip="${tooltip}"></div>`;
    }

    window.closeModal = (id) => getEl(id).style.display = 'none';
    let _fsMediaNode = null;
    let _fsReturnParent = null;
    let _fsReturnNext = null;
    let _fsPrevBodyOverflow = '';

    window.openMediaFullscreen = function () {
      const overlay = getEl('mediaFsOverlay');
      const host = getEl('mediaFsHost');
      const dMedia = getEl('dMedia');
      if (!overlay || !host || !dMedia) return;
      const media = dMedia.querySelector('iframe, video, img');
      if (!media) return;
      _fsMediaNode = media;
      _fsReturnParent = media.parentNode;
      _fsReturnNext = media.nextSibling;
      host.innerHTML = '';
      host.appendChild(media);
      _fsPrevBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      overlay.classList.add('show');
      if (media.tagName === 'VIDEO') { try { media.play(); } catch {} }
    };

    window.closeMediaFullscreen = function () {
      const overlay = getEl('mediaFsOverlay');
      const host = getEl('mediaFsHost');
      if (!overlay) return;
      if (_fsMediaNode) {
        const fallback = getEl('dMedia');
        const targetParent = (_fsReturnParent && document.contains(_fsReturnParent)) ? _fsReturnParent : fallback;
        if (targetParent) {
          if (_fsReturnNext && document.contains(_fsReturnNext)) targetParent.insertBefore(_fsMediaNode, _fsReturnNext);
          else targetParent.appendChild(_fsMediaNode);
        }
      }
      if (host) host.innerHTML = '';
      overlay.classList.remove('show');
      document.body.style.overflow = _fsPrevBodyOverflow || '';
      _fsMediaNode = null; _fsReturnParent = null; _fsReturnNext = null; _fsPrevBodyOverflow = '';
    };

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const overlay = getEl('mediaFsOverlay');
      if (overlay && overlay.classList.contains('show')) closeMediaFullscreen();
    });

    async function openCollection(policyId, name, type) {
        getEl('assetBrowserModal').style.display = 'flex';
        getEl('browserTitle').innerHTML = `${name} ${getBadgeHtml(type)}`;
        getEl('assetGrid').innerHTML = 'Loading assets...';
        try {
            const res = await fetch(`policies/${policyId}.json`);
            const data = await res.json();
            window.PUBLIC_PROFILE = data;
            const assets = data.assets_cache || [];
            currentAssets = assets;
            traitPairsSet.clear();
            activePropertyFilterPair = 'ALL';
            assetPageIndex = 0;
            currentAssets.forEach(asset => {
                const meta = asset.onchain_metadata || asset.metadata || {};
                let displayName = '';
                if (meta && typeof meta.name === 'string' && meta.name.trim()) displayName = meta.name.trim();
                else {
                    const hex = asset.asset_name || '';
                    const ascii = hexToAsciiSafe(hex);
                    displayName = ascii || asset.asset || 'Asset';
                }
                asset.displayName = displayName; 
                asset.traits = extractTraitsFromMeta(meta); 
                if(asset.traits) Object.entries(asset.traits).forEach(([k,v]) => traitPairsSet.add(`${k}|||${v}`));
            });
            refreshFilters();
            updateLabel(assets.length, assets.length);
            getEl('assetGrid').innerHTML = '';
            renderPage();
        } catch(e) { console.error(e); getEl('assetGrid').innerHTML = "Failed to load assets."; }
    }

    function refreshFilters() {
        const sel = getEl('propertyFilterSelect');
        const current = 'ALL';
        const options = Array.from(traitPairsSet).sort();
        sel.innerHTML = '<option value="ALL">All properties</option>' + options.map(s => `<option value="${s}">${s.replace('|||', ': ')}</option>`).join('');
        sel.value = current;
    }

    function updateLabel(count, total) {
        const lbl = getEl('assetCountLabel');
        if(count === total) lbl.textContent = `${total} assets`;
        else { const pct = ((count/total)*100).toFixed(1); lbl.textContent = `${count} of ${total} (${pct}%)`; }
    }

    function renderPage() {
        if(isAssetPageLoading) return; isAssetPageLoading = true;
        const grid = getEl('assetGrid');
        let matches = 0;
        let [fKey, fVal] = activePropertyFilterPair === 'ALL' ? [null,null] : activePropertyFilterPair.split('|||');
        
        currentAssets.forEach(a => { if(!fKey || (a.traits && a.traits[fKey] === fVal)) matches++; });
        updateLabel(matches, currentAssets.length);

        let added = 0; let i = assetPageIndex;
        while(i < currentAssets.length && added < assetPageSize) {
            const asset = currentAssets[i]; i++;
            if(fKey && (!asset.traits || asset.traits[fKey] !== fVal)) continue;

            const ac = document.createElement('div'); ac.className = 'asset-card';
            const thumbDiv = document.createElement('div'); thumbDiv.className = 'asset-thumb-wrap';
            const meta = asset.onchain_metadata || asset.metadata || {};
            buildMediaPreviewInto(meta, thumbDiv, '/fre5hfence/RF5.png', false);

            let traitsHtml = '';
            if (asset.traits) {
              for (const [k, v] of Object.entries(asset.traits)) {
                traitsHtml += `<div class="detail-meta-row"><span class="trait-k">${k}</span><span class="trait-v">${v}</span></div>`;
              }
            }

            ac.innerHTML = `<div class="asset-name">${asset.displayName}</div>` + (traitsHtml ? `<div class="asset-traits">${traitsHtml}</div>` : '');
            ac.prepend(thumbDiv);
            
            ac.onclick = () => {
              const dModal = getEl('assetDetailModal');
              const dContent = getEl('detailContent');
              dContent.innerHTML = `
                <div id="dMedia" style="height:300px; margin-bottom:15px; width:100%; background:#000; display:flex; align-items:center; justify-content:center; overflow:hidden; border-radius:12px;"></div>
                <div class="detail-title-row">
                  <h2 id="dTitle" style="margin:0;"></h2>
                  <button type="button" id="btnMediaFs" onclick="openMediaFullscreen()" title="Fullscreen"><i class="fas fa-expand"></i></button>
                </div>
                <div id="dTraits" style="text-align:left; background:rgba(255,255,255,0.05); padding:15px; border-radius:12px;"></div>
              `;
              buildMediaPreviewInto(meta, document.getElementById('dMedia'), '/fre5hfence/RF5.png', true);
              const traitsList = Object.entries(asset.traits || {}).map(([k,v]) => `<div class="detail-meta-row"><span style="color:#8899ac;">${k}</span><span style="font-family:monospace; color:#fff;">${v}</span></div>`).join('');
              getEl('dTitle').textContent = asset.displayName;
              getEl('dTraits').innerHTML = traitsList.length ? traitsList : '<div style="text-align:center; color:#666;">No traits found</div>';
              dModal.style.display = 'flex';
            };

            grid.appendChild(ac); added++;
        }
        assetPageIndex = i; isAssetPageLoading = false;
        const g2 = getEl('assetGrid');
        if (assetPageIndex < currentAssets.length && g2.scrollHeight <= g2.clientHeight + 2) {
            requestAnimationFrame(() => renderPage());
        }
    }

    getEl('assetPageSizeSelect').addEventListener('change', (e) => { assetPageSize = parseInt(e.target.value); assetPageIndex = 0; getEl('assetGrid').innerHTML = ''; renderPage(); });
    getEl('propertyFilterSelect').addEventListener('change', (e) => { activePropertyFilterPair = e.target.value; assetPageIndex = 0; getEl('assetGrid').innerHTML = ''; renderPage(); });
    getEl('assetGrid').addEventListener('scroll', () => { if(isAssetPageLoading) return; const g = getEl('assetGrid'); if(g.scrollTop + g.clientHeight >= g.scrollHeight - 100) renderPage(); });

    async function populateWalletLists() {
        try {
            const wallets = await BrowserWallet.getInstalledWallets();
            const sel = getEl('walletSelect');
            if(wallets.length && sel) {
                sel.innerHTML = wallets.map(w => `<option value="${w.id}">${formatWalletName(w.name)}</option>`).join('');
                sel.disabled = false;
            }
        } catch(e) {}
    }

    window.disconnectWallet = async () => {
        getEl('miniModal').style.display = 'none';
        const btn = getEl('connectBtn');
        const sel = getEl('walletSelect');
        const own = getEl('ownerControls');
        btn.textContent = "Connect";
        btn.classList.remove('connected');
        btn.disabled = false;
        own.style.display = 'none';
        await populateWalletLists();
        window.history.pushState({}, document.title, window.location.pathname);
    };

/* --- GLOBAL HELPER --- */
window.togglePublicDropdown = () => {
    const d = document.getElementById('profileDropdown');
    if(d) d.style.display = (d.style.display === 'flex') ? 'none' : 'flex';
};

/* --- MAIN INIT FUNCTION --- */
async function initWallet() {
    const pfpImg = document.getElementById('headerProfileBtn');
    const walletSel = document.getElementById('walletSelect');
    const dropdown = document.getElementById('profileDropdown');
    const balanceEl = document.getElementById('headerBalance');
    const ownControls = document.getElementById('ownerControls'); // Floating buttons
    const connectBtn = document.getElementById('connectBtn'); // The text button (if you kept it)

    const urlParams = new URLSearchParams(window.location.search);
    const returningAddr = urlParams.get('addr');
    const returningName = urlParams.get('name') || "Connected";

    let isConnected = false;

    // --- 1. Populate Wallets ---
    try {
        const wallets = await BrowserWallet.getInstalledWallets();
        if (wallets.length > 0) {
            walletSel.innerHTML = 
                `<option value="" disabled selected>Select Wallet...</option>` + 
                wallets.map(w => `<option value="${w.id}">${formatWalletName(w.name)}</option>`).join('');
        }
    } catch(e) {}

    // --- 2. Connection Logic ---
    const handleConnection = async (addr, identity) => {
        isConnected = true;
        walletSel.style.display = 'none'; // Hide selector
        if(connectBtn) connectBtn.style.display = 'none'; // Hide text button

        // A. Check if this is the Page Owner
        const isOwner = (addr === PAGE_OWNER_ADDR || identity === PAGE_OWNER_ADDR);

        // B. Fetch Profile Data (To get PFP)
        let userPfp = '/fre5hfence/RF5.png?v=2'; // Default
        let username = '';
        let hasProfile = false;

        try {
            // Using the same check_user script the dashboard uses
            const res = await fetch('/idp/check_user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ identity: identity })
            });
            const d = await res.json();
            
            if (d.found) {
                hasProfile = true;
                username = d.username;
                if (d.data.pfp) {
                    userPfp = d.data.pfp;
                    // Handle relative paths
                    if (!userPfp.startsWith('http') && !userPfp.startsWith('data:') && !userPfp.startsWith('/')) {
                        userPfp = `/idp/${username}/${userPfp}`;
                    }
                }
            }
        } catch (e) { console.error("Profile fetch failed", e); }

        // C. Update Header Visuals
        pfpImg.src = userPfp;
        pfpImg.style.border = isOwner ? '2px solid #ffd869' : '2px solid #4CAF50';
        pfpImg.title = username || "Connected";
        
        // D. Build Dropdown Menu
        let menuHtml = '';

        if (isOwner) {
            // --- OWNER MENU ---
            // "Edit Profile" sends signal to Dashboard to open Editor
            menuHtml += `
                <div class="menu-item" onclick="window.location.href='/idp/?addr=${addr}&name=${encodeURIComponent(returningName)}&edit=true'">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </div>
                <div class="menu-item" onclick="window.location.href='/idp/?addr=${addr}&name=${encodeURIComponent(returningName)}'">
                    <i class="fas fa-th-large"></i> Dashboard
                </div>
            `;
            // Enable Floating Controls too
            if(ownControls) {
                ownControls.style.display = 'flex';
                document.getElementById('dashLink').href = `/idp/?addr=${addr}&name=${encodeURIComponent(returningName)}`;
                document.getElementById('editLink').href = `/idp/?addr=${addr}&name=${encodeURIComponent(returningName)}&edit=true`;
            }
        } else {
            // --- VISITOR MENU ---
            if (hasProfile) {
                menuHtml += `
                    <div class="menu-item" onclick="window.location.href='/idp/?addr=${addr}'">
                        <i class="fas fa-th-large"></i> My Dashboard
                    </div>`;
            } else {
                menuHtml += `
                    <div class="menu-item" onclick="window.location.href='/idp/?addr=${addr}'">
                        <i class="fas fa-plus-circle"></i> Create Profile
                    </div>`;
            }
        }

        // Common Items
        menuHtml += `<div style="height:1px; background:rgba(255,255,255,0.1); margin:5px 0;"></div>`;
        menuHtml += `<div class="menu-item" onclick="window.location.reload()"><i class="fas fa-sign-out-alt"></i> Disconnect</div>`;

        dropdown.innerHTML = menuHtml;

        // E. Set Click Action to Toggle Menu
        pfpImg.onclick = (e) => {
            e.stopPropagation();
            window.togglePublicDropdown();
        };
    };

    // --- 3. Interaction Handlers ---

    // Click PFP when Disconnected -> Show Wallet List
    pfpImg.onclick = (e) => {
        if (!isConnected) {
            e.stopPropagation();
            walletSel.style.display = (walletSel.style.display === 'block') ? 'none' : 'block';
        }
    };

    // Select Wallet -> Connect
    walletSel.addEventListener('change', async () => {
        const wid = walletSel.value;
        if (!wid) return;
        
        pfpImg.style.opacity = '0.5'; // Loading feedback

        try {
            const wallet = await BrowserWallet.enable(wid);
            const addr = await wallet.getChangeAddress();
            const rArr = await wallet.getRewardAddresses();
            const identity = rArr[0] || addr;

            // Get Balance
            try {
                const lovelace = await wallet.getLovelace();
                if(balanceEl) {
                    balanceEl.textContent = `₳ ${(parseInt(lovelace)/1000000).toFixed(2)}`;
                    balanceEl.style.display = 'block';
                }
            } catch(e){}

            pfpImg.style.opacity = '1';
            await handleConnection(addr, identity);

        } catch (err) {
            console.error(err);
            pfpImg.style.opacity = '1';
            alert("Connection failed.");
        }
    });

    // Check URL for existing session
    if (returningAddr) {
        // We assume returningAddr is the identity for simplicity in URL params
        handleConnection(returningAddr, returningAddr); 
    }

    // Global click to close menus
    window.addEventListener('click', () => {
        if(walletSel) walletSel.style.display = 'none';
        if(dropdown) dropdown.style.display = 'none';
    });
}

    getEl('notifIcon').addEventListener('click', () => {
        const l = getEl('notifList'); 
        l.style.display = l.style.display === 'flex' ? 'none' : 'flex';
        getEl('notifDot').style.display = 'none';
    });
    
    // --- STARTUP ---
    renderPublicGrid(profile);
    initWallet();

  </script>
</body>
</html>

<?php


// CAPTURE BUFFER AND SAVE ONLY IF PUBLIC PUSH
// Drafts do not regenerate HTML to avoid overwriting the live page with temp data
$htmlContent = ob_get_clean();

if ($action === 'push_public') {
    if (file_put_contents("$targetDir/index.html", $htmlContent) === false) {
        throw new Exception("Failed to write index.html (permissions?)");
    }
}

echo json_encode([
    'status'   => 'success',
    'mode'     => 'public',
    'pushType' => $pushType,
    'hasDraft' => file_exists("$targetDir/profile_draft.json"),
]);



} catch (Throwable $e) {
    if (ob_get_length()) ob_clean(); 
    http_response_code(500); 
    echo json_encode(['error' => $e->getMessage()]);
}
?>