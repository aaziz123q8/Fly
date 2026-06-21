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
            $offers = $this->searchService->search($request->json() ?: [], $userId);
            Response::json(['offers' => $offers, 'count' => count($offers)]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 502);
        } catch (\Throwable $e) {
            $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
            Response::error($isDev ? $e->getMessage() : 'حدث خطأ أثناء البحث. الرجاء المحاولة لاحقاً.', 500);
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

    public function review(Request $request): void
    {
        $sessionKey = (string) ($request->input('session_key') ?? '');
        if (empty($sessionKey)) Response::error('session_key is required.', 400);

        $user = AuthMiddleware::currentUser();
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
    // User bookings
    // =========================================================================

    public function listBookings(Request $request): void
    {
        $user     = AuthMiddleware::currentUser();
        $bookings = $this->bookingService->getUserBookings((int) $user['id']);
        Response::json(['bookings' => $bookings, 'count' => count($bookings)]);
    }

    public function getBooking(Request $request): void
    {
        $id      = (int) $request->param('id');
        $user    = AuthMiddleware::currentUser();
        $booking = $this->bookingService->getBookingById($id, (int) $user['id']);

        if ($booking === null) Response::notFound('Booking not found.');

        Response::json(['booking' => $booking]);
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

        try {
            $result = $this->bookingService->confirmCancelBooking($id, $cancellationId, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
