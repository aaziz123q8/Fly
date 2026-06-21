<?php

declare(strict_types=1);

namespace App\Controllers\Hotel;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\RateLimiter;
use App\Middleware\AuthMiddleware;
use App\Services\HotelBookingService;
use App\Services\HotelSearchService;

class HotelController
{
    private HotelSearchService  $searchService;
    private HotelBookingService $bookingService;

    public function __construct()
    {
        $this->searchService  = new HotelSearchService();
        $this->bookingService = new HotelBookingService();
    }

    // =========================================================================
    // POST /api/hotels/search
    // =========================================================================

    public function search(Request $request): void
    {
        // Rate limit: 10 per minute
        $ip = $this->resolveIp();
        $rateLimiter = new RateLimiter();
        if (!$rateLimiter->allow($ip, 'ip', 'hotel_search', 10, 60)) {
            Response::error('Too many requests. Please slow down.', 429);
        }

        $errors = $request->validate([
            'check_in'  => 'required',
            'check_out' => 'required',
            'adults'    => 'required',
        ]);

        // Custom validation: require city_id OR ratehawk_region_id
        $body   = $request->json() ?: [];
        $cityId  = $body['city_id']            ?? null;
        $regionId = $body['ratehawk_region_id'] ?? null;

        if (empty($cityId) && empty($regionId)) {
            $errors['city_id'] = 'Either city_id or ratehawk_region_id is required.';
        }

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $user   = AuthMiddleware::currentUser();
        $userId = $user ? (int) $user['id'] : 0;

        try {
            $hotels = $this->searchService->search($body, $userId);
            Response::json(['hotels' => $hotels, 'count' => count($hotels)]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 502);
        }
    }

    // =========================================================================
    // GET /api/hotels/:provider_hotel_id
    // =========================================================================

    public function detail(Request $request): void
    {
        $providerHotelId = (string) $request->param('provider_hotel_id');

        if (empty($providerHotelId)) {
            Response::error('provider_hotel_id is required.', 400);
        }

        try {
            $hotel = $this->searchService->getHotelDetail($providerHotelId);

            if ($hotel === null) {
                Response::notFound('Hotel not found.');
            }

            Response::json(['hotel' => $hotel]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }

    // =========================================================================
    // POST /api/hotels/checkout/prebook
    // =========================================================================

    public function prebook(Request $request): void
    {
        $errors = $request->validate([
            'book_hash'       => 'required',
            'check_in'        => 'required',
            'check_out'       => 'required',
            'hotel_id'        => 'required',
            'displayed_price' => 'required',
        ]);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();

        try {
            $result = $this->bookingService->prebook(
                bookHash:       (string) $request->input('book_hash'),
                userId:         (int)    $user['id'],
                checkIn:        (string) $request->input('check_in'),
                checkOut:       (string) $request->input('check_out'),
                hotelId:        (int)    $request->input('hotel_id'),
                displayedPrice: (float)  $request->input('displayed_price')
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    // =========================================================================
    // POST /api/hotels/checkout/guests
    // =========================================================================

    public function saveGuests(Request $request): void
    {
        $errors = $request->validate([
            'session_key' => 'required',
            'guests'      => 'required',
        ]);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();

        $guests = $request->input('guests');
        if (!is_array($guests)) {
            Response::validationError(['guests' => 'guests must be an array.']);
        }

        try {
            $this->bookingService->saveGuests(
                sessionKey:      (string) $request->input('session_key'),
                guests:          $guests,
                userId:          (int)    $user['id'],
                specialRequests: $request->input('special_requests')
                                  ? (string) $request->input('special_requests')
                                  : null
            );
            Response::json(['message' => 'Guests saved.', 'next_step' => 'payment']);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    // =========================================================================
    // POST /api/hotels/checkout/payment-intent
    // =========================================================================

    public function createPaymentIntent(Request $request): void
    {
        $errors = $request->validate(['session_key' => 'required']);
        if (!empty($errors)) Response::validationError($errors);

        $user = AuthMiddleware::currentUser();

        try {
            $result = $this->bookingService->createPaymentIntent(
                sessionKey: (string) $request->input('session_key'),
                userId:     (int)    $user['id'],
                couponCode: $request->input('coupon_code')
                             ? (string) $request->input('coupon_code')
                             : null
            );
            Response::json([
                'client_secret'     => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
                'amount'            => $result['amount'],
                'currency'          => $result['currency'],
            ]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    // =========================================================================
    // GET /api/hotels/bookings
    // =========================================================================

    public function listBookings(Request $request): void
    {
        $user     = AuthMiddleware::currentUser();
        $bookings = $this->bookingService->getUserBookings((int) $user['id']);
        Response::json(['bookings' => $bookings, 'count' => count($bookings)]);
    }

    // =========================================================================
    // GET /api/hotels/bookings/:id
    // =========================================================================

    public function getBooking(Request $request): void
    {
        $id      = (int) $request->param('id');
        $user    = AuthMiddleware::currentUser();
        $booking = $this->bookingService->getBookingById($id, (int) $user['id']);

        if ($booking === null) {
            Response::notFound('Booking not found.');
        }

        Response::json(['booking' => $booking]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function resolveIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
