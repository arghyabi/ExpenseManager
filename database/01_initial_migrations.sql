-- ========================================
-- Migration: Add Budget Table
-- Purpose: Track monthly expected income/expenses without affecting actual balance
-- ========================================

CREATE TABLE IF NOT EXISTS budget (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wallet_id INTEGER NOT NULL,
    month TEXT NOT NULL,  -- Format: YYYY-MM
    expected_income REAL DEFAULT 0,
    expected_expense REAL DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(wallet_id) REFERENCES wallets(id),
    UNIQUE(wallet_id, month)
);

-- Index for fast month/wallet lookups
CREATE INDEX IF NOT EXISTS idx_budget_wallet_month ON budget(wallet_id, month);

-- ========================================
-- Migration: Add wallet_type column to wallets table
-- Created: 2026-04-04
-- Description: Add wallet type field to support Monthly Budget Tracker vs Running Balance Wallet
-- ========================================

ALTER TABLE wallets ADD COLUMN wallet_type TEXT DEFAULT 'balance' CHECK(wallet_type IN ('budget', 'balance'));

-- Auto-detect wallet type based on existing transactions
-- A wallet is 'budget' if it has NO income transactions, 'balance' if it has income
-- This is done via SQL, no application logic needed

-- Note: Manual verification may be needed for edge cases
-- Users can edit wallet type in the wallet edit form if auto-detection is incorrect

-- ========================================
-- Migration: Add payment_method column to transactions table
-- Created: 2026-04-04
-- Description: Track which bank/payment method each transaction came from (For expense) or went to (For income)
-- ========================================

ALTER TABLE transactions ADD COLUMN payment_method TEXT DEFAULT NULL;

-- payment_method will store bank name or payment method:
-- Examples: 'State Bank of India', 'ICICI Bank', 'RBL Bank', 'Amazon Pay', 'Credit Card', 'Cash', etc.
