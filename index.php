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

use App\Controllers\Admin\AdminAnalyticsController;
use App\Controllers\Traveler\TravelerController;
use App\Controllers\Admin\AdminAuthController;
use App\Controllers\Admin\AdminBookingsController;
use App\Controllers\Admin\AdminCmsController;
use App\Controllers\Admin\AdminCouponsController;
use App\Controllers\Admin\AdminDashboardController;
use App\Controllers\Admin\AdminInvoicesController;
use App\Controllers\Admin\AdminNotificationsController;
use App\Controllers\Admin\AdminPricingController;
use App\Controllers\Admin\AdminTravelersController;
use App\Controllers\Api\PushController;
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
// Routes — Travelers (traveler-facing)
// ---------------------------------------------------------------------------

$router->group('api/travelers', function (Router $r): void {
    $r->get('/', function (Request $req): void {
        (new TravelerController())->index($req);
    }, [AuthMiddleware::handle()]);
    $r->post('/', function (Request $req): void {
        (new TravelerController())->store($req);
    }, [AuthMiddleware::handle()]);
    $r->put('/:id', function (Request $req): void {
        (new TravelerController())->update($req);
    }, [AuthMiddleware::handle()]);
    $r->delete('/:id', function (Request $req): void {
        (new TravelerController())->destroy($req);
    }, [AuthMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Bookings (traveler-facing aliases)
// ---------------------------------------------------------------------------

$router->group('api/bookings', function (Router $r): void {
    $r->get('/flights', function (Request $req): void {
        (new FlightController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->post('/flights', function (Request $req): void {
        (new FlightController())->startCheckout($req);
    }, [AuthMiddleware::handle()]);
    $r->get('/hotels', function (Request $req): void {
        (new HotelController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->post('/hotels', function (Request $req): void {
        (new HotelController())->prebook($req);
    }, [AuthMiddleware::handle()]);
    $r->post('/lookup', function (Request $req): void {
        (new TravelerController())->lookupBooking($req);
    });
    $r->get('/my-flights', function (Request $req): void {
        (new FlightController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->get('/my-hotels', function (Request $req): void {
        (new HotelController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->get('/:id', function (Request $req): void {
        (new FlightController())->getBooking($req);
    }, [AuthMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Contact
// ---------------------------------------------------------------------------

$router->post('api/contact', function (Request $req): void {
    // Basic contact form handler — log and acknowledge
    $body = $req->json();
    error_log('[Contact] ' . json_encode($body));
    Response::json(['success' => true, 'message' => 'تم استلام رسالتك، سنتواصل معك قريباً.']);
});

// ---------------------------------------------------------------------------
// Routes — Auth extra endpoints
// ---------------------------------------------------------------------------

$router->post('api/auth/change-password', function (Request $req): void {
    (new TravelerController())->changePassword($req);
}, [AuthMiddleware::handle()]);

// ---------------------------------------------------------------------------
// Routes — Flight detail by ID (for booking page)
// ---------------------------------------------------------------------------

$router->get('api/flights/:id', function (Request $req): void {
    (new FlightController())->getBooking($req);
}, [AuthMiddleware::handle()]);

// ---------------------------------------------------------------------------
// Routes — Public Coupon Validation (traveler auth)
// ---------------------------------------------------------------------------

$router->post('api/coupons/validate', function (Request $req): void {
    (new AdminCouponsController())->validate($req);
}, [AuthMiddleware::handle()]);

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
// ---------------------------------------------------------------------------
// Routes — Notifications (traveler-facing)
// ---------------------------------------------------------------------------

$router->group('api/notifications', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminNotificationsController())->userNotifications($req), [AuthMiddleware::handle()]);
    $r->put('/:id/read', fn(Request $req) => (new AdminNotificationsController())->markRead($req), [AuthMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Notifications
// ---------------------------------------------------------------------------

$router->group('api/admin/notifications', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminNotificationsController())->index($req), [AdminMiddleware::handle()]);
    $r->post('/broadcast', fn(Request $req) => (new AdminNotificationsController())->broadcast($req), [AdminMiddleware::handle()]);
    $r->get('/:id', fn(Request $req) => (new AdminNotificationsController())->show($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Analytics
// ---------------------------------------------------------------------------

$router->group('api/admin/analytics', function (Router $r): void {
    $r->get('/overview', fn(Request $req) => (new AdminAnalyticsController())->overview($req), [AdminMiddleware::handle()]);
    $r->get('/searches', fn(Request $req) => (new AdminAnalyticsController())->searches($req), [AdminMiddleware::handle()]);
    $r->get('/revenue', fn(Request $req) => (new AdminAnalyticsController())->revenue($req), [AdminMiddleware::handle()]);
    $r->get('/popular-routes', fn(Request $req) => (new AdminAnalyticsController())->popularRoutes($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Invoices
// ---------------------------------------------------------------------------

$router->group('api/admin/invoices', function (Router $r): void {
    $r->get('/:type/:id', fn(Request $req) => (new AdminInvoicesController())->show($req), [AdminMiddleware::handle()]);
    $r->post('/:type/:id/regenerate', fn(Request $req) => (new AdminInvoicesController())->regenerate($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — CMS (public)
// ---------------------------------------------------------------------------

$router->get('api/cms/pages', fn(Request $req) => (new AdminCmsController())->publicList($req));
$router->get('api/cms/pages/:slug', fn(Request $req) => (new AdminCmsController())->publicShow($req));

// ---------------------------------------------------------------------------
// Routes — Admin: CMS
// ---------------------------------------------------------------------------

$router->group('api/admin/cms/pages', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminCmsController())->index($req), [AdminMiddleware::handle()]);
    $r->post('/', fn(Request $req) => (new AdminCmsController())->store($req), [AdminMiddleware::handle()]);
    $r->get('/:id', fn(Request $req) => (new AdminCmsController())->show($req), [AdminMiddleware::handle()]);
    $r->put('/:id', fn(Request $req) => (new AdminCmsController())->update($req), [AdminMiddleware::handle()]);
    $r->delete('/:id', fn(Request $req) => (new AdminCmsController())->destroy($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — PWA Push Subscriptions
// ---------------------------------------------------------------------------

$router->post('api/push/subscribe', fn(Request $req) => (new PushController())->subscribe($req), [AuthMiddleware::handle()]);
$router->delete('api/push/unsubscribe', fn(Request $req) => (new PushController())->unsubscribe($req), [AuthMiddleware::handle()]);

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
