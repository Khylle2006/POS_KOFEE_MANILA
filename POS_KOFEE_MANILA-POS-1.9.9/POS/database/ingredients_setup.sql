-- ============================================================
-- Inventory module — table setup
-- Run this ONCE against your kofeedb database.
--
-- Why you need this: the Inventory page (php/inventory.php)
-- reads/writes 3 tables — ingredient_categories, ingredients,
-- and restock_log — but they were never created in your MySQL
-- database yet (database/products.sql shipped empty). This is
-- what's causing:
--   "Base table or view not found: ingredient_categories"
--
-- How to run it:
--   phpMyAdmin > select kofeedb > SQL tab > paste this > Go
--   OR from a terminal:  mysql -u root kofeedb < this_file.sql
-- ============================================================

-- ── Categories ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ingredient_categories (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(100) NOT NULL,
    icon  VARCHAR(10)  NOT NULL DEFAULT '📦',
    UNIQUE KEY uniq_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ingredient_categories (name, icon) VALUES
    ('Coffee', '☕'),
    ('Milk',   '🥛'),
    ('Syrups', '🧊'),
    ('Tea',    '🍵'),
    ('Bakery', '🥐'),
    ('Other',  '📦');

-- ── Ingredients ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ingredients (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    cat_id       INT NOT NULL,
    name         VARCHAR(150)  NOT NULL,
    brand        VARCHAR(150)  NULL,
    unit         VARCHAR(20)   NOT NULL DEFAULT 'pcs',
    quantity     DECIMAL(10,2) NOT NULL DEFAULT 0,
    reorder_at   DECIMAL(10,2) NOT NULL DEFAULT 5,
    archived_at  DATETIME      NULL DEFAULT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ingredients_cat FOREIGN KEY (cat_id) REFERENCES ingredient_categories(id),
    INDEX idx_ingredients_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Restock history log ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS restock_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id  INT NOT NULL,
    added_qty      DECIMAL(10,2) NOT NULL,
    processed_by   INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_restock_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    CONSTRAINT fk_restock_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
