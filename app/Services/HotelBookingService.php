<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\RateHawk\RateHawkAdapter;
use App\Adapters\Stripe\StripeAdapter;
use App\Helpers\Database;
use PDO;
use RuntimeException;

class HotelBookingService
{
    private const PROVIDER_ID           = 2;   // RateHawk
    private const PRICE_CHANGE_THRESHOLD = 0.02; // 2%

    private PDO $db;
    private RateHawkAdapter $rateHawk;
    private StripeAdapter $stripe;
    private BookingSessionService $sessionService;

    public function __construct(
        ?PDO                   $db             = null,
        ?RateHawkAdapter       $rateHawk       = null,
        ?StripeAdapter         $stripe         = null,
        ?BookingSessionService $sessionService = null
    ) {
        $this->db             = $db             ?? Database::getInstance();
        $this->rateHawk       = $rateHawk       ?? new RateHawkAdapter();
        $this->stripe         = $stripe         ?? new StripeAdapter();
        $this->sessionService = $sessionService ?? new BookingSessionService($this->db);
    }

    // =========================================================================
    // prebook
    // =========================================================================

    /**
     * Pre-book a hotel rate to lock the price, then create a booking session.
     *
     * @param string $bookHash        The book_hash from RateHawk search results
     * @param int    $userId
     * @param string $checkIn         YYYY-MM-DD
     * @param string $checkOut        YYYY-MM-DD
     * @param int    $hotelId         Local hotels_content.id (or provider hotel id as string)
     * @param float  $displayedPrice  The price shown to the user (for price-change detection)
     * @return array
     */
    public function prebook(
        string  $bookHash,
        int     $userId,
        string  $checkIn,
        string  $checkOut,
        string  $hotelId,
        float   $displayedPrice = 0.0,
        ?string $hotelName = null
    ): array {
        // ── Resolve the net price + prebook session ──────────────────────────
        if (DemoHotelData::isEnabled()) {
            // Demo: the book_hash encodes the price (DEMO|<price>|<name>); no call.
            $parts             = explode('|', $bookHash);
            $netPrice          = (isset($parts[1]) && is_numeric($parts[1]))
                                    ? (float) $parts[1]
                                    : ($displayedPrice > 0.0 ? $displayedPrice : 50.0);
            $prebookSessionId  = 'demo-prebook-' . substr(md5($bookHash . microtime(true)), 0, 12);
            $currency          = 'GBP';
            $cancellationPolicyRaw = [];
            $roomData          = [];
        } else {
            $prebookResponse   = $this->rateHawk->prebook($bookHash);
            // Handle both response shapes: data.session_id or session_id
            $prebookData       = $prebookResponse['data'] ?? $prebookResponse;
            $prebookSessionId  = $prebookData['session_id'] ?? $prebookData['prebook_id'] ?? '';
            $priceData         = $prebookData['price_data'] ?? $prebookData['init_price_info'] ?? [];
            $netPrice          = (float) ($priceData['price'] ?? $priceData['amount'] ?? $priceData['total'] ?? 0.0);
            $currency          = strtoupper($priceData['currency'] ?? 'GBP');
            $cancellationPolicyRaw = $prebookData['cancellation_policy']
                                  ?? $prebookData['cancellation_penalties']
                                  ?? [];
            $roomData          = $prebookData['room_data'] ?? $prebookData['rooms'] ?? [];
        }

        // Apply the platform commission (admin "Commissions" page) on top of the
        // RateHawk net price. The customer is charged this commission-inclusive
        // amount; the drift guard below still compares the NET price against the
        // net price the customer saw, so a real RateHawk price change is caught.
        $commission        = (new CommissionService($this->db))->apply($netPrice, 'hotel');
        $confirmedPrice    = $commission['total'];
        $cancellationPolicy = $cancellationPolicyRaw;

        // ── Create booking session ───────────────────────────────────────────
        $sessionKey = $this->sessionService->create($userId, 'hotel');

        $offerExpiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes

        $pricingSnapshot = [
            'confirmed_price'     => $confirmedPrice,
            'net_price'           => $netPrice,
            'commission'          => $commission['commission'],
            'currency'            => $currency,
            'cancellation_policy' => $cancellationPolicy,
            'check_in'            => $checkIn,
            'check_out'           => $checkOut,
            'hotel_id'            => $hotelId,
            'hotel_name'          => $hotelName ?? ($prebookData['hotel_name'] ?? ''),
            'room_data'           => $roomData,
            'book_hash'           => $bookHash,
        ];

        $this->sessionService->update($sessionKey, [
            'provider_offer_id'  => (string) $hotelId,
            'prebook_session_id' => $prebookSessionId,
            'offer_expires_at'   => $offerExpiresAt,
            'pricing_snapshot'   => $pricingSnapshot,
            'current_step'       => 'guests',
        ]);

        // ── Price-change detection (compare NET vs the net price shown) ──────
        $priceChanged = false;
        if ($displayedPrice > 0.0) {
            $diff = abs($netPrice - $displayedPrice) / max(1.0, $displayedPrice);
            $priceChanged = $diff > self::PRICE_CHANGE_THRESHOLD;
        }

        return [
            'session_key'         => $sessionKey,
            'prebook_id'          => $prebookSessionId,
            'confirmed_price'     => $confirmedPrice,
            'net_price'           => $netPrice,
            'commission'          => $commission['commission'],
            'currency'            => $currency,
            'cancellation_policy' => $cancellationPolicy,
            'offer_expires_at'    => $offerExpiresAt,
            'price_changed'       => $priceChanged,
        ];
    }

    // =========================================================================
    // saveGuests
    // =========================================================================

    /**
     * Save guest information to the booking session.
     *
     * @param string      $sessionKey
     * @param array       $guests  [{first_name, last_name, email (lead), phone (lead), is_lead}]
     * @param int         $userId
     * @param string|null $specialRequests
     * @return bool
     */
    public function saveGuests(
        string  $sessionKey,
        array   $guests,
        int     $userId,
        ?string $specialRequests = null
    ): bool {
        $session = $this->requireSession($sessionKey, $userId);

        if ($session['current_step'] !== 'guests') {
            throw new RuntimeException('Invalid step for saving guests. Expected: guests.', 422);
        }

        // Validate: at least one lead guest with required fields
        $hasLead = false;
        foreach ($guests as $idx => $guest) {
            if (!empty($guest['is_lead'])) {
                if (empty($guest['first_name']) || empty($guest['last_name'])) {
                    throw new RuntimeException("Lead guest must have first_name and last_name.", 422);
                }
                $hasLead = true;
            } else {
                // Non-lead guests still need names
                if (empty($guest['first_name']) || empty($guest['last_name'])) {
                    throw new RuntimeException("Guest {$idx}: first_name and last_name are required.", 422);
                }
            }
        }

        if (!$hasLead) {
            throw new RuntimeException('At least one lead guest is required.', 422);
        }

        $guestsData = [
            'guests'           => $guests,
            'special_requests' => $specialRequests,
        ];

        return $this->sessionService->update($sessionKey, [
            'guests_data'  => $guestsData,
            'current_step' => 'payment',
        ]);
    }

    // =========================================================================
    // createPaymentIntent
    // =========================================================================

    /**
     * Create a Stripe PaymentIntent for the hotel booking.
     *
     * @param string      $sessionKey
     * @param int         $userId
     * @param string|null $couponCode
     * @return array {client_secret, payment_intent_id, amount, currency}
     */
    public function createPaymentIntent(
        string  $sessionKey,
        int     $userId,
        ?string $couponCode = null
    ): array {
        $session = $this->requireSession($sessionKey, $userId);

        if ($session['current_step'] !== 'payment') {
            throw new RuntimeException('Session is not at the payment step.', 422);
        }

        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? null);

        if (empty($pricingSnapshot)) {
            throw new RuntimeException('Pricing snapshot not found. Please prebook again.', 422);
        }

        $totalAmount = (float) ($pricingSnapshot['confirmed_price'] ?? 0);
        $currency    = strtolower($pricingSnapshot['currency'] ?? 'gbp');

        // ── Apply coupon if provided ─────────────────────────────────────────
        if ($couponCode !== null && $couponCode !== '') {
            $coupon = $this->findActiveCoupon($couponCode);
            if ($coupon) {
                if ($coupon['discount_type'] === 'percentage') {
                    $discount = round($totalAmount * ((float) $coupon['discount_value'] / 100), 2);
                } else {
                    $discount = min($totalAmount, (float) $coupon['discount_value']);
                }
                $totalAmount -= $discount;
                $totalAmount  = max(0.0, $totalAmount);

                $pricingSnapshot['coupon_code']     = $couponCode;
                $pricingSnapshot['discount_amount'] = $discount;
                $pricingSnapshot['total']           = $totalAmount;
            }
        }

        $amountInPence  = (int) round($totalAmount * 100);

        // C-01 hardening: reuse the idempotency key already minted for this
        // session when the charge amount/currency is unchanged, so a retry or
        // double-submit returns the SAME Stripe PaymentIntent instead of
        // creating a second one. A genuine re-price mints a fresh key.
        $idemTag   = $amountInPence . ':' . strtolower((string) $currency);
        $storedKey = (string) ($session['idempotency_key'] ?? '');
        $storedTag = (string) ($pricingSnapshot['idem_tag'] ?? '');
        $idempotencyKey = ($storedKey !== '' && $storedTag !== '' && hash_equals($storedTag, $idemTag))
            ? $storedKey
            : bin2hex(random_bytes(32));
        $pricingSnapshot['idem_tag'] = $idemTag;

        // ── Upsert payment record (keyed on the unique idempotency_key) ──────
        $payStmt = $this->db->prepare(
            'INSERT INTO payments
               (booking_type, booking_id, user_id, payment_method,
                idempotency_key, amount, currency, status)
             VALUES
               (:booking_type, 0, :user_id, :method,
                :idem_key, :amount, :currency, :status)
             ON DUPLICATE KEY UPDATE
                amount = VALUES(amount), currency = VALUES(currency),
                payment_method = VALUES(payment_method)'
        );
        $payStmt->execute([
            ':booking_type' => 'hotel',
            ':user_id'      => $userId,
            ':method'       => 'stripe',
            ':idem_key'     => $idempotencyKey,
            ':amount'       => number_format($totalAmount, 2, '.', ''),
            ':currency'     => strtoupper($currency),
            ':status'       => 'pending',
        ]);

        // ── Create Stripe PaymentIntent ──────────────────────────────────────
        $stripeResult = $this->stripe->createPaymentIntent(
            $amountInPence,
            $idempotencyKey,
            $currency,
            [
                'session_key'  => $sessionKey,
                'booking_type' => 'hotel',
                'user_id'      => (string) $userId,
            ]
        );

        $paymentIntentId = $stripeResult['payment_intent_id'];

        // ── Update payment row with Stripe PI id (keyed on idempotency_key) ──
        $this->db->prepare(
            'UPDATE payments SET stripe_payment_intent_id = :pi WHERE idempotency_key = :k'
        )->execute([':pi' => $paymentIntentId, ':k' => $idempotencyKey]);

        // ── Update session ───────────────────────────────────────────────────
        $this->sessionService->update($sessionKey, [
            'payment_intent_id' => $paymentIntentId,
            'idempotency_key'   => $idempotencyKey,
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

    /**
     * Finalise the hotel booking after successful Stripe payment.
     *
     * @param string $sessionKey
     * @param string $paymentIntentId
     * @return array {booking_id, booking_reference}
     */
    public function completeBooking(string $sessionKey, string $paymentIntentId): array
    {
        // ── Load session ─────────────────────────────────────────────────────
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions
             WHERE payment_intent_id = :pi AND booking_type = :bt
             LIMIT 1'
        );
        $stmt->execute([':pi' => $paymentIntentId, ':bt' => 'hotel']);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new RuntimeException('Booking session not found for payment intent.', 404);
        }

        $userId = (int) $session['user_id'];

        // Idempotency guard: if this session already produced a booking (webhook
        // and frontend confirmCheckout race, or webhook retry), return the
        // existing booking rather than creating a duplicate RateHawk booking.
        if (($session['current_step'] ?? '') === 'complete') {
            return $this->fetchCompletedBookingResult($userId);
        }

        $guestsData = is_string($session['guests_data'])
            ? json_decode($session['guests_data'], true)
            : ($session['guests_data'] ?? []);

        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? []);

        $prebookSessionId = $session['prebook_session_id'] ?? '';

        // ── Find lead guest ──────────────────────────────────────────────────
        $allGuests   = $guestsData['guests'] ?? [];
        $specialReqs = $guestsData['special_requests'] ?? null;
        $leadGuest   = null;

        foreach ($allGuests as $g) {
            if (!empty($g['is_lead'])) {
                $leadGuest = $g;
                break;
            }
        }

        if ($leadGuest === null && !empty($allGuests)) {
            $leadGuest = $allGuests[0];
        }

        if ($leadGuest === null) {
            throw new RuntimeException('No lead guest found in session.', 422);
        }

        $rateHawkLeadGuest = [
            'first_name' => $leadGuest['first_name'],
            'last_name'  => $leadGuest['last_name'],
            'email'      => $leadGuest['email']  ?? '',
            'phone'      => $leadGuest['phone']  ?? '',
        ];

        // ── Build rooms payload for RateHawk ─────────────────────────────────
        $roomData  = $pricingSnapshot['room_data'] ?? [];
        $rooms     = is_array($roomData) && !empty($roomData) ? $roomData : [[]];

        // ── Generate booking reference in HM00000001 format — atomic via table lock ──
        $this->db->exec("LOCK TABLES hotel_bookings WRITE");
        try {
            $lastRef = $this->db->query(
                "SELECT booking_reference FROM hotel_bookings ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            if ($lastRef && preg_match('/^HM(\d+)$/', $lastRef, $m)) {
                $nextNum = (int)$m[1] + 1;
            } else {
                $nextNum = (int)$this->db->query("SELECT COUNT(*) FROM hotel_bookings")->fetchColumn() + 1;
            }
            $bookingReference = 'HM' . str_pad((string)$nextNum, 8, '0', STR_PAD_LEFT);
        } finally {
            $this->db->exec("UNLOCK TABLES");
        }

        // ── Call RateHawk createBooking (or fake it in demo mode) ────────────
        if (DemoHotelData::isEnabled()) {
            $bookingResponse = ['data' => ['order_id' => 'DEMO-' . $bookingReference]];
        } else {
        try {
            $bookingResponse = $this->rateHawk->createBooking(
                $prebookSessionId,
                $rateHawkLeadGuest,
                $rooms,
                $bookingReference,
                $specialReqs
            );
        } catch (\Throwable $rateHawkEx) {
            $this->db->prepare(
                'UPDATE payments SET status = :s, updated_at = NOW()
                 WHERE stripe_payment_intent_id = :pi'
            )->execute([':s' => 'failed', ':pi' => $paymentIntentId]);

            try {
                $refund = $this->stripe->createRefund(
                    $paymentIntentId,
                    null,
                    'hotel_fail_refund_' . md5($paymentIntentId),
                    'requested_by_customer'
                );
                $this->db->prepare(
                    'UPDATE payments
                     SET status = :s, auto_refund_reason = :ar, auto_refunded_at = NOW(), updated_at = NOW()
                     WHERE stripe_payment_intent_id = :pi'
                )->execute([
                    ':s'  => 'auto_refunded',
                    ':ar' => 'ratehawk_booking_failed',
                    ':pi' => $paymentIntentId,
                ]);
            } catch (\Throwable $refundEx) {
                $this->db->prepare(
                    'UPDATE payments SET status = :s, updated_at = NOW()
                     WHERE stripe_payment_intent_id = :pi'
                )->execute([':s' => 'cancellation_pending_refund', ':pi' => $paymentIntentId]);
                try {
                    $this->db->prepare(
                        'INSERT INTO error_logs (level, message, context, created_at)
                         VALUES (?, ?, ?, NOW())'
                    )->execute([
                        'critical',
                        'Hotel auto-refund failed after RateHawk booking failure',
                        json_encode([
                            'payment_intent_id' => $paymentIntentId,
                            'ratehawk_error'    => $rateHawkEx->getMessage(),
                            'refund_error'      => $refundEx->getMessage(),
                        ]),
                    ]);
                } catch (\Throwable) {}
            }

            throw new RuntimeException('Hotel booking failed: ' . $rateHawkEx->getMessage(), 502, $rateHawkEx);
        }
        }

        $bookingResponseData = $bookingResponse['data'] ?? $bookingResponse;
        $providerBookingId   = $bookingResponseData['order_id']
                               ?? $bookingResponseData['id']
                               ?? '';

        // ── Extract booking details from snapshot ─────────────────────────────
        $hotelId          = (string) ($pricingSnapshot['hotel_id']  ?? '');
        $hotelName        = $pricingSnapshot['hotel_name'] ?? '';
        $providerHotelId  = $hotelId;
        $checkIn     = $pricingSnapshot['check_in']  ?? '';
        $checkOut    = $pricingSnapshot['check_out'] ?? '';
        $currency    = strtoupper($pricingSnapshot['currency'] ?? 'GBP');
        $totalAmount = (float) ($pricingSnapshot['confirmed_price'] ?? 0);
        $cancellationPolicy = $pricingSnapshot['cancellation_policy'] ?? [];

        // Compute nights
        $nightsCount = 1;
        if ($checkIn && $checkOut) {
            $diff = (new \DateTimeImmutable($checkIn))->diff(new \DateTimeImmutable($checkOut));
            $nightsCount = max(1, (int) $diff->days);
        }

        $roomsCount  = count($rooms);
        $adultsCount = 0;
        foreach ($rooms as $room) {
            $adultsCount += (int) ($room['adults'] ?? 1);
        }
        $childrenCount = 0;
        foreach ($rooms as $room) {
            $childrenCount += count($room['children'] ?? []);
        }

        // ── INSERT hotel_bookings ─────────────────────────────────────────────
        $bStmt = $this->db->prepare(
            'INSERT INTO hotel_bookings
               (user_id, provider_id, booking_reference, provider_booking_id,
                hotel_id, hotel_name, provider_hotel_id, check_in_date, check_out_date, nights_count,
                rooms_count, adults_count, children_count,
                total_amount, currency, status, cancellation_policy, special_requests)
             VALUES
               (:user_id, :provider_id, :ref, :provider_booking_id,
                :hotel_id, :hotel_name, :provider_hotel_id, :check_in, :check_out, :nights,
                :rooms, :adults, :children,
                :amount, :currency, :status, :cancellation_policy, :special_requests)'
        );
        $bStmt->execute([
            ':user_id'              => $userId,
            ':provider_id'          => self::PROVIDER_ID,
            ':ref'                  => $bookingReference,
            ':provider_booking_id'  => $providerBookingId,
            ':hotel_id'             => $hotelId,
            ':hotel_name'           => $hotelName !== '' ? $hotelName : null,
            ':provider_hotel_id'    => $providerHotelId,
            ':check_in'             => $checkIn,
            ':check_out'            => $checkOut,
            ':nights'               => $nightsCount,
            ':rooms'                => $roomsCount,
            ':adults'               => $adultsCount,
            ':children'             => $childrenCount,
            ':amount'               => number_format($totalAmount, 2, '.', ''),
            ':currency'             => $currency,
            ':status'               => 'confirmed',
            ':cancellation_policy'  => json_encode($cancellationPolicy, JSON_UNESCAPED_UNICODE),
            ':special_requests'     => $specialReqs,
        ]);
        $hotelBookingId = (int) $this->db->lastInsertId();

        // ── INSERT hotel_booking_guests ───────────────────────────────────────
        $gStmt = $this->db->prepare(
            'INSERT INTO hotel_booking_guests
               (booking_id, is_lead, first_name, last_name, email, phone)
             VALUES
               (:booking_id, :is_lead, :first_name, :last_name, :email, :phone)'
        );
        foreach ($allGuests as $guest) {
            $gStmt->execute([
                ':booking_id'  => $hotelBookingId,
                ':is_lead'     => !empty($guest['is_lead']) ? 1 : 0,
                ':first_name'  => $guest['first_name'] ?? '',
                ':last_name'   => $guest['last_name']  ?? '',
                ':email'       => $guest['email']  ?? null,
                ':phone'       => $guest['phone']  ?? null,
            ]);
        }

        // ── INSERT hotel_booking_rooms ────────────────────────────────────────
        $rStmt = $this->db->prepare(
            'INSERT INTO hotel_booking_rooms
               (booking_id, room_type, meal_plan, provider_room_id, amount, currency)
             VALUES
               (:booking_id, :room_type, :meal_plan, :provider_room_id, :amount, :currency)'
        );
        foreach ($rooms as $room) {
            $rStmt->execute([
                ':booking_id'       => $hotelBookingId,
                ':room_type'        => $room['room_type'] ?? $room['type'] ?? 'Standard Room',
                ':meal_plan'        => $room['meal_plan'] ?? $room['board_type'] ?? null,
                ':provider_room_id' => $room['id'] ?? $room['room_id'] ?? null,
                ':amount'           => $room['amount'] ?? $room['price'] ?? 0.00,
                ':currency'         => $currency,
            ]);
        }

        // ── Update payments row ───────────────────────────────────────────────
        $this->db->prepare(
            'UPDATE payments
             SET booking_id = :bid, status = :status
             WHERE stripe_payment_intent_id = :pi'
        )->execute([':bid' => $hotelBookingId, ':status' => 'succeeded', ':pi' => $paymentIntentId]);

        // ── Advance session ───────────────────────────────────────────────────
        $this->sessionService->update($session['session_key'], ['current_step' => 'complete']);

        // ── Record coupon usage ───────────────────────────────────────────────
        $couponCode     = $pricingSnapshot['coupon_code']     ?? null;
        $discountAmount = $pricingSnapshot['discount_amount'] ?? 0;
        if (!empty($couponCode)) {
            try {
                $couponStmt = $this->db->prepare('SELECT id FROM coupons WHERE code = ? LIMIT 1');
                $couponStmt->execute([$couponCode]);
                $couponRow = $couponStmt->fetch(PDO::FETCH_ASSOC);
                if ($couponRow) {
                    $this->db->prepare(
                        'INSERT INTO coupon_usages
                           (coupon_id, user_id, booking_type, booking_id, discount_applied, used_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    )->execute([
                        $couponRow['id'],
                        $userId,
                        'hotel',
                        $hotelBookingId,
                        $discountAmount,
                    ]);
                }
            } catch (\Throwable $couponEx) {
                try {
                    $this->db->prepare(
                        'INSERT INTO error_logs (level, message, context, created_at)
                         VALUES (?, ?, ?, NOW())'
                    )->execute([
                        'error',
                        'Failed to record coupon usage for hotel booking',
                        json_encode([
                            'booking_id'  => $hotelBookingId,
                            'coupon_code' => $couponCode,
                            'error'       => $couponEx->getMessage(),
                        ]),
                    ]);
                } catch (\Throwable) {}
            }
        }

        // ── Queue jobs ────────────────────────────────────────────────────────
        $this->queueJobs($hotelBookingId, $userId);

        return [
            'booking_id'        => $hotelBookingId,
            'booking_reference' => $bookingReference,
        ];
    }

    // =========================================================================
    // getUserBookings
    // =========================================================================

    /**
     * Return all hotel bookings for a user, enriched with hotel name + image.
     *
     * @param int $userId
     * @return array
     */
    public function getUserBookings(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT hb.*,
                    hc.name_en      AS hotel_name_en,
                    hc.name_ar      AS hotel_name_ar,
                    hc.main_image_url
             FROM hotel_bookings hb
             LEFT JOIN hotels_content hc ON hc.provider_hotel_id = hb.provider_hotel_id
             WHERE hb.user_id = :uid
             ORDER BY hb.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            if (isset($row['cancellation_policy']) && is_string($row['cancellation_policy'])) {
                $row['cancellation_policy'] = json_decode($row['cancellation_policy'], true) ?? [];
            }
            return $row;
        }, $rows);
    }

    // =========================================================================
    // getBookingById
    // =========================================================================

    /**
     * Return a single hotel booking with guests and rooms.
     *
     * @param int $bookingId
     * @param int $userId
     * @return array|null
     */
    public function getBookingById(int $bookingId, int $userId): ?array
    {
        return $this->fetchBooking('hb.id = :key', $bookingId, $userId);
    }

    /**
     * Look up a hotel booking by its HM reference (so confirmation/invoice pages
     * can load by reference, not just numeric id).
     */
    public function getBookingByReference(string $reference, int $userId): ?array
    {
        return $this->fetchBooking('hb.booking_reference = :key', $reference, $userId);
    }

    /**
     * @param string     $whereCol e.g. 'hb.id = :key' or 'hb.booking_reference = :key'
     * @param int|string $key
     */
    private function fetchBooking(string $whereCol, int|string $key, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT hb.*,
                    hc.name_en     AS hotel_name_en,
                    hc.name_ar     AS hotel_name_ar,
                    hc.main_image_url,
                    hc.star_rating,
                    hc.guest_rating,
                    hc.address_en, hc.address_ar,
                    ci.name_en AS city_name_en,  ci.name_ar AS city_name_ar,
                    co.name_en AS country_name_en, co.name_ar AS country_name_ar
             FROM hotel_bookings hb
             LEFT JOIN hotels_content hc ON hc.provider_hotel_id = hb.provider_hotel_id
             LEFT JOIN cities    ci ON ci.id = hc.city_id
             LEFT JOIN countries co ON co.id = hc.country_id
             WHERE {$whereCol} AND hb.user_id = :uid
             LIMIT 1"
        );
        $stmt->execute([':key' => $key, ':uid' => $userId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return null;
        }

        if (isset($booking['cancellation_policy']) && is_string($booking['cancellation_policy'])) {
            $booking['cancellation_policy'] = json_decode($booking['cancellation_policy'], true) ?? [];
        }

        $bookingId = (int) $booking['id'];

        // Guests
        $gStmt = $this->db->prepare(
            'SELECT * FROM hotel_booking_guests WHERE booking_id = :id ORDER BY is_lead DESC, id ASC'
        );
        $gStmt->execute([':id' => $bookingId]);
        $booking['guests'] = $gStmt->fetchAll(PDO::FETCH_ASSOC);

        // Rooms
        $rStmt = $this->db->prepare(
            'SELECT * FROM hotel_booking_rooms WHERE booking_id = :id'
        );
        $rStmt->execute([':id' => $bookingId]);
        $booking['rooms'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Demo hotels have no hotels_content row — enrich location/amenities from
        // the demo catalog so the confirmation/invoice can show full details.
        if (empty($booking['hotel_name_en']) && DemoHotelData::isEnabled()) {
            $demo = DemoHotelData::hotel((string) ($booking['provider_hotel_id'] ?? ''));
            if ($demo) {
                $booking['hotel_name_en'] = $demo['name_en'] ?? $booking['hotel_name'];
                $booking['hotel_name_ar'] = $demo['name']    ?? null;
                $booking['address_ar']    = $demo['address']  ?? null;
                $booking['city_name_ar']  = $demo['city']     ?? null;
                $booking['star_rating']   = $demo['stars']    ?? null;
                $booking['amenities']     = $demo['amenities'] ?? [];
            }
        }

        return $booking;
    }

    // =========================================================================
    // confirmCheckout — called by hotel-payment.html after Stripe confirms
    // =========================================================================

    public function confirmCheckout(string $sessionKey, string $paymentIntentId, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions WHERE session_key = :sk AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':sk' => $sessionKey, ':uid' => $userId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            throw new \RuntimeException('جلسة الحجز غير موجودة', 404);
        }

        // If webhook already completed the booking
        if (($session['current_step'] ?? '') === 'complete') {
            return $this->fetchCompletedBookingResult($userId);
        }

        // Verify payment with Stripe directly — don't wait for webhook
        try {
            $intent = $this->stripe->getPaymentIntent($paymentIntentId);
        } catch (\Throwable $e) {
            return ['status' => 'pending', 'message' => 'جارٍ التحقق من الدفع…'];
        }

        if (($intent['status'] ?? '') !== 'succeeded') {
            return ['status' => 'pending', 'message' => 'جارٍ معالجة الدفع…'];
        }

        // Payment confirmed — complete booking synchronously
        try {
            $this->completeBooking($sessionKey, $paymentIntentId);
            return $this->fetchCompletedBookingResult($userId);
        } catch (\Throwable $e) {
            error_log('[HOTEL_CONFIRM_CHECKOUT_FAIL] sk=' . $sessionKey . ' pi=' . $paymentIntentId . ' err=' . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), (int)$e->getCode() ?: 500);
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fetchCompletedBookingResult(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, booking_reference, status FROM hotel_bookings WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'status'            => 'confirmed',
            'booking_reference' => $row['booking_reference'] ?? '',
            'booking_id'        => $row['id']               ?? null,
        ];
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

    private function queueJobs(int $bookingId, int $userId): void
    {
        $jobs = [
            [
                'job_type' => 'generate_invoice_pdf',
                'payload'  => json_encode(['booking_type' => 'hotel', 'booking_id' => $bookingId]),
            ],
            [
                'job_type' => 'send_booking_confirmation_email',
                'payload'  => json_encode(['booking_type' => 'hotel', 'booking_id' => $bookingId, 'user_id' => $userId]),
            ],
            [
                'job_type' => 'send_whatsapp_booking_confirmation',
                'payload'  => json_encode(['booking_type' => 'hotel', 'booking_id' => $bookingId, 'user_id' => $userId]),
            ],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO job_queue (job_type, payload) VALUES (:job_type, :payload)'
        );

        foreach ($jobs as $job) {
            try {
                $stmt->execute($job);
            } catch (\Throwable) {
                // Non-critical
            }
        }
    }
}
