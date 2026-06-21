<?php

declare(strict_types=1);

namespace App\Controllers\Flight;

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
}
