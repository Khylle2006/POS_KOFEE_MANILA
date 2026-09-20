<?php

$local_config = __DIR__ . '/config.local.php';
if (is_file($local_config)) {
    require_once $local_config;
}

if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
}
if (!defined('PAYMONGO_WEBHOOK_SECRET')) {
    define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');
}

function paymongo_is_configured(): bool
{
    return PAYMONGO_SECRET_KEY !== '' && (strpos(PAYMONGO_SECRET_KEY, 'sk_') === 0 || strpos(PAYMONGO_SECRET_KEY, 'demo') === 0);
}

function paymongo_is_demo(): bool
{
    return str_starts_with(PAYMONGO_SECRET_KEY, 'demo') || PAYMONGO_SECRET_KEY === 'sandbox';
    return PAYMONGO_SECRET_KEY === ''
        || str_starts_with(PAYMONGO_SECRET_KEY, 'demo')
        || PAYMONGO_SECRET_KEY === 'sandbox'
        || (defined('APP_ENV') && APP_ENV === 'development' && !str_starts_with(PAYMONGO_SECRET_KEY, 'sk_'));
}

function paymongo_order_has_column(PDO $pdo, string $column): bool
{
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN, 0);
        $names = array_map('strtolower', $rows);
        return in_array(strtolower($column), $names, true);
    } catch (Throwable $e) {
        return false;
    }
}

function paymongo_ensure_order_columns(PDO $pdo): void
{
    $columns = [
        'paymongo_session_id' => 'VARCHAR(100) NULL',
        'paymongo_payment_id' => 'VARCHAR(100) NULL',
        'payment_status'      => "ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending'",
        'amount_tendered'     => 'DECIMAL(12,2) NULL',
        'change_amount'       => 'DECIMAL(12,2) NULL',
        'payment_reference'   => 'VARCHAR(100) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!paymongo_order_has_column($pdo, $column)) {
            try {
                $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $column . ' ' . $definition);
            } catch (Throwable $e) {
                error_log("paymongo_ensure_order_columns error for $column: " . $e->getMessage());
            }
        }
    }
}

function paymongo_request(string $endpoint, ?array $attributes = null, string $method = 'GET'): array
{
    if (paymongo_is_demo()) {
        // Safe local sandbox simulation for development & demonstration
        if (str_starts_with($endpoint, 'checkout_sessions')) {
            $demoId = 'cs_demo_' . bin2hex(random_bytes(8));
            return [
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'         => $demoId,
                    'type'       => 'checkout_session',
                    'attributes' => [
                        'status'              => 'active',
                        'checkout_url'        => 'https://test.paymongo.com/demo_checkout?session=' . $demoId,
                        'payment_method_used' => 'gcash',
                        'payments'            => []
                    ]
                ],
                'error'   => null
            ];
        }
    }

    if (!paymongo_is_configured()) {
        return ['success' => false, 'code' => 500, 'data' => null, 'error' => 'PayMongo secret key is not configured. Please set PAYMONGO_SECRET_KEY in includes/config.local.php.'];
    }

    $curl = curl_init('https://api.paymongo.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);

    // CA bundle detection for Windows XAMPP
    $caBundle = getenv('CURL_CA_BUNDLE') ?: dirname(__DIR__, 4) . '/apache/bin/curl-ca-bundle.crt';
    if (is_file($caBundle)) {
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    } else {
        $xamppCa = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        if (is_file($xamppCa)) {
            curl_setopt($curl, CURLOPT_CAINFO, $xamppCa);
        }
    }

    if ($attributes !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $attributes]]));
    }

    $body = curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $error !== '') {
        return ['success' => false, 'code' => $code ?: 500, 'data' => null, 'error' => $error ?: 'PayMongo connection failed.'];
    }

    $decoded = json_decode($body, true) ?: [];
    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'code' => $code, 'data' => $decoded['data'] ?? [], 'error' => null];
    }

    $errMsg = $decoded['errors'][0]['detail'] ?? ($decoded['error'] ?? 'PayMongo API error.');
    return ['success' => false, 'code' => $code, 'data' => $decoded, 'error' => $errMsg];
}
