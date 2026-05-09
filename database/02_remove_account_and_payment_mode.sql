-- ========================================
-- Migration: Remove account/payment_mode from transactions
-- Created: 2026-05-09
-- Description:
--   1) Remove legacy account_id and payment_mode from transactions
--   2) Keep payment_method as the transaction-level bank field
--   3) Drop accounts table and its index (no longer used)
-- ========================================

PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;

-- Rebuild transactions table without account_id and payment_mode.
CREATE TABLE transactions_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    title TEXT DEFAULT '',
    type TEXT NOT NULL CHECK(type IN ('income','expense')),
    amount REAL NOT NULL CHECK(amount >= 0),
    wallet_id INTEGER,
    note TEXT,
    payment_method TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id) REFERENCES wallets(id)
);

INSERT INTO transactions_new (
    id, date, title, type, amount, wallet_id, note, payment_method, created_at
)
SELECT
    id,
    date,
    title,
    type,
    amount,
    wallet_id,
    note,
    payment_method,
    created_at
FROM transactions;

DROP TABLE transactions;
ALTER TABLE transactions_new RENAME TO transactions;

-- Recreate required indexes for the rebuilt table.
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(date);
CREATE INDEX IF NOT EXISTS idx_transactions_wallet ON transactions(wallet_id);

-- Remove no-longer-used schema objects.
DROP INDEX IF EXISTS idx_transactions_account;
DROP TABLE IF EXISTS accounts;

COMMIT;
PRAGMA foreign_keys = ON;
