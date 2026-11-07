<?php
/**
 * RZO HUB - LootLabs Destination Callback
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';

requireLogin();

$user = currentUser();
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    setFlash('danger', 'LootLabs verification token was not provided.');
    header('Location: dashboard.php');
    exit;
}

$claimResult = processKeyClaim($user['id'], $token);

if ($claimResult['success']) {
    setFlash('success', 'Verification successful! License renewed for +24 Hours (Valid until: ' . $claimResult['expires_at'] . ').');
} else {
    setFlash('danger', 'Verification failed: ' . $claimResult['message']);
}

header('Location: dashboard.php');
exit;
