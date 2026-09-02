-- Migration 08: Audit log + soft delete on transactions

-- Soft-delete column on transactions (NULL = live, ISO datetime = deleted)
ALTER TABLE transactions ADD COLUMN deleted_at TEXT DEFAULT NULL;

-- Audit log: one row per meaningful action
CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    action      TEXT    NOT NULL,           -- e.g. tx_add, tx_edit, tx_delete, bank_add ...
    entity_type TEXT    NOT NULL,           -- transaction | bank | wallet | transfer | recurring | category
    entity_id   INTEGER,                    -- PK of the affected row (NULL for bulk/indirect)
    summary     TEXT    NOT NULL DEFAULT '',-- human-readable one-line description
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC);
