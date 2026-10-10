<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ingredient_deduction.php';
require_once __DIR__ . '/paymongo.php';

function money_centavos(mixed $value): int
{
    if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', (string)$value, $match)) {
        throw new SecurityFault('AMOUNT_INVALID', 'Amounts must be non-negative with at most two decimal places.');
    }
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
}

function money_decimal(int $centavos): string
{
    return intdiv($centavos, 100) . '.' . str_pad((string)($centavos % 100), 2, '0', STR_PAD_LEFT);
}

function positive_quantity(mixed $value, int $maximum = 999): int
{
    if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1 || (int)$value > $maximum) {
        throw new SecurityFault('QUANTITY_INVALID', 'A positive whole quantity within the allowed limit is required.');
    }
    return (int)$value;
}

function canonical_payload(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }
    return array_map('canonical_payload', $value);
}

/** @param array<string, mixed> $payload @return array<string, mixed>|null */
function begin_idempotency(PDO $pdo, string $scope, int $actor, string $key, array $payload): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
        throw new SecurityFault('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key between 16 and 100 characters is required.', 400);
    }
    unset($payload['_csrf'], $payload['csrf_token']);
    $hash = hash('sha256', json_encode(canonical_payload($payload), JSON_THROW_ON_ERROR));
    $args = [$scope, $actor, hash('sha256', $key)];
    $pdo->prepare('INSERT IGNORE INTO request_idempotency (scope, actor_id, key_hash, payload_hash) VALUES (?, ?, ?, ?)')->execute([...$args, $hash]);
    $stmt = $pdo->prepare('SELECT payload_hash, response FROM request_idempotency WHERE scope = ? AND actor_id = ? AND key_hash = ? FOR UPDATE');
    $stmt->execute($args);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['payload_hash'], $hash)) {
        throw new SecurityFault('IDEMPOTENCY_CONFLICT', 'This request key was already used for different input.', 409);
    }
    return $row['response'] !== null ? json_decode($row['response'], true, 64, JSON_THROW_ON_ERROR) : null;
}

/** @param array<string, mixed> $response */
function finish_idempotency(PDO $pdo, string $scope, int $actor, string $key, array $response): void
{
    $pdo->prepare('UPDATE request_idempotency SET response = ? WHERE scope = ? AND actor_id = ? AND key_hash = ?')
        ->execute([json_encode($response, JSON_THROW_ON_ERROR), $scope, $actor, hash('sha256', $key)]);
}

/** @param array<string, mixed> $data @return array{items:list<array<string,mixed>>,total:int} */
function validate_order_items(PDO $pdo, array $data): array
{
    $items = $data['items'] ?? null;
    if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 100) {
        throw new SecurityFault('CART_INVALID', 'Provide between 1 and 100 cart items.');
    }
    $ids = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new SecurityFault('CART_INVALID', 'Invalid cart item.');
        }
        $ids[] = positive_quantity($item['id'] ?? null, 2147483647);
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, price_small, price_large, price FROM products WHERE id IN ($placeholders) AND is_deleted = 0 ORDER BY id FOR UPDATE");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $product) {
        $products[(int)$product['id']] = $product;
    }
    $total = 0;
    $validated = [];
    foreach ($items as $item) {
        $product = $products[(int)$item['id']] ?? null;
        $size = $item['size'] ?? null;
        if (!$product || !in_array($size, ['small', 'large'], true)) {
            throw new SecurityFault('PRODUCT_INVALID', 'An item or size is unavailable.');
        }
        $quantity = positive_quantity($item['qty'] ?? null);
        $small = money_centavos($product['price_small'] ?? 0);
        $large = money_centavos($product['price_large'] ?? 0);
        $price = $size === 'large' ? ($large > 0 ? $large : $small) : ($small > 0 ? $small : money_centavos($product['price'] ?? 0));
        if ($price <= 0) {
            throw new SecurityFault('PRICE_INVALID', 'An item has no valid price.');
        }
        $subtotal = $price * $quantity;
        $total += $subtotal;
        if ($total > 999999999999) {
            throw new SecurityFault('AMOUNT_INVALID', 'Order total exceeds the allowed limit.');
        }
        $validated[] = ['id' => (int)$item['id'], 'qty' => $quantity, 'size' => $size, 'price' => $price, 'subtotal' => $subtotal, 'name' => $product['name']];
    }
    return ['items' => $validated, 'total' => $total];
}

/** @param array<string, mixed> $data */
function validate_cart_stock(PDO $pdo, array $data): void
{
    $cart = validate_order_items($pdo, $data);
    $ids = array_values(array_unique(array_column($cart['items'], 'id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT pi.product_id, pi.size, pi.ingredient_id, pi.qty_used, i.name, i.quantity
        FROM product_ingredients pi JOIN ingredients i ON i.id = pi.ingredient_id
        WHERE pi.product_id IN ($placeholders)");
    $stmt->execute($ids);
    $recipes = [];
    foreach ($stmt->fetchAll() as $row) {
        $recipes[$row['product_id'] . '_' . $row['size']][] = $row;
    }
    $requirements = [];
    foreach ($cart['items'] as $item) {
        foreach ($recipes[$item['id'] . '_' . $item['size']] ?? [] as $ingredient) {
            $used = (float)$ingredient['qty_used'];
            if ($used <= 0) {
                throw new SecurityFault('RECIPE_INVALID', 'Recipe quantities must be positive.', 503);
            }
            $id = (int)$ingredient['ingredient_id'];
            if (!isset($requirements[$id])) {
                $requirements[$id] = ['required' => 0.0, 'available' => (float)$ingredient['quantity'], 'name' => $ingredient['name']];
            }
            // Different drinks and sizes may consume the same ingredient.
            $requirements[$id]['required'] += $used * $item['qty'];
        }
    }
    foreach ($requirements as $ingredient) {
        // Decimal recipes can accumulate floating-point noise at an exact stock limit.
        if (round($ingredient['required'], 8) > $ingredient['available']) {
            throw new SecurityFault('STOCK_INSUFFICIENT',
                sprintf('Insufficient %s stock for this order (need %.2f, have %.2f).', $ingredient['name'], $ingredient['required'], $ingredient['available']), 409);
        }
    }
}

/** @param array<string, mixed> $data @return array<string, mixed> */
function submit_order(PDO $pdo, array $data, int $actor, string $key, bool $gateway = false): array
{
    $scope = $gateway ? 'order-gateway' : 'order-manual';
    $pdo->beginTransaction();
    try {
        $previous = begin_idempotency($pdo, $scope, $actor, $key, $data);
        if ($previous !== null) {
            $pdo->commit();
            return $previous;
        }
        $cart = validate_order_items($pdo, $data);
        $method = $gateway ? 'paymongo' : ($data['payment_method'] ?? 'cash');
        if (!$gateway && !in_array($method, ['cash', 'ewallet', 'card'], true)) {
            throw new SecurityFault('PAYMENT_METHOD_INVALID', 'Invalid payment method.');
        }
        $tendered = $gateway ? null : money_centavos($data['amount_tendered'] ?? ($method === 'cash' ? null : money_decimal($cart['total'])));
        if (!$gateway && $tendered < $cart['total']) {
            throw new SecurityFault('TENDER_INSUFFICIENT', 'Amount tendered is less than the order total.');
        }
        $reference = $data['payment_reference'] ?? '';
        if (!is_string($reference) || strlen($reference) > 100 || (!$gateway && $method !== 'cash' && trim($reference) === '')) {
            throw new SecurityFault('PAYMENT_REFERENCE_INVALID', 'A valid payment reference is required.');
        }
        $employee = $pdo->prepare('SELECT id FROM employees WHERE user_id = ? LIMIT 1');
        $employee->execute([$actor]);
        $pdo->prepare("INSERT INTO orders (user_id, employee_id, total_amount, payment_method, status, payment_status, amount_tendered, change_amount, payment_reference, stock_deducted, created_at, placed_at)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, 0, NOW(), NOW())")
            ->execute([$actor, $employee->fetchColumn() ?: null, money_decimal($cart['total']), $method, $gateway ? 'pending' : 'paid',
                $tendered === null ? null : money_decimal($tendered), $tendered === null ? null : money_decimal($tendered - $cart['total']), trim($reference) ?: null]);
        $orderId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, price, subtotal, size) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($cart['items'] as $item) {
            $stmt->execute([$orderId, $item['id'], $item['qty'], money_decimal($item['price']), money_decimal($item['subtotal']), $item['size']]);
        }
        deduct_order_ingredients($pdo, $orderId, $actor);
        require_once __DIR__ . '/jobs.php';
        enqueue_job($pdo, 'inventory-reorder-' . $orderId, 'inventory_reorder', ['order_id' => $orderId, 'actor_id' => $actor]);
        security_audit($pdo, 'order_submitted', 'order', $orderId, ['status' => $gateway ? 'pending' : 'paid']);
        $timeStmt = $pdo->prepare('SELECT created_at, placed_at FROM orders WHERE id = ?');
        $timeStmt->execute([$orderId]);
        $orderTime = $timeStmt->fetch();
        $response = ['success' => true, 'order_id' => $orderId,
            'created_at' => $orderTime['created_at'], 'placed_at' => $orderTime['placed_at']];
        if ($gateway) {
            $response += ['pending' => true, 'checkout_url' => '', 'session_id' => '', 'is_demo' => paymongo_is_demo()];
            $pdo->prepare("INSERT INTO payment_attempts (operation_key, entity_type, entity_id, status) VALUES (?, 'order', ?, 'processing')")
                ->execute(['checkout-' . $orderId, $orderId]);
        }
        finish_idempotency($pdo, $scope, $actor, $key, $response);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    invalidate_order_caches();
    if (!$gateway) {
        return $response;
    }
    // External requests occur after the local order is durable, never while holding stock locks.
    $lineItems = array_map(static fn(array $item): array => ['currency' => 'PHP', 'amount' => $item['price'], 'name' => $item['name'] . ' (' . $item['size'] . ')', 'quantity' => $item['qty']], $cart['items']);
    $base = app_url();
    $result = paymongo_request('checkout_sessions', ['send_email_receipt' => false, 'show_description' => true,
        'description' => 'Kofee Manila Order #' . $orderId, 'line_items' => $lineItems, 'payment_method_types' => ['gcash', 'paymaya', 'card'],
        'reference_number' => (string)$orderId, 'success_url' => $base . '/php/menu.php?paymongo_success=1&order_id=' . $orderId,
        'cancel_url' => $base . '/php/menu.php?paymongo_cancel=1&order_id=' . $orderId], 'POST');
    $session = $result['data']['id'] ?? null;
    if ($result['success'] && is_string($session) && $session !== '') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE orders SET paymongo_session_id = ? WHERE id = ?')->execute([$session, $orderId]);
            $pdo->prepare("UPDATE payment_attempts SET status = 'submitted', provider_id = ? WHERE operation_key = ?")->execute([$session, 'checkout-' . $orderId]);
            $response = ['success' => true, 'order_id' => $orderId, 'session_id' => $session, 'checkout_url' => $result['data']['attributes']['checkout_url'] ?? '', 'is_demo' => paymongo_is_demo()];
            finish_idempotency($pdo, $scope, $actor, $key, $response);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    } elseif (empty($result['unknown']) && in_array($result['code'] ?? 0, [400, 401, 403, 404, 422], true)) {
        $pdo->beginTransaction();
        try {
            restore_order_stock($pdo, $orderId);
            $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE payment_attempts SET status = 'rejected' WHERE operation_key = ?")->execute(['checkout-' . $orderId]);
            $response = ['success' => false, 'ok' => false, 'code' => 'PAYMENT_REJECTED', 'error' => 'Provider rejected checkout creation.', 'order_id' => $orderId];
            finish_idempotency($pdo, $scope, $actor, $key, $response);
            $pdo->commit();
        } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
        invalidate_order_caches();
    } else {
        $pdo->prepare("UPDATE payment_attempts SET status = 'unknown' WHERE operation_key = ?")->execute(['checkout-' . $orderId]);
        security_audit($pdo, 'payment_reconciliation_required', 'order', $orderId, ['reason_code' => 'CHECKOUT_OUTCOME_UNKNOWN']);
    }
    require_once __DIR__ . '/jobs.php';
    enqueue_job($pdo, 'reconcile-order-' . $orderId, 'reconcile_order', ['order_id' => $orderId]);
    return $response;
}

function invalidate_order_caches(): void
{
    require_once __DIR__ . '/cache.php';
    foreach (['analytics_', 'sidebar_badges', 'pos_menu', 'ingredients_'] as $prefix) {
        cache_delete_pattern($prefix);
    }
}

/** A caller must lock the order in its transaction before restoring the recorded usage. */
function restore_order_stock(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare('SELECT ingredients_deducted_at, stock_restored_at FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['ingredients_deducted_at'] === null || $order['stock_restored_at'] !== null) {
        return;
    }
    $stmt = $pdo->prepare('SELECT ingredient_id, SUM(used_qty) AS qty FROM ingredient_usage_log WHERE order_id = ? GROUP BY ingredient_id ORDER BY ingredient_id');
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll() as $usage) {
        $pdo->prepare('SELECT id FROM ingredients WHERE id = ? FOR UPDATE')->execute([$usage['ingredient_id']]);
        $pdo->prepare('UPDATE ingredients SET quantity = quantity + ? WHERE id = ?')->execute([$usage['qty'], $usage['ingredient_id']]);
    }
    $pdo->prepare('UPDATE orders SET stock_restored_at = NOW(), stock_deducted = 0 WHERE id = ?')->execute([$orderId]);
    security_audit($pdo, 'stock_restored', 'order', $orderId);
}

function finalize_order_payment(PDO $pdo, int $orderId, string $paymentId): void
{
    $stmt = $pdo->prepare('SELECT status, payment_status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['payment_status'] === 'paid') {
        return;
    }
    if ($order['status'] === 'cancelled' || $order['payment_status'] === 'failed') {
        security_audit($pdo, 'late_payment_requires_review', 'order', $orderId, ['reason_code' => 'LATE_PAYMENT']);
        $pdo->prepare("UPDATE payment_attempts SET status = 'reconciliation_required', provider_id = ? WHERE operation_key = ?")->execute([$paymentId, 'checkout-' . $orderId]);
        return;
    }
    // Payment settles the bill; staff complete kitchen preparation separately.
    $pdo->prepare("UPDATE orders SET status = 'pending', payment_status = 'paid', paymongo_payment_id = ?, payment_reference = ?, amount_tendered = total_amount, change_amount = 0 WHERE id = ?")
        ->execute([$paymentId ?: null, $paymentId ?: null, $orderId]);
    $pdo->prepare("UPDATE payment_attempts SET status = 'paid' WHERE operation_key = ?")->execute(['checkout-' . $orderId]);
    security_audit($pdo, 'payment_confirmed', 'order', $orderId, ['status' => 'paid']);
}
