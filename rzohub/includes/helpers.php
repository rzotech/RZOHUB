<?php
/**
 * RZO HUB - Helpers & Security Enforcement (Stealth Cloaked)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

// Stealth Header Stripping & Cloaking
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

/**
 * Universal IP resolver (Vercel, Cloudflare, Proxy, Origin)
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_VERCEL_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_VERCEL_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Robust Base URL resolver (detects Vercel HTTPS edge and custom APP_URL)
 */
function getBaseUrl(): string {
    if (defined('APP_URL') && !empty(APP_URL)) {
        return rtrim((string)APP_URL, '/');
    }
    if (function_exists('env') && !empty(env('APP_URL'))) {
        return rtrim((string)env('APP_URL'), '/');
    }

    $protocol = 'http';

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], 'https')) ||
        getenv('VERCEL') || !empty($_ENV['VERCEL'])
    ) {
        $protocol = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = rtrim($scriptDir, '/');

    return $protocol . '://' . $host . $scriptDir;
}

function csrfToken(): string {
    $secret = defined('AUTH_SECRET_KEY') ? (string)AUTH_SECRET_KEY : 'RZO_SECURE_TOKEN_SALT_2026';
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId && function_exists('currentUser')) {
        $u = currentUser();
        if ($u && !empty($u['id'])) {
            $userId = (int)$u['id'];
        }
    }

    if (!empty($userId)) {
        $userToken = hash_hmac('sha256', 'csrf:user:' . $userId, $secret);
        $_SESSION['csrf_token'] = $userToken;
        if (empty($_COOKIE['rzo_csrf']) || $_COOKIE['rzo_csrf'] !== $userToken) {
            if (!headers_sent()) {
                setcookie('rzo_csrf', $userToken, [
                    'expires' => time() + 86400 * 30,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ]);
            }
            $_COOKIE['rzo_csrf'] = $userToken;
        }
        return $userToken;
    }

    if (!empty($_COOKIE['rzo_csrf']) && strlen($_COOKIE['rzo_csrf']) >= 32) {
        $cookieToken = (string)$_COOKIE['rzo_csrf'];
        $_SESSION['csrf_token'] = $cookieToken;
        return $cookieToken;
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $token = (string)$_SESSION['csrf_token'];

    if (!headers_sent()) {
        setcookie('rzo_csrf', $token, [
            'expires' => time() + 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ]);
    }
    $_COOKIE['rzo_csrf'] = $token;

    return $token;
}

function verifyCsrfToken(?string $token): bool {
    if (!$token || strlen($token) < 16) {
        return false;
    }

    $secret = defined('AUTH_SECRET_KEY') ? (string)AUTH_SECRET_KEY : 'RZO_SECURE_TOKEN_SALT_2026';

    // 1. Authenticated User HMAC Check (Immune to Serverless Container Recycling)
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId && function_exists('currentUser')) {
        $u = currentUser();
        if ($u && !empty($u['id'])) {
            $userId = (int)$u['id'];
        }
    }
    if (!empty($userId)) {
        $expectedUserToken = hash_hmac('sha256', 'csrf:user:' . $userId, $secret);
        if (hash_equals($expectedUserToken, $token)) {
            return true;
        }
    }

    // 2. Double-Submit Cookie Check
    if (!empty($_COOKIE['rzo_csrf']) && hash_equals($_COOKIE['rzo_csrf'], $token)) {
        return true;
    }

    // 3. PHP Native Session Check
    if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }

    return false;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'info'
        'message' => $message
    ];

    if (!headers_sent()) {
        setcookie('rzo_flash', json_encode($_SESSION['flash']), [
            'expires' => time() + 120,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ]);
    }
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        if (!headers_sent()) {
            setcookie('rzo_flash', '', time() - 3600, '/');
        }
        return $flash;
    }

    if (!empty($_COOKIE['rzo_flash'])) {
        $raw = stripslashes($_COOKIE['rzo_flash']);
        $flash = json_decode($raw, true);
        if (!headers_sent()) {
            setcookie('rzo_flash', '', time() - 3600, '/');
        }
        unset($_COOKIE['rzo_flash']);
        if (is_array($flash) && !empty($flash['type']) && !empty($flash['message'])) {
            return $flash;
        }
    }

    return null;
}

function generateLicenseKey(): string {
    $prefix = defined('KEY_PREFIX') ? KEY_PREFIX : 'VBL';
    $segments = [];
    for ($i = 0; $i < 4; $i++) {
        $segments[] = strtoupper(bin2hex(random_bytes(2)));
    }
    return $prefix . '-' . implode('-', $segments);
}

function isLicenseActive(?string $expiresAt): bool {
    if (!$expiresAt) {
        return false;
    }
    return strtotime($expiresAt) > time();
}

function formatRemainingTime(?string $expiresAt): array {
    if (!$expiresAt) {
        return ['status' => 'inactive', 'text' => 'No Active License', 'seconds' => 0];
    }
    $diff = strtotime($expiresAt) - time();
    if ($diff <= 0) {
        return ['status' => 'expired', 'text' => 'Expired', 'seconds' => 0];
    }

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $seconds = $diff % 60;

    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    $parts[] = "{$minutes}m";
    $parts[] = "{$seconds}s";

    return [
        'status' => 'active',
        'text' => implode(' ', $parts),
        'seconds' => $diff
    ];
}

function logAction(?int $userId, string $action, ?string $details = null): void {
    try {
        $pdo = getDb();
        $ip = getClientIp();
        $stmt = $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (:uid, :action, :details, :ip)");
        $stmt->execute([
            ':uid' => $userId,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $ip
        ]);
    } catch (Exception $e) {
        // Silent fail
    }
}
