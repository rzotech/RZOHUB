<?php
/**
 * RZO HUB - Login Portal (Email & Google Sign-In with Persistent Remember Me)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF security token mismatch. Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = !empty($_POST['remember_me']);

        $result = loginUser($identifier, $password, $rememberMe);
        if ($result['success']) {
            if ($result['role'] === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$flash = getFlash();
$callbackUrl = getBaseUrl() . '/google_callback.php';
$hasGoogleConfig = (GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In &mdash; RZO HUB</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php require_once __DIR__ . '/../includes/speed_insights.php'; ?>
    <?php if ($hasGoogleConfig): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
</head>
<body>
    <div class="auth-container">
        <div style="text-align: center; margin-bottom: 32px;">
            <a href="login.php" class="nav-brand" style="font-size: 1.6rem; letter-spacing: 0.18em;">RZO HUB</a>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 8px;">Access Portal & License Authentication</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Sign In</span>
            </div>

            <!-- Google Authentication Section -->
            <div style="margin-bottom: 20px;">
                <?php if ($hasGoogleConfig): ?>
                    <div id="g_id_onload"
                         data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID) ?>"
                         data-login_uri="<?= htmlspecialchars($callbackUrl) ?>"
                         data-auto_prompt="false">
                    </div>
                    <div class="g_id_signin"
                         data-type="standard"
                         data-size="large"
                         data-theme="outline"
                         data-text="continue_with"
                         data-shape="rectangular"
                         data-logo_alignment="left"
                         data-width="100%">
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-block" onclick="alert('To activate 1-Click Google Sign-In, enter your Google OAuth Client ID in config/config.php');" style="display: flex; justify-content: center; gap: 10px;">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#ffffff" d="M21.35 11.1h-9.17v2.98h5.27c-.23 1.25-.94 2.31-1.99 3.02v2.51h3.22c1.89-1.74 2.97-4.3 2.97-7.39c0-.71-.07-1.4-.2-2.02z"/><path fill="#ffffff" d="M12.18 21c2.7 0 4.96-.9 6.62-2.43l-3.22-2.51c-.9.6-2.04.96-3.4.96c-2.61 0-4.83-1.76-5.62-4.14H3.21v2.58C4.86 18.73 8.27 21 12.18 21z"/><path fill="#ffffff" d="M6.56 12.88c-.2-.6-.31-1.25-.31-1.88s.11-1.28.31-1.88V6.54H3.21C2.55 7.85 2.18 9.38 2.18 11s.37 3.15 1.03 4.46l3.35-2.58z"/><path fill="#ffffff" d="M12.18 4.96c1.47 0 2.78.51 3.82 1.5l2.86-2.86C17.13 2.05 14.88 1.18 12.18 1.18c-3.91 0-7.32 2.27-8.97 5.56l3.35 2.58c.79-2.38 3.01-4.14 5.62-4.14z"/></svg>
                        <span>Continue with Google</span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Divider -->
            <div style="display: flex; align-items: center; margin: 20px 0; gap: 12px;">
                <div style="flex: 1; height: 1px; background: var(--glass-border);"></div>
                <span style="font-family: var(--font-mono); font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em;">or email</span>
                <div style="flex: 1; height: 1px; background: var(--glass-border);"></div>
            </div>

            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

                <div class="form-group">
                    <label for="identifier">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" class="form-input" required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input" required autocomplete="current-password">
                </div>

                <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; margin-top: -4px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; color: var(--text-secondary); user-select: none;">
                        <input type="checkbox" name="remember_me" value="1" checked style="width: 16px; height: 16px; accent-color: #ffffff; cursor: pointer;">
                        <span>Remember Me</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px;">Sign In</button>
            </form>
        </div>

        <div style="text-align: center; font-size: 0.85rem; color: var(--text-secondary);">
            Don't have an account? <a href="register.php" style="color: var(--text-primary); font-weight: 600;">Create Account</a>
        </div>
    </div>
</body>
</html>
