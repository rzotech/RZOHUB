<?php
/**
 * RZO HUB - Database Engine (Supabase PostgreSQL + Local SQLite Resilient Dual Mode)
 * Architected for zero-500 serverless execution on Vercel & AWS Lambda.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Robust URL Parser for Database Connection Strings.
 * Handles complex passwords with symbols (#, @, !, $, etc.) and Supabase connection poolers.
 */
function parseDatabaseUrl(string $url): array {
    $url = trim($url);
    if (empty($url)) {
        throw new InvalidArgumentException("DATABASE_URL is empty.");
    }

    $prefix = '';
    if (str_starts_with($url, 'postgresql://')) {
        $prefix = 'postgresql://';
    } elseif (str_starts_with($url, 'postgres://')) {
        $prefix = 'postgres://';
    } else {
        throw new InvalidArgumentException("Invalid scheme: must start with postgresql:// or postgres://");
    }

    $remainder = substr($url, strlen($prefix));

    // Separate query string if any
    $query = [];
    $queryPos = strpos($remainder, '?');
    if ($queryPos !== false) {
        $queryString = substr($remainder, $queryPos + 1);
        $remainder = substr($remainder, 0, $queryPos);
        parse_str($queryString, $query);
    }

    // Separate authority and path (dbname)
    $slashPos = strpos($remainder, '/');
    $dbname = 'postgres';
    if ($slashPos !== false) {
        $dbname = ltrim(substr($remainder, $slashPos + 1), '/');
        $authority = substr($remainder, 0, $slashPos);
    } else {
        $authority = $remainder;
    }
    if (empty($dbname)) {
        $dbname = 'postgres';
    }

    // Separate credentials (user:pass) and host/port
    $lastAtPos = strrpos($authority, '@');
    $user = 'postgres';
    $pass = '';
    if ($lastAtPos !== false) {
        $userinfo = substr($authority, 0, $lastAtPos);
        $hostport = substr($authority, $lastAtPos + 1);

        $firstColon = strpos($userinfo, ':');
        if ($firstColon !== false) {
            $user = substr($userinfo, 0, $firstColon);
            $pass = substr($userinfo, $firstColon + 1);
        } else {
            $user = $userinfo;
        }
    } else {
        $hostport = $authority;
    }

    // Extract host and port
    $host = 'localhost';
    $port = 5432;
    $colonPos = strrpos($hostport, ':');
    if ($colonPos !== false) {
        $host = substr($hostport, 0, $colonPos);
        $port = (int)substr($hostport, $colonPos + 1);
        if ($port <= 0) $port = 5432;
    } else {
        $host = $hostport;
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => urldecode($user),
        'pass' => urldecode($pass),
        'dbname' => $dbname,
        'sslmode' => $query['sslmode'] ?? 'require'
    ];
}

function isPostgres(PDO $pdo): bool {
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Returns database status and diagnostic information.
 */
function getDbDiagnostics(): array {
    $dbUrl = defined('DATABASE_URL') && !empty(DATABASE_URL) ? DATABASE_URL : '';
    $hasUrl = !empty($dbUrl);
    $activePdo = null;
    $activeDriver = 'none';

    try {
        $activePdo = getDb();
        $activeDriver = $activePdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {}

    $maskedHost = 'N/A';
    $isDirectSupabase = false;
    if ($hasUrl) {
        try {
            $parsed = parseDatabaseUrl($dbUrl);
            $maskedHost = $parsed['host'];
            if (str_contains($maskedHost, 'db.') && str_contains($maskedHost, '.supabase.co')) {
                $isDirectSupabase = true;
            }
        } catch (Throwable $e) {}
    }

    return [
        'status' => 'operational',
        'active_driver' => $activeDriver,
        'database_url_configured' => $hasUrl,
        'target_host' => $maskedHost,
        'is_direct_supabase_ipv6' => $isDirectSupabase,
        'available_pdo_drivers' => PDO::getAvailableDrivers(),
        'last_error' => $GLOBALS['DB_LAST_ERROR'] ?? null,
        'serverless' => (bool)(getenv('VERCEL') || !empty($_ENV['VERCEL']))
    ];
}

/**
 * Creates safe SQLite fallback connection for serverless/local runtimes.
 */
function getFallbackSqliteDb(): PDO {
    static $fallbackPdo = null;
    if ($fallbackPdo !== null) {
        return $fallbackPdo;
    }

    try {
        $dbPath = defined('DATABASE_PATH') ? DATABASE_PATH : '/tmp/database.sqlite';
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }

        $isNew = !file_exists($dbPath) || filesize($dbPath) === 0;

        $fallbackPdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        ]);

        // Serverless compatibility: use MEMORY or DELETE journal mode instead of WAL
        try {
            $fallbackPdo->exec('PRAGMA journal_mode = MEMORY;');
            $fallbackPdo->exec('PRAGMA foreign_keys = ON;');
        } catch (Throwable $e) {}

        initDatabaseSchema($fallbackPdo);

        if ($isNew) {
            try {
                restoreDatabaseSnapshot($fallbackPdo);
            } catch (Throwable $e) {}
            try {
                seedRootAdmin($fallbackPdo);
            } catch (Throwable $e) {}
        }

        return $fallbackPdo;
    } catch (Throwable $sqliteErr) {
        // Ultimate in-memory fallback
        $fallbackPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        initDatabaseSchema($fallbackPdo);
        seedRootAdmin($fallbackPdo);
        return $fallbackPdo;
    }
}

/**
 * Resilient Master Database Accessor.
 * Guarantees zero 500 errors by attempting Cloud PostgreSQL first and falling back cleanly.
 */
function getDb(bool $forceReconnect = false): PDO {
    static $pdo = null;

    if ($forceReconnect) {
        $pdo = null;
        return new PDO('sqlite::memory:');
    }

    if ($pdo !== null) {
        return $pdo;
    }

    $dbUrl = defined('DATABASE_URL') && !empty(DATABASE_URL) ? DATABASE_URL : '';

    if (!empty($dbUrl)) {
        try {
            $db = parseDatabaseUrl($dbUrl);
            $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};sslmode={$db['sslmode']}";
            
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3
            ]);

            // Lightweight ping to confirm connection is alive
            $pdo->query('SELECT 1');

            // Quick table existence check to avoid redundant DDL locks on every request
            try {
                $check = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
                if (empty($check)) {
                    initPostgresSchema($pdo);
                }
            } catch (Throwable $e) {
                // If permission restricts to_regclass, attempt init
                initPostgresSchema($pdo);
            }

            // Seed root admin if missing (wrapped so it never breaks requests)
            try {
                seedRootAdmin($pdo);
            } catch (Throwable $seedErr) {
                error_log("Seed notice: " . $seedErr->getMessage());
            }

            return $pdo;
        } catch (Throwable $pgError) {
            $GLOBALS['DB_LAST_ERROR'] = $pgError->getMessage();
            error_log("Supabase PostgreSQL connection failed: " . $pgError->getMessage() . " - switching to SQLite fallback.");
            $pdo = getFallbackSqliteDb();
            return $pdo;
        }
    }

    // Default: SQLite Mode
    $pdo = getFallbackSqliteDb();
    return $pdo;
}

function closeDb(): void {
    getDb(true);
}

function initPostgresSchema(PDO $pdo): void {
    $schema = <<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        email VARCHAR(255) UNIQUE,
        password_hash TEXT DEFAULT NULL,
        google_id VARCHAR(255) DEFAULT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'user',
        is_banned SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS licenses (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
        license_key VARCHAR(255) NOT NULL UNIQUE,
        hwid VARCHAR(255) DEFAULT NULL,
        last_hwid_reset TIMESTAMPTZ DEFAULT NULL,
        expires_at TIMESTAMPTZ NOT NULL,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS loot_sessions (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token VARCHAR(255) NOT NULL UNIQUE,
        lootlabs_link TEXT DEFAULT NULL,
        is_completed SMALLINT NOT NULL DEFAULT 0,
        completed_at TIMESTAMPTZ DEFAULT NULL,
        expires_at TIMESTAMPTZ NOT NULL,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS remember_tokens (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        selector VARCHAR(255) NOT NULL UNIQUE,
        hashed_validator VARCHAR(255) NOT NULL,
        user_snapshot TEXT DEFAULT NULL,
        expires_at TIMESTAMPTZ NOT NULL,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS logs (
        id SERIAL PRIMARY KEY,
        user_id INTEGER DEFAULT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS login_throttles (
        id SERIAL PRIMARY KEY,
        ip_address VARCHAR(100) NOT NULL UNIQUE,
        failed_count INTEGER NOT NULL DEFAULT 0,
        locked_until TIMESTAMPTZ DEFAULT NULL,
        last_attempt TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS api_throttles (
        id SERIAL PRIMARY KEY,
        ip_address VARCHAR(100) NOT NULL UNIQUE,
        request_count INTEGER NOT NULL DEFAULT 1,
        window_start BIGINT NOT NULL
    );

    CREATE INDEX IF NOT EXISTS idx_licenses_key ON licenses(license_key);
    CREATE INDEX IF NOT EXISTS idx_licenses_user ON licenses(user_id);
    CREATE INDEX IF NOT EXISTS idx_loot_token ON loot_sessions(token);
    CREATE INDEX IF NOT EXISTS idx_remember_selector ON remember_tokens(selector);
    CREATE INDEX IF NOT EXISTS idx_login_throttles_ip ON login_throttles(ip_address);
    CREATE INDEX IF NOT EXISTS idx_api_throttles_ip ON api_throttles(ip_address);
SQL;

    try {
        $pdo->exec($schema);
    } catch (Throwable $e) {
        error_log("Schema init warning: " . $e->getMessage());
    }
}

function initDatabaseSchema(PDO $pdo): void {
    $schema = <<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        email TEXT UNIQUE COLLATE NOCASE,
        password_hash TEXT DEFAULT NULL,
        google_id TEXT DEFAULT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        is_banned INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        license_key TEXT NOT NULL UNIQUE,
        hwid TEXT DEFAULT NULL,
        last_hwid_reset DATETIME DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS loot_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL UNIQUE,
        lootlabs_link TEXT DEFAULT NULL,
        is_completed INTEGER NOT NULL DEFAULT 0,
        completed_at DATETIME DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS remember_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        hashed_validator TEXT NOT NULL,
        user_snapshot TEXT DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        action TEXT NOT NULL,
        details TEXT DEFAULT NULL,
        ip_address TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS login_throttles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL UNIQUE,
        failed_count INTEGER NOT NULL DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS api_throttles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL UNIQUE,
        request_count INTEGER NOT NULL DEFAULT 1,
        window_start INTEGER NOT NULL
    );

    CREATE INDEX IF NOT EXISTS idx_licenses_key ON licenses(license_key);
    CREATE INDEX IF NOT EXISTS idx_licenses_user ON licenses(user_id);
    CREATE INDEX IF NOT EXISTS idx_loot_token ON loot_sessions(token);
    CREATE INDEX IF NOT EXISTS idx_remember_selector ON remember_tokens(selector);
    CREATE INDEX IF NOT EXISTS idx_login_throttles_ip ON login_throttles(ip_address);
    CREATE INDEX IF NOT EXISTS idx_api_throttles_ip ON api_throttles(ip_address);
SQL;

    try {
        $pdo->exec($schema);
    } catch (Throwable $e) {
        error_log("SQLite schema init warning: " . $e->getMessage());
    }
}

function seedRootAdmin(PDO $pdo): void {
    $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'rzohub@internasionalbusiness.com';
    $adminUsername = defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'RZO HUB';
    $rawPass = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'amadiketu123';
    
    $normalizedPass = strtolower($rawPass);
    $adminHash = password_hash($normalizedPass, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e) OR LOWER(username) = LOWER(:u) LIMIT 1");
        $stmt->execute([':e' => $adminEmail, ':u' => $adminUsername]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:u, :e, :p, 'admin')");
            $insert->execute([
                ':u' => $adminUsername,
                ':e' => $adminEmail,
                ':p' => $adminHash
            ]);

            $adminId = 0;
            if (isPostgres($pdo)) {
                $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1");
                $check->execute([':e' => $adminEmail]);
                $adminId = (int)$check->fetchColumn();
            } else {
                $adminId = (int)$pdo->lastInsertId();
            }

            $adminKey = 'VBL-MASTER-ROOT-ADMIN-KEYS';
            $tenYears = date('Y-m-d H:i:s', strtotime('+10 years'));
            $lic = $pdo->prepare("INSERT INTO licenses (user_id, license_key, expires_at) VALUES (:uid, :k, :exp)");
            $lic->execute([
                ':uid' => $adminId,
                ':k' => $adminKey,
                ':exp' => $tenYears
            ]);
        }
    } catch (Throwable $e) {
        error_log("SeedRootAdmin notice: " . $e->getMessage());
    }
}

function getStorageEndpoint(): ?string {
    if (defined('STORAGE_SYNC_URL') && !empty(STORAGE_SYNC_URL)) {
        return STORAGE_SYNC_URL;
    }
    $projectHash = substr(hash('sha256', ADMIN_EMAIL . ':' . ADMIN_PASSWORD . ':RZO_KEY_PROJECT_PERSIST_2026'), 0, 24);
    return 'https://kvdb.io/' . $projectHash . '/database_snapshot';
}

function exportDatabaseData(PDO $pdo): string {
    $data = [
        'users' => [],
        'licenses' => [],
        'loot_sessions' => [],
        'remember_tokens' => [],
        'logs' => [],
        'exported_at' => time()
    ];

    try {
        $data['users'] = $pdo->query("SELECT id, username, email, password_hash, google_id, role, is_banned, created_at FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $data['licenses'] = $pdo->query("SELECT id, user_id, license_key, hwid, last_hwid_reset, expires_at, created_at, updated_at FROM licenses")->fetchAll(PDO::FETCH_ASSOC);
        $data['loot_sessions'] = $pdo->query("SELECT id, user_id, token, lootlabs_link, is_completed, completed_at, expires_at, created_at FROM loot_sessions")->fetchAll(PDO::FETCH_ASSOC);
        $data['remember_tokens'] = $pdo->query("SELECT id, user_id, selector, hashed_validator, user_snapshot, expires_at, created_at FROM remember_tokens")->fetchAll(PDO::FETCH_ASSOC);
        $data['logs'] = $pdo->query("SELECT id, user_id, action, details, ip_address, created_at FROM logs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $json = (string)json_encode($data, JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $json, AUTH_SECRET_KEY);
    return $signature . '::' . base64_encode($json);
}

function importDatabaseData(PDO $pdo, string $payload): bool {
    $parts = explode('::', $payload, 2);
    if (count($parts) !== 2) return false;

    $signature = $parts[0];
    $json = base64_decode($parts[1], true);
    if ($json === false) return false;

    if (!hash_equals(hash_hmac('sha256', $json, AUTH_SECRET_KEY), $signature)) {
        return false;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['users'])) return false;

    $pdo->beginTransaction();
    try {
        $stmtUser = $pdo->prepare("
            INSERT INTO users (id, username, email, password_hash, google_id, role, is_banned, created_at)
            VALUES (:id, :u, :e, :p, :g, :r, :b, :c)
            ON CONFLICT(id) DO UPDATE SET
                username = excluded.username,
                email = excluded.email,
                password_hash = coalesce(excluded.password_hash, users.password_hash),
                google_id = coalesce(excluded.google_id, users.google_id),
                role = excluded.role,
                is_banned = excluded.is_banned
        ");
        foreach ($data['users'] as $u) {
            $stmtUser->execute([
                ':id' => $u['id'],
                ':u' => $u['username'],
                ':e' => $u['email'],
                ':p' => $u['password_hash'],
                ':g' => $u['google_id'] ?? null,
                ':r' => $u['role'] ?? 'user',
                ':b' => $u['is_banned'] ?? 0,
                ':c' => $u['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        if (!empty($data['licenses'])) {
            $stmtLic = $pdo->prepare("
                INSERT INTO licenses (id, user_id, license_key, hwid, last_hwid_reset, expires_at, created_at, updated_at)
                VALUES (:id, :uid, :k, :hw, :lhr, :exp, :c, :up)
                ON CONFLICT(id) DO UPDATE SET
                    license_key = excluded.license_key,
                    hwid = excluded.hwid,
                    last_hwid_reset = excluded.last_hwid_reset,
                    expires_at = excluded.expires_at,
                    updated_at = excluded.updated_at
            ");
            foreach ($data['licenses'] as $l) {
                $stmtLic->execute([
                    ':id' => $l['id'],
                    ':uid' => $l['user_id'],
                    ':k' => $l['license_key'],
                    ':hw' => $l['hwid'] ?? null,
                    ':lhr' => $l['last_hwid_reset'] ?? null,
                    ':exp' => $l['expires_at'],
                    ':c' => $l['created_at'] ?? date('Y-m-d H:i:s'),
                    ':up' => $l['updated_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        if (!empty($data['remember_tokens'])) {
            $stmtTok = $pdo->prepare("
                INSERT INTO remember_tokens (id, user_id, selector, hashed_validator, user_snapshot, expires_at, created_at)
                VALUES (:id, :uid, :s, :v, :snap, :exp, :c)
                ON CONFLICT(selector) DO UPDATE SET
                    hashed_validator = excluded.hashed_validator,
                    user_snapshot = excluded.user_snapshot,
                    expires_at = excluded.expires_at
            ");
            foreach ($data['remember_tokens'] as $t) {
                $stmtTok->execute([
                    ':id' => $t['id'],
                    ':uid' => $t['user_id'],
                    ':s' => $t['selector'],
                    ':v' => $t['hashed_validator'],
                    ':snap' => $t['user_snapshot'] ?? null,
                    ':exp' => $t['expires_at'],
                    ':c' => $t['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return false;
    }
}

function restoreDatabaseSnapshot(PDO $pdo): bool {
    if (isPostgres($pdo)) {
        return true;
    }

    $localSnap = ROOT_PATH . '/database/snapshot.json';
    if (file_exists($localSnap) && filesize($localSnap) > 0) {
        $payload = @file_get_contents($localSnap);
        if ($payload && importDatabaseData($pdo, $payload)) {
            return true;
        }
    }

    $endpoint = getStorageEndpoint();
    if (!empty($endpoint) && function_exists('curl_init')) {
        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => ['User-Agent: RZOHUB-Sync/1.0']
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && is_string($response) && strlen($response) > 32) {
                return importDatabaseData($pdo, $response);
            }
        } catch (Throwable $e) {}
    }

    return false;
}

function syncDatabaseSnapshot(): void {
    try {
        $pdo = getDb();
        if (isPostgres($pdo)) {
            return;
        }

        static $lastSync = 0;
        if (time() - $lastSync < 1) {
            return;
        }
        $lastSync = time();

        $payload = exportDatabaseData($pdo);

        $localSnap = ROOT_PATH . '/database/snapshot.json';
        $dbDir = dirname($localSnap);
        if (is_writable($dbDir) || (!file_exists($dbDir) && is_writable(dirname($dbDir)))) {
            @file_put_contents($localSnap, $payload);
        }

        $endpoint = getStorageEndpoint();
        if (!empty($endpoint) && function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/plain',
                    'User-Agent: RZOHUB-Sync/1.0'
                ]
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {}
}

function migrateAllDatabase(PDO $pdo): array {
    if (isPostgres($pdo)) {
        initPostgresSchema($pdo);
        seedRootAdmin($pdo);
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalLicenses = (int)$pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
        return [
            'success' => true,
            'integrity' => 'ok (Supabase Cloud PostgreSQL ACID)',
            'stats' => ['users' => $totalUsers, 'licenses' => $totalLicenses],
            'added_columns' => []
        ];
    }

    initDatabaseSchema($pdo);
    seedRootAdmin($pdo);

    $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $addedCols = [];
    if (!in_array('google_id', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id TEXT DEFAULT NULL;");
        $addedCols[] = 'google_id';
    }

    $integrity = $pdo->query("PRAGMA integrity_check;")->fetchColumn();
    $pdo->exec("PRAGMA optimize;");

    syncDatabaseSnapshot();

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalLicenses = (int)$pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();

    return [
        'success' => true,
        'integrity' => $integrity,
        'stats' => ['users' => $totalUsers, 'licenses' => $totalLicenses],
        'added_columns' => $addedCols
    ];
}

function wipeDatabase(): void {
    if (file_exists(DATABASE_PATH)) {
        @unlink(DATABASE_PATH);
    }
    getDb();
}
