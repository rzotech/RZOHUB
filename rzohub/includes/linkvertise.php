<?php
/**
 * RZO HUB - Linkvertise Monetization Engine
 * Integration for Linkvertise Dynamic Links, Publisher API, and Advertiser Postback.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lootlabs.php';

/**
 * Creates a unique claim session and wraps it in a Linkvertise dynamic monetized link
 */
function createLinkvertiseKeySession(int $userId): array {
    $pdo = getDb();

    // Expire old unused sessions
    $stmt = $pdo->prepare("UPDATE loot_sessions SET is_completed = 2 WHERE user_id = :uid AND is_completed = 0 AND expires_at < CURRENT_TIMESTAMP");
    $stmt->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes validity

    // Destination target when user completes Linkvertise tasks
    $destinationUrl = getBaseUrl() . '/claim_key.php?provider=linkvertise&token=' . urlencode($token);

    $memberId = defined('LINKVERTISE_MEMBER_ID') && !empty(LINKVERTISE_MEMBER_ID) 
        ? LINKVERTISE_MEMBER_ID 
        : '6748593';

    // Linkvertise dynamic publisher link (base64-encoded destination)
    $encodedTarget = base64_encode($destinationUrl);
    $finalLink = "https://linkvertise.com/{$memberId}/dynamic?r=" . urlencode($encodedTarget);

    $stmt = $pdo->prepare("INSERT INTO loot_sessions (user_id, token, lootlabs_link, expires_at) VALUES (:uid, :token, :link, :exp)");
    $stmt->execute([
        ':uid' => $userId,
        ':token' => $token,
        ':link' => $finalLink,
        ':exp' => $expiresAt
    ]);

    logAction($userId, 'LINKVERTISE_SESSION_CREATED', "Token: " . substr($token, 0, 8) . "... | Member: {$memberId}");

    return [
        'success' => true,
        'link' => $finalLink,
        'token' => $token,
        'provider' => 'linkvertise'
    ];
}

/**
 * Fires the Linkvertise Advertiser Postback to log conversion on the advertiser network
 * URL: https://api-advertiser.linkvertise.com/postback?token=${TOKEN}&click_id=${CLICK_ID}&payout=${PAYOUT}
 */
function sendLinkvertisePostback(string $clickId, float $payout = 0.0): bool {
    $postbackToken = defined('LINKVERTISE_POSTBACK_TOKEN') ? LINKVERTISE_POSTBACK_TOKEN : '';
    if (empty($postbackToken)) {
        return false;
    }

    $template = defined('LINKVERTISE_POSTBACK_URL') 
        ? LINKVERTISE_POSTBACK_URL 
        : 'https://api-advertiser.linkvertise.com/postback?token=${TOKEN}&click_id=${CLICK_ID}&payout=${PAYOUT}';

    $targetUrl = str_replace(
        ['${TOKEN}', '${CLICK_ID}', '${PAYOUT}', '{TOKEN}', '{CLICK_ID}', '{PAYOUT}'],
        [urlencode($postbackToken), urlencode($clickId), number_format($payout, 4, '.', '')],
        $template
    );

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 400);
    try {
        logAction(null, 'LINKVERTISE_POSTBACK_FIRED', "Target: {$targetUrl} | Status: {$httpCode} | Response: " . substr((string)$response, 0, 100));
    } catch (Exception $e) {}

    return $success;
}
