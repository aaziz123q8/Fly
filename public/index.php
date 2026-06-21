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
use App\Controllers\Flight\FlightController;
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
// Routes — Flights
// ---------------------------------------------------------------------------

$router->group('api/flights', function (Router $r): void {

    // Flight search — auth optional, rate-limited.
    $r->post('/search', function (Request $req): void {
        (new FlightController())->search($req);
    });

    // Checkout: start (auth required).
    $r->post('/checkout/start', function (Request $req): void {
        (new FlightController())->startCheckout($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: passengers (auth required).
    $r->post('/checkout/passengers', function (Request $req): void {
        (new FlightController())->savePassengers($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: services (auth required).
    $r->post('/checkout/services', function (Request $req): void {
        (new FlightController())->saveServices($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: review (auth required).
    $r->get('/checkout/review', function (Request $req): void {
        (new FlightController())->review($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: payment intent (auth required).
    $r->post('/checkout/payment-intent', function (Request $req): void {
        (new FlightController())->createPaymentIntent($req);
    }, [AuthMiddleware::handle()]);

    // User bookings list (auth required).
    $r->get('/bookings', function (Request $req): void {
        (new FlightController())->listBookings($req);
    }, [AuthMiddleware::handle()]);

    // Single booking detail (auth required).
    $r->get('/bookings/:id', function (Request $req): void {
        (new FlightController())->getBooking($req);
    }, [AuthMiddleware::handle()]);
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
