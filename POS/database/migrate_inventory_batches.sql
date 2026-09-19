-- ============================================================
-- Migration: Inventory Batches, Shelf Life, and Missing Metadata
-- Run this against kofeedb.
-- ============================================================

-- 1. Category shelf life
ALTER TABLE ingredient_categories
    ADD COLUMN IF NOT EXISTS shelf_life_days INT NOT NULL DEFAULT 30;

UPDATE ingredient_categories SET shelf_life_days = 30 WHERE name = 'Coffee';
UPDATE ingredient_categories SET shelf_life_days = 7  WHERE name = 'Milk';
UPDATE ingredient_categories SET shelf_life_days = 90 WHERE name = 'Syrups';
UPDATE ingredient_categories SET shelf_life_days = 60 WHERE name = 'Tea';
UPDATE ingredient_categories SET shelf_life_days = 5  WHERE name = 'Bakery';
UPDATE ingredient_categories SET shelf_life_days = 30 WHERE name = 'Other';

-- 2. Ingredient reorder & supplier metadata
ALTER TABLE ingredients
    ADD COLUMN IF NOT EXISTS default_supplier_id INT NULL,
    ADD COLUMN IF NOT EXISTS reorder_quantity DECIMAL(10,2) NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS auto_reorder TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_auto_reorder_at DATETIME NULL;

-- 3. Ingredient batches tracking
CREATE TABLE IF NOT EXISTS ingredient_batches (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id  INT NOT NULL,
    grn_id         INT NULL,
    po_id          INT NULL,
    supplier_id    INT NULL,
    batch_ref      VARCHAR(100) NULL,
    qty_received   DECIMAL(10,2) NOT NULL DEFAULT 0,
    qty_remaining  DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit           VARCHAR(20) NOT NULL DEFAULT 'pcs',
    delivery_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiry_date    DATE NULL,
    expiry_source  ENUM('auto', 'manual') NOT NULL DEFAULT 'auto',
    status         ENUM('active', 'expired', 'depleted', 'discarded') NOT NULL DEFAULT 'active',
    notes          TEXT NULL,
    recorded_by    INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_batch_ingredient (ingredient_id),
    INDEX idx_batch_status (status),
    INDEX idx_batch_expiry (expiry_date),
    CONSTRAINT fk_batch_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial opening batches for items that currently have stock
INSERT INTO ingredient_batches
    (ingredient_id, batch_ref, qty_received, qty_remaining, unit, delivery_date, expiry_date, expiry_source, status, notes)
SELECT
    i.id,
    CONCAT('INIT-', i.id),
    i.quantity,
    i.quantity,
    i.unit,
    NOW(),
    DATE_ADD(CURDATE(), INTERVAL COALESCE(ic.shelf_life_days, 30) DAY),
    'auto',
    'active',
    'Opening balance migrated from inventory'
FROM ingredients i
JOIN ingredient_categories ic ON ic.id = i.cat_id
WHERE i.quantity > 0
  AND i.archived_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM ingredient_batches b WHERE b.ingredient_id = i.id);

-- 4. Requisition linkage for auto-reordering
ALTER TABLE purchase_requisitions
    ADD COLUMN IF NOT EXISTS source VARCHAR(40) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS source_ingredient_id INT NULL;

ALTER TABLE requisition_items
    ADD COLUMN IF NOT EXISTS ingredient_id INT NULL;

-- 5. Notifications audit columns
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS actor_id INT NULL,
    ADD COLUMN IF NOT EXISTS action_type VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS entity_id INT NULL;

-- 6. RBAC permissions
INSERT IGNORE INTO permissions (perm_key, label, category, description)
VALUES ('inventory.expiry.manage', 'Manage Batch Expiry Dates', 'Inventory', 'Can update batch expiry and delivery dates or write off batches');

INSERT IGNORE INTO role_permissions (role, perm_key) VALUES
('admin', 'inventory.expiry.manage'),
('manager', 'inventory.expiry.manage');
