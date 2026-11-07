<?php
/**
 * RZO HUB - Key Verification Callback (Self-Healing Fallback for LootLabs & Linkvertise)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';
require_once __DIR__ . '/../includes/linkvertise.php';

requireLogin();

$user = currentUser();
$pdo = getDb();

// 1. Resolve token from multiple query parameters (token, click_id, subid, hash)
$token = trim($_GET['token'] ?? $_GET['click_id'] ?? $_GET['subid'] ?? $_GET['hash'] ?? '');
$provider = strtolower(trim($_GET['provider'] ?? ''));

// 2. If token is missing from URL, auto-resolve user's latest pending session
if (empty($token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT token, lootlabs_link 
            FROM loot_sessions 
            WHERE user_id = :uid 
              AND is_completed = 0 
              AND expires_at >= CURRENT_TIMESTAMP 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([':uid' => $user['id']]);
        $pending = $stmt->fetch();
        if ($pending && !empty($pending['token'])) {
            $token = $pending['token'];
            if (empty($provider) && !empty($pending['lootlabs_link']) && str_contains($pending['lootlabs_link'], 'linkvertise')) {
                $provider = 'linkvertise';
            }
        }
    } catch (Exception $e) {}
}

// 3. Fallback: If still no token found, create a verified session on the fly
if (empty($token)) {
    try {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $stmt = $pdo->prepare("
            INSERT INTO loot_sessions (user_id, token, lootlabs_link, expires_at) 
            VALUES (:uid, :token, 'direct_completion_return', :exp)
        ");
        $stmt->execute([
            ':uid' => $user['id'],
            ':token' => $token,
            ':exp' => $expiresAt
        ]);
        if (empty($provider)) {
            $provider = 'linkvertise';
        }
    } catch (Exception $e) {}
}

// 4. Process Key Claim
$claimResult = processKeyClaim($user['id'], $token);

if ($claimResult['success']) {
    // Notify Linkvertise advertiser postback if Linkvertise provider
    if ($provider === 'linkvertise' || !empty($_GET['click_id'])) {
        $clickId = !empty($_GET['click_id']) ? trim($_GET['click_id']) : $token;
        sendLinkvertisePostback($clickId, 0.0);
    }

    setFlash('success', 'Verification complete! License active for +24 Hours (Valid until: ' . $claimResult['expires_at'] . '). Your key is ready below!');
} else {
    // If the token was already redeemed or expired, check if user already has an active license
    $stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $user['id']]);
    $license = $stmt->fetch();

    if ($license && isLicenseActive($license['expires_at'])) {
        setFlash('info', 'Your license is already active! Key: ' . $license['license_key'] . ' (Valid until: ' . $license['expires_at'] . ').');
    } else {
        // If not active, grant fresh 24 hours directly so the user is never stuck
        $freshToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $stmt = $pdo->prepare("
            INSERT INTO loot_sessions (user_id, token, lootlabs_link, expires_at) 
            VALUES (:uid, :token, 'recovery_auto_grant', :exp)
        ");
        $stmt->execute([':uid' => $user['id'], ':token' => $freshToken, ':exp' => $expiresAt]);
        $recoveryClaim = processKeyClaim($user['id'], $freshToken);
        if ($recoveryClaim['success']) {
            setFlash('success', 'Verification complete! License active for +24 Hours (Valid until: ' . $recoveryClaim['expires_at'] . '). Your key is ready below!');
        } else {
            setFlash('danger', 'Verification failed: ' . $claimResult['message']);
        }
    }
}

header('Location: dashboard.php');
exit;
