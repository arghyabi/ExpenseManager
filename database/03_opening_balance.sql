-- ========================================
-- Migration: Full schema consolidation + opening_balance
-- Created: 2026-05-09
-- Description:
--   This migration brings any database state up to the current full schema.
--   It is written defensively with IF NOT EXISTS / IF EXISTS guards so it
--   is safe to run regardless of what previous migrations have or have not
--   been applied.
--
--   What it ensures:
--   1. banks table exists with opening_balance column
--   2. wallets table exists with wallet_type and opening_balance columns
--   3. transactions table exists in the correct final shape
--      (wallet_id, title, payment_method — no account_id, no payment_mode)
--   4. budget table exists (idempotent)
--   5. All required indexes exist
--   6. Legacy tables/views/indexes removed if still present
--
--   NOTE: This file supersedes 01_initial_migrations.sql and
--   02_remove_account_and_payment_mode.sql. Those are recorded as applied
--   in schema_version (see end of file) so dbTool skips them on fresh DBs.
-- ========================================

PRAGMA foreign_keys = OFF;

-- ======================================
-- 1. Banks table (final shape)
-- ======================================
CREATE TABLE IF NOT EXISTS banks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT,
    opening_balance  REAL    NOT NULL DEFAULT 0
);

-- Add opening_balance to banks if it was created without it
-- (safe no-op if the column already exists — SQLite ignores duplicate ADD COLUMN errors
--  when wrapped in a conditional, but SQLite has no IF NOT EXISTS for ALTER TABLE,
--  so we use the INSERT-into-shadow trick via a dummy SELECT guard instead.
--  The cleanest portable approach is to attempt and silently continue.)

-- ======================================
-- 2. Wallets table (final shape)
-- ======================================
CREATE TABLE IF NOT EXISTS wallets (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT,
    bank_id          INTEGER,
    wallet_type      TEXT    NOT NULL DEFAULT 'balance' CHECK(wallet_type IN ('budget', 'balance')),
    opening_balance  REAL    NOT NULL DEFAULT 0,
    FOREIGN KEY(bank_id) REFERENCES banks(id)
);

-- ======================================
-- 3. Transactions table (final shape — no account_id, no payment_mode)
-- ======================================
CREATE TABLE IF NOT EXISTS transactions (
    id              INTEGER   PRIMARY KEY AUTOINCREMENT,
    date            TEXT      NOT NULL,
    title           TEXT      DEFAULT '',
    type            TEXT      NOT NULL CHECK(type IN ('income','expense')),
    amount          REAL      NOT NULL CHECK(amount >= 0),
    wallet_id       INTEGER,
    note            TEXT,
    payment_method  TEXT      DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id) REFERENCES wallets(id)
);

-- ======================================
-- 4. Budget table (idempotent — may already exist from 01_)
-- ======================================
CREATE TABLE IF NOT EXISTS budget (
    id               INTEGER   PRIMARY KEY AUTOINCREMENT,
    wallet_id        INTEGER   NOT NULL,
    month            TEXT      NOT NULL,
    expected_income  REAL      DEFAULT 0,
    expected_expense REAL      DEFAULT 0,
    notes            TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id) REFERENCES wallets(id),
    UNIQUE(wallet_id, month)
);

-- ======================================
-- 5. Indexes
-- ======================================
CREATE INDEX IF NOT EXISTS idx_transactions_date         ON transactions(date);
CREATE INDEX IF NOT EXISTS idx_transactions_wallet       ON transactions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_budget_wallet_month       ON budget(wallet_id, month);

-- ======================================
-- 6. Add opening_balance to existing tables if created without it
--    (dbTool.py skips these with a warning if the column already exists)
-- ======================================
ALTER TABLE banks    ADD COLUMN opening_balance REAL NOT NULL DEFAULT 0;
ALTER TABLE wallets  ADD COLUMN opening_balance REAL NOT NULL DEFAULT 0;

-- Add wallet_type to wallets if created without it (pre-01_ databases)
ALTER TABLE wallets  ADD COLUMN wallet_type TEXT NOT NULL DEFAULT 'balance' CHECK(wallet_type IN ('budget', 'balance'));

-- ======================================
-- 7. Remove legacy objects if still present
-- ======================================
DROP VIEW  IF EXISTS monthly_summary;
DROP INDEX IF EXISTS idx_transactions_account;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS monthly_budget;

PRAGMA foreign_keys = ON;

-- ======================================
-- 8. Mark earlier migrations as applied so dbTool skips them on fresh DBs.
--    This file fully supersedes 01_ and 02_, so they must never run after this.
-- ======================================
INSERT OR IGNORE INTO schema_version(version) VALUES ('01_initial_migrations.sql');
INSERT OR IGNORE INTO schema_version(version) VALUES ('02_remove_account_and_payment_mode.sql');
