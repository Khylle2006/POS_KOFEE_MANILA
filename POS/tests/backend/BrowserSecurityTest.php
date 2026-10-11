<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BrowserSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_URL=https://example.test/POS');
        secure_session_start();
        $_SESSION['_csrf_token'] = 'browser-bound-token';
    }

    public static function formActions(): array
    {
        return [
            ['<form method="post">', true],
            ["<form method='POST' action='../api/checkout.php'>", true],
            ['<form method=post action=https://example.test/POS/api/checkout.php>', true],
            ['<form method="post" action="//example.test/POS/api/checkout.php">', true],
            ['<form method="post" action="https://external.test/submit">', false],
            ['<form method="post" action="//external.test/submit">', false],
            ['<form method="post" action="http://example.test/submit">', false],
            ['<form method="post" action="https://example.test:8443/submit">', false],
            ['<form method="post" action="javascript:alert(1)">', false],
            ['<form method="post" action="https://example.test@external.test/submit">', false],
            ['<form method="get" action="/submit">', false],
            ['<form method="postscript" action="/submit">', false],
        ];
    }

    #[DataProvider('formActions')]
    public function testTokensAreInjectedOnlyIntoSameOriginPostForms(string $form, bool $local): void
    {
        $result = browser_security_output('<html><head></head><body>' . $form . '<button>Submit</button></form></body></html>');
        $this->assertSame($local ? 1 : 0, substr_count($result, 'name="_csrf"'));
        $this->assertSame($local ? 1 : 0, substr_count($result, 'name="idempotency_key"'));
    }

    public function testExternalSubmitButtonCannotReceiveServerInjectedTokens(): void
    {
        $result = browser_security_output('<head></head><form method="post"><button formaction="https://external.test/submit">Submit</button></form>');
        $this->assertStringNotContainsString('name="_csrf"', $result);
    }

    public function testExistingFieldsArePreservedAndDifferentFormsHaveDifferentKeys(): void
    {
        $form = '<form method="post"><button>Submit</button></form>';
        $result = browser_security_output('<head></head>' . $form . $form);
        preg_match_all('/name="idempotency_key" value="([^"]+)"/', $result, $keys);
        $this->assertCount(2, $keys[1]);
        $this->assertNotSame($keys[1][0], $keys[1][1]);
        $existing = browser_security_output('<head></head><form method="post">' . csrf_field() . '<input name="idempotency_key" value="existing-key"></form>');
        $this->assertSame(1, substr_count($existing, 'name="_csrf"'));
        $this->assertSame(1, substr_count($existing, 'name="idempotency_key"'));
        $this->assertStringContainsString('value="existing-key"', $existing);
    }

    public function testCsrfIsBoundToTheCurrentSessionAndRejectsArrays(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf' => 'browser-bound-token'];
        $this->assertTrue(csrf_verify());
        $_POST = ['csrf_token' => 'browser-bound-token'];
        $this->assertTrue(csrf_verify());
        $_POST = ['_csrf' => 'another-session-token'];
        $this->assertFalse(csrf_verify());
        $_POST = ['_csrf' => ['browser-bound-token']];
        $this->assertFalse(csrf_verify());
    }
}
