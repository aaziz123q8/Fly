<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

// Load Composer autoloader.
require BASE_PATH . '/vendor/autoload.php';

// Global exception handler — catches any unhandled Throwable and returns JSON.
set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
    echo json_encode([
        'error'   => 'server_error',
        'message' => $isDev ? $e->getMessage() : 'An internal server error occurred.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
});

// Load environment variables from .env if phpdotenv is available
// and no env vars are set yet (Hostinger hPanel env vars take precedence).
if (class_exists(\Dotenv\Dotenv::class) && file_exists(BASE_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();
}

use App\Controllers\Admin\AdminAuthController;
use App\Controllers\Admin\AdminBookingsController;
use App\Controllers\Admin\AdminCouponsController;
use App\Controllers\Admin\AdminDashboardController;
use App\Controllers\Admin\AdminPricingController;
use App\Controllers\Admin\AdminTravelersController;
use App\Controllers\Auth\AuthController;
use App\Controllers\Flight\FlightController;
use App\Controllers\Hotel\HotelController;
use App\Controllers\Webhook\WebhookController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AdminMiddleware;
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
// Routes — Hotels
// ---------------------------------------------------------------------------

$router->group('api/hotels', function (Router $r): void {

    // Hotel search — auth optional, rate-limited.
    $r->post('/search', function (Request $req): void {
        (new HotelController())->search($req);
    });

    // Checkout: prebook (auth required).
    $r->post('/checkout/prebook', function (Request $req): void {
        (new HotelController())->prebook($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: save guests (auth required).
    $r->post('/checkout/guests', function (Request $req): void {
        (new HotelController())->saveGuests($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: payment intent (auth required).
    $r->post('/checkout/payment-intent', function (Request $req): void {
        (new HotelController())->createPaymentIntent($req);
    }, [AuthMiddleware::handle()]);

    // User bookings list (auth required) — must come before /:provider_hotel_id
    $r->get('/bookings', function (Request $req): void {
        (new HotelController())->listBookings($req);
    }, [AuthMiddleware::handle()]);

    // Single booking detail (auth required).
    $r->get('/bookings/:id', function (Request $req): void {
        (new HotelController())->getBooking($req);
    }, [AuthMiddleware::handle()]);

    // Hotel detail — auth optional (register after /bookings to avoid conflict).
    $r->get('/:provider_hotel_id', function (Request $req): void {
        (new HotelController())->detail($req);
    });
});

// ---------------------------------------------------------------------------
// Routes — Admin Panel
// ---------------------------------------------------------------------------

$router->group('api/admin', function (Router $r): void {

    // Auth (no middleware needed for login).
    $r->post('/auth/login', function (Request $req): void {
        (new AdminAuthController())->login($req);
    });
    $r->post('/auth/logout', function (Request $req): void {
        (new AdminAuthController())->logout($req);
    }, [AdminMiddleware::handle()]);
    $r->get('/auth/me', function (Request $req): void {
        (new AdminAuthController())->me($req);
    }, [AdminMiddleware::handle()]);

    // Dashboard.
    $r->get('/dashboard', function (Request $req): void {
        (new AdminDashboardController())->index($req);
    }, [AdminMiddleware::handle()]);

    // Travelers (users).
    $r->get('/travelers', function (Request $req): void {
        (new AdminTravelersController())->index($req);
    }, [AdminMiddleware::handle()]);
    $r->get('/travelers/:id', function (Request $req): void {
        (new AdminTravelersController())->show($req);
    }, [AdminMiddleware::handle()]);
    $r->put('/travelers/:id', function (Request $req): void {
        (new AdminTravelersController())->update($req);
    }, [AdminMiddleware::handle()]);
    $r->delete('/travelers/:id', function (Request $req): void {
        (new AdminTravelersController())->destroy($req);
    }, [AdminMiddleware::handle()]);

    // Bookings.
    $r->get('/bookings', function (Request $req): void {
        (new AdminBookingsController())->index($req);
    }, [AdminMiddleware::handle()]);
    $r->get('/bookings/:type/:id', function (Request $req): void {
        (new AdminBookingsController())->show($req);
    }, [AdminMiddleware::handle()]);
    $r->put('/bookings/:type/:id/status', function (Request $req): void {
        (new AdminBookingsController())->updateStatus($req);
    }, [AdminMiddleware::handle()]);

    // Coupons.
    $r->get('/coupons', function (Request $req): void {
        (new AdminCouponsController())->index($req);
    }, [AdminMiddleware::handle()]);
    $r->post('/coupons', function (Request $req): void {
        (new AdminCouponsController())->store($req);
    }, [AdminMiddleware::handle()]);
    $r->get('/coupons/:id', function (Request $req): void {
        (new AdminCouponsController())->show($req);
    }, [AdminMiddleware::handle()]);
    $r->put('/coupons/:id', function (Request $req): void {
        (new AdminCouponsController())->update($req);
    }, [AdminMiddleware::handle()]);
    $r->delete('/coupons/:id', function (Request $req): void {
        (new AdminCouponsController())->destroy($req);
    }, [AdminMiddleware::handle()]);

    // Pricing rules.
    $r->get('/pricing', function (Request $req): void {
        (new AdminPricingController())->index($req);
    }, [AdminMiddleware::handle()]);
    $r->post('/pricing', function (Request $req): void {
        (new AdminPricingController())->store($req);
    }, [AdminMiddleware::handle()]);
    $r->get('/pricing/:id', function (Request $req): void {
        (new AdminPricingController())->show($req);
    }, [AdminMiddleware::handle()]);
    $r->put('/pricing/:id', function (Request $req): void {
        (new AdminPricingController())->update($req);
    }, [AdminMiddleware::handle()]);
    $r->delete('/pricing/:id', function (Request $req): void {
        (new AdminPricingController())->destroy($req);
    }, [AdminMiddleware::handle()]);
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
