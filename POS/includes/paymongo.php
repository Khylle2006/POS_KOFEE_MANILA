<?php
require_once __DIR__ . '/db.php';

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
    $mode = payment_mode();
    return in_array($mode, ['test', 'live'], true)
        && str_starts_with(app_setting('PAYMONGO_SECRET_KEY'), $mode === 'live' ? 'sk_live_' : 'sk_test_');
}

function paymongo_is_demo(): bool
{
    return payment_mode() === 'demo' && !app_production();
}

function paymongo_get_base_url(): string
{
    return app_url();
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
    require_runtime_schema(get_db());
}

function paymongo_request(string $endpoint, ?array $attributes = null, string $method = 'GET'): array
{
    if (paymongo_is_demo()) {
        require_demo_payment();
        // Safe local sandbox simulation for development & demonstration
        if (str_starts_with($endpoint, 'checkout_sessions')) {
            // Check status of an existing session
            if (preg_match('#^checkout_sessions/(cs_demo_[a-zA-Z0-9_]+)#', $endpoint, $m)) {
                $sessId = $m[1];
                $isPaid = false;
                try {
                    $db = get_db();
                    $st = $db->prepare("SELECT payment_status, total_amount FROM orders WHERE paymongo_session_id = :sid LIMIT 1");
                    $st->execute([':sid' => $sessId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row && ($row['payment_status'] === 'paid')) {
                        $isPaid = true;
                    }
                } catch (Throwable $exception) {
                    throw new SecurityFault('PAYMENT_LOOKUP_UNAVAILABLE', 'Payment status is unavailable.', 503);
                }

                return [
                    'success' => true,
                    'code'    => 200,
                    'data'    => [
                        'id'         => $sessId,
                        'type'       => 'checkout_session',
                        'attributes' => [
                            'status'              => $isPaid ? 'paid' : 'active',
                            'payment_method_used' => 'gcash',
                            'payments'            => $isPaid ? [
                                [
                                    'id'         => 'pay_sim_' . substr(hash('sha256', $sessId), 0, 16),
                                    'type'       => 'payment',
                                    'attributes' => ['status' => 'paid', 'amount' => money_centavos($row['total_amount']), 'currency' => 'PHP']
                                ]
                            ] : []
                        ]
                    ],
                    'error'   => null
                ];
            }

            // Create new demo checkout session pointing to local interactive screen
            $demoId = 'cs_demo_' . bin2hex(random_bytes(8));
            $orderRef = $attributes['reference_number'] ?? '';
            $baseUrl = paymongo_get_base_url();
            $checkoutUrl = $baseUrl . '/php/demo_checkout.php?session=' . urlencode($demoId) . ($orderRef ? '&order_id=' . urlencode($orderRef) : '');

            return [
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'         => $demoId,
                    'type'       => 'checkout_session',
                    'attributes' => [
                        'status'              => 'active',
                        'checkout_url'        => $checkoutUrl,
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
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(app_setting('PAYMONGO_SECRET_KEY') . ':'),
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
        return ['success' => false, 'code' => $code ?: 500, 'data' => null, 'unknown' => true, 'error' => 'Provider connection failed. Reconciliation is required.'];
    }

    $decoded = json_decode($body, true) ?: [];
    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'code' => $code, 'data' => $decoded['data'] ?? [], 'error' => null];
    }

    $errMsg = $decoded['errors'][0]['detail'] ?? ($decoded['error'] ?? 'PayMongo API error.');
    return ['success' => false, 'code' => $code, 'data' => $decoded, 'error' => $errMsg];
}
