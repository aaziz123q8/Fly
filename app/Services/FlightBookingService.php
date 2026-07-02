<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\Duffel\DuffelAdapter;
use App\Adapters\Stripe\StripeAdapter;
use App\Helpers\Database;
use PDO;
use RuntimeException;

// DuffelErrorMapper loaded via autoloader (PSR-4: App\Services)

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

        // Normalise to ISO-8601 UTC so JS new Date() parses correctly on all browsers/timezones.
        // Cached values are stored as 'Y-m-d H:i:s' (UTC, no suffix) which Safari parses as local.
        $rawExpiry = $offer['expires_at'] ?? null;
        $expTs     = $rawExpiry ? strtotime($rawExpiry) : 0;
        // If expiry is already in the past, give a fresh 30-minute window so the user can proceed.
        if ($expTs < time()) {
            $expTs = time() + 1800;
        }
        $offerExpiresAt = gmdate('Y-m-d\TH:i:s\Z', $expTs);

        return [
            'session_key'      => $sessionKey,
            'offer'            => $this->formatOffer($offerData),
            'offer_expires_at' => $offerExpiresAt,
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

        // Normalize field names: booking.html sends document_number/document_expiry
        // but the rest of the pipeline expects passport_number/passport_expiry.
        foreach ($passengers as &$p) {
            if (!isset($p['passport_number']) && isset($p['document_number'])) {
                $p['passport_number'] = $p['document_number'];
            }
            if (!isset($p['passport_expiry']) && isset($p['document_expiry'])) {
                $p['passport_expiry'] = $p['document_expiry'];
            }
        }
        unset($p);

        // W4: Arabic labels for required fields (no internal field names exposed).
        $requiredLabels = [
            'title'           => 'اللقب (Mr/Ms/Mrs)',
            'first_name'      => 'الاسم الأول',
            'last_name'       => 'اسم العائلة',
            'gender'          => 'الجنس',
            'date_of_birth'   => 'تاريخ الميلاد',
            'nationality'     => 'الجنسية',
            'passport_number' => 'رقم جواز السفر',
            'passport_expiry' => 'تاريخ انتهاء الجواز',
            'email'           => 'البريد الإلكتروني',
        ];

        $today     = new \DateTime('today');
        $sixMonths = (new \DateTime('today'))->modify('+6 months');

        // W3: age boundaries per passenger type.
        $ageRules = [
            'adult'  => ['min' => 12,  'max' => null, 'label' => 'يجب أن يكون المسافر البالغ في عمر 12 سنة أو أكثر'],
            'child'  => ['min' => 2,   'max' => 11,   'label' => 'يجب أن يكون المسافر الطفل بين سنتين و11 سنة'],
            'infant' => ['min' => 0,   'max' => 1,    'label' => 'يجب أن يكون الرضيع أقل من سنتين'],
        ];

        foreach ($passengers as $idx => $p) {
            $num = $idx + 1;

            // Required fields — Arabic messages.
            foreach ($requiredLabels as $field => $label) {
                if (empty($p[$field])) {
                    throw new RuntimeException("المسافر {$num}: {$label} مطلوب.", 422);
                }
            }

            // Title must be a Duffel-accepted value.
            $validTitles = ['mr', 'ms', 'mrs', 'miss', 'dr'];
            if (!in_array(strtolower(trim($p['title'] ?? '')), $validTitles, true)) {
                throw new RuntimeException("المسافر {$num}: اللقب يجب أن يكون أحد: Mr, Ms, Mrs, Miss, Dr.", 422);
            }
            $p['title'] = strtolower(trim($p['title']));
            $passengers[$idx] = $p;

            // Email format validation.
            if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("المسافر {$num}: البريد الإلكتروني غير صحيح.", 422);
            }

            // DOB — must be a valid past date.
            $dob = \DateTime::createFromFormat('Y-m-d', $p['date_of_birth']);
            if (!$dob || $dob >= $today) {
                throw new RuntimeException("المسافر {$num}: تاريخ الميلاد يجب أن يكون في الماضي.", 422);
            }

            // W3: age-type consistency.
            // DateInterval::y has boundary-day quirks; Ymd integer method is exact.
            $pType    = strtolower(trim($p['type'] ?? 'adult'));
            $ageYears = (int) (((int)$today->format('Ymd') - (int)$dob->format('Ymd')) / 10000);
            if (isset($ageRules[$pType])) {
                $rule = $ageRules[$pType];
                $tooYoung = $rule['min'] !== null && $ageYears < $rule['min'];
                $tooOld   = $rule['max'] !== null && $ageYears > $rule['max'];
                if ($tooYoung || $tooOld) {
                    throw new RuntimeException("المسافر {$num}: {$rule['label']}.", 422);
                }
            }

            // Passport expiry — valid date required.
            $expiry = \DateTime::createFromFormat('Y-m-d', $p['passport_expiry']);
            if (!$expiry) {
                throw new RuntimeException("المسافر {$num}: تاريخ انتهاء الجواز غير صحيح (YYYY-MM-DD).", 422);
            }
            if ($expiry <= $today) {
                throw new RuntimeException("المسافر {$num}: جواز السفر منتهي الصلاحية.", 422);
            }
            if ($expiry < $sixMonths) {
                throw new RuntimeException("المسافر {$num}: تاريخ انتهاء الجواز يجب أن يكون بعد ستة أشهر على الأقل.", 422);
            }

            // W2: nationality must be a recognised ISO-3166-1 alpha-3 code.
            $nat = strtoupper(trim((string)($p['nationality'] ?? '')));
            if (!$this->isValidIso3Nationality($nat)) {
                throw new RuntimeException("المسافر {$num}: الجنسية غير صحيحة.", 422);
            }
            // Normalise to uppercase so mapPassengersForDuffel receives a clean value.
            $p['nationality'] = $nat;

            // Phone number: required for adult/child, optional for infant.
            $pTypeNorm = strtolower(trim($p['type'] ?? 'adult'));
            $rawPhone  = trim((string)($p['phone_number'] ?? ''));
            if ($pTypeNorm !== 'infant') {
                if ($rawPhone === '') {
                    throw new RuntimeException("المسافر {$num}: رقم الهاتف مطلوب.", 422);
                }
                $normalised = $this->normalizePhone($rawPhone);
                $digits = preg_replace('/\D/', '', $normalised);
                if (strlen($digits) < 8 || strlen($normalised) > 20) {
                    throw new RuntimeException("المسافر {$num}: رقم الهاتف غير صحيح — يجب أن يحتوي على 8 أرقام على الأقل بصيغة دولية.", 422);
                }
                $p['phone_number'] = $normalised;
            } elseif ($rawPhone !== '') {
                $p['phone_number'] = $this->normalizePhone($rawPhone);
            }

            $passengers[$idx] = $p;
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

        $servicesData = is_string($session['services_data'])
            ? json_decode($session['services_data'], true)
            : ($session['services_data'] ?? []);

        // Compute services cost from available_services prices in offer data
        $servicesCostGBP = $this->computeServicesCost($offerData, $servicesData ?: []);

        return [
            'session'          => [
                'session_key'  => $session['session_key'],
                'current_step' => 'payment',
                'expires_at'   => $session['expires_at'],
            ],
            'offer'            => $this->formatOffer($offerData),
            'passengers_data'  => $passengersData ?? [],
            'pricing_snapshot' => $pricingSnapshot,
            'selected_services' => $servicesData ?? [],
            'services_cost_gbp' => $servicesCostGBP,
        ];
    }

    // =========================================================================
    // createPaymentIntent
    // =========================================================================

    public function createPaymentIntent(
        string  $sessionKey,
        int     $userId,
        ?string $couponCode   = null,
        float   $walletAmount = 0.0
    ): array {
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['payment', 'services', 'review'], true)) {
            throw new RuntimeException('Session is not at the payment step.', 422);
        }

        // ── Payment deadline enforcement (backend guard — frontend countdown is supplementary) ──
        // Block payment if the offer has expired or if the payment/price window has closed.
        $this->enforcePaymentDeadlines($session);

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

        // Add services cost (baggage / seat) to the total charged
        $servicesData = is_string($session['services_data'] ?? null)
            ? json_decode($session['services_data'], true)
            : ($session['services_data'] ?? []);
        if (!empty($servicesData)) {
            $offerId   = $session['provider_offer_id'] ?? '';
            $offerRow  = $this->fetchOffer($offerId);
            if ($offerRow) {
                $offerData    = json_decode($offerRow['offer_data'], true);
                $servicesCost = $this->computeServicesCost($offerData, $servicesData);
                if ($servicesCost > 0) {
                    $totalAmount = round($totalAmount + $servicesCost, 2);
                    $pricingSnapshot['services_cost'] = $servicesCost;
                    $pricingSnapshot['total']         = $totalAmount;
                }
            }
        }

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

        // Mixed payment: validate wallet portion and reduce Stripe charge.
        $walletAmount = max(0.0, min($walletAmount, $totalAmount));
        if ($walletAmount > 0) {
            $walletService = new WalletService($this->db);
            $wallet = $walletService->getWallet($userId);
            if ((float) $wallet['balance'] < $walletAmount) {
                throw new RuntimeException('رصيد المحفظة غير كافٍ للمبلغ المطلوب.', 422);
            }
        }
        $stripeAmount = round($totalAmount - $walletAmount, 2);
        if ($stripeAmount < 0.5) {
            throw new RuntimeException('المبلغ المتبقي للبطاقة أقل من الحد الأدنى. استخدم الدفع بالمحفظة بالكامل.', 422);
        }
        if ($walletAmount > 0) {
            $pricingSnapshot['wallet_amount'] = $walletAmount;
        }

        // Amount in pence (minor units).
        $amountInPence = (int) round($stripeAmount * 100);

        // C-01 hardening: reuse the idempotency key already minted for this
        // session when the charge amount/currency is unchanged, so a retry or
        // double-submit returns the SAME Stripe PaymentIntent instead of
        // creating a second one. A genuine re-price (different amount) mints a
        // fresh key. The key is persisted on booking_sessions.idempotency_key.
        $idemTag   = $amountInPence . ':' . strtolower((string) $currency);
        $storedKey = (string) ($session['idempotency_key'] ?? '');
        $storedTag = (string) ($pricingSnapshot['idem_tag'] ?? '');
        $idempotencyKey = ($storedKey !== '' && $storedTag !== '' && hash_equals($storedTag, $idemTag))
            ? $storedKey
            : bin2hex(random_bytes(32));
        $pricingSnapshot['idem_tag'] = $idemTag;

        // Upsert payment record (placeholder booking_id = 0). Keyed on the unique
        // idempotency_key so reusing the key updates the existing row rather than
        // violating the uq_idempotency constraint.
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
            ':booking_type' => 'flight',
            ':user_id'      => $userId,
            ':method'       => $walletAmount > 0 ? 'mixed' : 'stripe',
            ':idem_key'     => $idempotencyKey,
            ':amount'       => number_format($stripeAmount, 2, '.', ''),
            ':currency'     => strtoupper($currency),
            ':status'       => 'pending',
        ]);

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

        // Update payment row with Stripe PI id (keyed on idempotency_key so it
        // works for both the insert and the reuse/upsert path above).
        $this->db->prepare(
            'UPDATE payments SET stripe_payment_intent_id = :pi WHERE idempotency_key = :k'
        )->execute([':pi' => $paymentIntentId, ':k' => $idempotencyKey]);

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
    // initCardPayment — DISABLED (Duffel Cards not enabled on this account)
    // =========================================================================

    /**
     * @throws \RuntimeException always (HTTP 410) — Duffel Cards is disabled.
     *         Payment is handled exclusively via Stripe.
     */
    public function initCardPayment(string $sessionKey, int $userId, array $cardData, ?string $couponCode = null, ?string $deviceIp = null, ?string $deviceUserAgent = null): array
    {
        throw new \RuntimeException('تم تعطيل Duffel Cards. يتم الدفع حالياً عبر Stripe فقط.', 410);
        // Dead code below — kept for reference only.
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['payment', 'services', 'review'], true)) {
            throw new RuntimeException('Session is not at the payment step.', 422);
        }

        $this->enforcePaymentDeadlines($session);

        $offerId = $session['provider_offer_id'] ?? '';
        if (empty($offerId)) {
            throw new RuntimeException('No offer in session.', 422);
        }

        // Build pricing snapshot (needed for payment record).
        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? null);

        if (empty($pricingSnapshot)) {
            $offer = $this->fetchOffer($offerId);
            if ($offer === null) throw new RuntimeException('Offer expired or not found', 410);
            $pricingSnapshot = $this->calculatePricing((float) $offer['total_amount'], strtoupper($offer['currency'] ?? 'GBP'));
        }

        $totalAmount = (float) ($pricingSnapshot['total'] ?? 0);
        $currency    = strtoupper($pricingSnapshot['currency'] ?? 'GBP');

        // Apply coupon.
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

        // Validate required card fields (billing address is optional — defaults are used).
        foreach (['number', 'name', 'cvc', 'expiry_month', 'expiry_year'] as $f) {
            if (empty($cardData[$f])) {
                throw new RuntimeException('بيانات البطاقة غير مكتملة: ' . $f, 422);
            }
        }
        // Apply billing address defaults when not supplied by the user.
        $cardData['address_line_1']       = trim($cardData['address_line_1']       ?? '') ?: 'N/A';
        $cardData['address_city']         = trim($cardData['address_city']         ?? '') ?: 'N/A';
        $cardData['address_region']       = trim($cardData['address_region']       ?? '') ?: 'N/A';
        $cardData['address_postal_code']  = trim($cardData['address_postal_code']  ?? '') ?: '00000';
        $cardData['address_country_code'] = strtoupper(trim($cardData['address_country_code'] ?? '')) ?: 'US';

        // Build card payload — omit address_line_2 if empty (Duffel rejects empty strings).
        $cardPayload = [
            'number'               => preg_replace('/\s/', '', $cardData['number']),
            'name'                 => $cardData['name'],
            'cvc'                  => (string) $cardData['cvc'],
            'expiry_month'         => str_pad((string) $cardData['expiry_month'], 2, '0', STR_PAD_LEFT),
            'expiry_year'          => str_pad((string) $cardData['expiry_year'], 2, '0', STR_PAD_LEFT),
            'address_line_1'       => $cardData['address_line_1'],
            'address_city'         => $cardData['address_city'],
            'address_region'       => $cardData['address_region'],
            'address_postal_code'  => (string) $cardData['address_postal_code'],
            'address_country_code' => $cardData['address_country_code'],
        ];
        $addrLine2 = trim($cardData['address_line_2'] ?? '');
        if ($addrLine2 !== '') {
            $cardPayload['address_line_2'] = $addrLine2;
        }

        error_log('[DUFFEL_CREATE_CARD_REQUEST] ' . json_encode(array_merge($cardPayload, ['number' => '****', 'cvc' => '***'])));

        // Create single-use Duffel card token.
        try {
            $cardResponse = $this->duffel->createCard($cardPayload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            error_log('[DUFFEL_CREATE_CARD_FAIL] http=' . $e->getCode() . ' msg=' . $msg);
            // Extract and surface the actual Duffel error for diagnosis.
            $decoded = null;
            if (($jsonPos = strpos($msg, '{')) !== false) {
                $decoded = json_decode(substr($msg, $jsonPos), true);
            }
            $duffelCode = $decoded['errors'][0]['code']    ?? '';
            $duffelMsg  = $decoded['errors'][0]['message'] ?? $msg;
            // Pass the real error through so it can be seen in the UI.
            throw new RuntimeException('[Duffel Cards] ' . $duffelCode . ': ' . $duffelMsg, (int)$e->getCode() ?: 422);
        }

        $cardId = $cardResponse['data']['id'] ?? '';
        if (empty($cardId)) {
            throw new RuntimeException('لم يتم استلام معرف البطاقة من Duffel.', 502);
        }

        // Get selected services for 3DS context.
        $servicesData = is_string($session['services_data'])
            ? json_decode($session['services_data'], true)
            : ($session['services_data'] ?? []);
        $cleanServices = [];
        foreach (($servicesData ?: []) as $svc) {
            $sid = trim((string)($svc['id'] ?? ''));
            $qty = (int)($svc['quantity'] ?? 1);
            if ($sid !== '') $cleanServices[] = ['id' => $sid, 'quantity' => max(1, $qty)];
        }

        // Create 3DS session to authenticate the card for this offer.
        // Uses secure_corporate_payment exception → status goes straight to ready_for_payment
        // without requiring a cardholder challenge UI component.
        try {
            $tdsResponse = $this->duffel->createThreeDSecureSession(
                $cardId,
                $offerId,
                $cleanServices,
                'secure_corporate_payment'
            );
        } catch (\Throwable $e) {
            try { $this->duffel->deleteCard($cardId); } catch (\Throwable) {}
            error_log('[DUFFEL_CREATE_3DS_FAIL] card=' . $cardId . ' ' . $e->getMessage());
            throw new RuntimeException('فشل إنشاء جلسة التحقق الأمني. يرجى المحاولة مجدداً.', 502);
        }

        $tdsData   = $tdsResponse['data'] ?? [];
        $tdsId     = $tdsData['id']     ?? '';
        $tdsStatus = $tdsData['status'] ?? '';
        $clientId  = $tdsData['client_id'] ?? ''; // used by Duffel UI component if challenge_required

        error_log('[3DS_STATUS] card=' . $cardId . ' offer=' . $offerId . ' status=' . $tdsStatus . ' tds_id=' . $tdsId);

        if ($tdsStatus === 'failed') {
            try { $this->duffel->deleteCard($cardId); } catch (\Throwable) {}
            throw new RuntimeException('فشل التحقق الأمني للبطاقة. يرجى المحاولة مجدداً أو استخدام بطاقة أخرى.', 422);
        }
        if ($tdsStatus === 'expired') {
            try { $this->duffel->deleteCard($cardId); } catch (\Throwable) {}
            throw new RuntimeException('انتهت صلاحية جلسة التحقق الأمني. يرجى المحاولة مجدداً.', 422);
        }
        if (empty($tdsId)) {
            try { $this->duffel->deleteCard($cardId); } catch (\Throwable) {}
            throw new RuntimeException('لم يتم استلام معرف جلسة التحقق الأمني.', 502);
        }

        // ready_for_payment → proceed directly to completeBooking (no challenge needed).
        // challenge_required → frontend must render the Duffel UI component using client_id.

        // Persist card_id, 3DS session id, device info, and updated pricing.
        $idempotencyKey = bin2hex(random_bytes(32));
        $this->sessionService->update($sessionKey, [
            'duffel_card_id'      => $cardId,
            'tds_session_id'      => $tdsId,
            'device_ip'           => $deviceIp,
            'device_user_agent'   => $deviceUserAgent,
            'idempotency_key'     => $idempotencyKey,
            'coupon_code'         => $couponCode,
            'pricing_snapshot'    => $pricingSnapshot,
            'current_step'        => 'payment',
        ]);

        // Insert payment record so ops can track it.
        try {
            $this->db->prepare(
                'INSERT INTO payments
                   (booking_type, booking_id, user_id, payment_method,
                    idempotency_key, amount, currency, status)
                 VALUES (?, 0, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'flight', $userId, 'duffel_card', $idempotencyKey,
                number_format($totalAmount, 2, '.', ''), $currency, 'pending',
            ]);
        } catch (\Throwable) { /* non-critical if column schema differs */ }

        return [
            'card_id'                   => $cardId,
            'three_d_secure_session_id' => $tdsId,
            'status'                    => $tdsStatus,
            'client_id'                 => $clientId,
            'amount'                    => $totalAmount,
            'currency'                  => $currency,
            'discount'                  => $discountAmount,
        ];
    }

    // =========================================================================
    // completeCardBooking — DISABLED (Duffel Cards not enabled on this account)
    // =========================================================================

    /**
     * @throws \RuntimeException always (HTTP 410) — Duffel Cards is disabled.
     */
    public function completeCardBooking(string $sessionKey, string $tdsSessionId, int $userId): array
    {
        throw new \RuntimeException('تم تعطيل Duffel Cards. يتم الدفع حالياً عبر Stripe فقط.', 410);
        // Dead code below — kept for reference only.
        $session = $this->requireSession($sessionKey, $userId);

        $storedTdsId = $session['tds_session_id'] ?? '';
        if ($storedTdsId !== $tdsSessionId) {
            throw new RuntimeException('جلسة التحقق الأمني غير مطابقة.', 422);
        }

        // Verify 3DS session is authenticated.
        try {
            $tdsResponse = $this->duffel->getThreeDSecureSession($tdsSessionId);
            $tdsStatus   = $tdsResponse['data']['status'] ?? '';
        } catch (\Throwable $e) {
            throw new RuntimeException('فشل التحقق من جلسة الأمان. يرجى المحاولة مجدداً.', 502);
        }

        if ($tdsStatus !== 'ready_for_payment') {
            throw new RuntimeException('فشل التحقق الأمني للبطاقة (3D Secure). يرجى المحاولة مجدداً.', 422);
        }

        $cardId  = $session['duffel_card_id'] ?? '';
        $offerId = $session['provider_offer_id'] ?? '';

        if (empty($cardId) || empty($offerId)) {
            throw new RuntimeException('بيانات الجلسة غير مكتملة.', 422);
        }

        // Delegate to completeBooking with card payment type.
        return $this->completeBooking($sessionKey, '', 'card', $cardId);
    }

    // =========================================================================
    // Forensic step logger (used throughout completeBooking)
    // =========================================================================

    private string $stepRef = '';  // booking reference for log correlation

    private function step(string $name, string $state, array $ctx = []): void
    {
        $line = sprintf(
            '[BOOKING_STEP][%s][%s] %s%s',
            $this->stepRef ?: 'pre-ref',
            $state,
            $name,
            $ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        error_log($line);
    }

    /**
     * Execute a PDO statement with full error logging on failure.
     * Throws RuntimeException with SQL details if the query fails.
     */
    private function execStep(string $stepName, \PDOStatement $stmt, array $params): void
    {
        $this->step($stepName, 'START');
        try {
            $stmt->execute($params);
            $this->step($stepName, 'SUCCESS');
        } catch (\Throwable $e) {
            $info = $stmt->errorInfo();
            $ctx  = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'sql_state' => $info[0] ?? null,
                'error_code'=> $info[1] ?? null,
                'error_msg' => $info[2] ?? null,
                'params'    => $params,
            ];
            $this->step($stepName, 'FAIL', $ctx);
            throw new \RuntimeException(
                sprintf('[DB_FAIL][%s] %s (SQL:%s %s)', $stepName, $e->getMessage(), $info[0] ?? '', $info[2] ?? ''),
                500,
                $e
            );
        }
    }

    // =========================================================================
    // completeBooking  (called by WebhookController or after card 3DS)
    // =========================================================================

    public function completeBooking(
        string $sessionKey,
        string $paymentIntentId,
        string $paymentType = 'balance',
        string $cardId = ''
    ): array
    {
        $this->stepRef = '(pre-ref)';
        $this->step('SESSION_LOAD', 'START', ['session_key' => substr($sessionKey, 0, 8) . '…', 'pi' => substr($paymentIntentId, 0, 12) . '…']);

        // For Stripe/balance payments, look up by session_key (also works for card — disabled).
        if ($paymentType === 'card' || $sessionKey !== '') {
            $stmt = $this->db->prepare(
                'SELECT * FROM booking_sessions
                 WHERE session_key = :sk AND booking_type = :bt
                 LIMIT 1'
            );
            $stmt->execute([':sk' => $sessionKey, ':bt' => 'flight']);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM booking_sessions
                 WHERE payment_intent_id = :pi AND booking_type = :bt
                 LIMIT 1'
            );
            $stmt->execute([':pi' => $paymentIntentId, ':bt' => 'flight']);
        }
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        // For Stripe/balance: fall back to payment_intent_id lookup when session_key didn't match.
        if (!$session && $paymentType !== 'card' && $paymentIntentId !== '') {
            $stmt = $this->db->prepare(
                'SELECT * FROM booking_sessions
                 WHERE payment_intent_id = :pi AND booking_type = :bt
                 LIMIT 1'
            );
            $stmt->execute([':pi' => $paymentIntentId, ':bt' => 'flight']);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$session) {
            $this->step('SESSION_LOAD', 'FAIL', ['reason' => 'not found']);
            throw new RuntimeException('Booking session not found.', 404);
        }
        $this->step('SESSION_LOAD', 'SUCCESS', ['session_id' => $session['id'] ?? '?', 'step' => $session['current_step'] ?? '?']);

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

        // Idempotency guard: if this session already produced a booking — e.g.
        // the Stripe webhook (onStripePaymentSucceeded) and the frontend
        // confirmCheckout race, or the webhook is retried — return the existing
        // booking instead of creating a second Duffel order / booking row.
        if (($session['current_step'] ?? '') === 'complete') {
            $this->step('IDEMPOTENT_RETURN', 'SUCCESS', ['session_id' => $session['id'] ?? '?']);
            return $this->fetchCompletedBookingResult($userId, $paymentIntentId);
        }

        $this->step('OFFER_LOAD', 'START', ['offer_id' => $offerId]);
        $offer = $this->fetchOffer($offerId);
        if ($offer === null) {
            $this->step('OFFER_LOAD', 'FAIL', ['reason' => 'not found / expired']);
            throw new RuntimeException('Offer no longer available.', 410);
        }
        $offerData = json_decode($offer['offer_data'], true);
        $this->step('OFFER_LOAD', 'SUCCESS', ['offer_id' => $offerId, 'total' => $offer['total_amount'], 'currency' => $offer['currency']]);

        // Generate unique booking reference in FM00000001 format — atomic via table lock.
        $this->step('BOOKING_REF_GEN', 'START');
        try {
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
        } catch (\Throwable $lockEx) {
            $sqlState = $lockEx instanceof \PDOException ? (string)$lockEx->getCode() : '';
            $this->step('BOOKING_REF_GEN', 'FAIL', ['error' => $lockEx->getMessage(), 'sql_state' => $sqlState]);
            throw new \RuntimeException('[LOCK_FAIL] ' . $lockEx->getMessage(), 500, $lockEx);
        }
        $this->stepRef = $bookingReference;
        $this->step('BOOKING_REF_GEN', 'SUCCESS', ['ref' => $bookingReference]);

        // Map passengers for Duffel (add required Duffel fields).
        $this->step('PASSENGER_MAPPING', 'START', ['count' => count($passengersData)]);
        $duffelPassengers = $this->mapPassengersForDuffel($passengersData, $offerData);
        $this->step('PASSENGER_MAPPING', 'SUCCESS', ['mapped_count' => count($duffelPassengers)]);

        // Pre-flight validation: ensure every required Duffel field is present
        // before hitting the API. Throws 422 with Arabic message on failure.
        $this->step('PASSENGER_VALIDATION', 'START');
        $this->validateDuffelPassengers($duffelPassengers);
        $this->step('PASSENGER_VALIDATION', 'SUCCESS');

        // Build maximum_quantity map from cached offer's available_services.
        // Duffel rejects quantity > maximum_quantity with a 422 validation error.
        $maxQtyMap = [];
        foreach (($offerData['available_services'] ?? []) as $availSvc) {
            $aid = $availSvc['id'] ?? '';
            if ($aid !== '') {
                $maxQtyMap[$aid] = (int)($availSvc['maximum_quantity'] ?? 1);
            }
        }

        // Build the services list for priceOffer (intended_services).
        // Per Duffel API: once an offer is repriced with intended_services, the services
        // are locked into the priced offer. createOrder MUST NOT send services again —
        // doing so causes an intended_services_conflict error.
        $intendedServices = [];
        foreach (($servicesData ?: []) as $svc) {
            $sid    = trim((string)($svc['id'] ?? ''));
            $maxQty = $maxQtyMap[$sid] ?? 1;
            $qty    = min((int)($svc['quantity'] ?? 1), $maxQty);
            if ($sid !== '' && $qty >= 1) {
                $intendedServices[] = ['id' => $sid, 'quantity' => $qty];
            }
        }

        // Call priceOffer to lock price (including any intended_services).
        // After this call the price is fixed on Duffel's side. createOrder uses
        // the same offer_id with NO services field — they are already embedded.
        $intendedMethods = ['balance']; // Stripe collects money; Duffel charges balance
        $this->step('PRICE_OFFER', 'START', ['offer_id' => $offerId, 'intended_services' => count($intendedServices)]);
        try {
            $priceResponse  = $this->duffel->priceOffer($offerId, $intendedMethods, $intendedServices);
            $pricedData     = $priceResponse['data'] ?? [];
            $pricedAmount   = $pricedData['total_amount']    ?? null;
            $pricedCurrency = strtoupper($pricedData['total_currency'] ?? $offer['currency'] ?? 'GBP');

            if ($pricedAmount === null || $pricedAmount === '') {
                throw new \RuntimeException('لم يتم الحصول على سعر الرحلة من Duffel.', 409);
            }
            $this->step('PRICE_OFFER', 'SUCCESS', ['amount' => $pricedAmount, 'currency' => $pricedCurrency]);
        } catch (\RuntimeException $priceEx) {
            $this->step('PRICE_OFFER', 'FAIL', ['error' => $priceEx->getMessage()]);
            error_log('[PRICE_OFFER_FAIL] offer=' . $offerId . ' error=' . $priceEx->getMessage());
            $mapped = DuffelErrorMapper::fromDuffelException($priceEx);
            $parts  = DuffelErrorMapper::split($mapped->getMessage());
            throw new \RuntimeException($parts['customer'], $mapped->getCode() ?: 409);
        }

        error_log('[AMOUNT_CHECK] offer_id=' . $offerId
            . ' offer_cached=' . $offer['total_amount']
            . ' priced=' . $pricedAmount
            . ' currency=' . $pricedCurrency
            . ' intended_services_count=' . count($intendedServices)
            . ' services_in_createOrder=0 (intentionally empty — embedded in priced offer)');

        // Build Duffel payment payload using the locked price.
        if ($paymentType === 'card' && $cardId !== '') {
            $duffelPayments = [[
                'type'     => 'card',
                'amount'   => (string) $pricedAmount,
                'currency' => $pricedCurrency,
                'card_id'  => $cardId,
            ]];
        } else {
            $duffelPayments = [[
                'type'     => 'balance',
                'amount'   => (string) $pricedAmount,
                'currency' => $pricedCurrency,
            ]];
        }

        // Attach metadata for traceability in Duffel dashboard.
        $metadata = [
            'booking_reference' => $bookingReference,
            'user_id'           => (string) $userId,
            'platform'          => 'flymasar',
        ];

        // Forensic pre-flight log: sanitize PII before logging.
        $sanitizedPassengers = array_map(function (array $pax): array {
            $safe = $pax;
            if (isset($safe['email'])) {
                [$local, $domain] = explode('@', $safe['email'] . '@') + ['', ''];
                $safe['email'] = (strlen($local) > 2 ? substr($local, 0, 2) . '***' : '***') . '@' . $domain;
            }
            if (isset($safe['phone_number'])) {
                $safe['phone_number'] = substr($safe['phone_number'], 0, 4) . '****';
            }
            // Strip passport numbers from logs entirely.
            foreach ($safe['identity_documents'] ?? [] as &$doc) {
                $doc['unique_identifier'] = '****';
            }
            unset($doc);
            return $safe;
        }, $duffelPassengers);

        // services must NOT be sent to createOrder — they were already passed to priceOffer
        // as intended_services and are now embedded in the priced offer. Sending them again
        // causes: intended_services_conflict (HTTP 422).
        $preFlightLog = [
            'offer_id'               => $offerId,
            'booking_reference'      => $bookingReference,
            'passengers_count'       => count($duffelPassengers),
            'passengers'             => $sanitizedPassengers,
            'intended_services_sent_to_price_offer' => $intendedServices,
            'services_in_createOrder' => [],    // always empty — embedded in priced offer
            'payments'               => array_map(fn($pay) => array_merge($pay, ['card_id' => isset($pay['card_id']) ? '****' : null]), $duffelPayments),
            'metadata'               => $metadata,
        ];
        error_log('[DUFFEL_CREATE_ORDER_PRE] ' . json_encode($preFlightLog, JSON_UNESCAPED_UNICODE));
        try {
            $this->db->prepare(
                'INSERT INTO error_logs (level, message, context, created_at) VALUES (?,?,?,NOW())'
            )->execute(['debug', 'createOrder payload', json_encode($preFlightLog, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) {}

        // Create Duffel order. Services field is intentionally omitted — it was already
        // passed to priceOffer as intended_services. Sending it again causes
        // intended_services_conflict per Duffel API spec.
        $this->step('CREATE_ORDER', 'START', ['offer_id' => $offerId, 'passengers' => count($duffelPassengers), 'payment_type' => $paymentType]);
        try {
            $orderResponse = $this->duffel->createOrder(
                $offerId,
                $duffelPassengers,
                $duffelPayments,
                [],             // NO services here — embedded in priced offer
                $metadata,
                $session['device_ip']         ?? null,
                $session['device_user_agent'] ?? null
            );
        $this->step('CREATE_ORDER', 'SUCCESS', ['order_id' => $orderResponse['data']['id'] ?? '?', 'http_status' => $orderResponse['http_status'] ?? 201]);
        } catch (\Throwable $duffelEx) {
            // Map to a structured, Arabic-ready exception
            $mapped   = DuffelErrorMapper::fromDuffelException($duffelEx);
            $parts    = DuffelErrorMapper::split($mapped->getMessage());

            // Full forensic log — exception message, code, trace
            $forensic = [
                'booking_reference' => $bookingReference,
                'offer_id'          => $offerId,
                'duffel_exception'  => $duffelEx->getMessage(),
                'http_status'       => $duffelEx->getCode(),
                'mapped_internal'   => $parts['internal'],
                'mapped_customer'   => $parts['customer'],
                'trace'             => substr($duffelEx->getTraceAsString(), 0, 1500),
            ];
            $this->step('CREATE_ORDER', 'FAIL', ['error' => $duffelEx->getMessage(), 'code' => $duffelEx->getCode()]);
            error_log('[DUFFEL_ORDER_FAIL] ' . json_encode($forensic, JSON_UNESCAPED_UNICODE));
            try {
                $this->db->prepare(
                    'INSERT INTO error_logs (level, message, context, created_at) VALUES (?,?,?,NOW())'
                )->execute(['error', 'createOrder failed', json_encode($forensic, JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable) {}

            // Log the internal detail for operations visibility
            error_log('[DUFFEL_ORDER_FAIL] ref=' . $bookingReference . ' | ' . $parts['internal']);

            // already_paid: the Duffel order already exists (duplicate webhook / retry).
            // Do NOT auto-refund — the booking is valid. Attempt to return the existing booking.
            if (str_contains($parts['internal'], '[Duffel:already_paid]')) {
                $recovered = $this->recoverExistingBooking($paymentIntentId, $userId);
                if ($recovered !== null) {
                    error_log('[ALREADY_PAID_RECOVERY] ref=' . $bookingReference
                        . ' found existing booking_id=' . $recovered['booking_id']);
                    return $recovered;
                }
                // Order exists in Duffel but we can't locate it locally yet — no refund.
                error_log('[ALREADY_PAID_NO_RECOVERY] ref=' . $bookingReference
                    . ' — no local booking found; no auto-refund issued; ops must reconcile');
                throw new RuntimeException($parts['customer'], $mapped->getCode() ?: 409);
            }

            // For card payments: delete the card token on failure (already expired in 25 min anyway).
            if ($paymentType === 'card' && $cardId !== '') {
                try { $this->duffel->deleteCard($cardId); } catch (\Throwable) {}
            }

            // For Stripe/balance payments: auto-refund the Stripe charge.
            if ($paymentType !== 'card' && $paymentIntentId !== '') {
                $this->autoRefundStripeOnDuffelFailure(
                    $paymentIntentId,
                    'auto_refund_' . md5($paymentIntentId),
                    $parts['internal']
                );
            }

            throw new RuntimeException($parts['customer'], $mapped->getCode() ?: 502);
        }

        // ── Handle 200 / 202 pending responses from card payments ──────────────
        // 201 = full order created; 200 = confirmed but resource not yet ready;
        // 202 = accepted, still processing. In both non-201 cases we record a
        // pending booking and return a confirmation message to the user.
        // The order details will arrive via Duffel order.created webhook.
        $httpStatus = $orderResponse['http_status'] ?? 201;
        if ($httpStatus === 200 || $httpStatus === 202) {
            $pendingMessage = $orderResponse['data']['message']
                ?? 'تم تأكيد الحجز وسيظهر في النظام قريباً.';
            error_log('[DUFFEL_ORDER_PENDING] http=' . $httpStatus
                . ' ref=' . $bookingReference . ' msg=' . $pendingMessage);

            // Persist a pending row so ops can track and the order.created/order.creation_failed
            // webhook can locate and update it via provider_offer_id.
            try {
                $this->db->prepare(
                    'INSERT INTO flight_bookings
                       (user_id, provider_id, booking_reference, provider_offer_id, status,
                        trip_type, cabin_class, adults_count, children_count,
                        origin_airport, destination_airport, departure_at, total_amount, currency,
                        created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
                )->execute([
                    $userId, 1, $bookingReference, $offerId, 'pending',
                    'one_way', 'economy',
                    1, 0,
                    '', '', null,
                    $pricedAmount, $pricedCurrency,
                ]);
            } catch (\Throwable $dbEx) {
                error_log('[PENDING_INSERT_FAIL] ' . $dbEx->getMessage());
            }

            return [
                'booking_id'        => 0,
                'booking_reference' => $bookingReference,
                'status'            => 'pending',
                'pending_message'   => $pendingMessage,
                'http_status'       => $httpStatus,
            ];
        }

        $order           = $orderResponse['data'] ?? [];
        $providerOrderId = $order['id'] ?? '';

        // ── Extract all critical Duffel Order fields ──────────────────────────
        $duffelBookingRef   = $order['booking_reference']    ?? null;
        // booking_references[] is an array of per-airline PNR objects (multi-carrier itineraries).
        // Stored as JSON; kept distinct from the single duffel_booking_reference string.
        $bookingReferences  = !empty($order['booking_references'])
            ? json_encode($order['booking_references'], JSON_UNESCAPED_UNICODE) : null;
        $liveMode           = isset($order['live_mode']) ? (int) $order['live_mode'] : 1;
        $orderDocuments   = $order['documents']         ?? [];
        $orderPassengers  = $order['passengers']        ?? [];
        $availableActions = !empty($order['available_actions'])
            ? json_encode($order['available_actions'], JSON_UNESCAPED_UNICODE)
            : null;

        // Payment status fields
        $payStatus               = $order['payment_status'] ?? [];
        $paidAt                  = !empty($payStatus['paid_at'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['paid_at'])) : null;
        $paymentRequiredBy       = !empty($payStatus['payment_required_by'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['payment_required_by'])) : null;
        $priceGuaranteeExpiresAt = !empty($payStatus['price_guarantee_expires_at'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['price_guarantee_expires_at'])) : null;
        $awaitingPayment         = (int)(bool)($payStatus['awaiting_payment'] ?? false);
        $duffelPaymentFailure    = !empty($payStatus['failure_reason'])
            ? substr((string)$payStatus['failure_reason'], 0, 500) : null;

        // Void window (free-cancellation deadline)
        $voidWindowEndsAt = !empty($order['void_window_ends_at'])
            ? date('Y-m-d H:i:s', strtotime($order['void_window_ends_at'])) : null;

        // Refund / change conditions
        $orderConditions  = $order['conditions'] ?? [];
        $refundConditions = isset($orderConditions['refund_before_departure'])
            ? json_encode($orderConditions['refund_before_departure'], JSON_UNESCAPED_UNICODE) : null;
        $changeConditions = isset($orderConditions['change_before_departure'])
            ? json_encode($orderConditions['change_before_departure'], JSON_UNESCAPED_UNICODE) : null;

        // Build ticket-number map: duffel_passenger_id => ticket_number
        $ticketMap = [];
        foreach ($orderPassengers as $op) {
            $opId = $op['id'] ?? '';
            foreach (($op['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'electronic_ticket' && !empty($doc['unique_identifier'])) {
                    $ticketMap[$opId] = $doc['unique_identifier'];
                    break;
                }
            }
        }

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

        // Ensure Duffel provider row exists (id=1). Uses INSERT IGNORE so it is
        // a no-op when the row already exists — safe to call on every booking.
        try {
            $this->db->exec(
                "INSERT IGNORE INTO providers (id, name, type, is_active)
                 VALUES (1, 'Duffel', 'flight', 1)"
            );
        } catch (\Throwable) {}

        // Insert flight_bookings row — base columns only (guaranteed to exist in migration 017).
        // Extended Duffel fields are written in a separate UPDATE below so the booking
        // succeeds even if migration 075 has not yet been applied to the live database.
        $this->step('DB_INSERT_BOOKING', 'START', ['ref' => $bookingReference]);
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
        $bParams = [
            ':user_id'           => $userId,
            ':ref'               => $bookingReference,
            ':provider_order_id' => $providerOrderId,
            ':trip_type'         => $tripType,
            ':cabin_class'       => $cabinClass,
            ':adults'            => $adults,
            ':children'          => $children,
            ':origin'            => $origin,
            ':dest'              => $dest,
            ':departure_at'      => $departureAt,
            ':amount'            => $totalAmount,
            ':currency'          => $currency,
            ':status'            => 'confirmed',
        ];
        $this->execStep('DB_INSERT_BOOKING', $bStmt, $bParams);
        $bookingId = (int) $this->db->lastInsertId();

        // Write extended Duffel order fields added by migration 075.
        // Wrapped in try/catch: if the columns do not exist yet the booking row is already
        // committed above and the user has a confirmed booking — this is non-fatal.
        try {
            $this->db->prepare(
                'UPDATE flight_bookings SET
                    duffel_booking_reference   = :duffel_booking_ref,
                    booking_references         = :booking_references,
                    paid_at                    = :paid_at,
                    payment_required_by        = :payment_required_by,
                    price_guarantee_expires_at = :price_guarantee_expires_at,
                    void_window_ends_at        = :void_window_ends_at,
                    available_actions          = :available_actions,
                    live_mode                  = :live_mode,
                    refund_conditions          = :refund_conditions,
                    change_conditions          = :change_conditions,
                    awaiting_payment           = :awaiting_payment,
                    duffel_payment_failure     = :duffel_payment_failure,
                    synced_at                  = NOW()
                 WHERE id = :id'
            )->execute([
                ':duffel_booking_ref'         => $duffelBookingRef,
                ':booking_references'         => $bookingReferences,
                ':paid_at'                    => $paidAt,
                ':payment_required_by'        => $paymentRequiredBy,
                ':price_guarantee_expires_at' => $priceGuaranteeExpiresAt,
                ':void_window_ends_at'        => $voidWindowEndsAt,
                ':available_actions'          => $availableActions,
                ':live_mode'                  => $liveMode,
                ':refund_conditions'          => $refundConditions,
                ':change_conditions'          => $changeConditions,
                ':awaiting_payment'           => $awaitingPayment,
                ':duffel_payment_failure'     => $duffelPaymentFailure,
                ':id'                         => $bookingId,
            ]);
            $this->step('DB_UPDATE_BOOKING_EXT', 'SUCCESS', ['booking_id' => $bookingId]);
        } catch (\Throwable $extEx) {
            error_log('[BOOKING_EXT_FIELDS_SKIP] booking_id=' . $bookingId
                . ' migration_075_not_applied=true | ' . $extEx->getMessage());
        }

        // Insert flight_booking_passengers (with Duffel passenger ID and ticket number).
        $pStmt = $this->db->prepare(
            'INSERT INTO flight_booking_passengers
               (booking_id, passenger_type, first_name, last_name, gender,
                date_of_birth, nationality, passport_number, passport_expiry,
                provider_passenger_id, ticket_number)
             VALUES
               (:booking_id, :passenger_type, :first_name, :last_name, :gender,
                :dob, :nationality, :passport_number, :passport_expiry,
                :provider_passenger_id, :ticket_number)'
        );
        foreach ($passengersData as $idx => $passenger) {
            $duffelPaxId  = $duffelPassengers[$idx]['id'] ?? null;
            $ticketNumber = $duffelPaxId ? ($ticketMap[$duffelPaxId] ?? null) : null;
            $rawGender    = strtolower(trim((string)($passenger['gender'] ?? '')));
            $genderNorm   = match($rawGender) {
                'male',   'm' => 'male',
                'female', 'f' => 'female',
                default       => 'male',
            };
            $pParams = [
                ':booking_id'             => $bookingId,
                ':passenger_type'         => $passenger['type'] ?? 'adult',
                ':first_name'             => $passenger['first_name'] ?? ($passenger['given_name'] ?? ''),
                ':last_name'              => $passenger['last_name']  ?? ($passenger['family_name'] ?? ''),
                ':gender'                 => $genderNorm,
                ':dob'                    => $passenger['date_of_birth'],
                ':nationality'            => $passenger['nationality'],
                ':passport_number'        => $passenger['passport_number'] ?? null,
                ':passport_expiry'        => $passenger['passport_expiry'] ?? null,
                ':provider_passenger_id'  => $duffelPaxId,
                ':ticket_number'          => $ticketNumber,
            ];
            $this->execStep('DB_INSERT_PASSENGER_' . ($idx + 1), $pStmt, $pParams);
        }

        // Insert order-level documents (electronic tickets, itineraries).
        if (!empty($orderDocuments)) {
            $this->upsertDocuments($bookingId, $orderDocuments);
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
                $sParams = [
                    ':booking_id'     => $bookingId,
                    ':slice_index'    => $sliceIdx,
                    ':segment_order'  => $segIdx + 1,
                    ':origin'         => $seg['origin']['iata_code']      ?? '',
                    ':destination'    => $seg['destination']['iata_code'] ?? '',
                    ':departing_at'   => $seg['departing_at']             ?? '1970-01-01 00:00:00',
                    ':arriving_at'    => $seg['arriving_at']              ?? '1970-01-01 00:00:00',
                    ':carrier'        => $seg['marketing_carrier']['iata_code'] ?? '',
                    ':flight_number'  => ($seg['marketing_carrier']['iata_code'] ?? '') . ($seg['marketing_carrier_flight_number'] ?? ''),
                    ':aircraft'       => $seg['aircraft']['iata_code']    ?? null,
                ];
                $this->execStep('DB_INSERT_SEGMENT_' . $sliceIdx . '_' . ($segIdx + 1), $sStmt, $sParams);
            }
        }

        // Update payment row.
        $payUpdateStmt = $this->db->prepare(
            'UPDATE payments
             SET booking_id = :bid, status = :status
             WHERE stripe_payment_intent_id = :pi'
        );
        $this->execStep('DB_UPDATE_PAYMENT', $payUpdateStmt, [':bid' => $bookingId, ':status' => 'succeeded', ':pi' => $paymentIntentId]);
        $this->step('SAVE_BOOKING', 'SUCCESS', ['booking_id' => $bookingId, 'ref' => $bookingReference]);

        // Advance session step.
        $this->sessionService->update($session['session_key'], ['current_step' => 'complete']);

        // Record coupon usage.
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
                        'flight',
                        $bookingId,
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
                        'Failed to record coupon usage for flight booking',
                        json_encode([
                            'booking_id'  => $bookingId,
                            'coupon_code' => $couponCode,
                            'error'       => $couponEx->getMessage(),
                        ]),
                    ]);
                } catch (\Throwable) {}
            }
        }

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
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions WHERE session_key = :sk AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':sk' => $sessionKey, ':uid' => $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new \RuntimeException('جلسة الحجز غير موجودة', 404);
        }

        // Already complete (webhook arrived before this call)
        if (($session['current_step'] ?? '') === 'complete') {
            return $this->fetchCompletedBookingResult($userId, $paymentIntentId);
        }

        // Verify payment with Stripe directly — don't wait for webhook
        try {
            $stripe = new \App\Adapters\Stripe\StripeAdapter();
            $intent = $stripe->getPaymentIntent($paymentIntentId);
        } catch (\Throwable $e) {
            return ['status' => 'pending', 'message' => 'جارٍ التحقق من الدفع…'];
        }

        if (($intent['status'] ?? '') !== 'succeeded') {
            return ['status' => 'pending', 'message' => 'جارٍ معالجة الدفع…'];
        }

        // Mixed payment: debit wallet for wallet portion before completing booking
        $pricingSnap   = is_string($session['pricing_snapshot'] ?? null)
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? []);
        $walletPortion = (float) ($pricingSnap['wallet_amount'] ?? 0);
        $walletDebited = false;
        $wCurrency     = strtoupper($pricingSnap['currency'] ?? 'GBP');
        $walletService = null;
        if ($walletPortion > 0.009) {
            $walletService = new WalletService($this->db);
            $walletService->debit($userId, $walletPortion, $wCurrency, 'دفع مختلط — حجز رحلة', $sessionKey);
            $walletDebited = true;
        }

        // Payment confirmed — complete booking synchronously
        try {
            $this->completeBooking($sessionKey, $paymentIntentId);
            return $this->fetchCompletedBookingResult($userId, $paymentIntentId);
        } catch (\Throwable $e) {
            // Re-credit wallet portion if booking failed
            if ($walletDebited && $walletService !== null) {
                try {
                    $walletService->credit($userId, $walletPortion, $wCurrency, 'استرداد دفع مختلط فاشل', $sessionKey);
                } catch (\Throwable) {}
            }
            $ctx = [
                'session_key' => $sessionKey,
                'pi'          => $paymentIntentId,
                'error'       => $e->getMessage(),
                'code'        => $e->getCode(),
                'file'        => $e->getFile() . ':' . $e->getLine(),
                'trace'       => substr($e->getTraceAsString(), 0, 1500),
            ];
            try {
                $this->db->prepare(
                    'INSERT INTO error_logs (level, message, context, created_at) VALUES (?,?,?,NOW())'
                )->execute(['error', 'confirmCheckout: completeBooking failed', json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable) {}
            error_log('[CONFIRM_CHECKOUT_FAIL] ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));

            // Throw with the exact mapped customer message (Arabic) + http code
            throw new \RuntimeException($e->getMessage(), (int)$e->getCode() ?: 500);
        }
    }

    private function fetchCompletedBookingResult(int $userId, string $paymentIntentId = ''): array
    {
        // Prefer lookup by payment_intent_id to avoid returning wrong booking in concurrent sessions
        if ($paymentIntentId !== '') {
            $stmt = $this->db->prepare(
                'SELECT fb.id, fb.booking_reference, fb.status
                 FROM flight_bookings fb
                 JOIN payments p ON p.booking_id = fb.id AND p.booking_type = "flight"
                 WHERE p.stripe_payment_intent_id = :pi AND fb.user_id = :uid
                 LIMIT 1'
            );
            $stmt->execute([':pi' => $paymentIntentId, ':uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'status'                   => 'confirmed',
                    'booking_reference'        => $row['booking_reference'] ?? '',
                    'booking_id'               => $row['id']               ?? null,
                    'duffel_booking_reference' => null,
                ];
            }
        }

        // Fallback: most recent booking for this user
        $stmt = $this->db->prepare(
            'SELECT id, booking_reference, status
             FROM flight_bookings WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'status'                   => 'confirmed',
            'booking_reference'        => $row['booking_reference'] ?? '',
            'booking_id'               => $row['id']               ?? null,
            'duffel_booking_reference' => null,
        ];
    }

    // =========================================================================
    // walletCheckout — full wallet payment (no Stripe involved)
    // =========================================================================

    public function walletCheckout(string $sessionKey, int $userId, ?string $couponCode = null): array
    {
        $session = $this->requireSession($sessionKey, $userId);

        if (!in_array($session['current_step'], ['payment', 'services', 'review'], true)) {
            throw new RuntimeException('الجلسة ليست في خطوة الدفع.', 422);
        }

        $this->enforcePaymentDeadlines($session);

        $pricingSnapshot = is_string($session['pricing_snapshot'])
            ? json_decode($session['pricing_snapshot'], true)
            : ($session['pricing_snapshot'] ?? null);

        if (empty($pricingSnapshot)) {
            $offerId = $session['provider_offer_id'] ?? '';
            $offer   = $this->fetchOffer($offerId);
            if ($offer === null) throw new RuntimeException('Offer expired or not found', 410);
            $pricingSnapshot = $this->calculatePricing((float) $offer['total_amount'], strtoupper($offer['currency'] ?? 'GBP'));
        }

        $totalAmount = (float) ($pricingSnapshot['total'] ?? 0);
        $currency    = strtoupper($pricingSnapshot['currency'] ?? 'GBP');

        // Add services cost (baggage / seat)
        $svData = is_string($session['services_data'] ?? null)
            ? json_decode($session['services_data'], true)
            : ($session['services_data'] ?? []);
        if (!empty($svData)) {
            $offId   = $session['provider_offer_id'] ?? '';
            $offerR  = $this->fetchOffer($offId);
            if ($offerR) {
                $oData = json_decode($offerR['offer_data'], true);
                $svCost = $this->computeServicesCost($oData, $svData);
                if ($svCost > 0) {
                    $totalAmount = round($totalAmount + $svCost, 2);
                    $pricingSnapshot['services_cost'] = $svCost;
                    $pricingSnapshot['total']         = $totalAmount;
                }
            }
        }

        // Apply coupon if provided
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

        // Debit wallet (throws if insufficient balance)
        $walletService = new WalletService($this->db);
        $walletService->debit($userId, $totalAmount, $currency, 'دفع حجز رحلة', $sessionKey);

        // Insert payment record for audit trail
        $this->db->prepare(
            'INSERT INTO payments
               (booking_type, booking_id, user_id, payment_method,
                idempotency_key, amount, currency, status)
             VALUES
               (:booking_type, 0, :user_id, :method,
                :idem_key, :amount, :currency, :status)'
        )->execute([
            ':booking_type' => 'flight',
            ':user_id'      => $userId,
            ':method'       => 'wallet',
            ':idem_key'     => bin2hex(random_bytes(16)),
            ':amount'       => number_format($totalAmount, 2, '.', ''),
            ':currency'     => $currency,
            ':status'       => 'succeeded',
        ]);
        $paymentId = (int) $this->db->lastInsertId();

        // Mark session as payment step
        $this->sessionService->update($sessionKey, [
            'pricing_snapshot' => $pricingSnapshot,
            'current_step'     => 'payment',
        ]);

        try {
            $result = $this->completeBooking($sessionKey, '');
        } catch (\Throwable $e) {
            // Booking failed — re-credit wallet
            try {
                $walletService->credit($userId, $totalAmount, $currency, 'استرداد حجز فاشل', $sessionKey);
            } catch (\Throwable) {}
            throw $e;
        }

        // Link payment record to booking
        if (!empty($result['booking_id'])) {
            $this->db->prepare('UPDATE payments SET booking_id = :bid WHERE id = :id')
                ->execute([':bid' => $result['booking_id'], ':id' => $paymentId]);
        }

        return $result;
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
                            "slice_index",        fbs.slice_index,
                            "segment_order",      fbs.segment_order,
                            "origin_airport",     fbs.origin_airport,
                            "destination_airport",fbs.destination_airport,
                            "departure_at",       fbs.departure_at,
                            "arrival_at",         fbs.arrival_at,
                            "airline_code",       fbs.airline_code,
                            "flight_number",      fbs.flight_number,
                            "aircraft_type",      fbs.aircraft_type
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
            $segs = json_decode($row['segments'] ?? 'null', true) ?? [];
            // Filter out null entries (LEFT JOIN with no segments produces [null])
            $row['segments'] = array_values(array_filter($segs, fn($s) => $s !== null && isset($s['origin_airport'])));
            return $row;
        }, $rows);
    }

    // =========================================================================
    // getBookingById
    // =========================================================================

    public function getBookingById(int $bookingId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flight_bookings WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId, ':uid' => $userId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return null;
        }
        return $this->enrichBooking($booking);
    }

    public function getBookingByReference(string $bookingRef, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flight_bookings WHERE booking_reference = :ref AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':ref' => $bookingRef, ':uid' => $userId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return null;
        }
        return $this->enrichBooking($booking);
    }

    public function getBookingByReferenceAdmin(string $bookingRef): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flight_bookings WHERE booking_reference = :ref LIMIT 1'
        );
        $stmt->execute([':ref' => $bookingRef]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return null;
        }
        return $this->enrichBooking($booking);
    }

    public function getBookingByReferenceGuest(string $bookingRef, string $lastName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT fb.* FROM flight_bookings fb
             JOIN flight_booking_passengers fbp ON fbp.booking_id = fb.id
             WHERE fb.booking_reference = :ref AND LOWER(fbp.last_name) = LOWER(:ln)
             LIMIT 1'
        );
        $stmt->execute([':ref' => $bookingRef, ':ln' => trim($lastName)]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return null;
        }
        return $this->enrichBooking($booking);
    }

    private function enrichBooking(array $booking): array
    {
        $bookingId = (int) $booking['id'];

        // Decode JSON columns.
        foreach (['available_actions', 'refund_conditions', 'change_conditions', 'booking_references'] as $col) {
            if (!empty($booking[$col]) && is_string($booking[$col])) {
                $booking[$col] = json_decode($booking[$col], true);
            }
        }

        // Segments.
        $segStmt = $this->db->prepare(
            'SELECT * FROM flight_booking_segments
             WHERE booking_id = :id ORDER BY slice_index ASC, segment_order ASC'
        );
        $segStmt->execute([':id' => $bookingId]);
        $segs = $segStmt->fetchAll(PDO::FETCH_ASSOC);

        // If no segment rows exist, synthesize one from the booking-level columns
        if (empty($segs) && (!empty($booking['origin_airport']) || !empty($booking['departure_at']))) {
            $segs = [[
                'booking_id'          => $bookingId,
                'slice_index'         => 0,
                'segment_order'       => 1,
                'origin_airport'      => $booking['origin_airport']      ?? '',
                'destination_airport' => $booking['destination_airport'] ?? '',
                'departure_at'        => $booking['departure_at']        ?? null,
                'arrival_at'          => $booking['return_at']           ?? null,
                'airline_code'        => '',
                'flight_number'       => '',
                'aircraft_type'       => null,
            ]];
        }
        $booking['segments'] = $segs;

        // Passengers.
        $pStmt = $this->db->prepare(
            'SELECT * FROM flight_booking_passengers WHERE booking_id = :id ORDER BY id'
        );
        $pStmt->execute([':id' => $bookingId]);
        $booking['passengers'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Account-holder contact (used by the e-ticket / invoice contact block).
        // Contact email/phone are not stored on the booking or passenger rows —
        // they belong to the booking's user account.
        try {
            $uStmt = $this->db->prepare('SELECT email, phone_number FROM users WHERE id = :id LIMIT 1');
            $uStmt->execute([':id' => (int) ($booking['user_id'] ?? 0)]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                if (empty($booking['user_email']))   $booking['user_email']   = $u['email'] ?? null;
                if (empty($booking['contact_phone'])) $booking['contact_phone'] = $u['phone_number'] ?? null;
            }
        } catch (\Throwable) {}

        // Documents (electronic tickets, itineraries).
        try {
            $dStmt = $this->db->prepare(
                'SELECT * FROM flight_booking_documents WHERE booking_id = :id ORDER BY id'
            );
            $dStmt->execute([':id' => $bookingId]);
            $docs = $dStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($docs as &$doc) {
                if (!empty($doc['passenger_ids']) && is_string($doc['passenger_ids'])) {
                    $doc['passenger_ids'] = json_decode($doc['passenger_ids'], true);
                }
            }
            $booking['documents'] = $docs;
        } catch (\Throwable) {
            $booking['documents'] = [];
        }

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

        // ── Guard 1: already cancelled ────────────────────────────
        if (in_array($booking['status'], ['cancelled', 'cancellation_pending_refund', 'cancellation_pending_manual_refund'], true)) {
            throw new RuntimeException('هذا الحجز ملغي بالفعل.', 422);
        }

        $providerOrderId = $booking['provider_order_id'] ?? '';
        if (empty($providerOrderId)) {
            throw new RuntimeException('Cannot cancel: no provider order ID found.', 422);
        }

        // ── Guard 2: flight has already departed ──────────────────
        $departureAt = $booking['departure_at'] ?? null;
        if ($departureAt && new \DateTime($departureAt) < new \DateTime()) {
            throw new RuntimeException(
                'لا يمكن إلغاء هذا الحجز لأن موعد الرحلة قد مضى. يرجى التواصل معنا عبر واتساب للمساعدة.',
                422
            );
        }

        // ── Guard 3: available_actions check ─────────────────────
        $availableActions = $booking['available_actions'] ?? null;
        if (is_string($availableActions)) {
            $availableActions = json_decode($availableActions, true) ?? [];
        }
        if (is_array($availableActions) && !empty($availableActions)
            && !in_array('cancel', $availableActions, true)) {
            throw new RuntimeException('هذا الحجز لا يقبل الإلغاء وفق شروط الناقل الجوي.', 422);
        }

        // ── Guard 4: rate-limit — only one pending cancellation per booking ──
        if (!empty($booking['pending_cancellation_id']) && !empty($booking['cancellation_expires_at'])) {
            if (new \DateTime($booking['cancellation_expires_at']) > new \DateTime()) {
                // Return the existing pending cancellation rather than creating a new one
                $rc = $booking['refund_conditions'] ?? null;
                if (is_string($rc)) { $rc = json_decode($rc, true); }
                $pen    = (is_array($rc) && isset($rc['penalty_amount']))   ? $rc['penalty_amount']   : null;
                $penCur = (is_array($rc) && isset($rc['penalty_currency'])) ? strtoupper($rc['penalty_currency']) : strtoupper($booking['cancellation_refund_currency'] ?? ($booking['currency'] ?? 'GBP'));
                return [
                    'cancellation_id'    => $booking['pending_cancellation_id'],
                    'refund_amount'      => $booking['cancellation_refund_amount']   ?? '0.00',
                    'refund_currency'    => strtoupper($booking['cancellation_refund_currency']  ?? ($booking['currency'] ?? 'GBP')),
                    'refund_to'          => $booking['cancellation_refund_to']        ?? 'original_payment_method',
                    'expires_at'         => $booking['cancellation_expires_at'],
                    'void_window_active' => false,
                    'no_refund_warning'  => $this->isNoRefundTicket($booking),
                    'total_paid'         => $booking['total_amount'] ?? null,
                    'total_paid_currency' => strtoupper($booking['currency'] ?? 'GBP'),
                    'penalty_amount'     => $pen,
                    'penalty_currency'   => $penCur,
                ];
            }
        }

        // Void window check
        $voidWindowActive = false;
        if (!empty($booking['void_window_ends_at'])) {
            $voidWindowActive = new \DateTime($booking['void_window_ends_at']) > new \DateTime();
        }

        $cancellationResponse = $this->duffel->cancelOrder($providerOrderId);
        $cancellation = $cancellationResponse['data'] ?? [];

        $cancellationExpiresAt = !empty($cancellation['expires_at'])
            ? date('Y-m-d H:i:s', strtotime($cancellation['expires_at'])) : null;

        $refundAmount = $cancellation['refund_amount'] ?? '0.00';

        // Extract penalty from stored refund_conditions (Duffel order data)
        $refCond = $booking['refund_conditions'] ?? null;
        if (is_string($refCond)) { $refCond = json_decode($refCond, true); }
        $penaltyAmount   = (is_array($refCond) && isset($refCond['penalty_amount']))   ? $refCond['penalty_amount']   : null;
        $penaltyCurrency = (is_array($refCond) && isset($refCond['penalty_currency'])) ? strtoupper($refCond['penalty_currency']) : strtoupper($cancellation['refund_currency'] ?? ($booking['currency'] ?? 'GBP'));

        $this->db->prepare(
            'UPDATE flight_bookings
             SET pending_cancellation_id      = :cid,
                 cancellation_expires_at      = :exp_at,
                 cancellation_refund_to       = :refund_to,
                 cancellation_refund_amount   = :refund_amount,
                 cancellation_refund_currency = :refund_currency,
                 updated_at                  = NOW()
             WHERE id = :id'
        )->execute([
            ':cid'            => $cancellation['id']             ?? '',
            ':exp_at'         => $cancellationExpiresAt,
            ':refund_to'      => $cancellation['refund_to']      ?? 'original_payment_method',
            ':refund_amount'  => $refundAmount,
            ':refund_currency'=> strtoupper($cancellation['refund_currency'] ?? ($booking['currency'] ?? 'GBP')),
            ':id'             => $bookingId,
        ]);

        return [
            'cancellation_id'   => $cancellation['id']    ?? null,
            'refund_amount'     => $refundAmount,
            'refund_currency'   => strtoupper($cancellation['refund_currency'] ?? ($booking['currency'] ?? 'GBP')),
            'refund_to'         => $cancellation['refund_to'] ?? 'original_payment_method',
            'expires_at'        => $cancellation['expires_at'] ?? null,
            'void_window_active' => $voidWindowActive,
            'no_refund_warning' => $this->isNoRefundTicket($booking),
            'total_paid'        => $booking['total_amount'] ?? null,
            'total_paid_currency' => strtoupper($booking['currency'] ?? 'GBP'),
            'penalty_amount'    => $penaltyAmount,
            'penalty_currency'  => $penaltyCurrency,
        ];
    }

    // Returns true if the ticket conditions explicitly disallow refund.
    private function isNoRefundTicket(array $booking): bool
    {
        $cond = $booking['refund_conditions'] ?? null;
        if (is_string($cond)) {
            $cond = json_decode($cond, true);
        }
        if (is_array($cond) && isset($cond['allowed'])) {
            return $cond['allowed'] === false;
        }
        return false;
    }

    // =========================================================================
    // confirmCancelBooking — Step 2: commit the cancellation
    // =========================================================================

    public function confirmCancelBooking(int $bookingId, string $cancellationId, int $userId, string $refundPreference = 'original_payment'): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.', 404);
        }

        if (in_array($booking['status'], ['cancelled', 'cancellation_pending_refund', 'cancellation_pending_manual_refund'], true)) {
            throw new RuntimeException('هذا الحجز ملغي بالفعل.', 422);
        }

        // Guard: flight already departed — absolute block
        $departureAt = $booking['departure_at'] ?? null;
        if ($departureAt && new \DateTime($departureAt) < new \DateTime()) {
            throw new RuntimeException('لا يمكن إتمام الإلغاء: موعد الرحلة قد مضى.', 422);
        }

        // Guard: cancellation_id must match what we stored (prevents tampering)
        if (!empty($booking['pending_cancellation_id'])
            && $booking['pending_cancellation_id'] !== $cancellationId) {
            throw new RuntimeException('معرّف الإلغاء غير صحيح.', 422);
        }

        // Guard: quote expiry
        if (!empty($booking['cancellation_expires_at'])) {
            if (new \DateTime($booking['cancellation_expires_at']) < new \DateTime()) {
                throw new RuntimeException('انتهت صلاحية عرض الاسترداد. يرجى بدء طلب الإلغاء من جديد.', 410);
            }
        }

        // Guard: enforce no-refund policy — override stored amount to 0
        if ($this->isNoRefundTicket($booking)) {
            $this->db->prepare(
                'UPDATE flight_bookings SET cancellation_refund_amount = 0 WHERE id = :id'
            )->execute([':id' => $bookingId]);
            $booking['cancellation_refund_amount'] = '0.00';
        }

        // Confirm with Duffel — this actually cancels the airline booking.
        $confirmResponse = $this->duffel->confirmCancellation($cancellationId);
        $confirmedAt = !empty($confirmResponse['data']['confirmed_at'])
            ? date('Y-m-d H:i:s', strtotime($confirmResponse['data']['confirmed_at']))
            : date('Y-m-d H:i:s');

        // Save customer refund preference
        try {
            $this->db->prepare(
                'UPDATE flight_bookings SET refund_preference = :pref WHERE id = :id'
            )->execute([':pref' => $refundPreference, ':id' => $bookingId]);
        } catch (\Throwable $e) { /* column may not exist on older deploys */ }

        // Load original payment record
        $payStmt = $this->db->prepare(
            'SELECT id, stripe_payment_intent_id, amount, currency, payment_method FROM payments
             WHERE booking_type = :bt AND booking_id = :bid AND status = :status
             LIMIT 1'
        );
        $payStmt->execute([':bt' => 'flight', ':bid' => $bookingId, ':status' => 'succeeded']);
        $payment = $payStmt->fetch(\PDO::FETCH_ASSOC);

        $refundId    = null;
        $finalStatus = 'cancelled';

        $duffelRefundAmount   = $booking['cancellation_refund_amount']   ?? null;
        $duffelRefundCurrency = strtoupper($booking['cancellation_refund_currency'] ?? $booking['currency'] ?? 'GBP');

        // ── Option A: refund to internal wallet ───────────────────
        if ($refundPreference === 'wallet' && $duffelRefundAmount !== null && (float)$duffelRefundAmount > 0) {
            try {
                $walletService = new WalletService($this->db);
                $walletService->credit(
                    $userId,
                    (float) $duffelRefundAmount,
                    $duffelRefundCurrency,
                    'استرداد إلغاء حجز ' . ($booking['booking_reference'] ?? $bookingId),
                    $booking['booking_reference'] ?? (string)$bookingId
                );
                // Mark original payment as wallet-refunded
                if ($payment) {
                    $this->db->prepare(
                        'UPDATE payments SET status = :s, updated_at = NOW() WHERE id = :id'
                    )->execute([':s' => 'refunded_to_wallet', ':id' => $payment['id']]);
                }
            } catch (\Throwable $e) {
                error_log('[WALLET_CREDIT_FAILED] BookingID=' . $bookingId . ' | ' . $e->getMessage());
                $finalStatus = 'cancellation_pending_refund';
            }
        }
        // ── Option B: refund to original payment method (Stripe) ──
        elseif ($payment && !empty($payment['stripe_payment_intent_id'])) {
            $stripeChargeCurrency = strtoupper($payment['currency'] ?? 'GBP');

            // Mixed (wallet + card) payments: the payment row only holds the card
            // portion, so auto-refunding the full Duffel amount to the card would
            // over-refund the card and never return the wallet portion. Route to
            // manual refund so ops returns each portion to its source correctly.
            if (($payment['payment_method'] ?? '') === 'mixed') {
                error_log(sprintf(
                    '[MANUAL_REFUND_REQUIRED|MIXED] BookingID=%d cardPortion=%s %s duffelRefund=%s %s',
                    $bookingId, $payment['amount'], $stripeChargeCurrency,
                    (string) $duffelRefundAmount, $duffelRefundCurrency
                ));
                $this->db->prepare(
                    'UPDATE payments SET status = :s, updated_at = NOW() WHERE id = :id'
                )->execute([':s' => 'refund_pending_manual', ':id' => $payment['id']]);
                $finalStatus = 'cancellation_pending_manual_refund';
            }
            // Currency mismatch: cannot safely convert — flag for manual processing.
            elseif ($duffelRefundAmount !== null && $duffelRefundCurrency !== $stripeChargeCurrency) {
                error_log(sprintf(
                    '[MANUAL_REFUND_REQUIRED] BookingID=%d DuffelRefund=%s %s StripeCharge=%s %s',
                    $bookingId, $duffelRefundAmount, $duffelRefundCurrency,
                    $payment['amount'], $stripeChargeCurrency
                ));
                $this->db->prepare(
                    'UPDATE payments SET status = :s, updated_at = NOW() WHERE id = :id'
                )->execute([':s' => 'refund_pending_manual', ':id' => $payment['id']]);
                $finalStatus = 'cancellation_pending_manual_refund';
            } else {
                $refundAmountMinor = $duffelRefundAmount !== null
                    ? (int) round((float) $duffelRefundAmount * 100)
                    : null;

                try {
                    $refund   = $this->stripe->createRefund(
                        $payment['stripe_payment_intent_id'],
                        $refundAmountMinor,
                        'cancel_refund_' . md5($cancellationId),
                        'requested_by_customer'
                    );
                    $refundId = $refund['id'] ?? null;

                    $this->db->prepare(
                        'UPDATE payments SET status = :status, stripe_refund_id = :rid, updated_at = NOW()
                         WHERE id = :id'
                    )->execute([':status' => 'refunded', ':rid' => $refundId, ':id' => $payment['id']]);
                } catch (\Throwable $e) {
                    error_log('[REFUND_FAILED] BookingID=' . $bookingId . ' | ' . $e->getMessage());
                    $finalStatus = 'cancellation_pending_refund';
                    $this->db->prepare(
                        'UPDATE payments SET status = :s, updated_at = NOW() WHERE id = :id'
                    )->execute([':s' => 'refund_failed', ':id' => $payment['id']]);
                }
            }
        }

        $this->db->prepare(
            'UPDATE flight_bookings
             SET status = :status, cancelled_at = NOW(), cancellation_confirmed_at = :confirmed_at, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            ':status'       => $finalStatus,
            ':confirmed_at' => $confirmedAt,
            ':id'           => $bookingId,
        ]);

        return [
            'booking_id'    => $bookingId,
            'status'        => $finalStatus,
            'refund_id'     => $refundId,
            'confirmed_at'  => $confirmedAt,
        ];
    }

    // =========================================================================
    // syncFromDuffel — refresh booking from live Duffel order
    // =========================================================================

    // Internal/job-worker entrypoint: no userId check (trusted internal call).
    public function syncFromDuffelByBookingId(int $bookingId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM flight_bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new \RuntimeException("Booking {$bookingId} not found.", 404);
        }
        return $this->syncBookingFromDuffelOrder($bookingId, $booking);
    }

    public function syncFromDuffel(int $bookingId, int $userId): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) {
            throw new \RuntimeException('Booking not found.', 404);
        }
        return $this->syncBookingFromDuffelOrder($bookingId, $booking);
    }

    private function syncBookingFromDuffelOrder(int $bookingId, array $booking): array
    {
        $userId = $booking['user_id'] ?? null;

        $providerOrderId = $booking['provider_order_id'] ?? '';
        if (empty($providerOrderId)) {
            throw new \RuntimeException('No Duffel order ID on this booking.', 422);
        }

        $response = $this->duffel->getOrder($providerOrderId);
        $order    = $response['data'] ?? $response;

        // Extract all live fields from Duffel.
        $duffelBookingRef        = $order['booking_reference']   ?? null;
        $bookingReferencesSync   = !empty($order['booking_references'])
            ? json_encode($order['booking_references'], JSON_UNESCAPED_UNICODE) : null;
        $liveMode                = isset($order['live_mode']) ? (int) $order['live_mode'] : 1;
        $availableActions        = !empty($order['available_actions'])
            ? json_encode($order['available_actions']) : null;
        $payStatus               = $order['payment_status']     ?? [];
        $paidAt                  = !empty($payStatus['paid_at'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['paid_at'])) : null;
        $paymentRequiredBy       = !empty($payStatus['payment_required_by'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['payment_required_by'])) : null;
        $priceGuaranteeExpiresAt = !empty($payStatus['price_guarantee_expires_at'])
            ? date('Y-m-d H:i:s', strtotime($payStatus['price_guarantee_expires_at'])) : null;
        $voidWindowEndsAt        = !empty($order['void_window_ends_at'])
            ? date('Y-m-d H:i:s', strtotime($order['void_window_ends_at'])) : null;
        // Always read conditions from Duffel — if key exists in response, write it (even null clears stale data)
        $orderConditionsRaw      = array_key_exists('conditions', $order) ? ($order['conditions'] ?? []) : false;
        $refundConditions        = ($orderConditionsRaw !== false && isset($orderConditionsRaw['refund_before_departure']))
            ? json_encode($orderConditionsRaw['refund_before_departure']) : null;
        $changeConditions        = ($orderConditionsRaw !== false && isset($orderConditionsRaw['change_before_departure']))
            ? json_encode($orderConditionsRaw['change_before_departure']) : null;
        // If Duffel returned the conditions key, always overwrite; otherwise keep existing via COALESCE
        $conditionsKeyPresent    = $orderConditionsRaw !== false;
        $cancelledAt             = !empty($order['cancelled_at'])
            ? date('Y-m-d H:i:s', strtotime($order['cancelled_at'])) : null;
        $cancellation            = $order['cancellation']       ?? null;
        $cancellationRefundAmt   = $cancellation['refund_amount'] ?? null;
        $cancellationRefundTo    = $cancellation['refund_to']     ?? null;
        $cancellationExpiresAt   = !empty($cancellation['expires_at'])
            ? date('Y-m-d H:i:s', strtotime($cancellation['expires_at'])) : null;

        // Determine status from Duffel order.status field + cancelled_at
        $duffelOrderStatus = strtolower($order['status'] ?? '');
        $newStatus = $booking['status'];

        if ($cancelledAt || $duffelOrderStatus === 'cancelled') {
            // Never downgrade from a more specific cancellation status
            if (!in_array($newStatus, ['cancellation_pending_refund', 'cancellation_pending_manual_refund'], true)) {
                $newStatus = 'cancelled';
            }
        } elseif ($duffelOrderStatus === 'confirmed' && in_array($newStatus, ['pending', 'awaiting_payment'], true)) {
            $newStatus = 'confirmed';
        }

        // Live total from Duffel (updated after changes/refunds)
        $liveTotalAmount   = $order['total_amount']   ?? null;
        $liveTotalCurrency = $order['total_currency'] ?? null;

        // Base fields — always exist (migration 017).
        $baseUpdate = 'UPDATE flight_bookings SET status = :status, updated_at = NOW()';
        $baseParams = [':status' => $newStatus, ':id' => $bookingId];
        if ($liveTotalAmount !== null) {
            $baseUpdate .= ', total_amount = :total_amount';
            $baseParams[':total_amount'] = $liveTotalAmount;
        }
        if ($liveTotalCurrency !== null) {
            $baseUpdate .= ', currency = :currency';
            $baseParams[':currency'] = strtoupper($liveTotalCurrency);
        }
        $baseUpdate .= ' WHERE id = :id';
        $this->db->prepare($baseUpdate)->execute($baseParams);

        // Extended Duffel fields — migration 075. Wrapped in try/catch so sync
        // succeeds even if migration 075 has not been applied to the live database.
        try {
            $this->db->prepare(
                'UPDATE flight_bookings SET
                    duffel_booking_reference      = COALESCE(:duffel_ref, duffel_booking_reference),
                    booking_references            = COALESCE(:booking_references, booking_references),
                    paid_at                       = COALESCE(:paid_at, paid_at),
                    payment_required_by           = :payment_required_by,
                    price_guarantee_expires_at    = :price_guarantee_expires_at,
                    void_window_ends_at           = :void_window_ends_at,
                    available_actions             = :available_actions,
                    live_mode                     = :live_mode,
                    refund_conditions             = IF(:cond_present, :refund_conditions, refund_conditions),
                    change_conditions             = IF(:cond_present2, :change_conditions, change_conditions),
                    cancellation_refund_to        = COALESCE(:refund_to, cancellation_refund_to),
                    cancellation_expires_at       = COALESCE(:cancel_exp, cancellation_expires_at),
                    cancellation_refund_amount    = COALESCE(:refund_amount, cancellation_refund_amount),
                    synced_at                     = NOW()
                 WHERE id = :id'
            )->execute([
                ':duffel_ref'                  => $duffelBookingRef,
                ':booking_references'          => $bookingReferencesSync,
                ':paid_at'                     => $paidAt,
                ':payment_required_by'         => $paymentRequiredBy,
                ':price_guarantee_expires_at'  => $priceGuaranteeExpiresAt,
                ':void_window_ends_at'         => $voidWindowEndsAt,
                ':available_actions'           => $availableActions,
                ':live_mode'                   => $liveMode,
                ':cond_present'                => $conditionsKeyPresent ? 1 : 0,
                ':cond_present2'               => $conditionsKeyPresent ? 1 : 0,
                ':refund_conditions'           => $refundConditions,
                ':change_conditions'           => $changeConditions,
                ':refund_to'                   => $cancellationRefundTo,
                ':cancel_exp'                  => $cancellationExpiresAt,
                ':refund_amount'               => $cancellationRefundAmt,
                ':id'                          => $bookingId,
            ]);
        } catch (\Throwable $syncExtEx) {
            error_log('[SYNC_EXT_FIELDS_SKIP] booking_id=' . $bookingId
                . ' migration_075_not_applied=true | ' . $syncExtEx->getMessage());
        }

        // Sync segments: replace all existing segments with current Duffel slices/segments.
        $slices = $order['slices'] ?? [];
        if (!empty($slices)) {
            // Delete old segments first.
            $this->db->prepare('DELETE FROM flight_booking_segments WHERE booking_id = :id')
                     ->execute([':id' => $bookingId]);

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
            $firstDeparture = null;
            $firstOrigin    = null;
            $firstDest      = null;

            foreach ($slices as $sliceIdx => $slice) {
                foreach (($slice['segments'] ?? []) as $segIdx => $seg) {
                    $depAt = $seg['departing_at'] ?? '1970-01-01 00:00:00';
                    $arrAt = $seg['arriving_at']  ?? '1970-01-01 00:00:00';
                    if ($sliceIdx === 0 && $segIdx === 0) {
                        $firstDeparture = $depAt;
                        $firstOrigin    = $seg['origin']['iata_code']      ?? null;
                        $firstDest      = $seg['destination']['iata_code'] ?? null;
                    }
                    // Track the final destination of the outbound slice.
                    if ($sliceIdx === 0) {
                        $firstDest = $seg['destination']['iata_code'] ?? $firstDest;
                    }
                    try {
                        $sStmt->execute([
                            ':booking_id'    => $bookingId,
                            ':slice_index'   => $sliceIdx,
                            ':segment_order' => $segIdx + 1,
                            ':origin'        => $seg['origin']['iata_code']              ?? '',
                            ':destination'   => $seg['destination']['iata_code']         ?? '',
                            ':departing_at'  => $depAt,
                            ':arriving_at'   => $arrAt,
                            ':carrier'       => $seg['marketing_carrier']['iata_code']   ?? ($seg['operating_carrier']['iata_code'] ?? ''),
                            ':flight_number' => ($seg['marketing_carrier']['iata_code']  ?? ($seg['operating_carrier']['iata_code'] ?? ''))
                                              . ($seg['marketing_carrier_flight_number'] ?? ($seg['operating_carrier_flight_number'] ?? '')),
                            ':aircraft'      => $seg['aircraft']['iata_code']            ?? null,
                        ]);
                    } catch (\Throwable) { /* non-critical */ }
                }
            }

            // Update booking-level departure_at, origin, destination to match new flight.
            if ($firstDeparture && $firstDeparture !== '1970-01-01 00:00:00') {
                try {
                    $fields = ['departure_at = :dep', 'updated_at = NOW()'];
                    $params = [':dep' => $firstDeparture, ':id' => $bookingId];
                    if ($firstOrigin) { $fields[] = 'origin_airport = :orig'; $params[':orig'] = $firstOrigin; }
                    if ($firstDest)   { $fields[] = 'destination_airport = :dest'; $params[':dest'] = $firstDest; }
                    $this->db->prepare('UPDATE flight_bookings SET ' . implode(', ', $fields) . ' WHERE id = :id')
                             ->execute($params);
                } catch (\Throwable) { /* non-critical */ }
            }
        }

        // Sync documents.
        $documents = $order['documents'] ?? [];
        if (!empty($documents)) {
            $this->upsertDocuments($bookingId, $documents);
        }

        // Sync ticket numbers onto passengers from order passenger documents.
        $orderPassengers = $order['passengers'] ?? [];
        foreach ($orderPassengers as $op) {
            $opId = $op['id'] ?? '';
            foreach (($op['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'electronic_ticket' && !empty($doc['unique_identifier'])) {
                    try {
                        $this->db->prepare(
                            'UPDATE flight_booking_passengers
                             SET ticket_number = :tn
                             WHERE booking_id = :bid
                               AND provider_passenger_id = :pid'
                        )->execute([':tn' => $doc['unique_identifier'], ':bid' => $bookingId, ':pid' => $opId]);
                    } catch (\Throwable) { /* non-critical */ }
                    break;
                }
            }
        }

        if ($userId !== null) {
            return $this->getBookingById($bookingId, (int)$userId) ?? $booking;
        }
        $stmt = $this->db->prepare('SELECT * FROM flight_bookings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $bookingId]);
        $refreshed = $stmt->fetch(\PDO::FETCH_ASSOC) ?: $booking;
        return $this->enrichBooking($refreshed);
    }

    /**
     * Enforce payment and price-guarantee deadlines before creating a Stripe PaymentIntent.
     * Backend guard — the frontend countdown is supplementary UX only.
     *
     * @throws RuntimeException 422 if any deadline has passed
     */
    private function enforcePaymentDeadlines(array $session): void
    {
        $deadlineMsg = 'انتهت مهلة الدفع لهذا الحجز. يرجى البحث من جديد لأن السعر أو المقعد لم يعد مضموناً.';
        $now         = new \DateTime();

        // 1. Offer expiry from session
        $offerExpiresAt = $session['offer_expires_at'] ?? null;
        if (!empty($offerExpiresAt) && new \DateTime($offerExpiresAt) < $now) {
            throw new RuntimeException($deadlineMsg, 422);
        }

        // 2. payment_required_by and price_guarantee_expires_at from the offer's payment_requirements
        $offerId = $session['provider_offer_id'] ?? '';
        if (!empty($offerId)) {
            $offer = $this->fetchOffer($offerId);
            if ($offer !== null) {
                $offerData = json_decode($offer['offer_data'], true);
                $payReqs   = $offerData['payment_requirements'] ?? [];

                $paymentRequiredBy = $payReqs['payment_required_by'] ?? null;
                if (!empty($paymentRequiredBy) && new \DateTime($paymentRequiredBy) < $now) {
                    throw new RuntimeException($deadlineMsg, 422);
                }

                $priceGuaranteeExpiresAt = $payReqs['price_guarantee_expires_at'] ?? null;
                if (!empty($priceGuaranteeExpiresAt) && new \DateTime($priceGuaranteeExpiresAt) < $now) {
                    throw new RuntimeException($deadlineMsg, 422);
                }
            }
        }
    }

    /**
     * Recovery path for Duffel "already_paid" errors.
     *
     * Looks up an existing booking by matching the payments row that already has a
     * non-zero booking_id for the given payment intent. Returns the minimal booking
     * response array if found, or null if the local row cannot be located yet.
     */
    private function recoverExistingBooking(string $paymentIntentId, int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT booking_id FROM payments
                 WHERE stripe_payment_intent_id = :pi AND booking_id > 0
                 LIMIT 1'
            );
            $stmt->execute([':pi' => $paymentIntentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && (int) $row['booking_id'] > 0) {
                $booking = $this->getBookingById((int) $row['booking_id'], $userId);
                if ($booking !== null) {
                    return [
                        'booking_id'        => (int) $booking['id'],
                        'booking_reference' => $booking['booking_reference'],
                    ];
                }
            }
        } catch (\Throwable) {}

        return null;
    }

    /**
     * Auto-refund the Stripe charge when Duffel order creation fails after payment succeeded.
     * Uses a deterministic idempotency key to prevent duplicate refunds on retry.
     * Updates the payments row with the outcome.
     */
    private function autoRefundStripeOnDuffelFailure(
        string $paymentIntentId,
        string $idempotencyKey,
        string $internalReason
    ): void {
        // Mark payment as pending refund immediately so ops can see it even if refund fails
        try {
            $this->db->prepare(
                'UPDATE payments
                 SET status = :s, duffel_payment_failure = :reason, updated_at = NOW()
                 WHERE stripe_payment_intent_id = :pi'
            )->execute([
                ':s'      => 'refund_pending',
                ':reason' => substr($internalReason, 0, 500),
                ':pi'     => $paymentIntentId,
            ]);
        } catch (\Throwable) { /* non-critical if column not yet migrated */ }

        try {
            $refund = $this->stripe->createRefund(
                $paymentIntentId,
                null,  // full refund — customer never received a booking
                $idempotencyKey,
                'requested_by_customer'
            );

            $this->db->prepare(
                'UPDATE payments
                 SET status = :s, stripe_refund_id = :rid, auto_refund_reason = :ar,
                     auto_refunded_at = NOW(), updated_at = NOW()
                 WHERE stripe_payment_intent_id = :pi'
            )->execute([
                ':s'   => 'refunded',
                ':rid' => $refund['id'] ?? null,
                ':ar'  => 'duffel_order_creation_failed',
                ':pi'  => $paymentIntentId,
            ]);

            error_log('[AUTO_REFUND_SUCCESS] PI=' . $paymentIntentId . ' RefundID=' . ($refund['id'] ?? 'n/a'));
        } catch (\Throwable $refundEx) {
            // Refund also failed — mark for urgent manual processing
            try {
                $this->db->prepare(
                    'UPDATE payments
                     SET status = :s, auto_refund_reason = :ar, updated_at = NOW()
                     WHERE stripe_payment_intent_id = :pi'
                )->execute([
                    ':s'  => 'refund_failed',
                    ':ar' => 'duffel_order_creation_failed_refund_also_failed',
                    ':pi' => $paymentIntentId,
                ]);
            } catch (\Throwable) {}

            error_log('[CRITICAL][AUTO_REFUND_FAILED] PI=' . $paymentIntentId
                . ' | Refund error: ' . $refundEx->getMessage());
        }
    }

    private function upsertDocuments(int $bookingId, array $documents): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO flight_booking_documents
                   (booking_id, document_type, unique_identifier, passenger_ids)
                 VALUES (:bid, :dtype, :uid, :pids)'
            );
            foreach ($documents as $doc) {
                $uid = $doc['unique_identifier'] ?? '';
                if (empty($uid)) {
                    continue;
                }
                $stmt->execute([
                    ':bid'   => $bookingId,
                    ':dtype' => $doc['type']         ?? 'electronic_ticket',
                    ':uid'   => $uid,
                    ':pids'  => json_encode($doc['passenger_ids'] ?? []),
                ]);
            }
        } catch (\Throwable) { /* non-critical if table not yet migrated */ }
    }

    // =========================================================================
    // searchFlightChange — Step 1: get available alternative flights
    // =========================================================================

    public function searchFlightChange(int $bookingId, int $userId, string $newDate): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) throw new RuntimeException('Booking not found.', 404);

        $orderId = $booking['provider_order_id'] ?? '';
        if (!$orderId) throw new RuntimeException('Order ID missing.', 500);

        // Fetch live order to get slice IDs
        $orderResp = $this->duffel->getOrder($orderId);
        $order     = $orderResp['data'] ?? [];
        $slices    = $order['slices'] ?? [];
        if (empty($slices)) throw new RuntimeException('No slices found for this order.', 500);

        // Build slices: remove existing outbound, add new date same route
        $removeSlices = array_map(fn($s) => ['slice_id' => $s['id']], $slices);
        $addSlices    = array_map(fn($s) => [
            'origin'         => $s['origin']['iata_code']      ?? $booking['origin_airport'],
            'destination'    => $s['destination']['iata_code'] ?? $booking['destination_airport'],
            'departure_date' => $newDate,
            'cabin_class'    => $booking['cabin_class'] ?? 'economy',
        ], $slices);

        $crResp = $this->duffel->createOrderChangeRequest($orderId, [
            'add'    => $addSlices,
            'remove' => $removeSlices,
        ]);
        $cr   = $crResp['data'] ?? [];
        $crId = $cr['id'] ?? null;
        if (!$crId) throw new RuntimeException('Failed to create change request.', 502);

        $offersResp = $this->duffel->listOrderChangeOffers($crId, 30, null, null, 'change_total_amount');
        $offers     = $offersResp['data'] ?? [];

        return [
            'change_request_id' => $crId,
            'offers'            => array_map(fn($o) => [
                'id'                    => $o['id'],
                'change_total_amount'   => $o['change_total_amount']  ?? '0.00',
                'change_total_currency' => $o['change_total_currency'] ?? 'GBP',
                'new_total_amount'      => $o['new_total_amount']      ?? null,
                // Duffel change offer slices: {add:[...], remove:[...]}
                'slices'                => array_map(function($s) {
                    $segs = $s['segments'] ?? [];
                    $last = !empty($segs) ? $segs[count($segs) - 1] : [];
                    return [
                        'origin'        => $s['origin']['iata_code']      ?? '',
                        'destination'   => $s['destination']['iata_code'] ?? '',
                        'departure_at'  => $segs[0]['departing_at']        ?? '',
                        'arrival_at'    => $last['arriving_at']            ?? '',
                        'airline'       => $segs[0]['marketing_carrier']['iata_code']
                                        ?? $segs[0]['operating_carrier']['iata_code'] ?? '',
                        'flight_number' => ($segs[0]['marketing_carrier']['iata_code']
                                        ?? $segs[0]['operating_carrier']['iata_code'] ?? '')
                                         . ($segs[0]['marketing_carrier_flight_number']
                                        ?? $segs[0]['operating_carrier_flight_number'] ?? ''),
                        'duration'      => $s['duration'] ?? '',
                        'stops'         => max(0, count($segs) - 1),
                    ];
                }, $o['slices']['add'] ?? $o['slices'] ?? []),
            ], $offers),
        ];
    }

    // =========================================================================
    // createChangePaymentIntent — create Stripe PI for a paid flight change
    // =========================================================================

    public function createChangePaymentIntent(int $bookingId, int $userId, string $changeOfferId): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) throw new RuntimeException('Booking not found.', 404);

        // Fetch the change offer from Duffel to get the price
        $coResp  = $this->duffel->getOrderChangeOffer($changeOfferId);
        $co      = $coResp['data'] ?? [];
        $amount  = (float)($co['change_total_amount']  ?? 0);
        $currency = strtolower($co['change_total_currency'] ?? 'gbp');

        if ($amount <= 0) {
            throw new RuntimeException('هذا التغيير مجاني، لا يلزم دفع.', 422);
        }

        $amountInMinorUnits = (int) round($amount * 100);
        $idempotencyKey     = bin2hex(random_bytes(16));

        $stripeResult = $this->stripe->createPaymentIntent(
            $amountInMinorUnits,
            $idempotencyKey,
            $currency,
            [
                'booking_id'       => (string) $bookingId,
                'change_offer_id'  => $changeOfferId,
                'type'             => 'flight_change',
            ]
        );

        return [
            'client_secret'     => $stripeResult['client_secret'],
            'payment_intent_id' => $stripeResult['payment_intent_id'],
            'amount'            => $amount,
            'currency'          => strtoupper($currency),
        ];
    }

    // =========================================================================
    // confirmFlightChange — Step 2: confirm chosen change offer (with optional paid PI)
    // =========================================================================

    public function confirmFlightChange(int $bookingId, int $userId, string $changeOfferId, ?string $paymentIntentId = null): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) throw new RuntimeException('Booking not found.', 404);

        // Create pending order change
        $ocResp     = $this->duffel->createOrderChange($changeOfferId);
        $oc         = $ocResp['data'] ?? [];
        $ocId       = $oc['id'] ?? null;
        if (!$ocId) throw new RuntimeException('Failed to create order change.', 502);

        $changeDiff = (float)($oc['change_total_amount']  ?? 0);
        $currency   = $oc['change_total_currency'] ?? 'GBP';

        $payment = null;
        if ($changeDiff > 0) {
            // Verify Stripe payment was collected before charging Duffel balance
            if ($paymentIntentId) {
                $stripe = new \App\Adapters\Stripe\StripeAdapter();
                $intent = $stripe->getPaymentIntent($paymentIntentId);
                if (($intent['status'] ?? '') !== 'succeeded') {
                    throw new RuntimeException('لم يتم تأكيد الدفع بعد. يرجى إكمال عملية الدفع أولاً.', 402);
                }
                // Verify amount matches (within 1 unit tolerance for rounding)
                $paidAmount = (int)($intent['amount'] ?? 0);
                $expected   = (int) round($changeDiff * 100);
                if (abs($paidAmount - $expected) > 1) {
                    throw new RuntimeException('مبلغ الدفع لا يتطابق مع رسوم التغيير.', 422);
                }
            } else {
                throw new RuntimeException('هذا التغيير يتطلب دفع رسوم إضافية. يرجى إكمال الدفع أولاً.', 402);
            }

            // Charge Duffel via balance (funded by customer's Stripe payment)
            $payment = [
                'type'     => 'balance',
                'currency' => $currency,
                'amount'   => number_format($changeDiff, 2, '.', ''),
            ];
        }

        $confirmed = $this->duffel->confirmOrderChange($ocId, $payment);
        if (empty($confirmed['data'])) throw new RuntimeException('Change confirmation failed.', 502);

        // Record change fee paid (accumulate if multiple changes)
        if ($changeDiff > 0) {
            try {
                $this->db->prepare(
                    'UPDATE flight_bookings
                     SET change_fee_paid = COALESCE(change_fee_paid, 0) + :fee, status = :st
                     WHERE id = :id'
                )->execute([':fee' => $changeDiff, ':st' => 'changed', ':id' => $bookingId]);
            } catch (\Throwable $e) {
                // change_fee_paid column may not exist yet — non-fatal
            }
        } else {
            try {
                $this->db->prepare('UPDATE flight_bookings SET status = :st WHERE id = :id')
                         ->execute([':st' => 'changed', ':id' => $bookingId]);
            } catch (\Throwable $e) {}
        }

        // Re-sync booking from Duffel to get updated status/segments/total_amount
        $updated = $this->syncFromDuffel($bookingId, $userId);

        return [
            'status'      => 'changed',
            'change_diff' => $changeDiff,
            'currency'    => $currency,
            'booking'     => $updated,
        ];
    }

    // =========================================================================
    // getAvailableServices — bags, seats, meals for an existing order
    // =========================================================================

    public function getAvailableServices(int $bookingId, int $userId): array
    {
        $booking = $this->getBookingById($bookingId, $userId);
        if ($booking === null) throw new RuntimeException('Booking not found.', 404);

        $orderId = $booking['provider_order_id'] ?? '';
        if (!$orderId) throw new RuntimeException('Order ID missing.', 500);

        // Duffel: fetch order with available_services included
        try {
            $resp = $this->duffel->getAvailableServices($orderId);
            $services = $resp['data'] ?? [];
        } catch (\Throwable $e) {
            // Fallback: get order and extract available_services from it
            $orderResp = $this->duffel->getOrder($orderId);
            $services  = $orderResp['data']['available_services'] ?? [];
        }

        // Group by type
        $grouped = [];
        foreach ($services as $svc) {
            $type = $svc['type'] ?? 'other';
            $grouped[$type][] = [
                'id'           => $svc['id'],
                'type'         => $type,
                'total_amount' => $svc['total_amount']   ?? '0.00',
                'currency'     => $svc['total_currency'] ?? 'GBP',
                'maximum_quantity' => $svc['maximum_quantity'] ?? 1,
                'metadata'     => $svc['metadata'] ?? [],
                'passenger_ids'=> $svc['passenger_ids'] ?? [],
                'segment_ids'  => $svc['segment_ids']   ?? [],
            ];
        }

        return ['services' => $grouped, 'order_id' => $orderId];
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
            $data      = $this->duffel->getOffer($offerId, true);
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
        $conditions = $offerData['conditions'] ?? [];
        return [
            'offer_id'                          => $offerData['id']                                     ?? '',
            'total_amount'                      => $offerData['total_amount']                            ?? '0.00',
            'currency'                          => strtoupper($offerData['total_currency']              ?? 'GBP'),
            'expires_at'                        => $offerData['expires_at']                              ?? null,
            'slices'                            => $offerData['slices']                                  ?? [],
            'passengers_included'               => $offerData['passengers']                              ?? [],
            'passenger_identity_documents_required' => $offerData['passenger_identity_documents_required'] ?? false,
            'payment_requirements'              => $offerData['payment_requirements']                    ?? null,
            'conditions'                        => $conditions,
            'refund_before_departure'           => $conditions['refund_before_departure']                ?? null,
            'change_before_departure'           => $conditions['change_before_departure']                ?? null,
        ];
    }

    public function priceOfferWithServices(string $sessionKey, array $services, int $userId): array
    {
        $session = $this->requireSession($sessionKey, $userId);
        $offerId = $session['provider_offer_id'] ?? '';
        if (empty($offerId)) {
            throw new \RuntimeException('No offer in session.', 422);
        }

        // Build maximum_quantity map from cached offer so we never exceed Duffel limits.
        $maxQtyMap = [];
        $cachedOffer = $this->fetchOffer($offerId);
        if ($cachedOffer) {
            $cachedData = json_decode($cachedOffer['offer_data'], true);
            foreach (($cachedData['available_services'] ?? []) as $availSvc) {
                $aid = $availSvc['id'] ?? '';
                if ($aid !== '') {
                    $maxQtyMap[$aid] = (int)($availSvc['maximum_quantity'] ?? 1);
                }
            }
        }

        $servicesData = [];
        foreach ($services as $s) {
            $sid    = (string) ($s['id'] ?? '');
            $maxQty = $maxQtyMap[$sid] ?? 1;
            $qty    = min((int)($s['quantity'] ?? 1), $maxQty);
            if ($sid !== '' && $qty >= 1) {
                $servicesData[] = ['id' => $sid, 'quantity' => $qty];
            }
        }

        $response = $this->duffel->priceOffer($offerId, ['balance'], $servicesData);
        $priced   = $response['data'] ?? $response;

        return [
            'total_amount'         => $priced['total_amount']        ?? '0.00',
            'currency'             => strtoupper($priced['total_currency'] ?? 'GBP'),
            'base_amount'          => $priced['base_amount']         ?? null,
            'tax_amount'           => $priced['tax_amount']          ?? null,
            'payment_requirements' => $priced['payment_requirements'] ?? null,
        ];
    }

    private function computeServicesCost(array $offerData, array $selectedServices): float
    {
        if (empty($selectedServices)) return 0.0;

        // Build a price map from the offer's available_services: id => total_amount (per unit)
        $priceMap = [];
        foreach (($offerData['available_services'] ?? []) as $svc) {
            $id = $svc['id'] ?? '';
            if ($id !== '') {
                $priceMap[$id] = (float) ($svc['total_amount'] ?? 0);
            }
        }

        $cost = 0.0;
        foreach ($selectedServices as $s) {
            $id  = (string) ($s['id'] ?? '');
            $qty = max(1, (int) ($s['quantity'] ?? 1));

            // Prefer total_amount stored directly in service data (sent from frontend)
            if (isset($s['total_amount']) && (float) $s['total_amount'] > 0) {
                $cost += (float) $s['total_amount'] * $qty;
            } elseif (isset($priceMap[$id])) {
                $cost += $priceMap[$id] * $qty;
            }
        }

        return round($cost, 2);
    }

    private function calculatePricing(float $baseAmount, string $currency): array
    {
        // The platform commission (admin "Commissions" page) is the single
        // markup source. Each matching rule becomes a fee line on top of the
        // net supplier price.
        $result = (new CommissionService($this->db))->apply($baseAmount, 'flight');

        $fees = [];
        foreach ($result['rules'] as $rule) {
            $fees[] = [
                'name'   => $rule['name'],
                'type'   => 'commission',
                'amount' => $rule['amount'],
            ];
        }

        return [
            'base_amount' => $baseAmount,
            'fees'        => $fees,
            'total'       => $result['total'],
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

        // Build type-indexed queues to match by type, not by position.
        $queues = ['adult' => [], 'child' => [], 'infant_without_seat' => []];
        // Also build a lookup: duffel_id => type for later infant detection.
        $duffelIdType = [];
        foreach ($offerPassengers as $op) {
            $type = $op['type'] ?? 'adult';
            $queues[$type][] = $op['id'];
            $duffelIdType[$op['id']] = $type;
        }

        // Normalise our type labels to Duffel's.
        $typeMap = ['adult' => 'adult', 'child' => 'child', 'infant' => 'infant_without_seat'];

        // Allowed Duffel title values.
        $allowedTitles = ['mr', 'ms', 'mrs', 'miss', 'dr'];

        $mapped    = [];
        $adultIds  = [];   // track assigned adult Duffel IDs for infant linkage
        $infantIds = [];   // track assigned infant Duffel IDs

        // First pass: collect the primary adult email to use as fallback for non-adults.
        $primaryAdultEmail = '';
        $primaryAdultPhone = '';
        foreach ($passengers as $p) {
            $ourType = $p['type'] ?? 'adult';
            if ($ourType === 'adult') {
                $primaryAdultEmail = trim((string)($p['email'] ?? ''));
                $primaryAdultPhone = trim((string)($p['phone_number'] ?? ''));
                break;
            }
        }

        foreach ($passengers as $p) {
            $ourType    = $p['type'] ?? 'adult';
            $duffelType = $typeMap[$ourType] ?? 'adult';
            $duffelId   = array_shift($queues[$duffelType]);

            // Nationality: accept both ISO alpha-3 codes and legacy display names.
            $nationality = (string) ($p['nationality'] ?? '');
            if (strlen($nationality) !== 3) {
                $nationality = $this->toIso3Nationality($nationality);
            }
            // Duffel requires ISO 3166-1 alpha-2 nationality codes (e.g. "KW" not "KWT").
            $nationalityAlpha2 = $this->toAlpha2($nationality);

            // Sanitise title to one of Duffel's accepted values.
            $rawTitle = strtolower(trim((string)($p['title'] ?? '')));
            if (!in_array($rawTitle, $allowedTitles, true)) {
                $rawTitle = (strtolower($p['gender'] ?? '') === 'female') ? 'ms' : 'mr';
            }

            // given_name / family_name accept multiple field name conventions.
            $givenName  = trim((string)($p['given_name']  ?? $p['first_name']  ?? ''));
            $familyName = trim((string)($p['family_name'] ?? $p['last_name']   ?? ''));

            // Normalise phone to E.164; use adult fallback for children/infants.
            $rawPhone = trim((string)($p['phone_number'] ?? ''));
            if ($rawPhone === '' && $duffelType !== 'adult') {
                $rawPhone = $primaryAdultPhone;
            }
            $phone = $rawPhone !== '' ? $this->normalizePhone($rawPhone) : '';

            // Email: use adult fallback for children/infants.
            $email = trim((string)($p['email'] ?? ''));
            if ($email === '' && $duffelType !== 'adult') {
                $email = $primaryAdultEmail;
            }

            $entry = [
                '_passenger_type' => $duffelType,   // internal — stripped before API call
                'title'        => $rawTitle,
                'given_name'   => $givenName,
                'family_name'  => $familyName,
                'gender'       => strtolower((string)($p['gender'] ?? '')) === 'female' ? 'f' : 'm',
                'born_on'      => (string)($p['date_of_birth'] ?? ''),
                'nationality'  => $nationalityAlpha2,
                'email'        => $email,
                'phone_number' => $phone,
            ];

            // Build identity_documents array (Duffel v2 format).
            $docNumber = trim((string)($p['passport_number'] ?? ''));
            $docExpiry = trim((string)($p['passport_expiry'] ?? ''));
            $docIssued = trim((string)($p['document_issue']  ?? ''));

            if ($docNumber !== '' && $docExpiry !== '') {
                $identityDoc = [
                    'type'                 => 'passport',
                    'unique_identifier'    => $docNumber,
                    'expires_on'           => $docExpiry,
                    'issuing_country_code' => $nationalityAlpha2,
                ];
                if ($docIssued !== '') {
                    $identityDoc['issued_on'] = $docIssued;
                }
                $entry['identity_documents'] = [$identityDoc];
            }

            if ($duffelId !== null) {
                $entry['id'] = $duffelId;
                if ($duffelType === 'adult') {
                    $adultIds[] = $duffelId;
                } elseif ($duffelType === 'infant_without_seat') {
                    $infantIds[] = $duffelId;
                }
            }

            $mapped[] = $entry;
        }

        // Assign infant_passenger_id on the adult passenger entries.
        // Use the $duffelIdType map (built before queue draining) for correct type detection.
        $infantCount = 0;
        foreach ($mapped as &$entry) {
            $entryDuffelId = $entry['id'] ?? null;
            if ($entryDuffelId === null) continue;
            if (($duffelIdType[$entryDuffelId] ?? '') === 'infant_without_seat') {
                if (isset($adultIds[$infantCount])) {
                    foreach ($mapped as &$adultEntry) {
                        if (($adultEntry['id'] ?? '') === $adultIds[$infantCount]) {
                            $adultEntry['infant_passenger_id'] = $entryDuffelId;
                            break;
                        }
                    }
                    unset($adultEntry);
                }
                $infantCount++;
            }
        }
        unset($entry);

        // Strip internal tracking field before returning.
        foreach ($mapped as &$entry) {
            unset($entry['_passenger_type']);
        }
        unset($entry);

        return $mapped;
    }

    /**
     * Pre-flight validator: checks every passenger in the Duffel-mapped array
     * has all required fields before we hit the API.
     * Throws RuntimeException(422) with an Arabic message on any failure.
     */
    private function validateDuffelPassengers(array $duffelPassengers): void
    {
        $allowedTitles = ['mr', 'ms', 'mrs', 'miss', 'dr'];

        // Duffel requires at least one adult passenger with email + phone.
        $hasAdultWithContact = false;

        foreach ($duffelPassengers as $i => $p) {
            $num        = $i + 1;
            $isAdult    = !isset($p['id'])
                || true; // type info already consumed; validate contact below per-passenger

            // Fields required for ALL passenger types.
            $alwaysRequired = [
                'title'       => 'اللقب',
                'given_name'  => 'الاسم الأول',
                'family_name' => 'اسم العائلة',
                'born_on'     => 'تاريخ الميلاد',
                'gender'      => 'الجنس',
                'nationality' => 'الجنسية',
            ];
            foreach ($alwaysRequired as $field => $label) {
                if (empty($p[$field])) {
                    throw new RuntimeException("المسافر {$num}: {$label} مطلوب قبل إرسال الحجز.", 422);
                }
            }

            // Title must be one of Duffel's allowed values.
            if (!in_array($p['title'], $allowedTitles, true)) {
                throw new RuntimeException("المسافر {$num}: اللقب يجب أن يكون أحد القيم: mr, ms, mrs, miss, dr.", 422);
            }

            // Nationality must be ISO 3166-1 alpha-2 (2 characters).
            if (strlen($p['nationality']) !== 2) {
                throw new RuntimeException("المسافر {$num}: رمز الجنسية يجب أن يكون برمز دولي (مثال: KW).", 422);
            }

            // born_on must be a valid date in YYYY-MM-DD format.
            $bornOn = $p['born_on'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bornOn)) {
                throw new RuntimeException("المسافر {$num}: تاريخ الميلاد يجب أن يكون بصيغة YYYY-MM-DD.", 422);
            }

            // Email validation — required for every passenger (Duffel API requires it on all).
            $email = $p['email'] ?? '';
            if (empty($email)) {
                throw new RuntimeException("المسافر {$num}: البريد الإلكتروني مطلوب.", 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("المسافر {$num}: البريد الإلكتروني غير صحيح.", 422);
            }

            // Phone validation — required for every passenger.
            $phone = $p['phone_number'] ?? '';
            if (empty($phone)) {
                throw new RuntimeException("المسافر {$num}: رقم الهاتف مطلوب.", 422);
            }
            // Must start with + (E.164).
            if ($phone[0] !== '+') {
                throw new RuntimeException("المسافر {$num}: رقم الهاتف يجب أن يبدأ بـ + (مثال: +96512345678).", 422);
            }
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) < 8 || strlen($phone) > 20) {
                throw new RuntimeException("المسافر {$num}: رقم الهاتف غير صحيح (E.164، 8 أرقام على الأقل).", 422);
            }

            if (!empty($email) && !empty($phone)) {
                $hasAdultWithContact = true;
            }
        }

        if (!$hasAdultWithContact) {
            throw new RuntimeException('يجب توفير بريد إلكتروني ورقم هاتف لمسافر واحد على الأقل.', 422);
        }
    }

    /**
     * Convert ISO 3166-1 alpha-3 country code to alpha-2 for Duffel API.
     * Duffel requires alpha-2 for nationality and issuing_country_code.
     */
    private function toAlpha2(string $alpha3): string
    {
        static $map = [
            'AFG'=>'AF','ALA'=>'AX','ALB'=>'AL','DZA'=>'DZ','ASM'=>'AS','AND'=>'AD',
            'AGO'=>'AO','AIA'=>'AI','ATA'=>'AQ','ATG'=>'AG','ARG'=>'AR','ARM'=>'AM',
            'ABW'=>'AW','AUS'=>'AU','AUT'=>'AT','AZE'=>'AZ','BHS'=>'BS','BHR'=>'BH',
            'BGD'=>'BD','BRB'=>'BB','BLR'=>'BY','BEL'=>'BE','BLZ'=>'BZ','BEN'=>'BJ',
            'BMU'=>'BM','BTN'=>'BT','BOL'=>'BO','BES'=>'BQ','BIH'=>'BA','BWA'=>'BW',
            'BVT'=>'BV','BRA'=>'BR','IOT'=>'IO','BRN'=>'BN','BGR'=>'BG','BFA'=>'BF',
            'BDI'=>'BI','CPV'=>'CV','KHM'=>'KH','CMR'=>'CM','CAN'=>'CA','CYM'=>'KY',
            'CAF'=>'CF','TCD'=>'TD','CHL'=>'CL','CHN'=>'CN','CXR'=>'CX','CCK'=>'CC',
            'COL'=>'CO','COM'=>'KM','COD'=>'CD','COG'=>'CG','COK'=>'CK','CRI'=>'CR',
            'CIV'=>'CI','HRV'=>'HR','CUB'=>'CU','CUW'=>'CW','CYP'=>'CY','CZE'=>'CZ',
            'DNK'=>'DK','DJI'=>'DJ','DMA'=>'DM','DOM'=>'DO','ECU'=>'EC','EGY'=>'EG',
            'SLV'=>'SV','GNQ'=>'GQ','ERI'=>'ER','EST'=>'EE','SWZ'=>'SZ','ETH'=>'ET',
            'FLK'=>'FK','FRO'=>'FO','FJI'=>'FJ','FIN'=>'FI','FRA'=>'FR','GUF'=>'GF',
            'PYF'=>'PF','ATF'=>'TF','GAB'=>'GA','GMB'=>'GM','GEO'=>'GE','DEU'=>'DE',
            'GHA'=>'GH','GIB'=>'GI','GRC'=>'GR','GRL'=>'GL','GRD'=>'GD','GLP'=>'GP',
            'GUM'=>'GU','GTM'=>'GT','GGY'=>'GG','GIN'=>'GN','GNB'=>'GW','GUY'=>'GY',
            'HTI'=>'HT','HMD'=>'HM','VAT'=>'VA','HND'=>'HN','HKG'=>'HK','HUN'=>'HU',
            'ISL'=>'IS','IND'=>'IN','IDN'=>'ID','IRN'=>'IR','IRQ'=>'IQ','IRL'=>'IE',
            'IMN'=>'IM','ISR'=>'IL','ITA'=>'IT','JAM'=>'JM','JPN'=>'JP','JEY'=>'JE',
            'JOR'=>'JO','KAZ'=>'KZ','KEN'=>'KE','KIR'=>'KI','PRK'=>'KP','KOR'=>'KR',
            'KWT'=>'KW','KGZ'=>'KG','LAO'=>'LA','LVA'=>'LV','LBN'=>'LB','LSO'=>'LS',
            'LBR'=>'LR','LBY'=>'LY','LIE'=>'LI','LTU'=>'LT','LUX'=>'LU','MAC'=>'MO',
            'MDG'=>'MG','MWI'=>'MW','MYS'=>'MY','MDV'=>'MV','MLI'=>'ML','MLT'=>'MT',
            'MHL'=>'MH','MTQ'=>'MQ','MRT'=>'MR','MUS'=>'MU','MYT'=>'YT','MEX'=>'MX',
            'FSM'=>'FM','MDA'=>'MD','MCO'=>'MC','MNG'=>'MN','MNE'=>'ME','MSR'=>'MS',
            'MAR'=>'MA','MOZ'=>'MZ','MMR'=>'MM','NAM'=>'NA','NRU'=>'NR','NPL'=>'NP',
            'NLD'=>'NL','NCL'=>'NC','NZL'=>'NZ','NIC'=>'NI','NER'=>'NE','NGA'=>'NG',
            'NIU'=>'NU','NFK'=>'NF','MKD'=>'MK','MNP'=>'MP','NOR'=>'NO','OMN'=>'OM',
            'PAK'=>'PK','PLW'=>'PW','PSE'=>'PS','PAN'=>'PA','PNG'=>'PG','PRY'=>'PY',
            'PER'=>'PE','PHL'=>'PH','PCN'=>'PN','POL'=>'PL','PRT'=>'PT','PRI'=>'PR',
            'QAT'=>'QA','REU'=>'RE','ROU'=>'RO','RUS'=>'RU','RWA'=>'RW','BLM'=>'BL',
            'SHN'=>'SH','KNA'=>'KN','LCA'=>'LC','MAF'=>'MF','SPM'=>'PM','VCT'=>'VC',
            'WSM'=>'WS','SMR'=>'SM','STP'=>'ST','SAU'=>'SA','SEN'=>'SN','SRB'=>'RS',
            'SYC'=>'SC','SLE'=>'SL','SGP'=>'SG','SXM'=>'SX','SVK'=>'SK','SVN'=>'SI',
            'SLB'=>'SB','SOM'=>'SO','ZAF'=>'ZA','SGS'=>'GS','SSD'=>'SS','ESP'=>'ES',
            'LKA'=>'LK','SDN'=>'SD','SUR'=>'SR','SJM'=>'SJ','SWE'=>'SE','CHE'=>'CH',
            'SYR'=>'SY','TWN'=>'TW','TJK'=>'TJ','TZA'=>'TZ','THA'=>'TH','TLS'=>'TL',
            'TGO'=>'TG','TKL'=>'TK','TON'=>'TO','TTO'=>'TT','TUN'=>'TN','TUR'=>'TR',
            'TKM'=>'TM','TCA'=>'TC','TUV'=>'TV','UGA'=>'UG','UKR'=>'UA','ARE'=>'AE',
            'GBR'=>'GB','UMI'=>'UM','USA'=>'US','URY'=>'UY','UZB'=>'UZ','VUT'=>'VU',
            'VEN'=>'VE','VNM'=>'VN','VGB'=>'VG','VIR'=>'VI','WLF'=>'WF','ESH'=>'EH',
            'YEM'=>'YE','ZMB'=>'ZM','ZWE'=>'ZW',
        ];
        return $map[strtoupper($alpha3)] ?? $alpha3;
    }

    private function normalizePhone(string $phone): string
    {
        // Strip spaces and dashes, ensure leading +
        $phone = preg_replace('/[\s\-()]/', '', $phone);
        // "00" is the international access prefix — convert it to "+".
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+' . $phone;
        }
        return $phone;
    }

    private function isInfantPassenger(string $duffelId, array $offerPassengers): bool
    {
        foreach ($offerPassengers as $op) {
            if (($op['id'] ?? '') === $duffelId) {
                return ($op['type'] ?? '') === 'infant_without_seat';
            }
        }
        return false;
    }

    // W2: validates that a nationality code is a known ISO-3166-1 alpha-3 code.
    // 'OTH' is explicitly excluded — it is not a valid Duffel nationality.
    private function isValidIso3Nationality(string $code): bool
    {
        // Full UN ISO-3166-1 alpha-3 list (249 entries as of 2024).
        static $codes = [
            'AFG','ALA','ALB','DZA','ASM','AND','AGO','AIA','ATA','ATG','ARG','ARM','ABW',
            'AUS','AUT','AZE','BHS','BHR','BGD','BRB','BLR','BEL','BLZ','BEN','BMU','BTN',
            'BOL','BES','BIH','BWA','BVT','BRA','IOT','BRN','BGR','BFA','BDI','CPV','KHM',
            'CMR','CAN','CYM','CAF','TCD','CHL','CHN','CXR','CCK','COL','COM','COD','COG',
            'COK','CRI','CIV','HRV','CUB','CUW','CYP','CZE','DNK','DJI','DMA','DOM','ECU',
            'EGY','SLV','GNQ','ERI','EST','SWZ','ETH','FLK','FRO','FJI','FIN','FRA','GUF',
            'PYF','ATF','GAB','GMB','GEO','DEU','GHA','GIB','GRC','GRL','GRD','GLP','GUM',
            'GTM','GGY','GIN','GNB','GUY','HTI','HMD','VAT','HND','HKG','HUN','ISL','IND',
            'IDN','IRN','IRQ','IRL','IMN','ISR','ITA','JAM','JPN','JEY','JOR','KAZ','KEN',
            'KIR','PRK','KOR','KWT','KGZ','LAO','LVA','LBN','LSO','LBR','LBY','LIE','LTU',
            'LUX','MAC','MDG','MWI','MYS','MDV','MLI','MLT','MHL','MTQ','MRT','MUS','MYT',
            'MEX','FSM','MDA','MCO','MNG','MNE','MSR','MAR','MOZ','MMR','NAM','NRU','NPL',
            'NLD','NCL','NZL','NIC','NER','NGA','NIU','NFK','MKD','MNP','NOR','OMN','PAK',
            'PLW','PSE','PAN','PNG','PRY','PER','PHL','PCN','POL','PRT','PRI','QAT','REU',
            'ROU','RUS','RWA','BLM','SHN','KNA','LCA','MAF','SPM','VCT','WSM','SMR','STP',
            'SAU','SEN','SRB','SYC','SLE','SGP','SXM','SVK','SVN','SLB','SOM','ZAF','SGS',
            'SSD','ESP','LKA','SDN','SUR','SJM','SWE','CHE','SYR','TWN','TJK','TZA','THA',
            'TLS','TGO','TKL','TON','TTO','TUN','TUR','TKM','TCA','TUV','UGA','UKR','ARE',
            'GBR','UMI','USA','URY','UZB','VUT','VEN','VNM','VGB','VIR','WLF','ESH','YEM',
            'ZMB','ZWE',
        ];
        return in_array($code, $codes, true);
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
