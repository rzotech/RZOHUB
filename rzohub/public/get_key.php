<?php
/**
 * RZO HUB - Multi-Provider Monetization Dispatcher (LootLabs & Linkvertise)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';
require_once __DIR__ . '/../includes/linkvertise.php';

requireLogin();

$user = currentUser();
$pdo = getDb();

// Cooldown & Active License Lock: Prevent quest spam while key is active
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $user['id']]);
$license = $stmt->fetch();

if ($license && isLicenseActive($license['expires_at'])) {
    $timeInfo = formatRemainingTime($license['expires_at']);
    setFlash('info', 'Your license is currently active. You can request a renewal once your current time expires (Remaining: ' . $timeInfo['text'] . ').');
    header('Location: dashboard.php');
    exit;
}

$provider = strtolower(trim($_GET['provider'] ?? ''));
if (!in_array($provider, ['lootlabs', 'linkvertise'], true)) {
    $provider = defined('DEFAULT_KEY_PROVIDER') ? DEFAULT_KEY_PROVIDER : 'lootlabs';
}

if ($provider === 'linkvertise') {
    $claimSession = createLinkvertiseKeySession($user['id']);
} else {
    $claimSession = createKeyClaimSession($user['id']);
}

if ($claimSession['success']) {
    $redirectLink = $claimSession['link'];

    // Security: Validate Redirect Target (Mitigates Open Redirect)
    $parsed = parse_url($redirectLink);
    $host = strtolower($parsed['host'] ?? '');
    
    $allowedHosts = [
        'loot-link.com',
        'lootdest.org',
        'lootlabs.gg',
        'creators.lootlabs.gg',
        'links.lootlabs.gg',
        'linkvertise.com',
        'linkvertise.net',
        'link-to.net',
        'up-to-down.net',
        'direct-link.net',
        'file-link.net',
        parse_url(getBaseUrl(), PHP_URL_HOST)
    ];

    $isAllowed = false;
    foreach ($allowedHosts as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $isAllowed = true;
            break;
        }
    }

    if (!$isAllowed) {
        logAction($user['id'], 'SECURITY_SUSPICIOUS_REDIRECT', "Untrusted host: {$host}");
        setFlash('danger', 'Untrusted redirect destination detected.');
        header('Location: dashboard.php');
        exit;
    }

    header('Location: ' . $redirectLink);
    exit;
} else {
    setFlash('danger', 'Failed to initialize session: ' . ($claimSession['error'] ?? 'Unknown error'));
    header('Location: dashboard.php');
    exit;
}
