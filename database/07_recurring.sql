-- Migration 07: Recurring transaction rules
-- Each rule is a template that auto-posts a real transaction whenever next_due <= today.
-- next_due is advanced by the engine on each trigger (lazy, no cron needed).

CREATE TABLE IF NOT EXISTS recurring_rules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    title            TEXT    NOT NULL,
    type             TEXT    NOT NULL CHECK(type IN ('income','expense')),
    amount           REAL    NOT NULL,
    wallet_id        INTEGER REFERENCES wallets(id) ON DELETE SET NULL,
    payment_bank_id  INTEGER REFERENCES banks(id)   ON DELETE SET NULL,
    category_id      INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    note             TEXT    DEFAULT '',
    frequency        TEXT    NOT NULL CHECK(frequency IN ('daily','weekly','monthly','yearly')),
    next_due         TEXT    NOT NULL,   -- ISO date YYYY-MM-DD
    active           INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT    NOT NULL DEFAULT (date('now'))
);
