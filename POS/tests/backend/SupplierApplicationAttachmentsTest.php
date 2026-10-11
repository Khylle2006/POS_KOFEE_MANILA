<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/procurement_helpers.php';

use PHPUnit\Framework\TestCase;

final class SupplierApplicationAttachmentsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('NOW', fn(): string => date('Y-m-d H:i:s'));
        $this->pdo->exec('CREATE TABLE supplier_applications (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE supplier_application_attachments (application_id INTEGER, filename TEXT, file_path TEXT, file_size INTEGER CHECK (file_size > 0), created_at TEXT)');
    }

    public function testAdditionalDocumentsStayInTheApplicationTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO supplier_applications VALUES (1)');
        save_supplier_application_attachments($this->pdo, 1, [
            ['original_name' => 'certificate.pdf', 'stored_path' => 'uploads/supplier_permits/a.pdf', 'file_size' => 100],
            ['original_name' => 'quality.docx', 'stored_path' => 'uploads/supplier_permits/b.docx', 'file_size' => 200],
        ]);
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->commit();
        $this->assertSame(1, $this->pdo->query('SELECT COUNT(*) FROM supplier_applications')->fetchColumn());
        $this->assertSame(['certificate.pdf', 'quality.docx'], $this->pdo->query('SELECT filename FROM supplier_application_attachments')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(300, $this->pdo->query('SELECT SUM(file_size) FROM supplier_application_attachments')->fetchColumn());
    }

    public function testAttachmentFailureAllowsTheWholeApplicationToRollBack(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO supplier_applications VALUES (1)');
        try {
            save_supplier_application_attachments($this->pdo, 1, [
                ['original_name' => 'one.pdf', 'stored_path' => 'uploads/supplier_permits/a.pdf', 'file_size' => 100],
                ['original_name' => 'two.pdf', 'stored_path' => 'uploads/supplier_permits/b.pdf', 'file_size' => 0],
            ]);
            $this->fail('Storage errors must not be swallowed.');
        } catch (PDOException $exception) {
            $this->assertTrue($this->pdo->inTransaction());
            $this->pdo->rollBack();
        }
        $this->assertSame(0, $this->pdo->query('SELECT COUNT(*) FROM supplier_applications')->fetchColumn());
        $this->assertSame(0, $this->pdo->query('SELECT COUNT(*) FROM supplier_application_attachments')->fetchColumn());
    }

    public function testNoAdditionalDocumentsStillAllowsApplicationCommit(): void
    {
        $this->pdo->beginTransaction();
        save_supplier_application_attachments($this->pdo, 1, []);
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->commit();
        $this->assertSame(0, $this->pdo->query('SELECT COUNT(*) FROM supplier_application_attachments')->fetchColumn());
    }

    public function testAttachmentsCannotBeSavedOutsideATransaction(): void
    {
        $this->expectException(LogicException::class);
        save_supplier_application_attachments($this->pdo, 1, []);
    }
}
