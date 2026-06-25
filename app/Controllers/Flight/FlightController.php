<?php

declare(strict_types=1);

namespace App\Controllers\Flight;

use App\Adapters\Duffel\DuffelAdapter;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\FlightBookingService;
use App\Services\FlightSearchService;

class FlightController
{
    private FlightSearchService  $searchService;
    private FlightBookingService $bookingService;

    public function __construct()
    {
        $this->searchService  = new FlightSearchService();
        $this->bookingService = new FlightBookingService();
    }

    // =========================================================================
    // Search
    // =========================================================================

    public function search(Request $request): void
    {
        $errors = $request->validate([
            'origin'         => 'required|max:3',
            'destination'    => 'required|max:3',
            'departure_date' => 'required',
            'adults'         => 'required',
        ]);
        if (!empty($errors)) Response::validationError($errors);

        $user   = AuthMiddleware::currentUser();
        $userId = $user ? (int) $user['id'] : 0;

        try {
            $body = $request->json() ?: [];

            // Normalize children/infants: frontend may send counts (int) instead of age arrays.
            // Duffel requires child ages (2-11) and infant ages (0-1) per passenger.
            if (isset($body['children']) && is_int($body['children'])) {
                $count = $body['children'];
                $body['children'] = array_fill(0, $count, 5); // default child age 5
            }
            if (isset($body['infants']) && (is_int($body['infants']) || is_numeric($body['infants']))) {
                $body['infants'] = (int) $body['infants'];
            }

            $offers = $this->searchService->search($body, $userId);
            Response::json(['offers' => $offers, 'count' => count($offers)]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 502);
        } catch (\Throwable $e) {
            $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
            Response::error($isDev ? $e->getMessage() : 'حدث خطأ في البحث، الرجاء المحاولة لاحقاً.', 500);
        }
    }

    // =========================================================================
    // Single offer (with available services / seat info)
    // =========================================================================

    public function getOffer(Request $request): void
    {
        $offerId = (string) $request->param('id');
        if (empty($offerId)) Response::error('offer_id is required.', 400);

        try {
            $duffel = new DuffelAdapter();
            $data   = $duffel->getOfferWithServices($offerId);
            Response::json($data);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            Response::error('حدث خطأ أثناء جلب تفاصيل العرض.', 500);
        }
    }

    // =========================================================================
    // Seat maps
    // =========================================================================

    public function getSeatMaps(Request $request): void
    {
        $offerId = (string) ($request->input('offer_id') ?? '');
        if (empty($offerId)) Response::error('offer_id is required.', 400);

        try {
            $duffel = new DuffelAdapter();
            $data   = $duffel->getSeatMaps($offerId);
            Response::json($data);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            Response::error('حدث خطأ أثناء جلب خريطة المقاعد.', 500);
        }
    }

    // =========================================================================
    // Airport search (autocomplete)
    // =========================================================================

    public function searchAirports(Request $request): void
    {
        $query = trim((string) ($request->input('q') ?? $request->input('query') ?? ''));
        if (strlen($query) < 2) {
            Response::json(['data' => []]);
            return;
        }

        try {
            $duffel  = new DuffelAdapter();
            $results = $duffel->searchAirports($query);
            Response::json($results);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 502);
        } catch (\Throwable $e) {
            Response::error('Airport search unavailable.', 500);
        }
    }

    // =========================================================================
    // Checkout flow
    // =========================================================================

    public function startCheckout(Request $request): void
    {
        $errors = $request->validate(['offer_id' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $result = $this->bookingService->startCheckout(
                (string) $request->input('offer_id'),
                (int) $user['id']
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function savePassengers(Request $request): void
    {
        $errors = $request->validate(['session_key' => 'required', 'passengers' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $this->bookingService->savePassengers(
                (string) $request->input('session_key'),
                (array)  $request->input('passengers'),
                (int)    $user['id']
            );
            Response::json(['message' => 'Passengers saved.', 'next_step' => 'services']);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function saveServices(Request $request): void
    {
        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $this->bookingService->saveServices(
                (string) $request->input('session_key'),
                (array)  ($request->input('services') ?? []),
                (int)    $user['id']
            );
            Response::json(['message' => 'Services saved.', 'next_step' => 'review']);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function priceOffer(Request $request): void
    {
        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $result = $this->bookingService->priceOfferWithServices(
                (string) $request->input('session_key'),
                (array)  ($request->input('services') ?? []),
                (int)    $user['id']
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function review(Request $request): void
    {
        $sessionKey = (string) ($request->input('session_key') ?? '');
        if (empty($sessionKey)) Response::error('session_key is required.', 400);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $data = $this->bookingService->getReview($sessionKey, (int) $user['id']);
            Response::json($data);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function createPaymentIntent(Request $request): void
    {
        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $result = $this->bookingService->createPaymentIntent(
                (string) $request->input('session_key'),
                (int)    $user['id'],
                $request->input('coupon_code') ? (string) $request->input('coupon_code') : null
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // =========================================================================
    // Confirm checkout after Stripe payment
    // =========================================================================

    public function confirmCheckout(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $sessionKey = (string) ($request->input('session_key') ?? '');
        $piId       = (string) ($request->input('payment_intent_id') ?? '');

        try {
            $result = $this->bookingService->confirmCheckout($sessionKey, $piId, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // =========================================================================
    // Card payment: Step 1 — tokenise card + create 3DS session
    // =========================================================================

    public function initCardPayment(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $body       = $request->json() ?: [];
        $sessionKey = (string) ($body['session_key'] ?? '');
        $coupon     = isset($body['coupon_code']) ? (string) $body['coupon_code'] : null;
        $cardData   = $body['card'] ?? [];

        if (empty($sessionKey) || empty($cardData)) {
            Response::error('session_key and card are required.', 422);
            return;
        }

        // Forward traveller device details for Duffel fraud detection.
        $deviceIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($deviceIp !== null && str_contains($deviceIp, ',')) {
            $deviceIp = trim(explode(',', $deviceIp)[0]);
        }
        $deviceUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        try {
            $result = $this->bookingService->initCardPayment(
                $sessionKey,
                (int) $user['id'],
                $cardData,
                $coupon,
                $deviceIp,
                $deviceUserAgent
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // =========================================================================
    // Card payment: Step 2 — complete booking after 3DS auth
    // =========================================================================

    public function completeCardBooking(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $body         = $request->json() ?: [];
        $sessionKey   = (string) ($body['session_key'] ?? '');
        $tdsSessionId = (string) ($body['three_d_secure_session_id'] ?? '');

        if (empty($sessionKey) || empty($tdsSessionId)) {
            Response::error('session_key and three_d_secure_session_id are required.', 422);
            return;
        }

        try {
            $result = $this->bookingService->completeCardBooking($sessionKey, $tdsSessionId, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // =========================================================================
    // User bookings
    // =========================================================================

    public function listBookings(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        $bookings = $this->bookingService->getUserBookings((int) $user['id']);
        Response::json(['bookings' => $bookings, 'count' => count($bookings)]);
    }

    public function getBooking(Request $request): void
    {
        $idParam = $request->param('id') ?? '';
        $user    = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        // Support both numeric booking ID (e.g. 42) and FM reference (e.g. FM00000001)
        $booking = is_numeric($idParam)
            ? $this->bookingService->getBookingById((int) $idParam, (int) $user['id'])
            : $this->bookingService->getBookingByReference((string) $idParam, (int) $user['id']);

        if ($booking === null) Response::notFound('Booking not found.');
        Response::json(['booking' => $booking]);
    }

    public function syncBooking(Request $request): void
    {
        $id   = (int) $request->param('id');
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        try {
            $result = $this->bookingService->syncFromDuffel($id, (int) $user['id']);
            Response::json(['booking' => $result, 'synced' => true]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // =========================================================================
    // Booking cancellation
    // =========================================================================

    /**
     * Step 1: Get refund quote for a booking cancellation.
     * Returns refund_amount and refund_to before committing.
     */
    public function cancelBooking(Request $request): void
    {
        $id   = (int) $request->param('id');
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->cancelBooking($id, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    /**
     * Step 2: Confirm the cancellation after reviewing the refund quote.
     */
    public function confirmCancelBooking(Request $request): void
    {
        $errors = $request->validate(['cancellation_id' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $id             = (int) $request->param('id');
        $cancellationId = (string) $request->input('cancellation_id');
        $user           = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->confirmCancelBooking($id, $cancellationId, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
