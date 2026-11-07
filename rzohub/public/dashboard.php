<?php
/**
 * RZO HUB - VBL License Dashboard
 * Lightweight, high-contrast monochrome interface.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';

requireLogin();

$user = currentUser();
$pdo = getDb();

// Handle HWID reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_hwid') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Security token mismatch. Request rejected.');
    } else {
        $result = resetUserHwid($user['id']);
        if ($result['success']) {
            setFlash('success', $result['message']);
        } else {
            setFlash('danger', $result['message']);
        }
    }
    header('Location: dashboard.php');
    exit;
}

// Fetch user license
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $user['id']]);
$license = $stmt->fetch();

$isActive = $license ? isLicenseActive($license['expires_at']) : false;
$timeInfo = $license ? formatRemainingTime($license['expires_at']) : ['status' => 'none', 'text' => 'No Active License', 'seconds' => 0];

// Fetch recent loot claim history
$stmt = $pdo->prepare("SELECT * FROM loot_sessions WHERE user_id = :uid ORDER BY id DESC LIMIT 5");
$stmt->execute([':uid' => $user['id']]);
$recentClaims = $stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Portal &mdash; RZO HUB</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="navbar">
        <a href="dashboard.php" class="nav-brand">RZO HUB</a>

        <div class="nav-links">
            <a href="<?= htmlspecialchars(DISCORD_INVITE_URL) ?>" target="_blank" rel="noopener" class="nav-link">Discord</a>
            <?php if (isAdmin()): ?>
                <a href="admin.php" class="nav-link" style="color: #ffffff; font-weight: 700;">Admin Panel</a>
            <?php endif; ?>
            <span class="nav-user"><?= htmlspecialchars($user['username']) ?></span>
            <a href="logout.php" class="nav-link">Sign Out</a>
        </div>
    </header>

    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title">Volleyball Legends License Portal</h1>
                <p class="page-subtitle">Personal access key, hardware binding, and daily 24-hour verification.</p>
            </div>
            <div>
                <a href="<?= htmlspecialchars(DISCORD_INVITE_URL) ?>" target="_blank" rel="noopener" class="btn btn-outline">
                    <span>Join Community</span>
                </a>
            </div>
        </div>

        <!-- License Card -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">VBL License Key</span>
                <div>
                    <?php if ($isActive): ?>
                        <span class="badge badge-active">ACTIVE</span>
                    <?php else: ?>
                        <span class="badge badge-expired">NO ACTIVE KEY</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="key-box">
                <div>
                    <span style="display: block; font-size: 0.72rem; font-family: var(--font-mono); color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.08em;">KEY STRING</span>
                    <?php if ($isActive && !empty($license['license_key'])): ?>
                        <span id="license-key-text" class="key-text">
                            <?= htmlspecialchars($license['license_key']) ?>
                        </span>
                    <?php else: ?>
                        <span id="license-key-text" class="key-text inactive" style="font-size: 0.92rem; letter-spacing: 0.04em; color: var(--text-muted);">
                            NO ACTIVE KEY &bull; COMPLETE GET KEY BELOW
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($isActive): ?>
                        <button type="button" id="btn-copy-key" class="btn btn-outline btn-sm">Copy Key</button>
                    <?php else: ?>
                        <button type="button" id="btn-copy-key" class="btn btn-outline btn-sm" disabled style="opacity: 0.45; cursor: not-allowed;">Copy Key</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Remaining Time</div>
                    <div class="stat-value" id="live-countdown" data-seconds="<?= $isActive ? (int)($timeInfo['seconds'] ?? 0) : 0 ?>">
                        <?= $isActive ? htmlspecialchars($timeInfo['text']) : '00h 00m 00s (Inactive)' ?>
                    </div>
                    <div class="stat-sub">
                        <?= ($isActive && !empty($license['expires_at'])) ? 'Expires: ' . htmlspecialchars($license['expires_at']) : 'Complete task below to activate +24 Hours' ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Hardware ID (HWID Bind)</div>
                    <div class="stat-value" style="font-size: 0.95rem; word-break: break-all;">
                        <?= (!empty($license['hwid']) && $isActive) ? htmlspecialchars(substr($license['hwid'], 0, 16) . '...') : '<span style="color: var(--text-muted)">UNBOUND (READY)</span>' ?>
                    </div>
                    <div class="stat-sub">
                        <?= (!empty($license['last_hwid_reset']) && $isActive) ? 'Last reset: ' . htmlspecialchars($license['last_hwid_reset']) : 'Reset available when active' ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Cycle Duration</div>
                    <div class="stat-value">+24 Hours</div>
                    <div class="stat-sub">Validity added per task completion</div>
                </div>
            </div>

            <!-- Action Controls -->
            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; align-items: center;">
                <?php if ($isActive): ?>
                    <button type="button" class="btn btn-locked" id="btn-get-key" disabled title="License is active. Renewal will open when expired.">
                        <span id="btn-get-key-text">License Active &bull; Locked</span>
                    </button>

                    <div style="font-size: 0.82rem; color: var(--text-secondary);">
                        Next renewal available in:
                        <span id="cooldown-timer" style="font-family: var(--font-mono); font-weight: 700; color: #ffffff;" data-seconds="<?= (int)($timeInfo['seconds'] ?? 0) ?>">
                            <?= htmlspecialchars($timeInfo['text']) ?>
                        </span>
                    </div>

                    <form method="POST" action="dashboard.php" onsubmit="return confirm('Are you sure you want to reset your hardware ID?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action" value="reset_hwid">
                        <button type="submit" class="btn btn-outline">
                            Reset HWID
                        </button>
                    </form>
                <?php else: ?>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; width: 100%;">
                        <a href="get_key.php?provider=linkvertise" id="btn-get-key-lv" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                            <span>Get Key (Linkvertise)</span>
                        </a>
                        <a href="get_key.php?provider=lootlabs" id="btn-get-key-loot" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <span>Get Key (LootLabs)</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Task Instructions -->
            <div class="guide-box">
                <div class="guide-title">How to Activate Your License:</div>
                <div class="guide-step">
                    <strong>1. Choose Provider:</strong> Select either <em>Linkvertise</em> or <em>LootLabs</em> above to begin your verification session.
                </div>
                <div class="guide-step">
                    <strong>2. Complete Required Tasks:</strong> Follow the verification steps on the provider page until you reach the completion redirect.
                </div>
                <div class="guide-step">
                    <strong>3. Automatic Activation:</strong> Once all steps are complete and you return to RZO HUB, your 24-hour key will be generated and activated immediately.
                </div>
            </div>
        </div>

        <!-- Claim History Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Verification History</span>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Session Token</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentClaims)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    No verification sessions recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentClaims as $claim): ?>
                                <tr>
                                    <td class="code-cell"><?= htmlspecialchars(substr($claim['token'], 0, 16)) ?>...</td>
                                    <td>
                                        <?php if ($claim['is_completed'] == 1): ?>
                                            <span class="badge badge-active">VERIFIED (+24H)</span>
                                        <?php elseif (strtotime($claim['expires_at']) < time()): ?>
                                            <span class="badge badge-expired">EXPIRED</span>
                                        <?php else: ?>
                                            <span class="badge badge-none">PENDING</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($claim['created_at']) ?></td>
                                    <td><?= htmlspecialchars($claim['completed_at'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="footer">
        &copy; <?= date('Y') ?> RZO HUB &mdash; All Rights Reserved.
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>
