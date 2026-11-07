<?php
/**
 * RZO HUB - Registration Portal (Email & Google 1-Click Register)
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
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $result = registerUser($username, $email, $password);
            if ($result['success']) {
                loginUser($username, $password);
                $welcomeMsg = ($result['role'] === 'admin')
                    ? 'Account created successfully! As the first user, you have been designated Administrator.'
                    : 'Account created successfully! Welcome to RZO HUB.';
                setFlash('success', $welcomeMsg);
                header('Location: ' . ($result['role'] === 'admin' ? 'admin.php' : 'dashboard.php'));
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}

$callbackUrl = getBaseUrl() . '/google_callback.php';
$hasGoogleConfig = (GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register &mdash; RZO HUB</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php require_once __DIR__ . '/../includes/speed_insights.php'; ?>
    <?php if ($hasGoogleConfig): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
</head>
<body>
    <div class="auth-container">
        <div style="text-align: center; margin-bottom: 32px;">
            <a href="register.php" class="nav-brand" style="font-size: 1.6rem; letter-spacing: 0.18em;">RZO HUB</a>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 8px;">Create a New Account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Register</span>
            </div>

            <!-- 1-Click Fast Google Sign-Up -->
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
                         data-text="signup_with"
                         data-shape="rectangular"
                         data-logo_alignment="left"
                         data-width="100%">
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-block" onclick="alert('To activate 1-Click Google Sign-Up, enter your Google OAuth Client ID in config/config.php');" style="display: flex; justify-content: center; gap: 10px;">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#ffffff" d="M21.35 11.1h-9.17v2.98h5.27c-.23 1.25-.94 2.31-1.99 3.02v2.51h3.22c1.89-1.74 2.97-4.3 2.97-7.39c0-.71-.07-1.4-.2-2.02z"/><path fill="#ffffff" d="M12.18 21c2.7 0 4.96-.9 6.62-2.43l-3.22-2.51c-.9.6-2.04.96-3.4.96c-2.61 0-4.83-1.76-5.62-4.14H3.21v2.58C4.86 18.73 8.27 21 12.18 21z"/><path fill="#ffffff" d="M6.56 12.88c-.2-.6-.31-1.25-.31-1.88s.11-1.28.31-1.88V6.54H3.21C2.55 7.85 2.18 9.38 2.18 11s.37 3.15 1.03 4.46l3.35-2.58z"/><path fill="#ffffff" d="M12.18 4.96c1.47 0 2.78.51 3.82 1.5l2.86-2.86C17.13 2.05 14.88 1.18 12.18 1.18c-3.91 0-7.32 2.27-8.97 5.56l3.35 2.58c.79-2.38 3.01-4.14 5.62-4.14z"/></svg>
                        <span>Sign up with Google</span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Divider -->
            <div style="display: flex; align-items: center; margin: 20px 0; gap: 12px;">
                <div style="flex: 1; height: 1px; background: var(--glass-border);"></div>
                <span style="font-family: var(--font-mono); font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em;">or manual registration</span>
                <div style="flex: 1; height: 1px; background: var(--glass-border);"></div>
            </div>

            <form method="POST" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <div class="form-help">Letters, numbers, and underscores (3-32 characters)</div>
                </div>

                <div class="form-group">
                    <label for="email">Gmail Address</label>
                    <input type="email" id="email" name="email" class="form-input" required autocomplete="email" placeholder="username@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <div class="form-help">Only @gmail.com accounts are permitted</div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input" required autocomplete="new-password">
                    <div class="form-help">Minimum 6 characters</div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px;">Create Account</button>
            </form>
        </div>

        <div style="text-align: center; font-size: 0.85rem; color: var(--text-secondary);">
            Already have an account? <a href="login.php" style="color: var(--text-primary); font-weight: 600;">Sign In</a>
        </div>
    </div>
</body>
</html>
