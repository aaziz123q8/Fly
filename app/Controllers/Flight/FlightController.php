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
            $data   = $duffel->getOffer($offerId, true);
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
        } catch (\Throwable $e) {
            $isDebug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN)
                    || (getenv('APP_ENV') ?: 'production') === 'development';
            $msg = $isDebug
                ? sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
                : $e->getMessage();
            error_log(sprintf('[CONFIRM_CHECKOUT_CONTROLLER] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            Response::error($msg, ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
        }
    }

    // =========================================================================
    // Card payment: Step 1 — tokenise card + create 3DS session
    // =========================================================================

    public function initCardPayment(Request $request): void
    {
        // Duffel Cards is disabled — payment is handled exclusively via Stripe.
        Response::error('تم تعطيل Duffel Cards. يتم الدفع حالياً عبر Stripe فقط.', 410);
    }

    // =========================================================================
    // Card payment: Step 2 — DISABLED (Duffel Cards removed)
    // =========================================================================

    public function completeCardBooking(Request $request): void
    {
        // Duffel Cards is disabled — payment is handled exclusively via Stripe.
        Response::error('تم تعطيل Duffel Cards. يتم الدفع حالياً عبر Stripe فقط.', 410);
    }

    // =========================================================================
    // User bookings
    // =========================================================================

    public function listBookings(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();
        $bookings = $this->bookingService->getUserBookings((int) $user['id']);

        // Auto-sync each booking with Duffel (non-fatal if it fails)
        $userId = (int) $user['id'];
        $bookings = array_map(function (array $b) use ($userId): array {
            if (empty($b['duffel_order_id'])) return $b;
            try {
                return $this->bookingService->syncFromDuffel((int) $b['id'], $userId);
            } catch (\Throwable $e) {
                return $b;
            }
        }, $bookings);

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

        // Auto-sync with Duffel to get latest status on every view
        if (!empty($booking['duffel_order_id'])) {
            try {
                $booking = $this->bookingService->syncFromDuffel((int) $booking['id'], (int) $user['id']);
            } catch (\Throwable $e) {
                // Sync failure is non-fatal — return cached data
            }
        }

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

    /**
     * Flight change Step 1: search available alternative flights.
     */
    public function searchFlightChange(Request $request): void
    {
        $errors = $request->validate(['new_date' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $id      = (int) $request->param('id');
        $newDate = (string) $request->input('new_date');
        $user    = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->searchFlightChange($id, (int) $user['id'], $newDate);
            Response::json($result);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $raw  = $e->getMessage();
            // Translate known Duffel error codes to friendly Arabic messages
            if (str_contains($raw, 'order_not_changeable') || str_contains($raw, 'cannot be changed')) {
                $msg = 'هذا الحجز لا يدعم التغيير الإلكتروني عبر الناقل الجوي. يرجى التواصل معنا عبر واتساب لتعديل رحلتك.';
                $code = 422;
            } elseif (str_contains($raw, 'no_availability') || str_contains($raw, 'no_flights')) {
                $msg = 'لا توجد رحلات بديلة متاحة في هذا التاريخ.';
                $code = 422;
            } else {
                $msg = 'تعذر البحث عن رحلات بديلة. ' . preg_replace('/\{.*\}/s', '', $raw);
            }
            Response::error(trim($msg), ($code >= 400 && $code < 600) ? $code : 422);
        }
    }

    /**
     * Flight change Step 2: confirm selected change offer.
     */
    public function confirmFlightChange(Request $request): void
    {
        $errors = $request->validate(['change_offer_id' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $id            = (int) $request->param('id');
        $changeOfferId = (string) $request->input('change_offer_id');
        $user          = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $paymentIntentId = $request->input('payment_intent_id')
            ? (string) $request->input('payment_intent_id')
            : null;

        try {
            $result = $this->bookingService->confirmFlightChange($id, (int) $user['id'], $changeOfferId, $paymentIntentId);
            Response::json($result);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $msg  = $e->getMessage() ?: 'تعذر تأكيد تغيير الرحلة';
            Response::error($msg, ($code >= 400 && $code < 600) ? $code : 422);
        }
    }

    public function createChangePaymentIntent(Request $request): void
    {
        $errors = $request->validate(['change_offer_id' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $id   = (int) $request->param('id');
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->createChangePaymentIntent(
                $id,
                (int) $user['id'],
                (string) $request->input('change_offer_id')
            );
            Response::json($result);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
        }
    }

    /**
     * Get available ancillary services (bags/seats) for a booking.
     */
    public function getAvailableServices(Request $request): void
    {
        $id   = (int) $request->param('id');
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->getAvailableServices($id, (int) $user['id']);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
