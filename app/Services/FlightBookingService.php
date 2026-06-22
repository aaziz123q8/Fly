<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\Duffel\DuffelAdapter;
use App\Adapters\Stripe\StripeAdapter;
use App\Helpers\Database;
use PDO;
use RuntimeException;

class FlightBookingService
{
    private PDO $db;
    private DuffelAdapter $duffel;
    private StripeAdapter $stripe;
    private BookingSessionService $sessionService;

    public function __construct(
        ?PDO                   $db             = null,
        ?DuffelAdapter         $duffel         = null,
        ?StripeAdapter         $stripe         = null,
        ?BookingSessionService $sessionService = null
    ) {
        $this->db             = $db             ?? Database::getInstance();
        $this->duffel         = $duffel         ?? new DuffelAdapter();
        $this->stripe         = $stripe         ?? new StripeAdapter();
        $this->sessionService = $sessionService ?? new BookingSessionService($this->db);
    }

    // =========================================================================
    // startCheckout
    // =========================================================================

    /**
     * Begin checkout for a given offer.
     *
     * @throws RuntimeException  code 410 if offer expired/not found
     */
    public function startCheckout(string $offerId, int $userId): array
    {
        $offer = $this->fetchOffer($offerId);

        if ($offer === null) {
            throw new RuntimeException('Offer expired or not found', 410);
        }

        $sessionKey = $this->sessionService->create($userId, 'flight');

        $this->sessionService->update($sessionKey, [
            'provider_offer_id' => $offerId,
            'offer_expires_at'  => $offer['expires_at'],
            'current_step'      => 'passengers',
        ]);

        $offerData = json_decode($offer['offer_data'], true);
        if (!is_array($offerData)) {
            throw new RuntimeException('Offer data is corrupted.', 500);
        }

        return [
            'session_key'      => $sessionKey,
            'offer'            => $this->formatOffer($offerData),
            'offer_expires_at' => $offer['expires_at'],
        ];
    }

    // =========================================================================
    // savePassengers
    // =========================================================================

    public function savePassengers(string $sessionKey, array $passengers, int $userId): bool
    {
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['passengers', 'services'], true)) {
            throw new RuntimeException('Invalid step for saving passengers.', 422);
        }

        // Validate each passenger record.
        $required = ['first_name', 'last_name', 'gender', 'date_of_birth',
                     'nationality', 'document_number', 'document_expiry'];

        foreach ($passengers as $idx => $p) {
            foreach ($required as $field) {
                if (empty($p[$field])) {
                    throw new RuntimeException("Passenger {$idx}: {$field} is required.", 422);
                }
            }
        }

        return $this->sessionService->update($sessionKey, [
            'passengers_data' => $passengers,
            'current_step'    => 'services',
        ]);
    }

    // =========================================================================
    // saveServices
    // =========================================================================

    public function saveServices(string $sessionKey, array $services, int $userId): bool
    {
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['services', 'review'], true)) {
            throw new RuntimeException('Invalid step for saving services.', 422);
        }

        return $this->sessionService->update($sessionKey, [
            'services_data' => $services,
            'current_step'  => 'review',
        ]);
    }

    // =========================================================================
    // getReview
    // =========================================================================

    public function getReview(string $sessionKey, int $userId): array
    {
        $session = $this->requireSession($sessionKey, $userId);

        $offerId = $session['provider_offer_id'] ?? null;
        if (!$offerId) {
            throw new RuntimeException('No offer associated with this session.', 422);
        }

        $offer = $this->fetchOffer($offerId);
        if ($offer === null) {
            throw new RuntimeException('Offer expired or not found', 410);
        }

        $offerData   = json_decode($offer['offer_data'], true);
        $baseAmount  = (float) ($offer['total_amount'] ?? 0);
        $currency    = strtoupper($offer['currency'] ?? 'GBP');

        // Fetch active pricing rules.
        $pricingSnapshot = $this->calculatePricing($baseAmount, $currency);

        $this->sessionService->update($sessionKey, [
            'pricing_snapshot' => $pricingSnapshot,
            'current_step'     => 'payment',
        ]);

        $passengersData = is_string($session['passengers_data'])
            ? json_decode($session['passengers_data'], true)
            : ($session['passengers_data'] ?? []);

        return [
            'session'          => [
                'session_key'  => $session['session_key'],
                'current_step' => 'payment',
                'expires_at'   => $session['expires_at'],
            ],
            'offer'            => $this->formatOffer($offerData),
            'passengers_data'  => $passengersData ?? [],
            'pricing_snapshot' => $pricingSnapshot,
        ];
    }

    // =========================================================================
    // createPaymentIntent
    // =========================================================================

    public function createPaymentIntent(
        string  $sessionKey,
        int     $userId,
        ?string $couponCode = null
    ): array {
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['payment', 'services'], true)) {
            throw new RuntimeException('Session is not at the payment step.', 422);
        }

        // Get pricing snapshot (may already exist from review step).
        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? null);

        if (empty($pricingSnapshot)) {
            // Recalculate if missing.
            $offerId = $session['provider_offer_id'] ?? '';
            $offer   = $this->fetchOffer($offerId);
            if ($offer === null) {
                throw new RuntimeException('Offer expired or not found', 410);
            }
            $baseAmount      = (float) $offer['total_amount'];
            $currency        = strtoupper($offer['currency'] ?? 'GBP');
            $pricingSnapshot = $this->calculatePricing($baseAmount, $currency);
        }

        $totalAmount = (float) ($pricingSnapshot['total'] ?? 0);
        $currency    = strtolower($pricingSnapshot['currency'] ?? 'gbp');

        // Apply coupon if provided.
        $discountAmount = 0.0;
        if ($couponCode !== null && $couponCode !== '') {
            $coupon = $this->findActiveCoupon($couponCode);
            if ($coupon) {
                if ($coupon['discount_type'] === 'percentage') {
                    $discountAmount = round($totalAmount * ((float) $coupon['discount_value'] / 100), 2);
                } else {
                    $discountAmount = min($totalAmount, (float) $coupon['discount_value']);
                }
                $totalAmount -= $discountAmount;
                $totalAmount  = max(0, $totalAmount);

                $pricingSnapshot['coupon_code']     = $couponCode;
                $pricingSnapshot['discount_amount'] = $discountAmount;
                $pricingSnapshot['total']           = $totalAmount;
            }
        }

        // Amount in pence (minor units).
        $amountInPence = (int) round($totalAmount * 100);

        $idempotencyKey = bin2hex(random_bytes(32));

        // Insert payment record (placeholder booking_id = 0).
        $payStmt = $this->db->prepare(
            'INSERT INTO payments
               (booking_type, booking_id, user_id, payment_method,
                idempotency_key, amount, currency, status)
             VALUES
               (:booking_type, 0, :user_id, :method,
                :idem_key, :amount, :currency, :status)'
        );
        $payStmt->execute([
            ':booking_type' => 'flight',
            ':user_id'      => $userId,
            ':method'       => 'stripe',
            ':idem_key'     => $idempotencyKey,
            ':amount'       => number_format($totalAmount, 2, '.', ''),
            ':currency'     => strtoupper($currency),
            ':status'       => 'pending',
        ]);
        $paymentId = (int) $this->db->lastInsertId();

        // Create Stripe PaymentIntent.
        $stripeResult = $this->stripe->createPaymentIntent(
            $amountInPence,
            $idempotencyKey,
            $currency,
            [
                'session_key'  => $sessionKey,
                'booking_type' => 'flight',
                'user_id'      => (string) $userId,
            ]
        );

        $paymentIntentId = $stripeResult['payment_intent_id'];

        // Update payment row with Stripe PI id.
        $this->db->prepare(
            'UPDATE payments SET stripe_payment_intent_id = :pi WHERE id = :id'
        )->execute([':pi' => $paymentIntentId, ':id' => $paymentId]);

        // Update session.
        $this->sessionService->update($sessionKey, [
            'payment_intent_id' => $paymentIntentId,
            'idempotency_key'   => $idempotencyKey,
            'coupon_code'       => $couponCode,
            'pricing_snapshot'  => $pricingSnapshot,
            'current_step'      => 'payment',
        ]);

        return [
            'client_secret'     => $stripeResult['client_secret'],
            'payment_intent_id' => $paymentIntentId,
            'amount'            => $totalAmount,
            'base'              => round($totalAmount / 1.1, 2),
            'tax'               => round($totalAmount - ($totalAmount / 1.1), 2),
            'discount'          => $discountAmount,
            'currency'          => strtoupper($currency),
        ];
    }

    // =========================================================================
    // completeBooking  (called by WebhookController after payment_intent.succeeded)
    // =========================================================================

    public function completeBooking(string $sessionKey, string $paymentIntentId): array
    {
        // Look up session by payment_intent_id.
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions
             WHERE payment_intent_id = :pi AND booking_type = :bt
             LIMIT 1'
        );
        $stmt->execute([':pi' => $paymentIntentId, ':bt' => 'flight']);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new RuntimeException('Booking session not found for payment intent.', 404);
        }

        $passengersData = is_string($session['passengers_data'])
            ? json_decode($session['passengers_data'], true)
            : ($session['passengers_data'] ?? []);

        $servicesData = is_string($session['services_data'])
            ? json_decode($session['services_data'], true)
            : ($session['services_data'] ?? []);

        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? []);

        $offerId = $session['provider_offer_id'];
        $userId  = (int) $session['user_id'];

        $offer = $this->fetchOffer($offerId);
        if ($offer === null) {
            throw new RuntimeException('Offer no longer available.', 410);
        }
        $offerData = json_decode($offer['offer_data'], true);

        // Generate unique booking reference in FM00000001 format — atomic via table lock.
        $this->db->exec("LOCK TABLES flight_bookings WRITE");
        try {
            $lastRef = $this->db->query(
                "SELECT booking_reference FROM flight_bookings ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            if ($lastRef && preg_match('/^FM(\d+)$/', $lastRef, $m)) {
                $nextNum = (int)$m[1] + 1;
            } else {
                $nextNum = (int)$this->db->query("SELECT COUNT(*) FROM flight_bookings")->fetchColumn() + 1;
            }
            $bookingReference = 'FM' . str_pad((string)$nextNum, 8, '0', STR_PAD_LEFT);
        } finally {
            $this->db->exec("UNLOCK TABLES");
        }

        // Map passengers for Duffel (add required Duffel fields).
        $duffelPassengers = $this->mapPassengersForDuffel($passengersData, $offerData);

        // Pre-price the offer with selected services to get the authoritative amount.
        // This catches any price changes since the user last viewed the offer.
        try {
            $priceResponse  = $this->duffel->priceOffer($offerId, ['balance'], $servicesData ?: []);
            $pricedAmount   = $priceResponse['data']['total_amount']   ?? $offer['total_amount'];
            $pricedCurrency = strtoupper($priceResponse['data']['total_currency'] ?? $offer['currency']);
        } catch (\Throwable) {
            // Fall back to cached offer amount if pricing endpoint fails
            $pricedAmount   = $offer['total_amount'];
            $pricedCurrency = strtoupper($offer['currency'] ?? 'GBP');
        }

        // Build Duffel payment payload using priced amount.
        $duffelPayments = [[
            'type'     => 'balance',
            'amount'   => (string) $pricedAmount,
            'currency' => $pricedCurrency,
        ]];

        // Attach metadata for traceability in Duffel dashboard.
        $metadata = [
            'booking_reference' => $bookingReference,
            'user_id'           => (string) $userId,
            'platform'          => 'flymasar',
        ];

        // Create Duffel order.
        $orderResponse = $this->duffel->createOrder(
            $offerId,
            $duffelPassengers,
            $duffelPayments,
            $servicesData ?: [],
            $metadata
        );

        $order        = $orderResponse['data'] ?? [];
        $providerOrderId = $order['id'] ?? '';

        // Determine trip type.
        $slices    = $offerData['slices'] ?? [];
        $tripType  = count($slices) > 1 ? 'round_trip' : 'one_way';
        $cabinClass = $offerData['cabin_class'] ?? ($slices[0]['segments'][0]['passengers'][0]['cabin_class'] ?? 'economy');

        $firstSlice = $slices[0] ?? [];
        $origin     = $firstSlice['origin']['iata_code'] ?? '';
        $dest       = $firstSlice['destination']['iata_code'] ?? '';
        $departureAt = $firstSlice['segments'][0]['departing_at'] ?? null;

        $adults   = 0;
        $children = 0;
        foreach (($offerData['passengers'] ?? []) as $p) {
            if (($p['type'] ?? '') === 'adult') {
                $adults++;
            } else {
                $children++;
            }
        }

        $totalAmount = $offer['total_amount'];
        $currency    = strtoupper($offer['currency'] ?? 'GBP');

        // Insert flight_bookings row.
        $bStmt = $this->db->prepare(
            'INSERT INTO flight_bookings
               (user_id, provider_id, booking_reference, provider_order_id,
                trip_type, cabin_class, adults_count, children_count,
                origin_airport, destination_airport, departure_at,
                total_amount, currency, status)
             VALUES
               (:user_id, 1, :ref, :provider_order_id,
                :trip_type, :cabin_class, :adults, :children,
                :origin, :dest, :departure_at,
                :amount, :currency, :status)'
        );
        $bStmt->execute([
            ':user_id'          => $userId,
            ':ref'              => $bookingReference,
            ':provider_order_id'=> $providerOrderId,
            ':trip_type'        => $tripType,
            ':cabin_class'      => $cabinClass,
            ':adults'           => $adults,
            ':children'         => $children,
            ':origin'           => $origin,
            ':dest'             => $dest,
            ':departure_at'     => $departureAt,
            ':amount'           => $totalAmount,
            ':currency'         => $currency,
            ':status'           => 'confirmed',
        ]);
        $bookingId = (int) $this->db->lastInsertId();

        // Insert flight_booking_passengers.
        $pStmt = $this->db->prepare(
            'INSERT INTO flight_booking_passengers
               (booking_id, passenger_type, first_name, last_name, gender,
                date_of_birth, nationality, passport_number, passport_expiry)
             VALUES
               (:booking_id, :passenger_type, :first_name, :last_name, :gender,
                :dob, :nationality, :passport_number, :passport_expiry)'
        );
        foreach ($passengersData as $passenger) {
            $pStmt->execute([
                ':booking_id'      => $bookingId,
                ':passenger_type'  => $passenger['type'] ?? 'adult',
                ':first_name'      => $passenger['first_name'],
                ':last_name'       => $passenger['last_name'],
                ':gender'          => $passenger['gender'],
                ':dob'             => $passenger['date_of_birth'],
                ':nationality'     => $passenger['nationality'],
                ':passport_number' => $passenger['passport_number'] ?? null,
                ':passport_expiry' => $passenger['passport_expiry'] ?? null,
            ]);
        }

        // Insert flight_booking_segments.
        $sStmt = $this->db->prepare(
            'INSERT INTO flight_booking_segments
               (booking_id, slice_index, segment_order,
                origin_airport, destination_airport,
                departure_at, arrival_at,
                flight_number, airline_code, aircraft_type)
             VALUES
               (:booking_id, :slice_index, :segment_order,
                :origin, :destination,
                :departing_at, :arriving_at,
                :flight_number, :carrier, :aircraft)'
        );
        foreach ($slices as $sliceIdx => $slice) {
            foreach (($slice['segments'] ?? []) as $segIdx => $seg) {
                $sStmt->execute([
                    ':booking_id'     => $bookingId,
                    ':slice_index'    => $sliceIdx,
                    ':segment_order'  => $segIdx + 1,
                    ':origin'         => $seg['origin']['iata_code']      ?? '',
                    ':destination'    => $seg['destination']['iata_code'] ?? '',
                    ':departing_at'   => $seg['departing_at']             ?? null,
                    ':arriving_at'    => $seg['arriving_at']              ?? null,
                    ':carrier'        => $seg['marketing_carrier']['iata_code'] ?? '',
                    ':flight_number'  => ($seg['marketing_carrier']['iata_code'] ?? '') . ($seg['marketing_carrier_flight_number'] ?? ''),
                    ':aircraft'       => $seg['aircraft']['iata_code']    ?? null,
                ]);
            }
        }

        // Update payment row.
        $this->db->prepare(
            'UPDATE payments
             SET booking_id = :bid, status = :status
             WHERE stripe_payment_intent_id = :pi'
        )->execute([':bid' => $bookingId, ':status' => 'succeeded', ':pi' => $paymentIntentId]);

        // Advance session step.
        $this->sessionService->update($session['session_key'], ['current_step' => 'complete']);

        // Queue jobs.
        $this->queueJobs($bookingId, $userId);

        return [
            'booking_id'        => $bookingId,
            'booking_reference' => $bookingReference,
        ];
    }

    // =========================================================================
    // confirmCheckout — called by payment.html after Stripe confirms
    // =========================================================================

    public function confirmCheckout(string $sessionKey, string $paymentIntentId, int $userId): array
    {
        // Look up session
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions WHERE session_key = :sk AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':sk' => $sessionKey, ':uid' => $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new \RuntimeException('جلسة الحجز غير موجودة', 404);
        }

        // If already complete, return booking reference
        if (($session['current_step'] ?? '') === 'complete') {
            $booking = $this->db->prepare(
                'SELECT booking_reference, status FROM flight_bookings WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
            );
            $booking->execute([':uid' => $userId]);
            $row = $booking->fetch(\PDO::FETCH_ASSOC);
            return [
                'status'            => 'confirmed',
                'booking_reference' => $row['booking_reference'] ?? '',
            ];
        }

        // Payment may still be processing via webhook — return pending
        return [
            'status'  => 'pending',
            'message' => 'جارٍ تأكيد الحجز…',
        ];
    }

    // =========================================================================
    // getUserBookings
    // =========================================================================

    public function getUserBookings(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT fb.*,
                    JSON_ARRAYAGG(
                        JSON_OBJECT(
                            "slice_index",   fbs.slice_index,
                            "segment_index", fbs.segment_index,
                            "origin",        fbs.origin_airport,
                            "destination",   fbs.destination_airport,
                            "departing_at",  fbs.departing_at,
                            "arriving_at",   fbs.arriving_at,
                            "carrier",       fbs.carrier_iata_code,
                            "flight_number", fbs.flight_number
                        )
                    ) AS segments
             FROM flight_bookings fb
             LEFT JOIN flight_booking_segments fbs ON fbs.booking_id = fb.id
             WHERE fb.user_id = :uid
             GROUP BY fb.id
             ORDER BY fb.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['segments'] = json_decode($row['segments'] ?? 'null', true) ?? [];
            return $row;
        }, $rows);
    }

    // =========================================================================
    // getBookingById
    // =========================================================================

    public function getBookingById(int $bookingId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flight_bookings
             WHERE id = :id AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId, ':uid' => $userId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return null;
        }

        // Segments.
        $segStmt = $this->db->prepare(
            'SELECT * FROM flight_booking_segments
             WHERE booking_id = :id
             ORDER BY slice_index ASC, segment_order ASC'
        );
        $segStmt->execute([':id' => $bookingId]);
        $booking['segments'] = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        // Passengers.
        $pStmt = $this->db->prepare(
            'SELECT * FROM flight_booking_passengers WHERE booking_id = :id'
        );
        $pStmt->execute([':id' => $bookingId]);
        $booking['passengers'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        return $booking;
    }

    // =========================================================================
    // cancelBooking — Step 1: get refund quote
    // =========================================================================

    public function cancelBooking(int $bookingId, int $userId): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.', 404);
        }

        if ($booking['status'] === 'cancelled') {
            throw new RuntimeException('Booking is already cancelled.', 422);
        }

        $providerOrderId = $booking['provider_order_id'] ?? '';
        if (empty($providerOrderId)) {
            throw new RuntimeException('Cannot cancel: no provider order ID found.', 422);
        }

        $cancellationResponse = $this->duffel->cancelOrder($providerOrderId);
        $cancellation = $cancellationResponse['data'] ?? [];

        // Store cancellation ID on the booking row for confirmation step.
        $this->db->prepare(
            'UPDATE flight_bookings SET pending_cancellation_id = :cid, updated_at = NOW() WHERE id = :id'
        )->execute([':cid' => $cancellation['id'] ?? '', ':id' => $bookingId]);

        return [
            'cancellation_id' => $cancellation['id']            ?? null,
            'refund_amount'   => $cancellation['refund_amount'] ?? '0.00',
            'refund_currency' => $cancellation['refund_currency'] ?? ($booking['currency'] ?? 'GBP'),
            'refund_to'       => $cancellation['refund_to']     ?? 'original_payment_method',
            'expires_at'      => $cancellation['expires_at']    ?? null,
        ];
    }

    // =========================================================================
    // confirmCancelBooking — Step 2: commit the cancellation
    // =========================================================================

    public function confirmCancelBooking(int $bookingId, string $cancellationId, int $userId): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.', 404);
        }

        if ($booking['status'] === 'cancelled') {
            throw new RuntimeException('Booking is already cancelled.', 422);
        }

        // Confirm with Duffel — this actually cancels the airline booking.
        $this->duffel->confirmCancellation($cancellationId);

        // Attempt Stripe refund before marking the booking as cancelled.
        // If the refund fails, we set status to 'cancellation_pending_refund' so staff can retry.
        $payStmt = $this->db->prepare(
            'SELECT id, stripe_payment_intent_id, amount, currency FROM payments
             WHERE booking_type = :bt AND booking_id = :bid AND status = :status
             LIMIT 1'
        );
        $payStmt->execute([':bt' => 'flight', ':bid' => $bookingId, ':status' => 'succeeded']);
        $payment = $payStmt->fetch(\PDO::FETCH_ASSOC);

        $refundId     = null;
        $finalStatus  = 'cancelled';

        if ($payment && !empty($payment['stripe_payment_intent_id'])) {
            try {
                $refund   = $this->stripe->createRefund(
                    $payment['stripe_payment_intent_id'],
                    null,
                    bin2hex(random_bytes(16)),
                    'requested_by_customer'
                );
                $refundId = $refund['id'] ?? null;

                $this->db->prepare(
                    'UPDATE payments SET status = :status, stripe_refund_id = :rid, updated_at = NOW()
                     WHERE id = :id'
                )->execute([':status' => 'refunded', ':rid' => $refundId, ':id' => $payment['id']]);
            } catch (\Throwable $e) {
                // Duffel cancellation succeeded but Stripe refund failed.
                // Keep booking in a holding status — ops team must manually refund.
                $finalStatus = 'cancellation_pending_refund';
                $this->db->prepare(
                    'UPDATE payments SET status = :status, updated_at = NOW() WHERE id = :id'
                )->execute([':status' => 'refund_failed', ':id' => $payment['id']]);
            }
        }

        $this->db->prepare(
            'UPDATE flight_bookings
             SET status = :status, cancelled_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute([':status' => $finalStatus, ':id' => $bookingId]);

        return [
            'booking_id'  => $bookingId,
            'status'      => $finalStatus,
            'refund_id'   => $refundId,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fetchOffer(string $offerId): ?array
    {
        // Try local cache first.
        try {
            $stmt = $this->db->prepare(
                'SELECT offer_id, offer_data, total_amount, currency, expires_at
                 FROM offer_cache
                 WHERE offer_id = :oid AND expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([':oid' => $offerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (\Throwable) {
            // offer_cache table may not exist — fall through to Duffel.
        }

        // Fall back: fetch live from Duffel.
        try {
            $data      = $this->duffel->getOfferWithServices($offerId);
            $offerData = $data['data'] ?? $data;
            if (empty($offerData['id'])) {
                return null;
            }
            $expiresAt   = $offerData['expires_at'] ?? date('Y-m-d H:i:s', time() + 1800);
            $totalAmount = $offerData['total_amount'] ?? '0.00';
            $currency    = strtoupper($offerData['total_currency'] ?? 'GBP');

            // Re-cache for this request.
            try {
                $ins = $this->db->prepare(
                    'INSERT IGNORE INTO offer_cache
                       (offer_request_id, offer_id, provider_id, search_hash,
                        offer_data, total_amount, currency, expires_at)
                     VALUES ("", :oid, 1, "", :odata, :amount, :cur, :exp)'
                );
                $ins->execute([
                    ':oid'   => $offerId,
                    ':odata' => json_encode($offerData, JSON_UNESCAPED_UNICODE),
                    ':amount'=> $totalAmount,
                    ':cur'   => $currency,
                    ':exp'   => date('Y-m-d H:i:s', strtotime($expiresAt)),
                ]);
            } catch (\Throwable) { /* non-critical */ }

            return [
                'offer_id'     => $offerId,
                'offer_data'   => json_encode($offerData, JSON_UNESCAPED_UNICODE),
                'total_amount' => $totalAmount,
                'currency'     => $currency,
                'expires_at'   => $expiresAt,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatOffer(array $offerData): array
    {
        return [
            'offer_id'            => $offerData['id']             ?? '',
            'total_amount'        => $offerData['total_amount']   ?? '0.00',
            'currency'            => strtoupper($offerData['total_currency'] ?? 'GBP'),
            'expires_at'          => $offerData['expires_at']     ?? null,
            'slices'              => $offerData['slices']          ?? [],
            'passengers_included' => $offerData['passengers']      ?? [],
        ];
    }

    private function calculatePricing(float $baseAmount, string $currency): array
    {
        $fees = [];

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM pricing_rules
                 WHERE is_active = 1
                   AND (applies_to = 'flight' OR applies_to = 'all')
                 ORDER BY sort_order ASC"
            );
            $stmt->execute();
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $runningTotal = $baseAmount;

            foreach ($rules as $rule) {
                $feeAmount = 0.0;

                if ($rule['rule_type'] === 'percentage') {
                    $feeAmount = round($runningTotal * ((float) $rule['value'] / 100), 2);
                } elseif ($rule['rule_type'] === 'fixed') {
                    $feeAmount = (float) $rule['value'];
                }

                $fees[] = [
                    'name'   => $rule['name']  ?? $rule['rule_type'],
                    'type'   => $rule['rule_type'],
                    'amount' => $feeAmount,
                ];

                $runningTotal = round($runningTotal + $feeAmount, 2);
            }
        } catch (\Throwable) {
            // If pricing_rules table does not exist yet, use base amount.
        }

        $total = $baseAmount;
        foreach ($fees as $fee) {
            $total = round($total + $fee['amount'], 2);
        }

        return [
            'base_amount' => $baseAmount,
            'fees'        => $fees,
            'total'       => $total,
            'currency'    => $currency,
        ];
    }

    private function findActiveCoupon(string $code): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM coupons
                 WHERE code = :code AND is_active = 1
                   AND (valid_until IS NULL OR valid_until > NOW())
                 LIMIT 1'
            );
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function requireSession(string $sessionKey, int $userId): array
    {
        $session = $this->sessionService->get($sessionKey);

        if ($session === null) {
            throw new RuntimeException('Booking session not found or expired.', 404);
        }

        if ((int) $session['user_id'] !== $userId) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return $session;
    }

    /**
     * Map internal passenger data to the format Duffel expects for order creation.
     * Passengers are matched to offer passenger IDs by type (adult/child/infant) order.
     */
    private function mapPassengersForDuffel(array $passengers, array $offerData): array
    {
        $offerPassengers = $offerData['passengers'] ?? [];

        // Build type-indexed queues to match by type, not by position
        $queues = ['adult' => [], 'child' => [], 'infant_without_seat' => []];
        foreach ($offerPassengers as $op) {
            $type = $op['type'] ?? 'adult';
            $queues[$type][] = $op['id'];
        }

        // Normalise our type labels to Duffel's
        $typeMap = ['adult' => 'adult', 'child' => 'child', 'infant' => 'infant_without_seat'];

        $mapped = [];
        foreach ($passengers as $p) {
            $ourType    = $p['type'] ?? 'adult';
            $duffelType = $typeMap[$ourType] ?? 'adult';
            $duffelId   = array_shift($queues[$duffelType]);

            // Nationality: accept both ISO alpha-3 codes and legacy display names.
            // If it looks like a 3-letter code already, pass through; otherwise try mapping.
            $nationality = (string) ($p['nationality'] ?? '');
            if (strlen($nationality) !== 3) {
                $nationality = $this->toIso3Nationality($nationality);
            }

            $entry = [
                'given_name'  => $p['first_name'],
                'family_name' => $p['last_name'],
                'gender'      => strtolower($p['gender'] ?? '') === 'female' ? 'f' : 'm',
                'born_on'     => $p['date_of_birth'],
                'nationality' => $nationality,
            ];

            if (!empty($p['passport_number'])) {
                $entry['passport_number'] = $p['passport_number'];
            }
            if (!empty($p['passport_expiry'])) {
                $entry['passport_expiry_date'] = $p['passport_expiry'];
            }

            if ($duffelId !== null) {
                $entry['id'] = $duffelId;
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    private function toIso3Nationality(string $name): string
    {
        $map = [
            'algerian'=>'DZA','bahraini'=>'BHR','egyptian'=>'EGY','emirati'=>'ARE',
            'jordanian'=>'JOR','kuwaiti'=>'KWT','lebanese'=>'LBN','libyan'=>'LBY',
            'mauritanian'=>'MRT','moroccan'=>'MAR','omani'=>'OMN','palestinian'=>'PSE',
            'qatari'=>'QAT','saudi'=>'SAU','sudanese'=>'SDN','syrian'=>'SYR',
            'tunisian'=>'TUN','yemeni'=>'YEM','american'=>'USA','british'=>'GBR',
            'french'=>'FRA','german'=>'DEU','indian'=>'IND','pakistani'=>'PAK',
            'filipino'=>'PHL','turkish'=>'TUR','iranian'=>'IRN','indonesian'=>'IDN',
            'malaysian'=>'MYS','bangladeshi'=>'BGD','ethiopian'=>'ETH','kenyan'=>'KEN',
            'nigerian'=>'NGA','south african'=>'ZAF','canadian'=>'CAN','australian'=>'AUS',
            'chinese'=>'CHN','japanese'=>'JPN','korean'=>'KOR','russian'=>'RUS',
            'spanish'=>'ESP','italian'=>'ITA','dutch'=>'NLD','swedish'=>'SWE',
        ];
        return $map[strtolower(trim($name))] ?? 'OTH';
    }

    private function queueJobs(int $bookingId, int $userId): void
    {
        $jobs = [
            [
                'job_type' => 'generate_invoice_pdf',
                'payload'  => json_encode(['booking_type' => 'flight', 'booking_id' => $bookingId]),
            ],
            [
                'job_type' => 'send_booking_confirmation_email',
                'payload'  => json_encode(['booking_type' => 'flight', 'booking_id' => $bookingId, 'user_id' => $userId]),
            ],
            [
                'job_type' => 'send_whatsapp_booking_confirmation',
                'payload'  => json_encode(['booking_type' => 'flight', 'booking_id' => $bookingId, 'user_id' => $userId]),
            ],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO job_queue (job_type, payload) VALUES (:job_type, :payload)'
        );

        foreach ($jobs as $job) {
            $stmt->execute($job);
        }
    }
}
