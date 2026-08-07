-- ============================================================
-- Adds soft-delete (archive) support to the ingredients table.
-- Run this once against your kofeedb database (e.g. via
-- phpMyAdmin > SQL tab, or `mysql -u root kofeedb < this_file`).
--
-- Items are no longer hard-deleted from Inventory: "Delete" now
-- sets archived_at to the current time, and archived items are
-- hidden from the normal (Active) inventory view. They can be
-- restored, or permanently purged from the Archived view.
-- ============================================================

ALTER TABLE ingredients
  ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER reorder_at;

CREATE INDEX idx_ingredients_archived ON ingredients (archived_at);
