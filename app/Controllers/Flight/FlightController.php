<?php

declare(strict_types=1);

namespace App\Controllers\Flight;

use App\Adapters\Duffel\DuffelAdapter;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\EmailNotificationService;
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

            // Apply the platform commission to the headline price so a guest (who
            // sees this public offer before a checkout session exists) is shown
            // the same price that will be charged at payment. Duffel wraps the
            // offer in { data: {...} }; fall back to a flat shape just in case.
            $commission = new \App\Services\CommissionService();
            if (isset($data['data']['total_amount'])) {
                $data['data']['net_amount']   = $data['data']['total_amount'];
                $data['data']['total_amount'] = number_format($commission->markup((float) $data['data']['total_amount'], 'flight'), 2, '.', '');
            } elseif (isset($data['total_amount'])) {
                $data['net_amount']   = $data['total_amount'];
                $data['total_amount'] = number_format($commission->markup((float) $data['total_amount'], 'flight'), 2, '.', '');
            }

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

    /**
     * Guest checkout entry point (no auth). Creates/reuses a guest account from
     * the lead contact details, issues a session token, and starts checkout —
     * so the rest of the (auth-required) flow works unchanged. On the first
     * confirmed booking the guest is upgraded to a real account (see
     * maybeCreateGuestAccount).
     */
    public function guestStart(Request $request): void
    {
        $errors = $request->validate([
            'offer_id'     => 'required',
            'email'        => 'required',
            'first_name'   => 'required',
            'phone_number' => 'required',
        ]);
        if (!empty($errors)) Response::validationError($errors);

        $email = strtolower(trim((string) $request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('بريد إلكتروني غير صالح.', 422);
            return;
        }

        try {
            $session = (new AuthService())->createGuestSession(
                $email,
                (string) $request->input('first_name'),
                (string) ($request->input('last_name') ?? ''),
                (string) ($request->input('phone_country_code') ?? ''),
                (string) $request->input('phone_number'),
                $request->ip(),
                $request->userAgent()
            );

            $start = $this->bookingService->startCheckout(
                (string) $request->input('offer_id'),
                (int) $session['user']['id']
            );

            Response::json(array_merge($start, [
                'session_token' => $session['session_token'],
                'expires_at'    => $session['expires_at'],
                'user'          => $session['user'],
                'guest'         => true,
            ]));
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage() === 'email_has_account'
                ? 'هذا البريد لديه حساب بالفعل. يرجى تسجيل الدخول للمتابعة.'
                : $e->getMessage();
            Response::error($msg, $e->getCode() ?: 400);
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
            $walletAmount = (float) ($request->input('wallet_amount') ?? 0);
            $pkgInput = $request->input('package');
            $result = $this->bookingService->createPaymentIntent(
                (string) $request->input('session_key'),
                (int)    $user['id'],
                $request->input('coupon_code') ? (string) $request->input('coupon_code') : null,
                $walletAmount,
                is_array($pkgInput) ? $pkgInput : null
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function walletConfirm(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $sessionKey = (string) ($request->input('session_key') ?? '');
        if (empty($sessionKey)) Response::error('session_key مطلوب.', 400);

        try {
            $result = $this->bookingService->walletCheckout(
                $sessionKey,
                (int) $user['id'],
                $request->input('coupon_code') ? (string) $request->input('coupon_code') : null
            );
            Response::json(array_merge($result, ['status' => 'confirmed']));
        } catch (\Throwable $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
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
            $userId = (int) $user['id'];
            $result = $this->bookingService->confirmCheckout($sessionKey, $piId, $userId);

            // Guest auto-account creation: if booking confirmed and user is a guest
            // (identified by is_guest flag on the user record), create a real account
            // and send a welcome email with credentials.
            if (($result['status'] ?? '') === 'confirmed') {
                try {
                    $this->maybeCreateGuestAccount($userId, $result['booking_reference'] ?? '');
                } catch (\Throwable $ge) {
                    // Non-fatal: log but don't fail the booking confirmation
                    error_log('[GUEST_ACCOUNT_CREATE] ' . $ge->getMessage());
                }
            }

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
            if (empty($b['provider_order_id'])) return $b;
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

        $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);

        // Support both numeric booking ID and FM/HM reference
        if ($isAdmin && !is_numeric($idParam)) {
            // Admins can view any booking by reference without user_id restriction
            $booking = $this->bookingService->getBookingByReferenceAdmin((string) $idParam);
        } elseif (is_numeric($idParam)) {
            $booking = $this->bookingService->getBookingById((int) $idParam, (int) $user['id']);
        } else {
            $booking = $this->bookingService->getBookingByReference((string) $idParam, (int) $user['id']);
        }

        if ($booking === null) Response::notFound('Booking not found.');

        // Auto-sync with Duffel to get latest status on every view
        if (!empty($booking['provider_order_id'])) {
            try {
                $synced  = $this->bookingService->syncFromDuffelByBookingId((int) $booking['id']);
                $booking = $synced;
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

        $id               = (int) $request->param('id');
        $cancellationId   = (string) $request->input('cancellation_id');
        $refundPreference = (string) ($request->input('refund_preference') ?? 'original_payment');
        if (!in_array($refundPreference, ['wallet', 'original_payment'], true)) {
            $refundPreference = 'original_payment';
        }
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $result = $this->bookingService->confirmCancelBooking($id, $cancellationId, (int) $user['id'], $refundPreference);
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

    // =========================================================================
    // Guest auto-account creation after successful booking
    // =========================================================================

    /**
     * If the user is flagged as a guest (is_guest = 1), generate a real password,
     * update their account, and send a welcome email with the credentials.
     *
     * The is_guest column must exist in the users table (migration 080 or later).
     * If the column is missing this method silently returns.
     */
    private function maybeCreateGuestAccount(int $userId, string $bookingRef): void
    {
        $db = \App\Helpers\Database::getInstance();

        // Fetch user and check guest flag — if column absent, the query still works
        // because we SELECT with a fallback in PHP.
        $stmt = $db->prepare('SELECT id, email, first_name, last_name, phone_number, is_guest FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$userRow || empty($userRow['is_guest'])) {
            return; // Not a guest — nothing to do.
        }

        $email     = $userRow['email']      ?? '';
        $firstName = $userRow['first_name'] ?? '';
        $phone     = $userRow['phone_number'] ?? '';

        if (!$email) return;

        // Generate a deterministic but safe password:
        // first 3 chars of first_name (lowercase) + last 4 digits of phone,
        // falling back to a random 8-char password.
        $namePart  = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $firstName), 0, 3));
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $phonePart = strlen($phoneDigits) >= 4 ? substr($phoneDigits, -4) : '';

        if (strlen($namePart) >= 2 && strlen($phonePart) === 4) {
            $plainPassword = $namePart . $phonePart;
        } else {
            $plainPassword = substr(bin2hex(random_bytes(5)), 0, 8);
        }

        // Update the hashed password and clear the guest flag.
        $hash = password_hash($plainPassword, PASSWORD_ARGON2ID);
        $upd  = $db->prepare('UPDATE users SET password = :pwd, is_guest = 0 WHERE id = :id');
        $upd->execute([':pwd' => $hash, ':id' => $userId]);

        // Send welcome email (non-fatal if mail fails).
        $name = trim($firstName . ' ' . ($userRow['last_name'] ?? ''));
        (new EmailNotificationService($db))->sendWelcomeGuestEmail($email, $name ?: $email, $plainPassword, $bookingRef);
    }
}
