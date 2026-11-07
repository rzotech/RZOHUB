<?php
/**
 * RZO HUB - Authentication Layer (Anti-Bruteforce, Persistent Remember Me, & Stateless Self-Healing)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function currentUser(): ?array {
    static $cachedUser = null;

    if (!empty($_SESSION['user_id'])) {
        if ($cachedUser !== null && $cachedUser['id'] === $_SESSION['user_id']) {
            return $cachedUser;
        }

        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT id, username, email, role, is_banned, google_id, created_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user && $user['is_banned']) {
            unset($_SESSION['user_id']);
            return null;
        }

        if ($user) {
            $cachedUser = $user;
            return $cachedUser;
        }
    }

    // Attempt stateless automatic authentication via Remember Me token
    $rememberedUser = tryRememberLogin();
    if ($rememberedUser) {
        $cachedUser = $rememberedUser;
        return $cachedUser;
    }

    return null;
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function isAdmin(): bool {
    $user = currentUser();
    return $user !== null && $user['role'] === 'admin';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('danger', 'Please log in to access this page.');
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        setFlash('danger', 'Access denied. Administrator privileges required.');
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * IP-based Rate Limiter for Login Protection
 */
function checkLoginThrottle(string $ip): ?string {
    try {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT failed_count, locked_until FROM login_throttles WHERE ip_address = :ip LIMIT 1");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();

        if ($row && !empty($row['locked_until'])) {
            $lockTime = strtotime($row['locked_until']);
            if ($lockTime > time()) {
                $wait = ceil(($lockTime - time()) / 60);
                return "Too many failed attempts. Login locked for {$wait} more minute(s).";
            }
        }
    } catch (Exception $e) {}
    return null;
}

function recordLoginFailure(string $ip): void {
    try {
        $pdo = getDb();
        $lockTime = date('Y-m-d H:i:s', time() + 600);
        $stmt = $pdo->prepare("
            INSERT INTO login_throttles (ip_address, failed_count, last_attempt) 
            VALUES (:ip, 1, CURRENT_TIMESTAMP)
            ON CONFLICT(ip_address) DO UPDATE SET 
                failed_count = login_throttles.failed_count + 1,
                locked_until = CASE WHEN login_throttles.failed_count + 1 >= 5 THEN :lock_time ELSE login_throttles.locked_until END,
                last_attempt = CURRENT_TIMESTAMP
        ");
        $stmt->execute([':ip' => $ip, ':lock_time' => $lockTime]);
    } catch (Exception $e) {}
}

function clearLoginThrottle(string $ip): void {
    try {
        $pdo = getDb();
        $stmt = $pdo->prepare("DELETE FROM login_throttles WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
    } catch (Exception $e) {}
}

/**
 * Generates and sets a 30-day persistent remember token with self-healing recovery payload
 */
function createRememberToken(int $userId): void {
    try {
        $pdo = getDb();

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.role, u.password_hash, l.license_key, l.expires_at, l.hwid
            FROM users u
            LEFT JOIN licenses l ON u.id = l.user_id
            WHERE u.id = :uid LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return;

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(24));
        $hashedValidator = hash('sha256', $validator);
        $expires = time() + (86400 * 30); // 30 days
        $expiresSql = date('Y-m-d H:i:s', $expires);

        $snapshotData = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
            'password_hash' => $row['password_hash'],
            'license_key' => $row['license_key'],
            'expires_at' => $row['expires_at'],
            'hwid' => $row['hwid'],
            'exp' => $expires
        ];
        $snapshotJson = (string)json_encode($snapshotData, JSON_UNESCAPED_SLASHES);

        $stmt = $pdo->prepare("
            INSERT INTO remember_tokens (user_id, selector, hashed_validator, user_snapshot, expires_at)
            VALUES (:uid, :s, :v, :snap, :exp)
            ON CONFLICT(selector) DO UPDATE SET
                hashed_validator = excluded.hashed_validator,
                user_snapshot = excluded.user_snapshot,
                expires_at = excluded.expires_at
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':s' => $selector,
            ':v' => $hashedValidator,
            ':snap' => $snapshotJson,
            ':exp' => $expiresSql
        ]);

        $snapEncoded = base64_encode($snapshotJson);
        $sigData = $selector . ':' . $validator . ':' . $snapEncoded;
        $signature = hash_hmac('sha256', $sigData, AUTH_SECRET_KEY);
        $cookieValue = $selector . ':' . $validator . ':' . $signature . ':' . $snapEncoded;

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('rzo_remember', $cookieValue, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        $_COOKIE['rzo_remember'] = $cookieValue;

        syncDatabaseSnapshot();
    } catch (Exception $e) {}
}

/**
 * Validates Remember Me cookie and executes self-healing database re-hydration if container was cold-started
 */
function tryRememberLogin(): ?array {
    if (empty($_COOKIE['rzo_remember'])) return null;

    $parts = explode(':', $_COOKIE['rzo_remember'], 4);
    if (count($parts) !== 4) return null;

    [$selector, $validator, $signature, $snapEncoded] = $parts;

    $sigData = $selector . ':' . $validator . ':' . $snapEncoded;
    if (!hash_equals(hash_hmac('sha256', $sigData, AUTH_SECRET_KEY), $signature)) {
        return null;
    }

    $json = base64_decode($snapEncoded, true);
    if (!$json) return null;
    $snapshot = json_decode($json, true);
    if (!is_array($snapshot) || empty($snapshot['id']) || empty($snapshot['exp'])) {
        return null;
    }

    if ($snapshot['exp'] < time()) {
        return null;
    }

    $pdo = getDb();

    // Check if user exists in local SQLite
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $snapshot['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        // SELF-HEALING RE-HYDRATION: Vercel serverless container /tmp was recycled, restore user & license immediately!
        $stmt = $pdo->prepare("
            INSERT INTO users (id, username, email, password_hash, role, is_banned)
            VALUES (:id, :u, :e, :p, :r, 0)
            ON CONFLICT(id) DO NOTHING
        ");
        $stmt->execute([
            ':id' => $snapshot['id'],
            ':u' => $snapshot['username'],
            ':e' => $snapshot['email'],
            ':p' => $snapshot['password_hash'] ?? null,
            ':r' => $snapshot['role'] ?? 'user'
        ]);

        if (!empty($snapshot['license_key'])) {
            $stmtLic = $pdo->prepare("
                INSERT INTO licenses (user_id, license_key, hwid, expires_at)
                VALUES (:uid, :k, :hw, :exp)
                ON CONFLICT(user_id) DO UPDATE SET
                    license_key = excluded.license_key,
                    expires_at = excluded.expires_at
            ");
            $stmtLic->execute([
                ':uid' => $snapshot['id'],
                ':k' => $snapshot['license_key'],
                ':hw' => $snapshot['hwid'] ?? null,
                ':exp' => $snapshot['expires_at'] ?? date('Y-m-d H:i:s', time() + 86400)
            ]);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $snapshot['id']]);
        $user = $stmt->fetch();

        syncDatabaseSnapshot();
    }

    if (!$user || $user['is_banned']) {
        return null;
    }

    $_SESSION['user_id'] = $user['id'];
    logAction($user['id'], 'REMEMBER_LOGIN_SUCCESS');

    return $user;
}

function deleteRememberToken(string $cookieValue): void {
    $parts = explode(':', $cookieValue, 2);
    if (!empty($parts[0])) {
        try {
            $pdo = getDb();
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :s");
            $stmt->execute([':s' => $parts[0]]);
        } catch (Exception $e) {}
    }
    setcookie('rzo_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE['rzo_remember']);
}

function registerUser(string $username, string $email, string $password): array {
    $username = trim($username);
    $email = strtolower(trim($email));

    if (!str_ends_with($email, '@gmail.com')) {
        return ['success' => false, 'message' => 'Registration is strictly limited to @gmail.com email addresses.'];
    }

    if (strlen($username) < 3 || strlen($username) > 32) {
        return ['success' => false, 'message' => 'Username must be between 3 and 32 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_ ]+$/', $username)) {
        return ['success' => false, 'message' => 'Username contains invalid characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address format.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters long.'];
    }

    $pdo = getDb();

    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e) LIMIT 1");
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username or Email is already registered.'];
    }

    $normalizedPass = strtolower($password);
    $hash = password_hash($normalizedPass, PASSWORD_BCRYPT);

    $userId = 0;
    if (isPostgres($pdo)) {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:u, :e, :p, 'user') RETURNING id");
        $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
        $userId = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:u, :e, :p, 'user')");
        $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
        $userId = (int)$pdo->lastInsertId();
    }

    logAction($userId, 'USER_REGISTERED', "Username: {$username} | Email: {$email}");

    $initialKey = generateLicenseKey();
    $expiredPast = date('Y-m-d H:i:s', time() - 60);
    $stmt = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :key, :exp)");
    $stmt->execute([':uid' => $userId, ':key' => $initialKey, ':exp' => $expiredPast]);

    // Issue Remember Token & Sync
    createRememberToken($userId);
    syncDatabaseSnapshot();

    return ['success' => true, 'user_id' => $userId, 'role' => 'user'];
}

function loginUser(string $usernameOrEmail, string $password, bool $rememberMe = true): array {
    $ip = getClientIp();
    $lockMessage = checkLoginThrottle($ip);
    if ($lockMessage) {
        return ['success' => false, 'message' => $lockMessage];
    }

    $identifier = trim($usernameOrEmail);
    if (empty($identifier) || empty($password)) {
        return ['success' => false, 'message' => 'Please fill in all required fields.'];
    }

    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:id) OR LOWER(email) = LOWER(:id) LIMIT 1");
    $stmt->execute([':id' => $identifier]);
    $user = $stmt->fetch();

    $normalizedPass = strtolower($password);

    if (!$user || empty($user['password_hash']) || !password_verify($normalizedPass, $user['password_hash'])) {
        recordLoginFailure($ip);
        return ['success' => false, 'message' => 'Invalid username/email or password.'];
    }

    if ($user['is_banned']) {
        return ['success' => false, 'message' => 'Your account has been suspended by an administrator.'];
    }

    clearLoginThrottle($ip);
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    logAction($user['id'], 'USER_LOGIN', "IP: " . $ip);

    if ($rememberMe) {
        createRememberToken((int)$user['id']);
    }

    return ['success' => true, 'role' => $user['role']];
}

/**
 * Google OAuth 2.0 Verification with Anti-Fixation Protection
 */
function handleGoogleAuth(string $idToken): array {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$res) {
        return ['success' => false, 'message' => 'Google authentication verification failed.'];
    }

    $payload = json_decode($res, true);
    if (empty($payload['email']) || empty($payload['sub'])) {
        return ['success' => false, 'message' => 'Invalid response from Google authentication service.'];
    }

    if (!empty(GOOGLE_CLIENT_ID) && GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
        if (empty($payload['aud']) || $payload['aud'] !== GOOGLE_CLIENT_ID) {
            return ['success' => false, 'message' => 'Google authentication audience mismatch.'];
        }
    }

    $email = strtolower(trim($payload['email']));

    if (!str_ends_with($email, '@gmail.com')) {
        return ['success' => false, 'message' => 'Registration is strictly limited to @gmail.com accounts.'];
    }

    $googleId = (string)$payload['sub'];
    $pdo = getDb();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :gid OR LOWER(email) = LOWER(:e) LIMIT 1");
    $stmt->execute([':gid' => $googleId, ':e' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['is_banned']) {
            return ['success' => false, 'message' => 'Your account has been suspended by an administrator.'];
        }

        if (empty($user['google_id'])) {
            $up = $pdo->prepare("UPDATE users SET google_id = :gid WHERE id = :id");
            $up->execute([':gid' => $googleId, ':id' => $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        logAction($user['id'], 'USER_LOGIN_GOOGLE', "Google ID: {$googleId} | IP: " . getClientIp());

        createRememberToken((int)$user['id']);
        syncDatabaseSnapshot();

        return ['success' => true, 'role' => $user['role'], 'is_new' => false];
    }

    // Register via Google
    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
    if (strlen($baseUsername) < 3) {
        $baseUsername = 'user_' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    $finalUsername = $baseUsername;
    $counter = 1;
    while (true) {
        $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $check->execute([':u' => $finalUsername]);
        if (!$check->fetch()) {
            break;
        }
        $finalUsername = $baseUsername . $counter;
        $counter++;
    }

    $userId = 0;
    if (isPostgres($pdo)) {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, google_id, role) VALUES (:u, :e, :gid, 'user') RETURNING id");
        $stmt->execute([
            ':u' => $finalUsername,
            ':e' => $email,
            ':gid' => $googleId
        ]);
        $userId = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, google_id, role) VALUES (:u, :e, :gid, 'user')");
        $stmt->execute([
            ':u' => $finalUsername,
            ':e' => $email,
            ':gid' => $googleId
        ]);
        $userId = (int)$pdo->lastInsertId();
    }

    $initialKey = generateLicenseKey();
    $expiredPast = date('Y-m-d H:i:s', time() - 60);
    $stmt = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :key, :exp)");
    $stmt->execute([':uid' => $userId, ':key' => $initialKey, ':exp' => $expiredPast]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    logAction($userId, 'USER_REGISTER_GOOGLE', "Registered via Google: {$email}");

    createRememberToken($userId);
    syncDatabaseSnapshot();

    return ['success' => true, 'role' => 'user', 'is_new' => true];
}

function logoutUser(): void {
    if (isset($_SESSION['user_id'])) {
        logAction($_SESSION['user_id'], 'USER_LOGOUT');
    }
    if (!empty($_COOKIE['rzo_remember'])) {
        deleteRememberToken($_COOKIE['rzo_remember']);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('rzo_remember', '', [
            'expires' => time() - 86400,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}
