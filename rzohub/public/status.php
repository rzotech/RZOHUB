<?php
/**
 * RZO HUB - System Diagnostic & Database Health Check
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$diag = getDbDiagnostics();
$isPg = ($diag['active_driver'] === 'pgsql');
$isSqlite = ($diag['active_driver'] === 'sqlite');

// Query test
$dbWorks = false;
$userCount = 0;
$licenseCount = 0;
try {
    $pdo = getDb();
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $licenseCount = (int)$pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
    $dbWorks = true;
} catch (Throwable $e) {}

$isJson = isset($_GET['json']);
if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $dbWorks ? 'healthy' : 'degraded',
        'database_mode' => $diag['active_driver'],
        'supabase_connected' => $isPg,
        'users_count' => $userCount,
        'licenses_count' => $licenseCount,
        'last_error' => $diag['last_error'],
        'ipv6_warning' => $diag['is_direct_supabase_ipv6']
    ], JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health &mdash; RZO HUB</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .badge-success { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .row-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--glass-border); }
        .row-item:last-child { border-bottom: none; }
        pre.err-box { background: rgba(0,0,0,0.5); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 0.85rem; overflow-x: auto; white-space: pre-wrap; }
        .tip-box { background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; padding: 14px; margin-top: 16px; border-radius: 0 6px 6px 0; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="auth-container" style="max-width: 640px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <a href="login.php" class="nav-brand" style="font-size: 1.6rem; letter-spacing: 0.18em;">RZO HUB</a>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 8px;">System Health & Infrastructure Diagnostics</p>
        </div>

        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <span class="card-title">Diagnostics Overview</span>
                <?php if ($dbWorks): ?>
                    <span class="badge badge-success">Operational</span>
                <?php else: ?>
                    <span class="badge badge-danger">Degraded</span>
                <?php endif; ?>
            </div>

            <div class="row-item">
                <span style="color: var(--text-secondary);">Active Database Engine</span>
                <?php if ($isPg): ?>
                    <span class="badge badge-success">Supabase Cloud PostgreSQL</span>
                <?php elseif ($isSqlite): ?>
                    <span class="badge badge-warning">Serverless SQLite Fallback</span>
                <?php else: ?>
                    <span class="badge badge-danger">Unavailable</span>
                <?php endif; ?>
            </div>

            <div class="row-item">
                <span style="color: var(--text-secondary);">DATABASE_URL Status</span>
                <?php if ($diag['database_url_configured']): ?>
                    <span style="color: #4ade80; font-size: 0.9rem;">Configured (Host: <?= htmlspecialchars($diag['target_host']) ?>)</span>
                <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 0.9rem;">Not Set in Vercel</span>
                <?php endif; ?>
            </div>

            <div class="row-item">
                <span style="color: var(--text-secondary);">Registered Users in DB</span>
                <span style="font-weight: 600;"><?= $userCount ?></span>
            </div>

            <div class="row-item">
                <span style="color: var(--text-secondary);">Registered Licenses in DB</span>
                <span style="font-weight: 600;"><?= $licenseCount ?></span>
            </div>

            <div class="row-item">
                <span style="color: var(--text-secondary);">Available PHP PDO Drivers</span>
                <span style="font-family: monospace; font-size: 0.85rem;"><?= implode(', ', $diag['available_pdo_drivers']) ?></span>
            </div>

            <?php if (!empty($diag['last_error'])): ?>
                <div style="margin-top: 20px;">
                    <div style="color: #f87171; font-weight: 600; font-size: 0.9rem; margin-bottom: 8px;">
                        Supabase Connection Diagnostic Log:
                    </div>
                    <pre class="err-box"><?= htmlspecialchars((string)$diag['last_error']) ?></pre>
                </div>
            <?php endif; ?>

            <?php if ($diag['is_direct_supabase_ipv6']): ?>
                <div class="tip-box" style="border-left-color: #f59e0b; background: rgba(245, 158, 11, 0.1);">
                    <strong style="color: #fbbf24;">Action Required for Supabase on Vercel:</strong><br>
                    You are currently pointing to Supabase's direct endpoint (<code><?= htmlspecialchars($diag['target_host']) ?></code>).
                    Vercel serverless functions are <strong>IPv4-only</strong> and cannot reach direct Supabase over IPv6.<br><br>
                    <strong>Fix:</strong> In your <strong>Vercel Settings &rarr; Environment Variables</strong>, change <code>DATABASE_URL</code> to the <strong>Connection Pooler (Supavisor)</strong> URL from Supabase:
                    <div style="background: rgba(0,0,0,0.4); padding: 8px; border-radius: 4px; margin-top: 6px; font-family: monospace; word-break: break-all;">
                        postgresql://postgres.[PROJECT-REF]:[PASSWORD]@aws-0-[REGION].pooler.supabase.com:6543/postgres
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 24px; text-align: center;">
                <a href="login.php" class="btn btn-primary" style="display: inline-block;">Go to Sign In Portal &rarr;</a>
            </div>
        </div>
    </div>
</body>
</html>

