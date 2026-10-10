<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrivateStorageConfigurationTest extends TestCase
{
    private string $root;
    private string|false $previousRoot;
    private string|false $previousWebRoot;

    protected function setUp(): void
    {
        $this->previousRoot = getenv('PRIVATE_STORAGE_ROOT');
        $this->previousWebRoot = getenv('WEB_DOCUMENT_ROOT');
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kofee-private-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0700)) throw new RuntimeException('Cannot create private storage fixture.');
        putenv('PRIVATE_STORAGE_ROOT=' . $this->root);
        putenv('WEB_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        foreach (['supplier_permits', 'backups'] as $kind) {
            $directory = $this->root . DIRECTORY_SEPARATOR . $kind;
            if (is_dir($directory)) rmdir($directory);
        }
        if (is_dir($this->root)) rmdir($this->root);
        putenv($this->previousRoot === false ? 'PRIVATE_STORAGE_ROOT' : 'PRIVATE_STORAGE_ROOT=' . $this->previousRoot);
        putenv($this->previousWebRoot === false ? 'WEB_DOCUMENT_ROOT' : 'WEB_DOCUMENT_ROOT=' . $this->previousWebRoot);
    }

    public function testUnconfiguredStorageFailsExplicitly(): void
    {
        putenv('PRIVATE_STORAGE_ROOT=');
        try {
            private_upload_directory('supplier_permits');
            $this->fail('Unconfigured storage must not fall back to public uploads.');
        } catch (SecurityFault $error) {
            $this->assertSame('STORAGE_NOT_CONFIGURED', $error->errorCode);
            $this->assertSame(503, $error->status);
        }
    }

    public function testConfiguredSupplierDocumentsAreWritableOutsideTheWebRoot(): void
    {
        $directory = private_upload_directory('supplier_permits');
        $this->assertTrue(path_within($directory, (string)realpath($this->root)));
        $this->assertFalse(path_within($directory, dirname(__DIR__, 2)));
        $file = $directory . DIRECTORY_SEPARATOR . 'write-check.txt';
        try {
            $this->assertSame(5, file_put_contents($file, 'probe'));
            $this->assertSame('probe', file_get_contents($file));
            $this->assertSame($directory, private_upload_directory('supplier_permits'));
        } finally {
            if (is_file($file)) unlink($file);
        }
    }

    public function testStorageInsideTheWebRootRemainsBlocked(): void
    {
        putenv('WEB_DOCUMENT_ROOT=' . $this->root);
        try {
            private_upload_directory('supplier_permits');
            $this->fail('Supplier documents must never be publicly served.');
        } catch (SecurityFault $error) {
            $this->assertSame('STORAGE_CONFIGURATION_INVALID', $error->errorCode);
        }
    }
}
