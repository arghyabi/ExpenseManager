-- Migration 09: Bill Reminders
-- Periodic payment reminders (credit card bill, electricity, water, etc.)
-- Notifications appear from notify_day of each month (or year).

CREATE TABLE IF NOT EXISTS bill_reminders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    title            TEXT    NOT NULL,
    type             TEXT    NOT NULL DEFAULT 'expense' CHECK(type IN ('income','expense')),
    default_amount   REAL    NOT NULL DEFAULT 0,
    wallet_id        INTEGER REFERENCES wallets(id) ON DELETE SET NULL,
    payment_bank_id  INTEGER REFERENCES banks(id)   ON DELETE SET NULL,
    category_id      INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    note             TEXT    DEFAULT '',
    frequency        TEXT    NOT NULL DEFAULT 'monthly' CHECK(frequency IN ('monthly','yearly')),
    notify_day       INTEGER NOT NULL DEFAULT 1,
    notify_month     INTEGER NOT NULL DEFAULT 1,
    active           INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT    NOT NULL DEFAULT (date('now'))
);
