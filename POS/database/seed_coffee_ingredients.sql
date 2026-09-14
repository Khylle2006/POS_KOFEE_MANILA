-- Standard coffee ingredient catalog based on the supplied coffee guide.
-- Run ingredients_setup.sql first. This file is safe to run more than once.

INSERT INTO ingredients (cat_id, name, brand, unit, quantity, reorder_at)
SELECT c.id, s.name, NULL, s.unit, 0, s.reorder_at
FROM ingredient_categories c
JOIN (
    SELECT 'Espresso' AS name, 'ml' AS unit, 1000 AS reorder_at
    UNION ALL SELECT 'Brewed Coffee', 'ml', 2000
    UNION ALL SELECT 'Water', 'ml', 5000
    UNION ALL SELECT 'Ice', 'g', 5000
    UNION ALL SELECT 'Whole Milk', 'ml', 2000
    UNION ALL SELECT 'Condensed Milk', 'ml', 1000
    UNION ALL SELECT 'Heavy Cream', 'ml', 1000
    UNION ALL SELECT 'Whipped Cream', 'g', 500
    UNION ALL SELECT 'Chocolate Sauce', 'ml', 500
    UNION ALL SELECT 'White Chocolate Sauce', 'ml', 500
    UNION ALL SELECT 'Caramel Syrup', 'ml', 500
    UNION ALL SELECT 'Vanilla Syrup', 'ml', 500
    UNION ALL SELECT 'Irish Cream Syrup', 'ml', 500
    UNION ALL SELECT 'Matcha Powder', 'g', 250
    UNION ALL SELECT 'Cocoa Powder', 'g', 250
    UNION ALL SELECT 'Cinnamon Powder', 'g', 100
    UNION ALL SELECT 'Brown Sugar', 'g', 500
    UNION ALL SELECT 'Sugar', 'g', 1000
    UNION ALL SELECT 'Ice Cream', 'g', 1000
) s
WHERE c.name = CASE
    WHEN s.name IN ('Espresso', 'Brewed Coffee') THEN 'Coffee'
    WHEN s.name IN ('Whole Milk', 'Condensed Milk', 'Heavy Cream', 'Ice Cream') THEN 'Milk'
    WHEN s.name IN ('Chocolate Sauce', 'White Chocolate Sauce', 'Caramel Syrup', 'Vanilla Syrup', 'Irish Cream Syrup') THEN 'Syrups'
    WHEN s.name = 'Matcha Powder' THEN 'Tea'
    ELSE 'Other'
END
AND NOT EXISTS (
    SELECT 1 FROM ingredients i
    WHERE i.name = s.name AND i.archived_at IS NULL
);

-- Set the starting quantity manually from Inventory after receiving stock.
-- Recipe rows belong in product_ingredients and must use your real product IDs.
-- Example:
-- INSERT INTO product_ingredients (product_id, ingredient_id, size, qty_used)
-- SELECT p.id, i.id, 'small', 200
-- FROM products p JOIN ingredients i ON i.name = 'Ice'
-- WHERE p.name = 'Iced Caramel Macchiato';
