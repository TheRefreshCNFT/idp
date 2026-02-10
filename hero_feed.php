<?php
// hero_feed.php
// 1. Reads policy_claims.json.
// 2. Picks a random asset from the approved user's policy file.
// 3. Pre-processes the media (flattens arrays, detects HTML/Video) to prevent JS crashes.
// 4. Returns a lightweight JSON feed.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$baseDir = __DIR__; 
$claimsFile = $baseDir . '/data/policy_claims.json';
$cacheFile  = $baseDir . '/hero_feed.json';
$lockFile   = $baseDir . '/hero_feed.lock';

// Settings
$ttlSeconds = 5000; 
$maxItems = 8;
$maxRecentCandidates = 800; 

// --- 1. Serve Cache if Fresh ---
$now = time();
if (is_file($cacheFile) && ($now - @filemtime($cacheFile) < $ttlSeconds)) {
    readfile($cacheFile);
    exit;
}

// --- 2. Lock Mechanism ---
$fp = @fopen($lockFile, 'c');
if ($fp && !@flock($fp, LOCK_EX | LOCK_NB)) {
    if (is_file($cacheFile)) readfile($cacheFile);
    else echo json_encode(['generatedAt' => gmdate('c'), 'items' => []]);
    exit;
}

// --- Helper: Hex Decoder ---
function hexToAsciiSafe($hex) {
    if (!is_string($hex)) return $hex;
    $hex = trim($hex);
    if (strpos($hex, ' ') !== false || !ctype_xdigit($hex)) return $hex;
    $bin = @hex2bin($hex);
    if ($bin === false || !ctype_print($bin)) return $hex;
    return $bin;
}

// --- Helper: Name Derivation ---
function deriveCollectionName($displayName) {
    if (!$displayName) return 'Collection';
    if (preg_match('/^(.*?)(?:\s*#\d+)?\s*$/', $displayName, $m)) {
        return trim($m[1]) ?: trim($displayName);
    }
    return trim($displayName);
}

// --- 3. Load Candidates from JSON ---
$candidates = [];

if (file_exists($claimsFile)) {
    $jsonRaw = @file_get_contents($claimsFile);
    $claims = json_decode($jsonRaw, true);

    if (is_array($claims)) {
        foreach ($claims as $row) {
            if (isset($row['status']) && $row['status'] !== 'approved') continue;

            $u = $row['user'] ?? '';
            $p = $row['policyId'] ?? '';

            if (!$u || !$p) continue;

            $policyPath = $baseDir . '/' . $u . '/policies/' . $p . '.json';

            if (file_exists($policyPath)) {
                $candidates[] = [
                    'u' => $u,
                    'p' => $p,
                    'f' => $policyPath,
                    't' => @filemtime($policyPath) ?: 0
                ];
            }
        }
    }
}

if (empty($candidates)) {
    $payload = json_encode(['generatedAt' => gmdate('c'), 'items' => []]);
    @file_put_contents($cacheFile, $payload);
    echo $payload;
    if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
    exit;
}

// --- 4. Sort & Shuffle ---
usort($candidates, function($a, $b) {
    return ($b['t'] - $a['t']); 
});
$bucket = array_slice($candidates, 0, min(count($candidates), $maxRecentCandidates));
shuffle($bucket);

// --- 5. Process Assets ---
$items = [];
$seenUsers = [];

foreach ($bucket as $cand) {
    if (count($items) >= $maxItems) break;
    if (isset($seenUsers[$cand['u']])) continue;

    $raw = @file_get_contents($cand['f']);
    if (!$raw) continue;
    $data = json_decode($raw, true);
    
    $assets = $data['assets_cache'] ?? null;
    if (!is_array($assets) || empty($assets)) continue;

    // Pick 1 Random Asset
    $chosen = $assets[array_rand($assets)];

    // Get Metadata Root
    $meta = $chosen['onchain_metadata'] ?? $chosen['metadata'] ?? [];
    $unit = $chosen['asset'] ?? (($chosen['policy_id']??'') . ($chosen['asset_name']??''));

    // Resolve Name (Hex Decode)
    $assetName = '';
    if (isset($meta['name']) && is_string($meta['name'])) {
        $assetName = trim($meta['name']);
    } else {
        $rawHex = $chosen['asset_name'] ?? '';
        $assetName = hexToAsciiSafe($rawHex) ?: $unit ?: 'Asset';
    }

    // Resolve Collection Name
    $policyName = $data['meta']['name'] ?? '';
    if (in_array($policyName, ['Verified Collection', 'Untitled', 'Collection', ''])) {
        $policyName = deriveCollectionName($assetName);
    }

   // --- MEDIA PRE-PROCESSING (hero must match dashboard’s on-chain shape) ---
$finalSrc = null;          // keep original type: array (on-chain chunks) OR string (normal URL)
$finalType = 'image';      // image | html | video
$finalMediaType = '';      // pass-through for JS if needed

// Priority 1: files[]
if (isset($meta['files']) && is_array($meta['files'])) {
    foreach ($meta['files'] as $f) {
        $fType = strtolower($f['mediaType'] ?? '');
        $fSrcRaw = $f['src'] ?? null;

        // Detect using a small string (prevents regex/match on huge base64)
        $fSrcDetect = '';
        if (is_array($fSrcRaw)) $fSrcDetect = (string)($fSrcRaw[0] ?? '');
        elseif (is_string($fSrcRaw)) $fSrcDetect = $fSrcRaw;

        if ($fSrcDetect === '') continue;

        // HTML
        if (strpos($fType, 'html') !== false || strpos($fSrcDetect, 'data:text/html') === 0) {
            $finalType = 'html';
            $finalSrc = $fSrcRaw;            // KEEP ARRAY if it is an array
            $finalMediaType = $f['mediaType'] ?? 'text/html';
            break;
        }

        // Video
        if (strpos($fType, 'video') !== false || strpos($fSrcDetect, 'data:video') === 0) {
            $finalType = 'video';
            $finalSrc = $fSrcRaw;            // KEEP ARRAY if it is an array
            $finalMediaType = $f['mediaType'] ?? 'video/*';
            break;
        }

        // First usable fallback (usually image)
        if ($finalSrc === null) {
            $finalType = 'image';
            $finalSrc = $fSrcRaw;            // KEEP ARRAY if it is an array
            $finalMediaType = $f['mediaType'] ?? '';
        }
    }
}

// Priority 2: root image (common in your policies)
if ($finalSrc === null) {
    $rootImgRaw = $meta['image'] ?? null;

    $rootDetect = '';
    if (is_array($rootImgRaw)) $rootDetect = (string)($rootImgRaw[0] ?? '');
    elseif (is_string($rootImgRaw)) $rootDetect = $rootImgRaw;

    if ($rootDetect !== '') {
        $finalSrc = $rootImgRaw;             // KEEP ARRAY if it is an array
        if (strpos($rootDetect, 'data:text/html') === 0) $finalType = 'html';
        elseif (strpos($rootDetect, 'data:video') === 0) $finalType = 'video';
        else $finalType = 'image';
        $finalMediaType = $meta['mediaType'] ?? '';
    }
}

// Normalize ipfs:// prefix ONLY when the actual src is a string starting with ipfs://
// (If it’s an array, normalize only element[0] — that’s where the prefix lives.)
if (is_string($finalSrc)) {
    if (strpos($finalSrc, 'data:') !== 0 && strpos($finalSrc, 'ipfs://') === 0) {
        $finalSrc = 'ipfs://' . preg_replace('~^ipfs://|^ipfs/~', '', $finalSrc);
    }
} elseif (is_array($finalSrc) && isset($finalSrc[0]) && is_string($finalSrc[0])) {
    if (strpos($finalSrc[0], 'data:') !== 0 && strpos($finalSrc[0], 'ipfs://') === 0) {
        $finalSrc[0] = 'ipfs://' . preg_replace('~^ipfs://|^ipfs/~', '', $finalSrc[0]);
    }
}


    // --- Build Clean Output ---
    $items[] = [
        'username'   => $cand['u'],
        'policyId'   => $cand['p'],
        'policyName' => $policyName,
        'assetName'  => $assetName,
        // Removed 'unit' as requested
        // Removed 'meta' to prevent "two src" issues
        'media' => [
            'type'      => $finalType,
            'src'       => $finalSrc,        // now array for on-chain base64, string for normal URLs
            'mediaType' => $finalMediaType
        ]

    ];

    $seenUsers[$cand['u']] = true;
}

$out = [
    'generatedAt' => gmdate('c'),
    'items' => $items
];

// Cache & Output
$payload = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
@file_put_contents($cacheFile, $payload, LOCK_EX);
echo $payload;

if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
?>