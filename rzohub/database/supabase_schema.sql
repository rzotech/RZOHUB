-- =============================================================================
-- RZO HUB - Supabase (PostgreSQL) Database Schema
-- Run this in your Supabase SQL Editor (Dashboard -> SQL Editor -> New Query)
-- =============================================================================

-- 1. Users Table
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

-- 2. Licenses Table
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

-- 3. LootSessions Table (LootLabs Verification)
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

-- 4. Remember Tokens Table (Persistent Login)
CREATE TABLE IF NOT EXISTS remember_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector VARCHAR(255) NOT NULL UNIQUE,
    hashed_validator VARCHAR(255) NOT NULL,
    user_snapshot TEXT DEFAULT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 5. Logs Table (Audit Trail)
CREATE TABLE IF NOT EXISTS logs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 6. Login Throttles Table (Anti-Bruteforce)
CREATE TABLE IF NOT EXISTS login_throttles (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(100) NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ DEFAULT NULL,
    last_attempt TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 7. API Throttles Table (Rate Limiting)
CREATE TABLE IF NOT EXISTS api_throttles (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(100) NOT NULL UNIQUE,
    request_count INTEGER NOT NULL DEFAULT 1,
    window_start BIGINT NOT NULL
);

-- Indexes for lightning fast lookups
CREATE INDEX IF NOT EXISTS idx_licenses_key ON licenses(license_key);
CREATE INDEX IF NOT EXISTS idx_licenses_user ON licenses(user_id);
CREATE INDEX IF NOT EXISTS idx_loot_token ON loot_sessions(token);
CREATE INDEX IF NOT EXISTS idx_remember_selector ON remember_tokens(selector);
CREATE INDEX IF NOT EXISTS idx_login_throttles_ip ON login_throttles(ip_address);
CREATE INDEX IF NOT EXISTS idx_api_throttles_ip ON api_throttles(ip_address);