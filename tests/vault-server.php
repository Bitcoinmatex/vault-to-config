<?php

declare(strict_types=1);

/**
 * Single-purpose fake HashiCorp Vault server for the CI integration test.
 *
 * It speaks just enough of the Vault HTTP KV API for App\Vault\VaultClient:
 *   - KV v2:  GET /v1/{mount}/data/{path}  -> {"data":{"data":{...},"metadata":{...}}}
 *   - KV v1:  GET /v1/{mount}/{path}       -> {"data":{...}}
 *
 * It authenticates via the X-Vault-Token header (any non-empty token is accepted)
 * and returns exactly the keys that examples/config.latte expects. Values contain
 * the "dangerous" characters & " : # on purpose, so the run exercises the
 * {contentType text} / |neon escaping path end to end.
 *
 * Run as a router script:
 *   php -S 127.0.0.1:8200 tests/vault-server.php
 */

// Secret payload returned for every recognised KV path. The keys mirror the
// variables used by examples/config.latte.
$secret = [
    'db_dsn' => 'mysql://app:p@ss&w"rd@db.internal:3306/exchange#main',
    'db_user' => 'app',
    'db_password' => 's3cr3t & "quoted" : value # hash',
    'redis_dsn' => 'redis://:r3dis&pw@cache.internal:6379/0',
    'mail-dsn' => 'smtp://mailer:pw@smtp.internal:587?encryption=tls',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header('Content-Type: application/json');

$sendJson = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// Health endpoint so the test can wait until the server is ready.
if ($uri === '/v1/sys/health') {
    $sendJson(200, ['initialized' => true, 'sealed' => false, 'standby' => false]);

    return true;
}

if ($method !== 'GET') {
    $sendJson(405, ['errors' => ['method not allowed']]);

    return true;
}

// Vault requires a token; mimic the 403 the real server would return.
$token = $_SERVER['HTTP_X_VAULT_TOKEN'] ?? '';
if ($token === '') {
    $sendJson(403, ['errors' => ['permission denied']]);

    return true;
}

// KV v2: /v1/{mount}/data/{path}
if (preg_match('#^/v1/[^/]+/data/.+#', $uri) === 1) {
    $sendJson(200, [
        'data' => [
            'data' => $secret,
            'metadata' => [
                'created_time' => '2026-06-12T00:00:00Z',
                'version' => 1,
            ],
        ],
    ]);

    return true;
}

// KV v1: /v1/{mount}/{path}
if (preg_match('#^/v1/[^/]+/.+#', $uri) === 1) {
    $sendJson(200, ['data' => $secret]);

    return true;
}

$sendJson(404, ['errors' => []]);

return true;
