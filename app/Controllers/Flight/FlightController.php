<?php

declare(strict_types=1);

namespace App\Controllers\Flight;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\FlightBookingService;
use App\Services\FlightSearchService;
use RuntimeException;

class FlightController
{
    private FlightSearchService  $searchService;
    private FlightBookingService $bookingService;
    private RateLimitMiddleware  $rateLimiter;

    public function __construct(
        ?FlightSearchService  $searchService  = null,
        ?FlightBookingService $bookingService = null,
        ?RateLimitMiddleware  $rateLimiter    = null
    ) {
        $this->searchService  = $searchService  ?? new FlightSearchService();
        $this->bookingService = $bookingService ?? new FlightBookingService();
        $this->rateLimiter    = $rateLimiter    ?? new RateLimitMiddleware();
    }

    // =========================================================================
    // POST /api/flights/search
    // =========================================================================

    public function search(Request $request): void
    {
        // Rate limit: 10/min per IP (unauthenticated endpoint).
        $this->rateLimiter->handle('POST /api/flights/search', null, $request->ip());

        $errors = $request->validate([
            'origin'         => 'required|min:3|max:3',
            'destination'    => 'required|min:3|max:3',
            'departure_date' => 'required',
            'adults'         => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $params = [
            'origin'         => $request->input('origin'),
            'destination'    => $request->input('destination'),
            'departure_date' => $request->input('departure_date'),
            'return_date'    => $request->input('return_date'),
            'cabin_class'    => $request->input('cabin_class', 'economy'),
            'adults'         => (int) $request->input('adults', 1),
            'children'       => $request->input('children', []),
        ];

        // adults must be at least 1.
        if ($params['adults'] < 1) {
            Response::validationError(['adults' => ['The adults field must be at least 1.']]);
        }

        // Optional auth — log user_id if available.
        $currentUser = AuthMiddleware::currentUser();
        $userId      = $currentUser ? (int) $currentUser['id'] : 0;

        try {
            $offers = $this->searchService->search($params, $userId);
        } catch (RuntimeException $e) {
            Response::error('Flight search failed: ' . $e->getMessage(), 502, 'search_error');
        }

        Response::json([
            'data'  => $offers,
            'total' => count($offers),
        ]);
    }

    // =========================================================================
    // POST /api/flights/checkout/start
    // =========================================================================

    public function startCheckout(Request $request): void
    {
        $user = $this->requireAuth();

        $errors = $request->validate(['offer_id' => 'required']);
        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $offerId = (string) $request->input('offer_id');

        try {
            $result = $this->bookingService->startCheckout($offerId, (int) $user['id']);
        } catch (RuntimeException $e) {
            $this->handleBookingException($e);
        }

        Response::json($result);
    }

    // =========================================================================
    // POST /api/flights/checkout/passengers
    // =========================================================================

    public function savePassengers(Request $request): void
    {
        $user = $this->requireAuth();

        $errors = $request->validate([
            'session_key' => 'required',
            'passengers'  => 'required',
        ]);
        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $sessionKey = (string) $request->input('session_key');
        $passengers = $request->input('passengers');

        if (!is_array($passengers) || empty($passengers)) {
            Response::validationError(['passengers' => ['The passengers field must be a non-empty array.']]);
        }

        try {
            $this->bookingService->savePassengers($sessionKey, $passengers, (int) $user['id']);
        } catch (RuntimeException $e) {
            $this->handleBookingException($e);
        }

        Response::json(['message' => 'ok', 'next_step' => 'services']);
    }

    // =========================================================================
    // POST /api/flights/checkout/services
    // =========================================================================

    public function saveServices(Request $request): void
    {
        $user = $this->requireAuth();

        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $sessionKey = (string) $request->input('session_key');
        $services   = $request->input('services', []);

        if (!is_array($services)) {
            $services = [];
        }

        try {
            $this->bookingService->saveServices($sessionKey, $services, (int) $user['id']);
        } catch (RuntimeException $e) {
            $this->handleBookingException($e);
        }

        Response::json(['message' => 'ok', 'next_step' => 'review']);
    }

    // =========================================================================
    // GET /api/flights/checkout/review
    // =========================================================================

    public function getReview(Request $request): void
    {
        $user = $this->requireAuth();

        $sessionKey = (string) ($request->input('session_key') ?? '');
        if ($sessionKey === '') {
            Response::validationError(['session_key' => ['The session_key field is required.']]);
        }

        try {
            $review = $this->bookingService->getReview($sessionKey, (int) $user['id']);
        } catch (RuntimeException $e) {
            $this->handleBookingException($e);
        }

        Response::json($review);
    }

    // =========================================================================
    // POST /api/flights/checkout/payment-intent
    // =========================================================================

    public function createPaymentIntent(Request $request): void
    {
        $user = $this->requireAuth();

        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $sessionKey = (string) $request->input('session_key');
        $couponCode = $request->input('coupon_code') ? (string) $request->input('coupon_code') : null;

        try {
            $result = $this->bookingService->createPaymentIntent($sessionKey, (int) $user['id'], $couponCode);
        } catch (RuntimeException $e) {
            $this->handleBookingException($e);
        }

        Response::json($result);
    }

    // =========================================================================
    // GET /api/flights/bookings
    // =========================================================================

    public function listBookings(Request $request): void
    {
        $user     = $this->requireAuth();
        $bookings = $this->bookingService->getUserBookings((int) $user['id']);
        Response::json(['data' => $bookings, 'total' => count($bookings)]);
    }

    // =========================================================================
    // GET /api/flights/bookings/:id
    // =========================================================================

    public function getBooking(Request $request): void
    {
        $user = $this->requireAuth();

        $bookingId = (int) $request->param('id', 0);
        if ($bookingId < 1) {
            Response::notFound('Booking not found.');
        }

        $booking = $this->bookingService->getBookingById($bookingId, (int) $user['id']);

        if ($booking === null) {
            Response::notFound('Booking not found.');
        }

        Response::json(['data' => $booking]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function requireAuth(): array
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized();
        }

        return $user;
    }

    /**
     * Map RuntimeException codes to appropriate HTTP responses.
     *
     * @return never
     */
    private function handleBookingException(RuntimeException $e): never
    {
        $code = $e->getCode();

        match (true) {
            $code === 410 => Response::error($e->getMessage(), 410, 'offer_expired'),
            $code === 403 => Response::forbidden($e->getMessage()),
            $code === 404 => Response::notFound($e->getMessage()),
            $code === 422 => Response::error($e->getMessage(), 422, 'validation_failed'),
            default       => Response::error('Booking error: ' . $e->getMessage(), 500, 'booking_error'),
        };
    }
}
