<?php
/**
 * RZO HUB - Key Verification Callback (Strict Anti-Bypass Enforced)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';
require_once __DIR__ . '/../includes/linkvertise.php';

requireLogin();

$user = currentUser();
$pdo = getDb();

// 1. Capture parameters
$hash = trim($_GET['hash'] ?? '');
$token = trim($_GET['token'] ?? $_GET['click_id'] ?? $_GET['subid'] ?? '');
$provider = strtolower(trim($_GET['provider'] ?? ''));

// ANTI-BYPASS ENFORCEMENT: Reject any direct access without proof of completion
if (empty($hash) && empty($token)) {
    logAction($user['id'], 'SECURITY_BYPASS_ATTEMPT_BLOCKED', 'Direct URL navigation without verification hash or token');
    setFlash('danger', 'Access denied. You must complete the task checkpoint to obtain a license key.');
    header('Location: dashboard.php');
    exit;
}

// Verification Branch A: Linkvertise Anti-Bypassing Hash Check
if (!empty($hash)) {
    $isLegit = verifyLinkvertiseHash($hash);
    if (!$isLegit) {
        logAction($user['id'], 'SECURITY_LINKVERTISE_HASH_FAILED', "Spoofed or expired hash: {$hash}");
        setFlash('danger', 'Anti-Bypass verification failed. Invalid or expired Linkvertise completion proof.');
        header('Location: dashboard.php');
        exit;
    }

    // Hash is verified by Linkvertise server! Find user's active session or fulfill claim
    $stmt = $pdo->prepare("
        SELECT token FROM loot_sessions 
        WHERE user_id = :uid 
          AND is_completed = 0 
          AND expires_at >= CURRENT_TIMESTAMP 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user['id']]);
    $pending = $stmt->fetch();
    $claimToken = ($pending && !empty($pending['token'])) ? $pending['token'] : null;

    if (!$claimToken) {
        // If user completed through direct link with verified hash, register session
        $claimToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $in = $pdo->prepare("INSERT INTO loot_sessions (user_id, token, lootlabs_link, expires_at) VALUES (:uid, :token, 'linkvertise_verified_hash', :exp)");
        $in->execute([':uid' => $user['id'], ':token' => $claimToken, ':exp' => $expiresAt]);
    }

    $claimResult = processKeyClaim($user['id'], $claimToken);
    if ($claimResult['success']) {
        sendLinkvertisePostback($claimToken, 0.0);
        setFlash('success', 'Verification complete! License active for +24 Hours (Valid until: ' . $claimResult['expires_at'] . '). Your key is ready below!');
    } else {
        setFlash('danger', 'Verification error: ' . $claimResult['message']);
    }

    header('Location: dashboard.php');
    exit;
}

// Verification Branch B: Cryptographic Session Token Verification (LootLabs / Dynamic Linkvertise Session)
if (!empty($token)) {
    // Atomic check in database: Token MUST exist, belong to this user, be uncompleted, and not expired
    $claimResult = processKeyClaim($user['id'], $token);

    if ($claimResult['success']) {
        if ($provider === 'linkvertise') {
            sendLinkvertisePostback($token, 0.0);
        }
        setFlash('success', 'Verification complete! License active for +24 Hours (Valid until: ' . $claimResult['expires_at'] . '). Your key is ready below!');
    } else {
        logAction($user['id'], 'SECURITY_INVALID_TOKEN_SUBMISSION', "Token: {$token} | Message: " . $claimResult['message']);
        setFlash('danger', 'Verification failed: ' . $claimResult['message']);
    }

    header('Location: dashboard.php');
    exit;
}
