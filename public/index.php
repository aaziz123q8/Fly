<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

// Load Composer autoloader.
require BASE_PATH . '/vendor/autoload.php';

// Load environment variables from .env if phpdotenv is available
// and no env vars are set yet (Hostinger hPanel env vars take precedence).
if (class_exists(\Dotenv\Dotenv::class) && file_exists(BASE_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();
}

// ---------------------------------------------------------------------------
// Request parsing
// ---------------------------------------------------------------------------

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . trim((string)$uri, '/');

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

/**
 * Simple pattern-based router.
 * Patterns use named captures: /api/bookings/:id  →  (?P<id>[^/]+)
 */
$routes = [
    // ── Health / Meta ───────────────────────────────────────────────────────
    'GET /'                               => fn() => jsonResponse(['status' => 'FlyMasar API', 'version' => '1.0.0']),
    'GET /health'                         => fn() => jsonResponse(['status' => 'ok', 'time' => date('c')]),

    // ── Webhooks ────────────────────────────────────────────────────────────
    'POST /webhooks/stripe'               => 'webhook_stripe',
    'POST /webhooks/duffel'               => 'webhook_duffel',

    // ── Auth ─────────────────────────────────────────────────────────────────
    // POST /api/auth/register
    // POST /api/auth/login
    // POST /api/auth/logout
    // POST /api/auth/password/reset

    // ── Flights ──────────────────────────────────────────────────────────────
    // POST /api/flights/search
    // POST /api/flights/book
    // GET  /api/flights/bookings/:id

    // ── Hotels ───────────────────────────────────────────────────────────────
    // POST /api/hotels/search
    // POST /api/hotels/prebook
    // POST /api/hotels/book
    // GET  /api/hotels/bookings/:id

    // ── Payments ─────────────────────────────────────────────────────────────
    // POST /api/payments/intent
    // POST /api/payments/:id/refund
];

// Resolve the route.
$routeKey = $method . ' ' . $uri;

if (isset($routes[$routeKey])) {
    $handler = $routes[$routeKey];

    if (is_callable($handler)) {
        $handler();
    } elseif (is_string($handler)) {
        dispatchNamedHandler($handler);
    }
    exit;
}

// 404 fallback.
http_response_code(404);
echo json_encode(['error' => 'not_found', 'path' => $uri]);
exit;

// ---------------------------------------------------------------------------
// Handler dispatch
// ---------------------------------------------------------------------------

function dispatchNamedHandler(string $name): void
{
    switch ($name) {
        case 'webhook_stripe':
            (new \App\Controllers\Webhook\WebhookController())->handleStripe();
            break;

        case 'webhook_duffel':
            (new \App\Controllers\Webhook\WebhookController())->handleDuffel();
            break;

        default:
            http_response_code(501);
            echo json_encode(['error' => 'not_implemented']);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
