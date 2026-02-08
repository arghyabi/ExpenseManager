-- ========================================
-- Expense Manager - Consolidated Database Schema
-- Merged from: expenseManagerDatabase.sql, dataEntry.sql, update_001.sql, update_002.sql
-- Cleaned up: Removed unused tables (monthly_budget, categories), removed old migrations
-- ========================================

PRAGMA foreign_keys = ON;

-- ======================================
-- Banks Table
-- ======================================
CREATE TABLE IF NOT EXISTS banks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- ======================================
-- Wallets Table (migrated from categories)
-- ======================================
CREATE TABLE IF NOT EXISTS wallets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    bank_id INTEGER,
    FOREIGN KEY(bank_id) REFERENCES banks(id)
);

-- ======================================
-- Accounts Table
-- ======================================
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- ======================================
-- Transactions Table
-- ======================================
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    title TEXT DEFAULT '',
    type TEXT NOT NULL CHECK(type IN ('income','expense')),
    amount REAL NOT NULL CHECK(amount >= 0),
    payment_mode TEXT DEFAULT 'cash',
    wallet_id INTEGER,
    note TEXT,
    account_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id) REFERENCES wallets(id),
    FOREIGN KEY(account_id) REFERENCES accounts(id)
);

-- ======================================
-- Indexes for Performance
-- ======================================
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(date);
CREATE INDEX IF NOT EXISTS idx_transactions_wallet ON transactions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_transactions_account ON transactions(account_id);

-- ======================================
-- Default Data
-- ======================================
INSERT OR IGNORE INTO accounts(name) VALUES
('Cash'),
('UPI'),
('Bank');
