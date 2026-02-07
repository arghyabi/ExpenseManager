PRAGMA foreign_keys = ON;

-- =============================
-- Categories
-- =============================
CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- ===================================
-- Accounts table
-- ===================================
CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- ===================================
-- Monthly budget table
-- ===================================
CREATE TABLE monthly_budget (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    amount REAL NOT NULL,
    UNIQUE(year, month)
);

-- =============================
-- Transactions
-- =============================
CREATE TABLE transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('income','expense')),
    amount REAL NOT NULL CHECK(amount >= 0),
    payment_mode TEXT DEFAULT 'cash',
    category_id INTEGER,
    note TEXT,
    account_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(category_id) REFERENCES categories(id)
);

CREATE INDEX idx_transactions_date ON transactions(date);
CREATE INDEX idx_transactions_category ON transactions(category_id);


-- ===================================
-- Create reporting VIEW (very useful)
-- ===================================
CREATE VIEW monthly_summary AS
SELECT
    strftime('%Y', date) AS year,
    strftime('%m', date) AS month,
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense,
    SUM(CASE WHEN type='income' THEN amount ELSE -amount END) AS net
FROM transactions
GROUP BY year, month;