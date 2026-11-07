<?php
/**
 * RZO HUB - Key Verification Callback (LootLabs & Linkvertise)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';
require_once __DIR__ . '/../includes/linkvertise.php';

requireLogin();

$user = currentUser();
$token = trim($_GET['token'] ?? '');
$provider = strtolower(trim($_GET['provider'] ?? ''));

if (empty($token)) {
    setFlash('danger', 'Verification token was not provided.');
    header('Location: dashboard.php');
    exit;
}

$claimResult = processKeyClaim($user['id'], $token);

if ($claimResult['success']) {
    // If completed via Linkvertise, notify advertiser postback
    if ($provider === 'linkvertise' || !empty($_GET['click_id'])) {
        $clickId = !empty($_GET['click_id']) ? trim($_GET['click_id']) : $token;
        sendLinkvertisePostback($clickId, 0.0);
    }

    setFlash('success', 'Verification complete! Your license is now active for +24 Hours (Valid until: ' . $claimResult['expires_at'] . '). Your key is ready below!');
} else {
    setFlash('danger', 'Verification failed: ' . $claimResult['message']);
}

header('Location: dashboard.php');
exit;
