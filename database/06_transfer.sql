-- Migration 06: Transfer transaction support
-- Adds transfer_pair_id to transactions so two rows (expense + income) can be linked
-- as a single bank-to-bank transfer. Both legs have wallet_id = NULL.

ALTER TABLE transactions ADD COLUMN transfer_pair_id INTEGER DEFAULT NULL;
