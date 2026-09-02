-- ========================================
-- Migration: Add categories table and category_id to transactions
-- Created: 2026-05-09
-- Description:
--   Adds structured spending categories (Food, Transport, etc.) so
--   transactions can be tagged and analysed by category.
--
--   1. Creates categories table with name and color
--   2. Adds category_id FK to transactions
--   3. Seeds a default set of common categories
-- ========================================

-- ======================================
-- 1. Categories table
-- ======================================
CREATE TABLE IF NOT EXISTS categories (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL UNIQUE,
    color   TEXT    NOT NULL DEFAULT '#95a5a6'
);

-- ======================================
-- 2. Add category_id to transactions
--    (nullable — existing rows stay uncategorised)
-- ======================================
ALTER TABLE transactions ADD COLUMN category_id INTEGER REFERENCES categories(id);

CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions(category_id);

-- ======================================
-- 3. Default categories
-- ======================================
INSERT OR IGNORE INTO categories (name, color) VALUES
    ('Food & Dining',    '#e67e22'),
    ('Groceries',        '#27ae60'),
    ('Transport',        '#2980b9'),
    ('Fuel',             '#8e44ad'),
    ('Shopping',         '#e74c3c'),
    ('Health & Medical', '#16a085'),
    ('Entertainment',    '#f39c12'),
    ('Utilities',        '#2c3e50'),
    ('Rent & Housing',   '#c0392b'),
    ('Education',        '#1abc9c'),
    ('Travel',           '#3498db'),
    ('Salary',           '#27ae60'),
    ('Investment',       '#8e44ad'),
    ('Transfer',         '#7f8c8d'),
    ('Other',            '#95a5a6');
