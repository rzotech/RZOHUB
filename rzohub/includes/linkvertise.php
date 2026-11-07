<?php
/**
 * RZO HUB - Linkvertise Monetization Engine
 * Dynamic publisher link generation for automated key claim sessions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lootlabs.php';

/**
 * Creates a unique claim session and wraps it in a Linkvertise dynamic link
 */
function createLinkvertiseKeySession(int $userId): array {
    $pdo = getDb();

    // Expire old unused sessions
    $stmt = $pdo->prepare("UPDATE loot_sessions SET is_completed = 2 WHERE user_id = :uid AND is_completed = 0 AND expires_at < CURRENT_TIMESTAMP");
    $stmt->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes validity

    $destinationUrl = getBaseUrl() . '/claim_key.php?token=' . urlencode($token);

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

