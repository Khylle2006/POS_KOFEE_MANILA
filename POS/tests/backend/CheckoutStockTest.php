<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// SQLite exercises state changes without touching the application's MySQL data.
final class CheckoutTestConnection extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

final class CheckoutStockTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new CheckoutTestConnection('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price_small TEXT, price_large TEXT, price TEXT, is_deleted INTEGER)');
        $this->pdo->exec("INSERT INTO products VALUES (1, 'Coffee', '100.00', '120.00', '100.00', 0), (2, 'Latte', '110.00', '130.00', '110.00', 0)");
        $this->pdo->exec('CREATE TABLE ingredients (id INTEGER PRIMARY KEY, name TEXT, quantity NUMERIC)');
        $this->pdo->exec("INSERT INTO ingredients VALUES (1, 'Milk', 10)");
        $this->pdo->exec('CREATE TABLE product_ingredients (product_id INTEGER, size TEXT, ingredient_id INTEGER, qty_used NUMERIC)');
        $this->pdo->exec("INSERT INTO product_ingredients VALUES (1, 'small', 1, 2), (1, 'large', 1, 3), (2, 'small', 1, 4)");
    }

    private function item(int $id = 1, string $size = 'small', int $qty = 1): array
    {
        return ['id' => $id, 'size' => $size, 'qty' => $qty];
    }

    private function assertInsufficient(array $items): void
    {
        try {
            validate_cart_stock($this->pdo, ['items' => $items]);
            $this->fail('The proposed cart exceeds ingredient stock.');
        } catch (SecurityFault $error) {
            $this->assertSame('STOCK_INSUFFICIENT', $error->errorCode);
            $this->assertSame(409, $error->status);
        }
        $this->assertSame(10, $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testExactStockLimitIsAllowedWithoutDeductingStock(): void
    {
        validate_cart_stock($this->pdo, ['items' => [$this->item(qty: 5)]]);
        $this->assertSame(10, $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testAddingBeyondStockIsRejected(): void
    {
        $this->assertInsufficient([$this->item(qty: 6)]);
    }

    public function testSharedIngredientAcrossProductsIsSummed(): void
    {
        $this->assertInsufficient([$this->item(qty: 4), $this->item(id: 2)]);
    }

    public function testDifferentSizesAndDuplicateLinesAreSummed(): void
    {
        $this->assertInsufficient([$this->item(qty: 3), $this->item(size: 'large', qty: 2)]);
        $this->assertInsufficient([$this->item(qty: 3), $this->item(qty: 3)]);
    }

    public function testReducingCartFreesRoomForAnotherDrink(): void
    {
        $this->assertInsufficient([$this->item(qty: 4), $this->item(id: 2)]);
        validate_cart_stock($this->pdo, ['items' => [$this->item(qty: 3), $this->item(id: 2)]]);
        $this->assertSame(10, $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testFractionalRecipeAtExactStockLimitIsAllowed(): void
    {
        $this->pdo->exec('UPDATE ingredients SET quantity = 0.3');
        $this->pdo->exec('UPDATE product_ingredients SET qty_used = 0.1');
        validate_cart_stock($this->pdo, ['items' => [$this->item(qty: 3)]]);
        $this->assertEquals(0.3, $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testArchivedProductsAndInvalidQuantitiesAreRejected(): void
    {
        $this->pdo->exec('UPDATE products SET is_deleted = 1 WHERE id = 1');
        try {
            validate_cart_stock($this->pdo, ['items' => [$this->item()]]);
            $this->fail('An archived product cannot be added.');
        } catch (SecurityFault $error) {
            $this->assertSame('PRODUCT_INVALID', $error->errorCode);
        }
        $this->expectException(SecurityFault::class);
        validate_cart_stock($this->pdo, ['items' => [$this->item(id: 2, qty: 0)]]);
    }

    public function testPayMongoPaymentRemainsPendingAndReplayPreservesStaffCompletion(): void
    {
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, payment_status TEXT, total_amount NUMERIC, paymongo_payment_id TEXT, payment_reference TEXT, amount_tendered NUMERIC, change_amount NUMERIC)');
        $this->pdo->exec("INSERT INTO orders (id, status, payment_status, total_amount) VALUES (1, 'pending', 'pending', 100)");
        $this->pdo->exec('CREATE TABLE payment_attempts (operation_key TEXT, status TEXT, provider_id TEXT)');
        $this->pdo->exec("INSERT INTO payment_attempts VALUES ('checkout-1', 'submitted', NULL)");
        $this->pdo->exec('CREATE TABLE security_audit (actor_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, request_id TEXT, details TEXT)');
        $this->pdo->beginTransaction();
        finalize_order_payment($this->pdo, 1, 'pay_test_1');
        $this->pdo->commit();
        $this->assertSame(['status' => 'pending', 'payment_status' => 'paid'], $this->pdo->query('SELECT status, payment_status FROM orders')->fetch());
        $this->assertSame('paid', $this->pdo->query('SELECT status FROM payment_attempts')->fetchColumn());
        $this->pdo->exec("UPDATE orders SET status = 'completed' WHERE id = 1");
        $this->pdo->beginTransaction();
        finalize_order_payment($this->pdo, 1, 'pay_test_1');
        $this->pdo->commit();
        $this->assertSame('completed', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        $this->assertSame(1, $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }
}
