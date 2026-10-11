<?php

/** Deducts the recipe ingredients at submission. Call inside a transaction. */
function deduct_order_ingredients(PDO $pdo, int $orderId, ?int $processedBy = null): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Ingredient deduction requires a transaction.');
    $orderStmt = $pdo->prepare('SELECT ingredients_deducted_at FROM orders WHERE id = :id FOR UPDATE');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found');
    }
    if ($order['ingredients_deducted_at'] !== null) {
        return;
    }

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT pi.ingredient_id, SUM(pi.qty_used * oi.quantity) AS required_qty
        FROM order_items oi
        JOIN product_ingredients pi
          ON pi.product_id = oi.product_id
         AND pi.size = oi.size
        WHERE oi.order_id = :order_id
        GROUP BY pi.ingredient_id
        ORDER BY pi.ingredient_id
        SQL);
    $stmt->execute([':order_id' => $orderId]);
    $requirements = $stmt->fetchAll();

    $lockIngredient = $pdo->prepare(
        'SELECT name, quantity FROM ingredients WHERE id = :id FOR UPDATE'
    );
    $updateIngredient = $pdo->prepare(
        'UPDATE ingredients SET quantity = quantity - :used WHERE id = :id'
    );
    $logUsage = $pdo->prepare(
        'INSERT INTO ingredient_usage_log (order_id, ingredient_id, used_qty, processed_by)
         VALUES (:order_id, :ingredient_id, :used_qty, :processed_by)'
    );

    foreach ($requirements as $requirement) {
        $ingredientId = (int)$requirement['ingredient_id'];
        $requiredQty = (float)$requirement['required_qty'];
        if ($requiredQty <= 0) throw new SecurityFault('RECIPE_INVALID', 'Recipe quantities must be positive.', 503);

        $lockIngredient->execute([':id' => $ingredientId]);
        $ingredient = $lockIngredient->fetch();
        if (!$ingredient) {
            throw new RuntimeException('Ingredient not found');
        }
        if ((float)$ingredient['quantity'] < $requiredQty) {
            throw new SecurityFault('STOCK_INSUFFICIENT',
                sprintf('Insufficient %s stock (need %.2f, have %.2f)', $ingredient['name'], $requiredQty, $ingredient['quantity'])
            );
        }

        $updateIngredient->execute([':used' => $requiredQty, ':id' => $ingredientId]);
        $logUsage->execute([
            ':order_id' => $orderId,
            ':ingredient_id' => $ingredientId,
            ':used_qty' => $requiredQty,
            ':processed_by' => $processedBy,
        ]);


    }

    $pdo->prepare('UPDATE orders SET ingredients_deducted_at = NOW(), stock_deducted = 1 WHERE id = :id')
        ->execute([':id' => $orderId]);
}
