<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrivateUploadTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kofee-upload-');
        if ($path === false) throw new RuntimeException('Cannot create an upload test fixture.');
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) unlink($this->path);
    }

    private function archive(bool $includeDocument = true): ?ZipArchive
    {
        if (!class_exists('ZipArchive')) {
            file_put_contents($this->path, "PK\x03\x04" . str_repeat("\0", 100));
            try {
                validate_private_file($this->path, 'docx', 5 * 1024 * 1024);
                $this->fail('DOCX cannot be accepted without archive validation.');
            } catch (SecurityFault $error) {
                $this->assertSame('FILE_VALIDATION_UNAVAILABLE', $error->errorCode);
                $this->assertSame(503, $error->status);
            }
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create an archive fixture.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        if ($includeDocument) $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        return $zip;
    }

    public function testValidDocxIsAccepted(): void
    {
        $zip = $this->archive();
        if ($zip === null) return;
        $zip->close();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', validate_private_file($this->path, 'docx', 5 * 1024 * 1024)['mime']);
    }

    public function testDocxWithoutRequiredStructureIsRejected(): void
    {
        $zip = $this->archive(false);
        if ($zip === null) return;
        $zip->close();
        $this->expectException(SecurityFault::class);
        validate_private_file($this->path, 'docx', 5 * 1024 * 1024);
    }

    public function testMimeRecognizedDocxWithTooManyEntriesIsRejected(): void
    {
        $zip = $this->archive();
        if ($zip === null) return;
        for ($index = 0; $index < 1000; $index++) $zip->addFromString('word/file' . $index . '.xml', '<a/>');
        $zip->close();
        $this->expectException(SecurityFault::class);
        validate_private_file($this->path, 'docx', 5 * 1024 * 1024);
    }

    public function testCompressedDocxWithExcessiveExpandedContentIsRejected(): void
    {
        $zip = $this->archive();
        if ($zip === null) return;
        for ($index = 0; $index < 51; $index++) $zip->addFromString('word/file' . $index . '.xml', str_repeat('a', 1024 * 1024));
        $zip->close();
        $this->expectException(SecurityFault::class);
        validate_private_file($this->path, 'docx', 5 * 1024 * 1024);
    }

    public function testOversizedImageIsRejected(): void
    {
        // A valid GIF header is enough for dimension checks; no large pixel buffer is allocated.
        file_put_contents($this->path, 'GIF89a' . pack('vv', 4097, 1) . "\x80\0\0" . str_repeat("\0", 6) . ';');
        $this->expectException(SecurityFault::class);
        validate_private_file($this->path, 'gif', 5 * 1024 * 1024);
    }
}
