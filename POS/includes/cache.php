<?php
// ==============================================================================
// FILE: includes/cache.php
// Enterprise Multi-Tier Caching System (Memory L1 + File L2 / APCu)
// Kofee Manila POS & Management System
// ==============================================================================

/**
 * Returns the directory path used for persistent cache files.
 * Automatically initializes directory and protection files on first use.
 */
function cache_dir(): string {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $target = __DIR__ . '/../cache';
    if (!is_dir($target)) {
        @mkdir($target, 0755, true);
    }

    // Defense-in-depth: Ensure .htaccess blocks direct HTTP web requests to cached data
    $htaccess = $target . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "# Protect cached data\nRequire all denied\n");
    }

    $dir = realpath($target) ?: $target;
    return $dir;
}

/**
 * In-memory L1 request cache storage.
 */
function &cache_memory_store(): array {
    static $memory = [];
    return $memory;
}

/**
 * Returns true if APCu is available and enabled.
 */
function cache_has_apcu(): bool {
    static $hasApcu = null;
    if ($hasApcu === null) {
        $hasApcu = extension_loaded('apcu') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
    }
    return $hasApcu;
}

/**
 * Store a value in the cache with a time-to-live (in seconds).
 *
 * @param string $key Unique cache identifier
 * @param mixed $value Any serializable PHP data (arrays, objects, primitives)
 * @param int $ttl Time to live in seconds (default 300 = 5 minutes)
 * @return bool True on success, false on failure
 */
function cache_set(string $key, mixed $value, int $ttl = 300): bool {
    $expiresAt = time() + max(1, $ttl);
    $mem =& cache_memory_store();

    // 1. L1 Request Memory Cache
    $mem[$key] = [
        'exp' => $expiresAt,
        'val' => $value,
    ];

    // 2. APCu Fast Cache (if available)
    if (cache_has_apcu()) {
        @apcu_store('km_' . $key, [
            'key'        => $key,
            'expires_at' => $expiresAt,
            'data'       => $value,
        ], max(1, $ttl));
    }

    // 3. L2 File Cache (Persistent across PHP requests & processes)
    $dir = cache_dir();
    $filename = $dir . '/' . md5($key) . '.cache';
    $tmpFilename = $dir . '/' . md5($key) . '.' . uniqid('tmp_', true) . '.tmp';

    $payload = [
        'key'        => $key,
        'created_at' => time(),
        'expires_at' => $expiresAt,
        'data'       => $value,
    ];

    // Security header prevents direct PHP execution if accessed accidentally
    $content = "<" . "?php exit; ?" . ">\n" . serialize($payload);

    $written = @file_put_contents($tmpFilename, $content, LOCK_EX);
    if ($written === false) {
        return false;
    }

    // Atomic file rename
    if (!@rename($tmpFilename, $filename)) {
        @unlink($tmpFilename);
        return false;
    }

    return true;
}

/**
 * Retrieve an item from the cache.
 *
 * @param string $key Cache key
 * @param mixed $default Value returned if key is missing or expired
 * @return mixed
 */
function cache_get(string $key, mixed $default = null): mixed {
    $now = time();
    $mem =& cache_memory_store();

    // 1. Check L1 In-Memory Cache (0ms)
    if (isset($mem[$key])) {
        if ($mem[$key]['exp'] >= $now) {
            return $mem[$key]['val'];
        }
        unset($mem[$key]);
    }

    // 2. Check APCu Cache (if available)
    if (cache_has_apcu()) {
        $success = false;
        $apcuItem = @apcu_fetch('km_' . $key, $success);
        if ($success && is_array($apcuItem) && ($apcuItem['expires_at'] ?? 0) >= $now) {
            $mem[$key] = [
                'exp' => $apcuItem['expires_at'],
                'val' => $apcuItem['data'],
            ];
            return $apcuItem['data'];
        }
    }

    // 3. Check L2 File Cache
    $dir = cache_dir();
    $filename = $dir . '/' . md5($key) . '.cache';

    if (!is_file($filename)) {
        return $default;
    }

    $raw = @file_get_contents($filename);
    if ($raw === false || strlen($raw) < 16) {
        @unlink($filename);
        return $default;
    }

    // Strip security prefix (15 bytes)
    $serialized = substr($raw, 15);
    $payload = @unserialize($serialized);

    if (!is_array($payload) || !isset($payload['expires_at'])) {
        @unlink($filename);
        return $default;
    }

    // Expiration check
    if ($payload['expires_at'] < $now) {
        @unlink($filename);
        return $default;
    }

    // Populate L1 cache for subsequent lookups in this request
    $mem[$key] = [
        'exp' => $payload['expires_at'],
        'val' => $payload['data'],
    ];

    return $payload['data'];
}

/**
 * Check if a valid (non-expired) cache key exists.
 */
function cache_has(string $key): bool {
    $marker = new stdClass();
    return cache_get($key, $marker) !== $marker;
}

/**
 * Remember pattern: Get from cache, or execute callback, store result, and return it.
 *
 * @param string $key
 * @param int $ttl
 * @param callable $callback
 * @return mixed
 */
function cache_remember(string $key, int $ttl, callable $callback): mixed {
    $marker = new stdClass();
    $existing = cache_get($key, $marker);

    if ($existing !== $marker) {
        return $existing;
    }

    $computed = $callback();
    cache_set($key, $computed, $ttl);
    return $computed;
}

/**
 * Remove an item from the cache.
 */
function cache_delete(string $key): bool {
    $mem =& cache_memory_store();
    unset($mem[$key]);

    if (cache_has_apcu()) {
        @apcu_delete('km_' . $key);
    }

    $dir = cache_dir();
    $filename = $dir . '/' . md5($key) . '.cache';
    if (is_file($filename)) {
        return @unlink($filename);
    }

    return true;
}

/**
 * Invalidate all cache entries matching a key prefix.
 * Example: cache_delete_pattern('pos_menu_') removes all menu-related caches.
 *
 * @param string $prefix
 * @return int Number of invalidated entries
 */
function cache_delete_pattern(string $prefix): int {
    $deleted = 0;
    $mem =& cache_memory_store();

    // Clear matching L1 keys
    foreach (array_keys($mem) as $k) {
        if (str_starts_with($k, $prefix)) {
            unset($mem[$k]);
        }
    }

    // Clear matching APCu keys (if available)
    if (cache_has_apcu()) {
        $info = @apcu_cache_info();
        if (!empty($info['cache_list'])) {
            foreach ($info['cache_list'] as $entry) {
                $itemKey = $entry['info'] ?? '';
                if (str_starts_with($itemKey, 'km_' . $prefix)) {
                    @apcu_delete($itemKey);
                }
            }
        }
    }

    // Clear matching File Cache entries
    $dir = cache_dir();
    $files = @glob($dir . '/*.cache') ?: [];
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw !== false && strlen($raw) >= 16) {
            $serialized = substr($raw, 15);
            $payload = @unserialize($serialized);
            if (is_array($payload) && isset($payload['key'])) {
                if (str_starts_with($payload['key'], $prefix)) {
                    @unlink($file);
                    $deleted++;
                }
            }
        }
    }

    return $deleted;
}

/**
 * Flush all application cache entries.
 */
function cache_flush(): bool {
    $mem =& cache_memory_store();
    $mem = [];

    if (cache_has_apcu()) {
        @apcu_clear_cache();
    }

    $dir = cache_dir();
    $files = @glob($dir . '/*.cache') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }

    return true;
}

/**
 * Get cache diagnostics & stats.
 */
function cache_stats(): array {
    $dir = cache_dir();
    $files = @glob($dir . '/*.cache') ?: [];
    $totalBytes = 0;
    $activeCount = 0;
    $expiredCount = 0;
    $keys = [];
    $now = time();

    foreach ($files as $file) {
        $bytes = @filesize($file) ?: 0;
        $totalBytes += $bytes;

        $raw = @file_get_contents($file);
        if ($raw !== false && strlen($raw) >= 16) {
            $payload = @unserialize(substr($raw, 15));
            if (is_array($payload)) {
                $isExpired = ($payload['expires_at'] ?? 0) < $now;
                if ($isExpired) {
                    $expiredCount++;
                } else {
                    $activeCount++;
                    $keys[] = [
                        'key'        => $payload['key'] ?? 'unknown',
                        'size_bytes' => $bytes,
                        'ttl_left'   => max(0, ($payload['expires_at'] ?? $now) - $now),
                        'created_at' => date('Y-m-d H:i:s', $payload['created_at'] ?? $now),
                    ];
                }
            }
        }
    }

    return [
        'driver'        => cache_has_apcu() ? 'APCu + File (Hybrid)' : 'File-based (L1 Memory + L2 Disk)',
        'cache_dir'     => $dir,
        'active_items'  => $activeCount,
        'expired_items' => $expiredCount,
        'total_bytes'   => $totalBytes,
        'total_kb'      => round($totalBytes / 1024, 2),
        'keys'          => $keys,
    ];
}
