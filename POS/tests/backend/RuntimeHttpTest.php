<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RuntimeHttpTest extends TestCase
{
    private static mixed $server = null;
    private static mixed $log = null;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($socket === false) throw new RuntimeException('Cannot reserve a local test port.');
        self::$port = (int)substr((string)stream_socket_get_name($socket, false), strrpos((string)stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        self::$log = tmpfile();
        if (self::$log === false) throw new RuntimeException('Cannot open a test server log.');
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/runtime_http.php'],
            [['pipe', 'r'], self::$log, self::$log], $pipes, __DIR__);
        if (!is_resource(self::$server)) throw new RuntimeException('Cannot start the local HTTP test server.');
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $error, $message, 0.1);
            if ($connection !== false) { fclose($connection); return; }
            usleep(50000);
        }
        self::tearDownAfterClass();
        throw new RuntimeException('The local HTTP test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        if (is_resource(self::$log)) fclose(self::$log);
    }

    /** @return array{status:int,headers:string,body:array<string,mixed>|string} */
    private function request(?string $body = null, string $query = '', string $cookie = ''): array
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . '/' . $query);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
        if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        $raw = curl_exec($curl);
        if ($raw === false) throw new RuntimeException('Local HTTP request failed.');
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $length = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        $headers = substr($raw, 0, $length);
        $content = substr($raw, $length);
        return ['status' => $status, 'headers' => $headers, 'body' => str_contains(strtolower($headers), 'content-type: application/json')
            ? json_decode($content, true, 64, JSON_THROW_ON_ERROR) : $content];
    }

    public function testRuntimeEmitsSecurityHeadersAndRequestCorrelation(): void
    {
        $response = $this->request();
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Cache-Control: private, no-cache, no-store, must-revalidate', $response['headers']);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $response['headers']);
        $this->assertStringContainsString('X-Frame-Options: SAMEORIGIN', $response['headers']);
        $this->assertStringContainsString('Permissions-Policy: geolocation=(self), microphone=(), camera=(self)', $response['headers']);
        $this->assertStringNotContainsString('X-Powered-By:', $response['headers']);
        $this->assertStringContainsString('HttpOnly; SameSite=Lax', $response['headers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $response['body']['request_id']);
        $this->assertStringContainsString('X-Request-ID: ' . $response['body']['request_id'], $response['headers']);
    }

    public function testJsonCsrfAliasesWorkAndTheHandlerStillReceivesTheBody(): void
    {
        foreach (['_csrf', 'csrf_token'] as $field) {
            $response = $this->request(json_encode([$field => 'http-bound-token', 'value' => 'retained'], JSON_THROW_ON_ERROR));
            $this->assertSame(200, $response['status']);
            $this->assertSame('retained', $response['body']['value']);
        }
        $response = $this->request('{"_csrf":["http-bound-token"]}');
        $this->assertSame(419, $response['status']);
        $this->assertSame('CSRF_INVALID', $response['body']['code']);
    }

    public function testMalformedNonObjectAndOversizedJsonFailAtTheBoundary(): void
    {
        foreach (['{bad', '[]'] as $body) {
            $response = $this->request($body);
            $this->assertSame(400, $response['status']);
            $this->assertSame('JSON_INVALID', $response['body']['code']);
        }
        $response = $this->request('{"value":"' . str_repeat('a', 8 * 1024 * 1024) . '"}');
        $this->assertSame(413, $response['status']);
        $this->assertSame('PAYLOAD_TOO_LARGE', $response['body']['code']);
    }

    public function testUnexpectedExceptionReturnsASanitizedCorrelatedError(): void
    {
        $response = $this->request(null, '?action=exception');
        $this->assertSame(503, $response['status']);
        $this->assertSame('SERVICE_UNAVAILABLE', $response['body']['code']);
        $this->assertSame('Service temporarily unavailable.', $response['body']['error']);
        $this->assertStringContainsString('X-Request-ID: ' . $response['body']['request_id'], $response['headers']);
    }

    public function testLogoutGetPreservesTheSessionAndOnlyAValidPostSignsOut(): void
    {
        $seed = $this->request(null, '?action=logout_seed');
        $this->assertSame(1, preg_match('/Set-Cookie: ([^;\r\n]+)/i', $seed['headers'], $cookies));
        $cookie = $cookies[1];
        $page = $this->request(null, '?action=logout', $cookie);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('<form method="post" action="logout.php">', $page['body']);
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $tokens));
        $this->assertSame('retained', $this->request(null, '?action=logout_state', $cookie)['body']['probe']);

        foreach (['{}', '{"_csrf":"invalid"}'] as $body) {
            $denied = $this->request($body, '?action=logout', $cookie);
            $this->assertSame(419, $denied['status']);
            $this->assertSame('CSRF_INVALID', $denied['body']['code']);
            $this->assertSame('retained', $this->request(null, '?action=logout_state', $cookie)['body']['probe']);
        }

        $logout = $this->request(json_encode(['_csrf' => $tokens[1]], JSON_THROW_ON_ERROR), '?action=logout', $cookie);
        $this->assertSame(303, $logout['status']);
        $this->assertStringContainsString('Location: ../index.html?reason=logout', $logout['headers']);
        $this->assertStringContainsString('Max-Age=0', $logout['headers']);
        $this->assertNull($this->request(null, '?action=logout_state', $cookie)['body']['probe']);
    }

    public function testDirectLogoutUrlOffersAConfirmationWithoutRequiringDatabaseAccess(): void
    {
        $page = $this->request(null, '?action=logout');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Log out of Kofee Manila?', $page['body']);
        $this->assertStringContainsString('name="_csrf"', $page['body']);
    }
}
