<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

define('BASE_PATH', __DIR__);
define('APP_START', microtime(true));

// Load Composer autoloader.
require BASE_PATH . '/vendor/autoload.php';

// Global exception handler — catches any unhandled Throwable and returns JSON.
set_exception_handler(function (Throwable $e): void {
    // Always log the full exception to the PHP error log.
    error_log(sprintf(
        '[UNHANDLED_EXCEPTION] %s: %s in %s:%d | trace: %s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        substr($e->getTraceAsString(), 0, 3000)
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }

    $isDebug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN)
            || (getenv('APP_ENV') ?: 'production') === 'development';

    echo json_encode([
        'error'   => 'server_error',
        'message' => $isDebug
            ? sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
            : 'حدث خطأ داخلي في الخادم. يرجى المحاولة مجدداً.',
        'debug'   => $isDebug ? substr($e->getTraceAsString(), 0, 2000) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
});

// Load environment variables from .env. The whole app reads configuration via
// getenv(), so we MUST use createUnsafeImmutable() — it registers the putenv
// adapter so .env values become visible to getenv(). createImmutable() only
// populates $_ENV/$_SERVER, which is why a .env file appeared to "not work".
//
// Look one level ABOVE the web root first (outside the deploy target, so the
// .env survives every deploy), then the web root itself. Immutable = real
// hPanel env vars, if any, still take precedence over the file.
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createUnsafeImmutable([dirname(BASE_PATH), BASE_PATH])->safeLoad();
}

use App\Controllers\Admin\AdminAnalyticsController;
use App\Controllers\Admin\AdminApiSettingsController;
use App\Controllers\Admin\AdminCommissionsController;
use App\Controllers\Admin\AdminCurrenciesController;
use App\Controllers\Admin\AdminPaymentsController;
use App\Controllers\Admin\AdminSupportController;
use App\Controllers\Admin\AdminUsersController;
use App\Controllers\Traveler\TravelerController;
use App\Controllers\Passport\PassportController;
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
use App\Middleware\RateLimitMiddleware;

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


$router->get('/admin/clear-cache', function (Request $req): void {
    $token     = $_GET['token'] ?? '';
    $masterKey = getenv('APP_MASTER_KEY') ?: '';
    if ($masterKey === '' || $token !== $masterKey) {
        Response::error('Forbidden', 403);
        return;
    }
    $cleared = function_exists('opcache_reset') && opcache_reset();
    Response::json(['success' => true, 'opcache_cleared' => $cleared, 'time' => date('c')]);
});

// Forensic debug endpoint — returns last Duffel error_logs entries.
// Protected by APP_MASTER_KEY token (?token=xxx).
$router->get('/admin/duffel-debug', function (Request $req): void {
    $token     = $_GET['token'] ?? '';
    $masterKey = getenv('APP_MASTER_KEY') ?: '';
    if ($masterKey === '' || $token !== $masterKey) {
        Response::error('Forbidden', 403);
        return;
    }
    try {
        $db   = \App\Helpers\Database::getInstance();
        $rows = $db->query(
            "SELECT id, level, message, context, created_at
             FROM error_logs
             WHERE message LIKE '%duffel%'
                OR message LIKE '%Duffel%'
                OR message LIKE '%createOrder%'
                OR message LIKE '%DUFFEL%'
             ORDER BY id DESC
             LIMIT 20"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $decoded = json_decode($row['context'] ?? '', true);
            $row['context_parsed'] = $decoded;
        }
        unset($row);

        Response::json([
            'count'    => count($rows),
            'entries'  => $rows,
            'time'     => date('c'),
        ]);
    } catch (\Throwable $e) {
        Response::json(['error' => $e->getMessage()], 500);
    }
});

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
    }, [function (Request $req, callable $next): void { (new RateLimitMiddleware())->handle('POST /api/search'); $next($req); }]);

    // Airport autocomplete (public).
    $r->get('/airports', function (Request $req): void {
        (new FlightController())->searchAirports($req);
    });

    // Single offer detail + available services (bags, meals) — public.
    $r->get('/offers/:id', function (Request $req): void {
        (new FlightController())->getOffer($req);
    });

    // Seat maps for an offer — public.
    $r->get('/seat-maps', function (Request $req): void {
        (new FlightController())->getSeatMaps($req);
    });

    // Checkout: start (auth required).
    $r->post('/checkout/start', function (Request $req): void {
        (new FlightController())->startCheckout($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: guest start (NO auth) — creates a guest account + session.
    $r->post('/checkout/guest-start', function (Request $req): void {
        (new FlightController())->guestStart($req);
    });

    // Checkout: passengers (auth required).
    $r->post('/checkout/passengers', function (Request $req): void {
        (new FlightController())->savePassengers($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: services/ancillaries (auth required).
    $r->post('/checkout/services', function (Request $req): void {
        (new FlightController())->saveServices($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: real-time price recalculation via Duffel (auth required).
    $r->post('/checkout/price', function (Request $req): void {
        (new FlightController())->priceOffer($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: review pricing (auth required).
    $r->get('/checkout/review', function (Request $req): void {
        (new FlightController())->review($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: create Stripe payment intent (auth required).
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

    // Checkout: confirm after Stripe payment (auth required).
    $r->post('/checkout/confirm', function (Request $req): void {
        (new FlightController())->confirmCheckout($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: full wallet payment — no Stripe (auth required).
    $r->post('/checkout/wallet-confirm', function (Request $req): void {
        (new FlightController())->walletConfirm($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: Duffel card payment — Step 1 tokenise card + create 3DS session (auth required).
    $r->post('/checkout/card/init', function (Request $req): void {
        (new FlightController())->initCardPayment($req);
    }, [AuthMiddleware::handle()]);

    // Checkout: Duffel card payment — Step 2 complete booking after 3DS auth (auth required).
    $r->post('/checkout/card/complete', function (Request $req): void {
        (new FlightController())->completeCardBooking($req);
    }, [AuthMiddleware::handle()]);

    // Sync booking from live Duffel order (auth required).
    $r->post('/bookings/:id/sync', function (Request $req): void {
        (new FlightController())->syncBooking($req);
    }, [AuthMiddleware::handle()]);

    // Cancellation: Step 1 — get refund quote (auth required).
    $r->post('/bookings/:id/cancel', function (Request $req): void {
        (new FlightController())->cancelBooking($req);
    }, [AuthMiddleware::handle()]);

    // Cancellation: Step 2 — confirm cancellation (auth required).
    $r->post('/bookings/:id/cancel/confirm', function (Request $req): void {
        (new FlightController())->confirmCancelBooking($req);
    }, [AuthMiddleware::handle()]);

    // Flight change: Step 1 — search alternatives (auth required).
    $r->post('/bookings/:id/change/search', function (Request $req): void {
        (new FlightController())->searchFlightChange($req);
    }, [AuthMiddleware::handle()]);

    // Flight change: payment intent for paid changes.
    $r->post('/bookings/:id/change/payment-intent', function (Request $req): void {
        (new FlightController())->createChangePaymentIntent($req);
    }, [AuthMiddleware::handle()]);

    // Flight change: Step 2 — confirm selected offer (auth required).
    $r->post('/bookings/:id/change/confirm', function (Request $req): void {
        (new FlightController())->confirmFlightChange($req);
    }, [AuthMiddleware::handle()]);

    // Available ancillary services (bags/seats) for a booking.
    $r->get('/bookings/:id/services', function (Request $req): void {
        (new FlightController())->getAvailableServices($req);
    }, [AuthMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Hotels
// ---------------------------------------------------------------------------

$router->group('api/hotels', function (Router $r): void {

    // Hotel search — auth optional, rate-limited.
    $r->post('/search', function (Request $req): void {
        (new HotelController())->search($req);
    }, [function (Request $req, callable $next): void { (new RateLimitMiddleware())->handle('POST /api/search'); $next($req); }]);

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

    // Checkout: confirm after Stripe payment (auth required).
    $r->post('/checkout/confirm', function (Request $req): void {
        (new HotelController())->confirmCheckout($req);
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

// Passport / ID scan (uses Claude Vision API)
$router->post('api/passport/scan', function (Request $req): void {
    (new PassportController())->scan($req);
}, [AuthMiddleware::handle()]);

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
    $r->get('/guest-invoice', function (Request $req): void {
        (new TravelerController())->guestInvoice($req);
    });
    $r->get('/my-flights', function (Request $req): void {
        (new FlightController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->get('/my-hotels', function (Request $req): void {
        (new HotelController())->listBookings($req);
    }, [AuthMiddleware::handle()]);
    $r->get('/:id', function (Request $req): void {
        $id = $req->param('id') ?? '';
        // Route to hotel controller for HM references, flight controller otherwise
        if (str_starts_with(strtoupper($id), 'HM')) {
            (new HotelController())->getBooking($req);
        } else {
            (new FlightController())->getBooking($req);
        }
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
// Routes — Payment mode flag (no auth — only reveals test vs live, not any secret)
// ---------------------------------------------------------------------------

$router->get('api/config/payment-mode', function (Request $req): void {
    $isTest = false;
    $cfg    = \App\Helpers\ConfigLoader::load('apis');
    if ($cfg) {
        $apiKey = $cfg['duffel']['api_key'] ?? '';
        $isTest = str_starts_with($apiKey, 'duffel_test_');
    }
    if (!$isTest) {
        $envKey = getenv('DUFFEL_API_KEY') ?: '';
        $isTest = str_starts_with($envKey, 'duffel_test_');
    }
    Response::json(['test_mode' => $isTest]);
});

// Routes — Stripe public key (no auth — only exposes publishable key)
// ---------------------------------------------------------------------------

$router->get('api/config/stripe-key', function (Request $req): void {
    $cfg = \App\Helpers\ConfigLoader::load('apis');
    $key = '';
    if ($cfg) {
        $key = $cfg['stripe']['publishable_key'] ?? $cfg['stripe']['public_key'] ?? '';
    }
    if (!$key) $key = getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
    if (!$key) {
        error_log('[STRIPE_KEY_MISSING] publishable_key not found in config/apis.php or STRIPE_PUBLISHABLE_KEY env');
    }
    Response::json(['publishable_key' => $key]);
});

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
    $r->get('/bookings/:id', function (Request $req): void {
        (new AdminBookingsController())->showById($req);
    }, [AdminMiddleware::handle()]);
    $r->patch('/bookings/:id/status', function (Request $req): void {
        (new AdminBookingsController())->patchStatus($req);
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
// Routes — Admin: Users
// ---------------------------------------------------------------------------

$router->group('api/admin/users', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminUsersController())->index($req), [AdminMiddleware::handle()]);
    $r->get('/:id', fn(Request $req) => (new AdminUsersController())->show($req), [AdminMiddleware::handle()]);
    $r->put('/:id', fn(Request $req) => (new AdminUsersController())->update($req), [AdminMiddleware::handle()]);
    $r->delete('/:id', fn(Request $req) => (new AdminUsersController())->destroy($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Payments
// ---------------------------------------------------------------------------

$router->group('api/admin/payments', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminPaymentsController())->index($req), [AdminMiddleware::handle()]);
    $r->get('/:id', fn(Request $req) => (new AdminPaymentsController())->show($req), [AdminMiddleware::handle()]);
    $r->post('/:id/refund', fn(Request $req) => (new AdminPaymentsController())->refund($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Support
// ---------------------------------------------------------------------------

$router->group('api/admin/support', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminSupportController())->index($req), [AdminMiddleware::handle()]);
    $r->get('/:id', fn(Request $req) => (new AdminSupportController())->show($req), [AdminMiddleware::handle()]);
    $r->post('/:id/reply', fn(Request $req) => (new AdminSupportController())->reply($req), [AdminMiddleware::handle()]);
    $r->put('/:id/status', fn(Request $req) => (new AdminSupportController())->updateStatus($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Commissions
// ---------------------------------------------------------------------------

$router->group('api/admin/commissions', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminCommissionsController())->index($req), [AdminMiddleware::handle()]);
    $r->post('/', fn(Request $req) => (new AdminCommissionsController())->store($req), [AdminMiddleware::handle()]);
    $r->put('/:id', fn(Request $req) => (new AdminCommissionsController())->update($req), [AdminMiddleware::handle()]);
    $r->delete('/:id', fn(Request $req) => (new AdminCommissionsController())->destroy($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: Currencies
// ---------------------------------------------------------------------------

$router->group('api/admin/currencies', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminCurrenciesController())->index($req), [AdminMiddleware::handle()]);
    $r->post('/', fn(Request $req) => (new AdminCurrenciesController())->store($req), [AdminMiddleware::handle()]);
    $r->put('/:id', fn(Request $req) => (new AdminCurrenciesController())->update($req), [AdminMiddleware::handle()]);
    $r->delete('/:id', fn(Request $req) => (new AdminCurrenciesController())->destroy($req), [AdminMiddleware::handle()]);
    $r->post('/refresh-rates', fn(Request $req) => (new AdminCurrenciesController())->refreshRates($req), [AdminMiddleware::handle()]);
});

// ---------------------------------------------------------------------------
// Routes — Admin: API Settings
// ---------------------------------------------------------------------------

$router->group('api/admin/settings', function (Router $r): void {
    $r->get('/', fn(Request $req) => (new AdminApiSettingsController())->index($req), [AdminMiddleware::handle()]);
    $r->put('/:id', fn(Request $req) => (new AdminApiSettingsController())->update($req), [AdminMiddleware::handle()]);
    $r->post('/upsert', fn(Request $req) => (new AdminApiSettingsController())->upsert($req), [AdminMiddleware::handle()]);
    $r->post('/test/duffel', fn(Request $req) => (new AdminApiSettingsController())->testDuffel($req), [AdminMiddleware::handle()]);
    $r->post('/test/ratehawk', fn(Request $req) => (new AdminApiSettingsController())->testRatehawk($req), [AdminMiddleware::handle()]);
    $r->post('/test/stripe', fn(Request $req) => (new AdminApiSettingsController())->testStripe($req), [AdminMiddleware::handle()]);
});

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
// Routes — Wallet
// ---------------------------------------------------------------------------

$router->group('api/wallet', function (Router $r): void {
    $r->get('/balance',      fn(Request $req) => (new \App\Controllers\Api\WalletController())->getBalance($req));
    $r->get('/transactions', fn(Request $req) => (new \App\Controllers\Api\WalletController())->getTransactions($req));
}, [AuthMiddleware::handle()]);

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
