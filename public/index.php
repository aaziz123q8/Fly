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

use App\Controllers\Auth\AuthController;
use App\Controllers\Webhook\WebhookController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;

// ---------------------------------------------------------------------------
// Bootstrap core objects
// ---------------------------------------------------------------------------

$request = new Request();
$router  = new Router();

// ---------------------------------------------------------------------------
// Routes — Health / Meta
// ---------------------------------------------------------------------------

$router->get('/', fn(Request $req) => Response::json([
    'status'  => 'FlyMasar API',
    'version' => '1.0.0',
]));

$router->get('/health', fn(Request $req) => Response::json([
    'status' => 'ok',
    'time'   => date('c'),
]));

// ---------------------------------------------------------------------------
// Routes — Webhooks
// ---------------------------------------------------------------------------

$router->post('/webhooks/stripe', function (Request $req): void {
    (new WebhookController())->handleStripe();
});

$router->post('/webhooks/duffel', function (Request $req): void {
    (new WebhookController())->handleDuffel();
});

// ---------------------------------------------------------------------------
// Routes — Auth
// ---------------------------------------------------------------------------

$router->group('api/auth', function (Router $r): void {

    $r->post('/register', function (Request $req): void {
        (new AuthController())->register($req);
    });

    $r->post('/login', function (Request $req): void {
        (new AuthController())->login($req);
    });

    $r->post('/logout', function (Request $req): void {
        (new AuthController())->logout($req);
    }, [AuthMiddleware::handle()]);

    $r->get('/me', function (Request $req): void {
        (new AuthController())->me($req);
    }, [AuthMiddleware::handle()]);

    $r->post('/password/forgot', function (Request $req): void {
        (new AuthController())->forgotPassword($req);
    });

    $r->post('/password/reset', function (Request $req): void {
        (new AuthController())->resetPassword($req);
    });
});

// ---------------------------------------------------------------------------
// 404 / 405 handlers
// ---------------------------------------------------------------------------

$router->setNotFound(function (Request $req): void {
    Response::error('The requested endpoint does not exist.', 404, 'not_found');
});

$router->setMethodNotAllowed(function (Request $req, array $allowed): void {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    header('Allow: ' . implode(', ', $allowed));
    echo json_encode([
        'error'   => 'method_not_allowed',
        'message' => 'Method not allowed.',
        'allowed' => $allowed,
    ], JSON_UNESCAPED_SLASHES);
    exit;
});

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

$router->dispatch($request);
