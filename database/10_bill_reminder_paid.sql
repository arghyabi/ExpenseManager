-- Migration 10: Add last_paid_date to bill_reminders
-- Tracks the most recent cycle in which the bill was marked paid,
-- so the notification banner hides it for the rest of that cycle.

ALTER TABLE bill_reminders ADD COLUMN last_paid_date TEXT DEFAULT NULL;
