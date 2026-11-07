<?php
/**
 * RZO HUB - VBL License Verification API (Throttled & Hardened)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = getDb();
$ip = getClientIp();
$nowTime = time();

// Rate Limiting: Max 60 queries per 60 seconds per IP
try {
    $stmt = $pdo->prepare("SELECT request_count, window_start FROM api_throttles WHERE ip_address = :ip LIMIT 1");
    $stmt->execute([':ip' => $ip]);
    $throttle = $stmt->fetch();

    if ($throttle) {
        if ($nowTime - $throttle['window_start'] < 60) {
            if ($throttle['request_count'] >= 60) {
                http_response_code(429);
                echo json_encode([
                    'status' => 'error',
                    'valid' => false,
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Too many requests. Please wait a minute before retrying.'
                ]);
                exit;
            }
            $up = $pdo->prepare("UPDATE api_throttles SET request_count = request_count + 1 WHERE ip_address = :ip");
            $up->execute([':ip' => $ip]);
        } else {
            $up = $pdo->prepare("UPDATE api_throttles SET request_count = 1, window_start = :w WHERE ip_address = :ip");
            $up->execute([':w' => $nowTime, ':ip' => $ip]);
        }
    } else {
        $in = $pdo->prepare("INSERT INTO api_throttles (ip_address, request_count, window_start) VALUES (:ip, 1, :w)");
        $in->execute([':ip' => $ip, ':w' => $nowTime]);
    }
} catch (Exception $e) {}

$rawKey = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));
$hwid = trim((string)($_GET['hwid'] ?? $_POST['hwid'] ?? ''));

// Thoroughly normalize key: strip invisible unicode, zero-width chars, BOM, quotes, and whitespace
$cleanKey = preg_replace('/[\x00-\x1F\x7F\xC2\xA0\x{200B}-\x{200D}\x{FEFF}\s\'"]/u', '', $rawKey);
$cleanKeyUpper = strtoupper(trim((string)$cleanKey));

if (empty($cleanKeyUpper)) {
    echo json_encode([
        'status' => 'error',
        'valid' => false,
        'code' => 'MISSING_KEY',
        'message' => 'License key parameter is required.'
    ]);
    exit;
}

// Prepare lookup variants for 100% resilient case & prefix matching
$keyPrefixed = str_starts_with($cleanKeyUpper, 'VBL-') ? $cleanKeyUpper : ('VBL-' . ltrim($cleanKeyUpper, '-'));
$keyNoHyphens = str_replace('-', '', $cleanKeyUpper);
$keyNoHyphensPrefixed = str_starts_with($keyNoHyphens, 'VBL') ? $keyNoHyphens : ('VBL' . $keyNoHyphens);

$stmt = $pdo->prepare("
    SELECT l.*, u.username, u.is_banned 
    FROM licenses l 
    JOIN users u ON l.user_id = u.id 
    WHERE UPPER(TRIM(l.license_key)) = :k1 
       OR UPPER(TRIM(l.license_key)) = :k2 
       OR UPPER(REPLACE(l.license_key, '-', '')) = :k3 
       OR UPPER(REPLACE(l.license_key, '-', '')) = :k4 
    LIMIT 1
");
$stmt->execute([
    ':k1' => $cleanKeyUpper,
    ':k2' => $keyPrefixed,
    ':k3' => $keyNoHyphens,
    ':k4' => $keyNoHyphensPrefixed
]);
$license = $stmt->fetch();

if (!$license) {
    echo json_encode([
        'status' => 'error',
        'valid' => false,
        'code' => 'INVALID_KEY',
        'message' => 'Invalid VBL license key.'
    ]);
    exit;
}

if ($license['is_banned']) {
    echo json_encode([
        'status' => 'error',
        'valid' => false,
        'code' => 'USER_BANNED',
        'message' => 'Associated user account is suspended.'
    ]);
    exit;
}

$currentTime = time();
$expiryTime = strtotime($license['expires_at']);

if ($expiryTime <= $currentTime) {
    echo json_encode([
        'status' => 'error',
        'valid' => false,
        'code' => 'KEY_EXPIRED',
        'message' => 'VBL license key has expired. Please claim a new 24h extension.',
        'expires_at' => $license['expires_at']
    ]);
    exit;
}

// Hardware ID Binding Logic
if (!empty($hwid)) {
    if (empty($license['hwid'])) {
        $up = $pdo->prepare("UPDATE licenses SET hwid = :hwid, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $up->execute([':hwid' => $hwid, ':id' => $license['id']]);
        $license['hwid'] = $hwid;
        logAction((int)$license['user_id'], 'HWID_AUTO_BOUND', "Bound to HWID: {$hwid}");
        syncDatabaseSnapshot();
    } elseif ($license['hwid'] !== $hwid) {
        echo json_encode([
            'status' => 'error',
            'valid' => false,
            'code' => 'HWID_MISMATCH',
            'message' => 'License is bound to another device. Reset your HWID on the dashboard.'
        ]);
        exit;
    }
}

echo json_encode([
    'status' => 'success',
    'valid' => true,
    'license_key' => $license['license_key'],
    'user' => $license['username'],
    'hwid_bound' => !empty($license['hwid']),
    'expires_at' => $license['expires_at'],
    'remaining_seconds' => max(0, $expiryTime - $currentTime)
]);
exit;
