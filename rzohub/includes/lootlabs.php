<?php
/**
 * RZO HUB - LootLabs Content Locker Integration (Race-Condition Hardened)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class LootLabsClient {
    private string $apiKey;
    private string $endpoint;

    public function __construct() {
        $this->apiKey = LOOTLABS_API_KEY;
        $this->endpoint = LOOTLABS_API_ENDPOINT;
    }

    /**
     * Request a monetized content locker link from LootLabs API
     */
    public function createLockerLink(string $destinationUrl, string $title = 'RZO HUB Access Key'): array {
        $payload = [
            'title' => substr($title, 0, 30),
            'url' => $destinationUrl,
            'tier_id' => LOOTLABS_TIER_ID,
            'number_of_tasks' => LOOTLABS_TASK_COUNT,
            'theme' => LOOTLABS_THEME
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'cURL error: ' . $curlError
            ];
        }

        $data = json_decode($response ?: '', true);

        $lockerUrl = null;
        if (!empty($data['message']) && is_array($data['message']) && !empty($data['message'][0]['loot_url'])) {
            $lockerUrl = $data['message'][0]['loot_url'];
        } elseif (!empty($data['item']['link'])) {
            $lockerUrl = $data['item']['link'];
        } elseif (!empty($data['link'])) {
            $lockerUrl = $data['link'];
        }

        if ($httpCode >= 200 && $httpCode < 300 && $lockerUrl) {
            return [
                'success' => true,
                'link' => $lockerUrl,
                'short' => $data['message'][0]['short'] ?? null,
                'raw' => $data
            ];
        }

        return [
            'success' => false,
            'status_code' => $httpCode,
            'message' => $data['message'] ?? $data['error'] ?? 'Failed to generate LootLabs link (Status: ' . $httpCode . ')',
            'raw' => $data
        ];
    }
}

/**
 * Creates a unique session for key claim and invokes LootLabs
 */
function createKeyClaimSession(int $userId): array {
    $pdo = getDb();

    // Expire old unused sessions
    $stmt = $pdo->prepare("UPDATE loot_sessions SET is_completed = 2 WHERE user_id = :uid AND is_completed = 0 AND expires_at < CURRENT_TIMESTAMP");
    $stmt->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes validity

    $destinationUrl = getBaseUrl() . '/claim_key.php?token=' . urlencode($token);

    $client = new LootLabsClient();
    $result = $client->createLockerLink($destinationUrl, 'RZO HUB ' . substr($token, 0, 8));

    $finalLink = null;
    $isDirectFallback = false;

    if ($result['success']) {
        $finalLink = $result['link'];
    } else {
        logAction($userId, 'LOOTLABS_API_ERROR', json_encode($result));
        $finalLink = $destinationUrl;
        $isDirectFallback = true;
    }

    $stmt = $pdo->prepare("INSERT INTO loot_sessions (user_id, token, lootlabs_link, expires_at) VALUES (:uid, :token, :link, :exp)");
    $stmt->execute([
        ':uid' => $userId,
        ':token' => $token,
        ':link' => $finalLink,
        ':exp' => $expiresAt
    ]);

    return [
        'success' => true,
        'link' => $finalLink,
        'token' => $token,
        'is_fallback' => $isDirectFallback,
        'api_response' => $result
    ];
}

/**
 * Atomically validates token and applies +24h time to the user license (Race Condition Free)
 */
function processKeyClaim(int $userId, string $token): array {
    $pdo = getDb();
    $now = date('Y-m-d H:i:s');

    // ATOMIC LOCK: Execute update in a single transaction-guaranteed statement
    // Row count will ONLY be 1 for the very first execution; all parallel race requests will return 0 rows affected
    $stmt = $pdo->prepare("
        UPDATE loot_sessions 
        SET is_completed = 1, completed_at = :now 
        WHERE token = :token 
          AND user_id = :uid 
          AND is_completed = 0 
          AND expires_at >= :now2
    ");
    $stmt->execute([
        ':now' => $now,
        ':now2' => $now,
        ':token' => $token,
        ':uid' => $userId
    ]);

    if ($stmt->rowCount() === 0) {
        return [
            'success' => false, 
            'message' => 'Claim token is invalid, expired, or has already been redeemed.'
        ];
    }

    // Now safely extend license
    $stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $license = $stmt->fetch();

    $extensionSeconds = KEY_HOURS_PER_CLAIM * 3600;

    if ($license) {
        $currentExpiry = strtotime($license['expires_at']);
        $baseTime = ($currentExpiry > time()) ? $currentExpiry : time();
        $newExpiry = date('Y-m-d H:i:s', $baseTime + $extensionSeconds);

        $up = $pdo->prepare("UPDATE licenses SET expires_at = :exp, updated_at = :now WHERE id = :id");
        $up->execute([':exp' => $newExpiry, ':now' => $now, ':id' => $license['id']]);
        $key = $license['license_key'];
    } else {
        $key = generateLicenseKey();
        $newExpiry = date('Y-m-d H:i:s', time() + $extensionSeconds);
        $in = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :key, :exp)");
        $in->execute([':uid' => $userId, ':key' => $key, ':exp' => $newExpiry]);
    }

    logAction($userId, 'LICENSE_EXTENDED', "Key: {$key} extended by " . KEY_HOURS_PER_CLAIM . " hours. Expiry: {$newExpiry}");
    syncDatabaseSnapshot();

    return [
        'success' => true,
        'license_key' => $key,
        'expires_at' => $newExpiry,
        'hours_added' => KEY_HOURS_PER_CLAIM
    ];
}

/**
 * Resets user's HWID
 */
function resetUserHwid(int $userId): array {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $license = $stmt->fetch();

    if (!$license) {
        return ['success' => false, 'message' => 'License record not found.'];
    }

    if (HWID_RESET_COOLDOWN_MINUTES > 0 && !empty($license['last_hwid_reset'])) {
        $lastReset = strtotime($license['last_hwid_reset']);
        $diff = time() - $lastReset;
        $cooldown = HWID_RESET_COOLDOWN_MINUTES * 60;
        if ($diff < $cooldown) {
            $waitMinutes = ceil(($cooldown - $diff) / 60);
            return [
                'success' => false,
                'message' => "HWID reset cooldown active. Please wait {$waitMinutes} minute(s)."
            ];
        }
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE licenses SET hwid = NULL, last_hwid_reset = :now WHERE id = :id");
    $stmt->execute([':now' => $now, ':id' => $license['id']]);

    logAction($userId, 'HWID_RESET', 'User reset HWID binding');
    syncDatabaseSnapshot();

    return ['success' => true, 'message' => 'HWID binding reset successfully.'];
}
