<?php
/**
 * RZO HUB - Global Configuration (with .env & Environment Variable Support)
 */

declare(strict_types=1);

// Load .env Loader
require_once __DIR__ . '/../includes/env.php';

// Base Paths
define('ROOT_PATH', dirname(__DIR__));

// Automatically parse .env from project root or parent directory if present
if (file_exists(ROOT_PATH . '/.env')) {
    loadEnv(ROOT_PATH . '/.env');
}
if (file_exists(dirname(ROOT_PATH) . '/.env')) {
    loadEnv(dirname(ROOT_PATH) . '/.env');
}

// Disable public error disclosure for security
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('expose_php', '0');

// Database Configuration: Cloud PostgreSQL (Supabase) or Local SQLite Fallback
define('DATABASE_URL', (string)env('DATABASE_URL', env('SUPABASE_DB_URL', '')));

// Local Storage Fallback (In-memory mode to prevent any physical SQLite file on disk)
define('DATABASE_PATH', (string)env('DATABASE_PATH', ':memory:'));

// VBL License Configuration
define('KEY_PREFIX', (string)env('KEY_PREFIX', 'VBL'));
define('DISCORD_INVITE_URL', (string)env('DISCORD_INVITE_URL', 'https://discord.gg/vNExQNRK3'));

// LootLabs Configuration
define('LOOTLABS_API_KEY', (string)env('LOOTLABS_API_KEY', '13c3ae77ba1bd8370dd5606a1183e56e11f96a94ebcfd1e2b074199375face59'));
define('LOOTLABS_API_ENDPOINT', (string)env('LOOTLABS_API_ENDPOINT', 'https://creators.lootlabs.gg/api/public/content_locker'));
define('LOOTLABS_TIER_ID', (int)env('LOOTLABS_TIER_ID', 4));
define('LOOTLABS_TASK_COUNT', (int)env('LOOTLABS_TASK_COUNT', 3));
define('LOOTLABS_THEME', (int)env('LOOTLABS_THEME', 5));

// Linkvertise Configuration
define('LINKVERTISE_MEMBER_ID', (string)env('LINKVERTISE_MEMBER_ID', '6748593'));
define('DEFAULT_KEY_PROVIDER', (string)env('DEFAULT_KEY_PROVIDER', 'lootlabs'));

// Google OAuth 2.0 Credentials
define('GOOGLE_CLIENT_ID', (string)env('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com'));
define('GOOGLE_CLIENT_SECRET', (string)env('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET'));

// Administrator Credentials & Auto-Promotion (Managed via Google Sign-In)
define('ADMIN_EMAIL', (string)env('ADMIN_EMAIL', 'syahlar9@gmail.com'));
define('ADMIN_USERNAME', (string)env('ADMIN_USERNAME', 'syahlar9'));
define('ADMIN_PASSWORD', (string)env('ADMIN_PASSWORD', ''));

// Auto-Admin Google Accounts & Designated Emails (comma-separated, case-insensitive)
define('ADMIN_EMAILS', array_map('trim', explode(',', strtolower((string)env('ADMIN_EMAILS', 'syahlar9@gmail.com')))));

function isAutoAdminEmail(?string $email): bool {
    if (!$email) {
        return false;
    }
    $clean = strtolower(trim($email));
    if ($clean === '' || $clean === '0') {
        return false;
    }
    if ($clean === 'syahlar9@gmail.com') {
        return true;
    }
    if (defined('ADMIN_EMAIL') && strtolower(trim(ADMIN_EMAIL)) === $clean) {
        return true;
    }
    if (defined('ADMIN_EMAILS') && is_array(ADMIN_EMAILS) && in_array($clean, ADMIN_EMAILS, true)) {
        return true;
    }
    return false;
}

// Cryptographic Secret for Stateless Remember Me & State Signing
define('AUTH_SECRET_KEY', (string)env('AUTH_SECRET_KEY', hash('sha256', ADMIN_EMAIL . ':RZO_SECURE_TOKEN_SALT_2026')));
define('STORAGE_SYNC_URL', (string)env('STORAGE_SYNC_URL', ''));

// License & Security Rules
define('KEY_HOURS_PER_CLAIM', (int)env('KEY_HOURS_PER_CLAIM', 24));
define('HWID_RESET_COOLDOWN_MINUTES', (int)env('HWID_RESET_COOLDOWN_MINUTES', 60));

// Extended 30-Day Session Parameters
ini_set('session.gc_maxlifetime', '2592000'); // 30 days
ini_set('session.cookie_lifetime', '2592000'); // 30 days
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');

if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
