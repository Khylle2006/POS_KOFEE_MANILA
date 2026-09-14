-- Ingredient recipes and consumption tracking.
-- Run after ingredients_setup.sql.

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS ingredients_deducted_at DATETIME NULL DEFAULT NULL;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS size VARCHAR(10) NOT NULL DEFAULT 'small';

CREATE TABLE IF NOT EXISTS product_ingredients (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    product_id   INT NOT NULL,
    size         ENUM('small', 'large') NOT NULL,
    ingredient_id INT NOT NULL,
    qty_used     DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uniq_recipe_line (product_id, size, ingredient_id),
    CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    CONSTRAINT chk_recipe_quantity CHECK (qty_used > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingredient_usage_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT NOT NULL,
    ingredient_id INT NOT NULL,
    used_qty      DECIMAL(10,2) NOT NULL,
    processed_by  INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_order_ingredient (order_id, ingredient_id),
    CONSTRAINT fk_usage_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_usage_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    CONSTRAINT fk_usage_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;