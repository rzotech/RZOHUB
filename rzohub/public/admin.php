<?php
/**
 * RZO HUB - Administrator Console
 * High-performance management: license control, user administration, and system audit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lootlabs.php';

requireAdmin();

$adminUser = currentUser();
$pdo = getDb();

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'CSRF verification failed.');
        header('Location: admin.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    // Global Operations
    if ($action === 'migrate_all_data') {
        $result = migrateAllDatabase($pdo);
        logAction($adminUser['id'], 'ADMIN_MIGRATE_DATA', "Executed complete database integrity sync.");
        setFlash('success', "Database integrity sync completed. Users: {$result['stats']['users']} | Licenses: {$result['stats']['licenses']}.");
        header('Location: admin.php');
        exit;
    }

    if ($action === 'reset_all_keys') {
        $licenses = $pdo->query("SELECT id, user_id FROM licenses")->fetchAll();
        $count = 0;
        foreach ($licenses as $lic) {
            $newKey = generateLicenseKey();
            $up = $pdo->prepare("UPDATE licenses SET license_key = :k, hwid = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $up->execute([':k' => $newKey, ':id' => $lic['id']]);
            $count++;
        }
        logAction($adminUser['id'], 'ADMIN_RESET_ALL_KEYS', "Regenerated {$count} license keys globally.");
        setFlash('success', "Successfully regenerated {$count} license keys.");
        header('Location: admin.php');
        exit;
    }

    if ($action === 'reset_all_hwid') {
        $stmt = $pdo->prepare("UPDATE licenses SET hwid = NULL, last_hwid_reset = CURRENT_TIMESTAMP");
        $stmt->execute();
        $affected = $stmt->rowCount();
        logAction($adminUser['id'], 'ADMIN_RESET_ALL_HWID', "Reset HWID bindings globally for {$affected} licenses.");
        setFlash('success', "Successfully reset all HWID bindings.");
        header('Location: admin.php');
        exit;
    }

    if ($action === 'expire_all_keys') {
        $stmt = $pdo->prepare("UPDATE licenses SET expires_at = :exp, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([':exp' => date('Y-m-d H:i:s', time() - 60)]);
        logAction($adminUser['id'], 'ADMIN_EXPIRE_ALL_KEYS', "Expired all user licenses globally.");
        setFlash('success', "All licenses have been set to EXPIRED.");
        header('Location: admin.php');
        exit;
    }

    // Individual User Operations
    if ($targetUserId > 0) {
        switch ($action) {
            case 'edit_key':
                $customKey = trim($_POST['custom_key'] ?? '');
                if (empty($customKey)) {
                    setFlash('danger', 'License key string cannot be empty.');
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM licenses WHERE user_id = :uid LIMIT 1");
                    $stmt->execute([':uid' => $targetUserId]);
                    if ($stmt->fetch()) {
                        $up = $pdo->prepare("UPDATE licenses SET license_key = :k, hwid = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
                        $up->execute([':k' => $customKey, ':uid' => $targetUserId]);
                    } else {
                        $exp = date('Y-m-d H:i:s', time() + 86400);
                        $ins = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :k, :exp)");
                        $ins->execute([':uid' => $targetUserId, ':k' => $customKey, ':exp' => $exp]);
                    }
                    logAction($adminUser['id'], 'ADMIN_EDIT_KEY', "Updated license key for User #{$targetUserId} to: {$customKey}");
                    setFlash('success', "License key updated to '{$customKey}' for User #{$targetUserId}.");
                }
                break;

            case 'delete_user':
                if ($targetUserId === $adminUser['id']) {
                    setFlash('danger', 'You cannot delete your own administrator account.');
                } else {
                    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid")->execute([':uid' => $targetUserId]);
                    $pdo->prepare("DELETE FROM loot_sessions WHERE user_id = :uid")->execute([':uid' => $targetUserId]);
                    $pdo->prepare("DELETE FROM licenses WHERE user_id = :uid")->execute([':uid' => $targetUserId]);
                    $pdo->prepare("DELETE FROM logs WHERE user_id = :uid")->execute([':uid' => $targetUserId]);
                    $pdo->prepare("DELETE FROM users WHERE id = :uid")->execute([':uid' => $targetUserId]);

                    logAction($adminUser['id'], 'ADMIN_DELETE_USER', "Deleted user account ID: {$targetUserId}");
                    setFlash('success', "User #{$targetUserId} and all associated data have been permanently deleted.");
                }
                break;

            case 'reset_hwid':
                $stmt = $pdo->prepare("UPDATE licenses SET hwid = NULL, last_hwid_reset = CURRENT_TIMESTAMP WHERE user_id = :uid");
                $stmt->execute([':uid' => $targetUserId]);
                logAction($adminUser['id'], 'ADMIN_RESET_HWID', "Reset HWID for user ID: {$targetUserId}");
                setFlash('success', "HWID reset successfully for User #{$targetUserId}.");
                break;

            case 'add_time':
                $hours = (int)($_POST['hours'] ?? 24);
                $stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = :uid LIMIT 1");
                $stmt->execute([':uid' => $targetUserId]);
                $lic = $stmt->fetch();

                if ($lic) {
                    $curExp = strtotime($lic['expires_at']);
                    $base = ($curExp > time()) ? $curExp : time();
                    $newExp = date('Y-m-d H:i:s', $base + ($hours * 3600));
                    $update = $pdo->prepare("UPDATE licenses SET expires_at = :exp WHERE id = :id");
                    $update->execute([':exp' => $newExp, ':id' => $lic['id']]);
                } else {
                    $newExp = date('Y-m-d H:i:s', time() + ($hours * 3600));
                    $insert = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :key, :exp)");
                    $insert->execute([':uid' => $targetUserId, ':key' => generateLicenseKey(), ':exp' => $newExp]);
                }
                logAction($adminUser['id'], 'ADMIN_ADD_TIME', "Added {$hours} hours to User #{$targetUserId}");
                setFlash('success', "Successfully added {$hours} hours to User #{$targetUserId}.");
                break;

            case 'regen_key':
                $newKey = generateLicenseKey();
                $stmt = $pdo->prepare("UPDATE licenses SET license_key = :k, hwid = NULL WHERE user_id = :uid");
                $stmt->execute([':k' => $newKey, ':uid' => $targetUserId]);
                logAction($adminUser['id'], 'ADMIN_REGEN_KEY', "Regenerated key for User #{$targetUserId}: {$newKey}");
                setFlash('success', "New license key assigned to User #{$targetUserId}: {$newKey}");
                break;

            case 'delete_key':
                $stmt = $pdo->prepare("DELETE FROM licenses WHERE user_id = :uid");
                $stmt->execute([':uid' => $targetUserId]);
                logAction($adminUser['id'], 'ADMIN_DELETE_KEY', "Deleted license key for User #{$targetUserId}");
                setFlash('success', "License key for User #{$targetUserId} has been deleted.");
                break;

            case 'toggle_ban':
                if ($targetUserId === $adminUser['id']) {
                    setFlash('danger', 'You cannot suspend your own account.');
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET is_banned = CASE WHEN is_banned = 1 THEN 0 ELSE 1 END WHERE id = :uid");
                    $stmt->execute([':uid' => $targetUserId]);
                    logAction($adminUser['id'], 'ADMIN_TOGGLE_BAN', "Toggled suspension for User #{$targetUserId}");
                    setFlash('success', "Suspension status updated for User #{$targetUserId}.");
                }
                break;

            case 'toggle_role':
                if ($targetUserId === $adminUser['id']) {
                    setFlash('danger', 'You cannot change your own role.');
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET role = CASE WHEN role = 'admin' THEN 'user' ELSE 'admin' END WHERE id = :uid");
                    $stmt->execute([':uid' => $targetUserId]);
                    logAction($adminUser['id'], 'ADMIN_TOGGLE_ROLE', "Toggled role for User #{$targetUserId}");
                    setFlash('success', "Role updated for User #{$targetUserId}.");
                }
                break;
        }
    }

    syncDatabaseSnapshot();

    header('Location: admin.php');
    exit;
}

// Compute Statistics
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeLicenses = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE expires_at > CURRENT_TIMESTAMP")->fetchColumn();
$totalClaims = (int)$pdo->query("SELECT COUNT(*) FROM loot_sessions WHERE is_completed = 1")->fetchColumn();
$totalBanned = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn();

// Fetch Users and License Details
$query = <<<SQL
    SELECT 
        u.id, u.username, u.email, u.role, u.is_banned, u.created_at as user_created,
        l.license_key, l.hwid, l.expires_at, l.last_hwid_reset
    FROM users u
    LEFT JOIN licenses l ON u.id = l.user_id
    ORDER BY u.id DESC
SQL;
$usersList = $pdo->query($query)->fetchAll();

// Fetch System Logs
$logs = $pdo->query("SELECT l.*, u.username FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 15")->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel &mdash; RZO HUB</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        function editKeyPrompt(userId, currentKey) {
            var newKey = prompt("Enter new custom license key:", currentKey || "VBL-");
            if (newKey && newKey.trim() !== "") {
                document.getElementById('edit-key-user-id').value = userId;
                document.getElementById('edit-key-value').value = newKey.trim();
                document.getElementById('form-edit-key').submit();
            }
        }
    </script>
</head>
<body>
    <header class="navbar">
        <a href="admin.php" class="nav-brand">RZO HUB</a>

        <div class="nav-links">
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="<?= htmlspecialchars(DISCORD_INVITE_URL) ?>" target="_blank" rel="noopener" class="nav-link">Discord</a>
            <span class="nav-user"><?= htmlspecialchars($adminUser['username']) ?> (Admin)</span>
            <a href="logout.php" class="nav-link">Sign Out</a>
        </div>
    </header>

    <div class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Hidden Form for Editing Keys -->
        <form id="form-edit-key" method="POST" action="admin.php" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_key">
            <input type="hidden" name="user_id" id="edit-key-user-id" value="">
            <input type="hidden" name="custom_key" id="edit-key-value" value="">
        </form>

        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
            <div>
                <h1 class="page-title">Admin Management Console</h1>
                <p class="page-subtitle">Manage users, custom license keys, hardware bindings, and system logs.</p>
            </div>

            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <!-- Sync Integrity -->
                <form method="POST" action="admin.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="migrate_all_data">
                    <button type="submit" class="btn btn-outline btn-sm">Sync DB</button>
                </form>

                <!-- Reset All Keys -->
                <form method="POST" action="admin.php" onsubmit="return confirm('Regenerate all license keys globally? All devices will need to re-verify.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_all_keys">
                    <button type="submit" class="btn btn-outline btn-sm">Reset All Keys</button>
                </form>

                <!-- Reset All HWIDs -->
                <form method="POST" action="admin.php" onsubmit="return confirm('Unbind hardware IDs for all users?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_all_hwid">
                    <button type="submit" class="btn btn-outline btn-sm">Reset All HWIDs</button>
                </form>

                <!-- Expire All Keys -->
                <form method="POST" action="admin.php" onsubmit="return confirm('Immediately set all active licenses to expired?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="expire_all_keys">
                    <button type="submit" class="btn btn-danger btn-sm">Expire All</button>
                </form>
            </div>
        </div>

        <!-- Metrics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-sub">Registered accounts</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Active Licenses</div>
                <div class="stat-value"><?= $activeLicenses ?></div>
                <div class="stat-sub">Valid keys</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Completed Tasks</div>
                <div class="stat-value"><?= $totalClaims ?></div>
                <div class="stat-sub">Key claim sessions</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Banned Users</div>
                <div class="stat-value"><?= $totalBanned ?></div>
                <div class="stat-sub">Suspended accounts</div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">User Accounts &amp; License Inventory</span>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role &amp; Status</th>
                            <th>License Key</th>
                            <th>Hardware ID</th>
                            <th>Expiration</th>
                            <th>Management Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usersList)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 28px;">
                                    No users found in database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usersList as $row): ?>
                                <?php 
                                    $isLicActive = isLicenseActive($row['expires_at']);
                                    $timeData = formatRemainingTime($row['expires_at']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['username']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= htmlspecialchars($row['email']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $row['role'] === 'admin' ? 'badge-admin' : 'badge-none' ?>">
                                            <?= htmlspecialchars($row['role']) ?>
                                        </span>
                                        <?php if ($row['is_banned']): ?>
                                            <span class="badge badge-expired">BANNED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="code-cell">
                                        <?= htmlspecialchars($row['license_key'] ?? 'NO_KEY') ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['hwid'])): ?>
                                            <span class="code-cell" title="<?= htmlspecialchars($row['hwid']) ?>">
                                                <?= htmlspecialchars(substr($row['hwid'], 0, 10)) ?>...
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.75rem;">UNBOUND</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isLicActive): ?>
                                            <span class="badge badge-active"><?= htmlspecialchars($timeData['text']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-expired">EXPIRED</span>
                                        <?php endif; ?>
                                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 2px;">
                                            <?= htmlspecialchars($row['expires_at'] ?? '-') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <!-- Add 24h -->
                                            <form method="POST" action="admin.php" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="add_time">
                                                <input type="hidden" name="hours" value="24">
                                                <button type="submit" class="btn btn-outline btn-sm">+24h</button>
                                            </form>

                                            <!-- Edit Key (Custom string) -->
                                            <button type="button" class="btn btn-outline btn-sm" onclick="editKeyPrompt(<?= $row['id'] ?>, '<?= htmlspecialchars($row['license_key'] ?? '') ?>')">
                                                Edit Key
                                            </button>

                                            <!-- Reset HWID -->
                                            <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('Reset HWID for <?= htmlspecialchars($row['username']) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="reset_hwid">
                                                <button type="submit" class="btn btn-outline btn-sm">Reset HWID</button>
                                            </form>

                                            <!-- Regenerate Key -->
                                            <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('Regenerate random key for <?= htmlspecialchars($row['username']) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="regen_key">
                                                <button type="submit" class="btn btn-outline btn-sm">Regen</button>
                                            </form>

                                            <!-- Delete Key -->
                                            <?php if (!empty($row['license_key'])): ?>
                                                <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('Delete license key for <?= htmlspecialchars($row['username']) ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="action" value="delete_key">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete Key</button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Ban / Unban -->
                                            <?php if ($row['id'] !== $adminUser['id']): ?>
                                                <form method="POST" action="admin.php" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="action" value="toggle_ban">
                                                    <button type="submit" class="btn btn-outline btn-sm">
                                                        <?= $row['is_banned'] ? 'Unban' : 'Ban' ?>
                                                    </button>
                                                </form>

                                                <!-- Delete User Entirely -->
                                                <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('DANGER: Delete user account <?= htmlspecialchars($row['username']) ?>? This will delete all user data and require them to register again.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete User</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Activity Audit Log -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Activity Audit Trail</span>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    No activity logs recorded.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="font-family: var(--font-mono); font-size: 0.75rem;"><?= htmlspecialchars($log['created_at']) ?></td>
                                    <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                                    <td><span class="badge badge-none"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td style="font-size: 0.8rem; word-break: break-all;"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                    <td class="code-cell"><?= htmlspecialchars($log['ip_address'] ?? '0.0.0.0') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="footer">
        &copy; <?= date('Y') ?> RZO HUB &mdash; Administrator Console
    </footer>
    <?php renderSpeedInsights(); ?>
</body>
</html>
