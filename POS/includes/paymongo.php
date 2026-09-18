<?php

define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

function paymongo_is_configured(): bool
{
	return PAYMONGO_SECRET_KEY !== '' && strpos(PAYMONGO_SECRET_KEY, 'sk_') === 0;
}

function paymongo_order_has_column(PDO $pdo, string $column): bool
{
	$rows = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN, 0);
	$names = array_map('strtolower', $rows);
	return in_array(strtolower($column), $names, true);
}

function paymongo_ensure_order_columns(PDO $pdo): void
{
	$columns = [
		'paymongo_session_id' => 'VARCHAR(100) NULL',
		'paymongo_payment_id' => 'VARCHAR(100) NULL',
		'payment_status' => "ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending'",
	];

	foreach ($columns as $column => $definition) {
		if (!paymongo_order_has_column($pdo, $column)) {
			$pdo->exec('ALTER TABLE orders ADD COLUMN ' . $column . ' ' . $definition);
		}
	}
}

function paymongo_request(string $endpoint, ?array $attributes = null, string $method = 'GET'): array
{
	if (!paymongo_is_configured()) {
		return ['success' => false, 'code' => 500, 'data' => null, 'error' => 'PayMongo secret key is not configured.'];
	}

	$curl = curl_init('https://api.paymongo.com/v1/' . ltrim($endpoint, '/'));
	curl_setopt_array($curl, [
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTPHEADER => [
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
		],
	]);
	$caBundle = getenv('CURL_CA_BUNDLE') ?: dirname(__DIR__, 4) . '/apache/bin/curl-ca-bundle.crt';
	if (is_file($caBundle)) {
		curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
	}
	if ($attributes !== null) {
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $attributes]]));
	}

	$body = curl_exec($curl);
	$code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
	$error = curl_error($curl);
	curl_close($curl);

	if ($body === false || $error !== '') {
		return ['success' => false, 'code' => $code ?: 500, 'data' => null, 'error' => $error ?: 'PayMongo request failed.'];
	}

	$decoded = json_decode($body, true) ?: [];
	if ($code >= 200 && $code < 300) {
		return ['success' => true, 'code' => $code, 'data' => $decoded['data'] ?? [], 'error' => null];
	}

	return ['success' => false, 'code' => $code, 'data' => $decoded, 'error' => $decoded['errors'][0]['detail'] ?? 'PayMongo API error.'];
}
