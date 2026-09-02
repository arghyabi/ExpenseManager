-- ========================================
-- Migration: Replace payment_method TEXT with payment_bank_id INTEGER
-- Created: 2026-05-09
-- Description:
--   transactions.payment_method stored the bank name as a plain string.
--   This was fragile — renaming a bank required a cascade UPDATE, and
--   there was no referential integrity.
--
--   This migration:
--   1. Adds payment_bank_id INTEGER FK column to transactions
--   2. Populates it by matching existing payment_method text to banks.name
--   3. Rebuilds the transactions table without the payment_method column
--   4. Recreates all affected indexes
-- ========================================

PRAGMA foreign_keys = OFF;

-- Step 1: Add payment_bank_id column (nullable — unmatched rows stay NULL)
ALTER TABLE transactions ADD COLUMN payment_bank_id INTEGER REFERENCES banks(id);

-- Step 2: Populate payment_bank_id from existing payment_method text
UPDATE transactions
SET payment_bank_id = (
    SELECT b.id FROM banks b WHERE b.name = transactions.payment_method
)
WHERE payment_method IS NOT NULL AND payment_method != '';

-- Step 3: Rebuild transactions without the payment_method column
CREATE TABLE transactions_new (
    id               INTEGER   PRIMARY KEY AUTOINCREMENT,
    date             TEXT      NOT NULL,
    title            TEXT      DEFAULT '',
    type             TEXT      NOT NULL CHECK(type IN ('income','expense')),
    amount           REAL      NOT NULL CHECK(amount >= 0),
    wallet_id        INTEGER,
    note             TEXT,
    payment_bank_id  INTEGER,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id)       REFERENCES wallets(id),
    FOREIGN KEY(payment_bank_id) REFERENCES banks(id)
);

INSERT INTO transactions_new
    (id, date, title, type, amount, wallet_id, note, payment_bank_id, created_at)
SELECT
    id, date, title, type, amount, wallet_id, note, payment_bank_id, created_at
FROM transactions;

DROP TABLE transactions;
ALTER TABLE transactions_new RENAME TO transactions;

-- Step 4: Recreate indexes
CREATE INDEX IF NOT EXISTS idx_transactions_date     ON transactions(date);
CREATE INDEX IF NOT EXISTS idx_transactions_wallet   ON transactions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_transactions_bank     ON transactions(payment_bank_id);

PRAGMA foreign_keys = ON;
