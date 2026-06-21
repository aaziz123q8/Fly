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
                     'nationality', 'passport_number', 'passport_expiry'];

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

        if ($session['current_step'] !== 'payment') {
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

        // Generate unique booking reference.
        $bookingReference = 'FM-' . strtoupper(bin2hex(random_bytes(4)));

        // Map passengers for Duffel (add required Duffel fields).
        $duffelPassengers = $this->mapPassengersForDuffel($passengersData, $offerData);

        // Build Duffel payment payload.
        $duffelPayments = [[
            'type'     => 'balance',
            'amount'   => $offer['total_amount'],
            'currency' => $offer['currency'],
        ]];

        // Create Duffel order.
        $orderResponse = $this->duffel->createOrder(
            $offerId,
            $duffelPassengers,
            $duffelPayments,
            $servicesData ?: []
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
               (flight_booking_id, first_name, last_name, gender,
                date_of_birth, nationality, passport_number, passport_expiry)
             VALUES
               (:booking_id, :first_name, :last_name, :gender,
                :dob, :nationality, :passport_number, :passport_expiry)'
        );
        foreach ($passengersData as $passenger) {
            $pStmt->execute([
                ':booking_id'      => $bookingId,
                ':first_name'      => $passenger['first_name'],
                ':last_name'       => $passenger['last_name'],
                ':gender'          => $passenger['gender'],
                ':dob'             => $passenger['date_of_birth'],
                ':nationality'     => $passenger['nationality'],
                ':passport_number' => $passenger['passport_number'],
                ':passport_expiry' => $passenger['passport_expiry'],
            ]);
        }

        // Insert flight_booking_segments.
        $sStmt = $this->db->prepare(
            'INSERT INTO flight_booking_segments
               (flight_booking_id, slice_index, segment_index,
                origin_airport, destination_airport,
                departing_at, arriving_at,
                carrier_iata_code, flight_number, aircraft_iata_code, duration)
             VALUES
               (:booking_id, :slice_index, :segment_index,
                :origin, :destination,
                :departing_at, :arriving_at,
                :carrier, :flight_number, :aircraft, :duration)'
        );
        foreach ($slices as $sliceIdx => $slice) {
            foreach (($slice['segments'] ?? []) as $segIdx => $seg) {
                $sStmt->execute([
                    ':booking_id'     => $bookingId,
                    ':slice_index'    => $sliceIdx,
                    ':segment_index'  => $segIdx,
                    ':origin'         => $seg['origin']['iata_code']      ?? '',
                    ':destination'    => $seg['destination']['iata_code'] ?? '',
                    ':departing_at'   => $seg['departing_at']             ?? null,
                    ':arriving_at'    => $seg['arriving_at']              ?? null,
                    ':carrier'        => $seg['marketing_carrier']['iata_code'] ?? '',
                    ':flight_number'  => ($seg['marketing_carrier']['iata_code'] ?? '') . ($seg['marketing_carrier_flight_number'] ?? ''),
                    ':aircraft'       => $seg['aircraft']['iata_code']    ?? null,
                    ':duration'       => $seg['duration']                 ?? null,
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
             LEFT JOIN flight_booking_segments fbs ON fbs.flight_booking_id = fb.id
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
             WHERE flight_booking_id = :id
             ORDER BY slice_index ASC, segment_index ASC'
        );
        $segStmt->execute([':id' => $bookingId]);
        $booking['segments'] = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        // Passengers.
        $pStmt = $this->db->prepare(
            'SELECT * FROM flight_booking_passengers WHERE flight_booking_id = :id'
        );
        $pStmt->execute([':id' => $bookingId]);
        $booking['passengers'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        return $booking;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fetchOffer(string $offerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT offer_id, offer_data, total_amount, currency, expires_at
             FROM offer_cache
             WHERE offer_id = :oid AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':oid' => $offerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
     */
    private function mapPassengersForDuffel(array $passengers, array $offerData): array
    {
        $offerPassengers = $offerData['passengers'] ?? [];
        $mapped = [];

        foreach ($passengers as $idx => $p) {
            $duffelId = $offerPassengers[$idx]['id'] ?? null;

            $entry = [
                'given_name'         => $p['first_name'],
                'family_name'        => $p['last_name'],
                'gender'             => strtolower($p['gender']) === 'female' ? 'f' : 'm',
                'born_on'            => $p['date_of_birth'],
                'nationality'        => $p['nationality'],
                'passport_number'    => $p['passport_number'] ?? null,
                'passport_expiry_date' => $p['passport_expiry'] ?? null,
            ];

            if ($duffelId !== null) {
                $entry['id'] = $duffelId;
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    private function queueJobs(int $bookingId, int $userId): void
    {
        $jobs = [
            [
                'job_type' => 'generate_invoice',
                'payload'  => json_encode(['booking_type' => 'flight', 'booking_id' => $bookingId]),
            ],
            [
                'job_type' => 'send_booking_confirmation_email',
                'payload'  => json_encode(['booking_id' => $bookingId, 'user_id' => $userId]),
            ],
            [
                'job_type' => 'send_booking_confirmation_whatsapp',
                'payload'  => json_encode(['booking_id' => $bookingId, 'user_id' => $userId]),
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
