<?php

declare(strict_types=1);

/**
 * Sprint 4 — Production-Critical Implementation Tests
 *
 * Covers:
 *   TASK 1 – Customer cancellation UI (JS tested via presence checks)
 *   TASK 2 – Admin cancellation fields
 *   TASK 3 – confirmed_at capture and storage
 *   TASK 4 – Passport validation hardening (field normalisation + 6-month rule)
 *   TASK 5 – Webhook sync + missing JobWorker handlers + EmailNotificationService methods
 *
 * Run: php tests/Sprint4ProductionCriticalTest.php
 */

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $file = BASE_PATH . '/app/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

require_once BASE_PATH . '/app/Adapters/Duffel/DuffelAdapter.php';
require_once BASE_PATH . '/app/Adapters/Stripe/StripeAdapter.php';
require_once BASE_PATH . '/app/Services/BookingSessionService.php';
require_once BASE_PATH . '/app/Services/DuffelErrorMapper.php';
require_once BASE_PATH . '/app/Services/FlightBookingService.php';
require_once BASE_PATH . '/app/Services/EmailNotificationService.php';
require_once BASE_PATH . '/app/Workers/JobWorker.php';

// ── Test runner ────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "\033[32m  ✓ {$label}\033[0m\n";
        $passed++;
    } else {
        echo "\033[31m  ✗ {$label}\033[0m\n";
        $failed++;
    }
}

function section(string $title): void {
    echo "\n\033[1;34m── {$title} ──\033[0m\n";
}

// ── Fake adapters ──────────────────────────────────────────────────────────────

class S4FakeStripe extends \App\Adapters\Stripe\StripeAdapter
{
    public function __construct() {}
    public function createPaymentIntent(int $amountInMinorUnits, string $idempotencyKey, string $currency = '', array $metadata = [], array $paymentMethodTypes = []): array
    {
        return ['payment_intent_id' => 'pi_s4_001', 'client_secret' => 'pi_s4_001_secret'];
    }
    public function createRefund(string $paymentIntentId, ?int $amountMinor = null, string $idempotencyKey = '', string $reason = 'requested_by_customer'): array
    {
        return ['id' => 're_s4_001'];
    }
}

class S4FakeDuffel extends \App\Adapters\Duffel\DuffelAdapter
{
    public string $lastConfirmedCancellationId = '';
    public bool $returnConfirmedAt = true;

    public function __construct() {}

    public function cancelOrder(string $orderId): array
    {
        return ['data' => [
            'id'           => 'can_test_001',
            'refund_amount' => '150.00',
            'refund_currency' => 'GBP',
            'refund_to'    => 'original_form_of_payment',
            'expires_at'   => date('c', strtotime('+1 hour')),
        ]];
    }

    public function confirmCancellation(string $cancellationId): array
    {
        $this->lastConfirmedCancellationId = $cancellationId;
        $payload = ['id' => $cancellationId];
        if ($this->returnConfirmedAt) {
            $payload['confirmed_at'] = '2026-06-22T10:00:00Z';
        }
        return ['data' => $payload];
    }

    public function getOrder(string $orderId): array
    {
        return ['data' => ['booking_reference' => 'ABC123', 'passengers' => [], 'documents' => []]];
    }
}

// ── SQLite PDO compatibility wrapper (same pattern as Sprint3) ─────────────────

class S4SqliteCompatPdo extends PDO
{
    private PDO $inner;
    public function __construct(PDO $inner) { $this->inner = $inner; }
    public function exec(string $statement): int|false {
        $t = strtoupper(ltrim($statement));
        if (str_starts_with($t, 'LOCK TABLE') || str_starts_with($t, 'UNLOCK TABLE')) return 0;
        return $this->inner->exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $query);
        return $this->inner->prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return $this->inner->query($query, $fetchMode, ...$args); }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool           { return $this->inner->commit(); }
    public function rollBack(): bool         { return $this->inner->rollBack(); }
    public function getAttribute(int $attr): mixed { return $this->inner->getAttribute($attr); }
    public function setAttribute(int $attr, mixed $value): bool { return $this->inner->setAttribute($attr, $value); }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return $this->inner->quote($string, $type); }
    public function errorCode(): ?string { return $this->inner->errorCode(); }
    public function errorInfo(): array   { return $this->inner->errorInfo(); }
}

// ── SQLite in-memory DB ────────────────────────────────────────────────────────

function makeDb(): S4SqliteCompatPdo
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->sqliteCreateFunction('NOW',               fn() => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', fn() => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('COALESCE',          fn(...$a) => array_values(array_filter($a, fn($v) => $v !== null))[0] ?? null);
    $pdo->sqliteCreateFunction('GET_LOCK',          fn($n, $t) => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK',      fn($n) => 1, 1);
    $pdo->sqliteCreateFunction('DATABASE',          fn() => 'test', 0);

    $pdo->exec('CREATE TABLE booking_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_key TEXT, user_id INTEGER, booking_type TEXT, current_step TEXT DEFAULT "search",
        provider_offer_id TEXT, offer_expires_at TEXT, offer_data TEXT DEFAULT "{}",
        payment_intent_id TEXT, passengers_data TEXT, services_data TEXT,
        pricing_snapshot TEXT, expires_at TEXT
    )');
    $pdo->exec('CREATE TABLE flight_bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER, provider_id INTEGER DEFAULT 1,
        booking_reference TEXT, provider_order_id TEXT,
        duffel_booking_reference TEXT, booking_references TEXT,
        trip_type TEXT, cabin_class TEXT, adults_count INTEGER DEFAULT 0, children_count INTEGER DEFAULT 0,
        origin_airport TEXT, destination_airport TEXT, departure_at TEXT,
        total_amount REAL, currency TEXT DEFAULT "GBP", status TEXT DEFAULT "confirmed",
        paid_at TEXT, payment_required_by TEXT, price_guarantee_expires_at TEXT,
        void_window_ends_at TEXT, available_actions TEXT, live_mode INTEGER DEFAULT 1,
        refund_conditions TEXT, change_conditions TEXT,
        awaiting_payment INTEGER DEFAULT 0, duffel_payment_failure TEXT,
        synced_at TEXT, updated_at TEXT, cancelled_at TEXT, cancellation_confirmed_at TEXT,
        pending_cancellation_id TEXT, cancellation_expires_at TEXT,
        cancellation_refund_to TEXT, cancellation_refund_amount REAL,
        cancellation_refund_currency TEXT, auto_refunded_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE flight_booking_passengers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        passenger_type TEXT, first_name TEXT, last_name TEXT, gender TEXT,
        date_of_birth TEXT, nationality TEXT, passport_number TEXT, passport_expiry TEXT,
        provider_passenger_id TEXT, ticket_number TEXT
    )');
    $pdo->exec('CREATE TABLE flight_booking_segments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        slice_index INTEGER DEFAULT 0, segment_order INTEGER DEFAULT 0,
        origin_airport TEXT, destination_airport TEXT, departure_at TEXT, arrival_at TEXT,
        flight_number TEXT, airline_code TEXT, aircraft_type TEXT
    )');
    $pdo->exec('CREATE TABLE flight_booking_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        document_type TEXT, unique_identifier TEXT, passenger_ids TEXT,
        UNIQUE(booking_id, unique_identifier)
    )');
    $pdo->exec('CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_type TEXT, booking_id INTEGER DEFAULT 0, user_id INTEGER,
        payment_method TEXT DEFAULT "stripe", idempotency_key TEXT,
        stripe_payment_intent_id TEXT, stripe_refund_id TEXT,
        amount REAL, currency TEXT DEFAULT "GBP",
        status TEXT DEFAULT "pending", gateway_status TEXT, gateway_response TEXT,
        failure_reason TEXT, paid_at TEXT,
        duffel_payment_failure TEXT, auto_refund_reason TEXT, auto_refunded_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT, first_name TEXT, last_name TEXT
    )');
    $pdo->exec('CREATE TABLE job_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_type TEXT, payload TEXT, status TEXT DEFAULT "pending",
        attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3,
        scheduled_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT, error_message TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE offer_cache (
        offer_id TEXT PRIMARY KEY, offer_data TEXT, total_amount REAL,
        currency TEXT, expires_at TEXT,
        offer_request_id TEXT DEFAULT "", provider_id INTEGER DEFAULT 1, search_hash TEXT DEFAULT ""
    )');
    $pdo->exec('CREATE TABLE pricing_rules (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE coupons (id INTEGER PRIMARY KEY)');

    return new S4SqliteCompatPdo($pdo);
}

function makeService(S4SqliteCompatPdo $db, S4FakeDuffel $duffel = null, S4FakeStripe $stripe = null): \App\Services\FlightBookingService
{
    $duffel ??= new S4FakeDuffel();
    $stripe ??= new S4FakeStripe();
    $session = new \App\Services\BookingSessionService($db);
    return new \App\Services\FlightBookingService($db, $duffel, $stripe, $session);
}

function seedBooking(S4SqliteCompatPdo $db, string $status = 'confirmed'): int
{
    $db->exec("INSERT OR IGNORE INTO users (id,email,first_name,last_name) VALUES (1,'test@example.com','Ali','Ahmed')");
    $db->prepare("INSERT INTO flight_bookings (user_id,booking_reference,provider_order_id,status,total_amount,currency)
                  VALUES (1,'FM00000001','ord_test_001',:s,300.00,'GBP')")->execute([':s' => $status]);
    return (int)$db->lastInsertId();
}

// ── TASK 4: Passport validation hardening ─────────────────────────────────────

section('TASK 4: Passport validation hardening');

(function () {
    $db = makeDb();
    $svc = makeService($db);

    $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at)
                  VALUES ('sess_t4','1','ofr_test','passengers',:exp)")
       ->execute([':exp' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);

    $validBase = [
        'first_name'      => 'Ali',
        'last_name'       => 'Ahmed',
        'gender'          => 'male',
        'date_of_birth'   => '1990-01-15',
        'nationality'     => 'SAU',
        'passport_number' => 'A123456',
        'passport_expiry' => date('Y-m-d', strtotime('+2 years')),
        'type'            => 'adult',
    ];

    // Valid passenger passes.
    try {
        $result = $svc->savePassengers('sess_t4', [$validBase], 1);
        ok($result === true, 'Valid passport data accepted');
    } catch (\Throwable $e) {
        ok(false, 'Valid passport data accepted — error: ' . $e->getMessage());
    }

    // Reset step.
    $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");

    // document_number / document_expiry aliases normalised.
    $aliasPassenger = array_merge($validBase, [
        'passport_number' => null,
        'passport_expiry' => null,
        'document_number' => 'B987654',
        'document_expiry' => date('Y-m-d', strtotime('+2 years')),
    ]);
    unset($aliasPassenger['passport_number'], $aliasPassenger['passport_expiry']);
    try {
        $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");
        $result = $svc->savePassengers('sess_t4', [$aliasPassenger], 1);
        ok($result === true, 'document_number / document_expiry aliases normalised to passport_*');
    } catch (\Throwable $e) {
        ok(false, 'Alias normalisation — error: ' . $e->getMessage());
    }

    // Expired passport rejected.
    $expired = array_merge($validBase, ['passport_expiry' => date('Y-m-d', strtotime('-1 day'))]);
    try {
        $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");
        $svc->savePassengers('sess_t4', [$expired], 1);
        ok(false, 'Expired passport rejected');
    } catch (\RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'منتهي') || str_contains($e->getMessage(), 'انتهاء'), 'Expired passport rejected (Arabic message) — ' . $e->getMessage());
    }

    // Passport expiring within 6 months rejected.
    $soonExpiry = array_merge($validBase, ['passport_expiry' => date('Y-m-d', strtotime('+3 months'))]);
    try {
        $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");
        $svc->savePassengers('sess_t4', [$soonExpiry], 1);
        ok(false, '<6 month passport rejected');
    } catch (\RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'ستة أشهر'), '<6 month passport rejected (Arabic message) — ' . $e->getMessage());
    }

    // Future DOB rejected.
    $futureDob = array_merge($validBase, ['date_of_birth' => date('Y-m-d', strtotime('+1 year'))]);
    try {
        $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");
        $svc->savePassengers('sess_t4', [$futureDob], 1);
        ok(false, 'Future DOB rejected');
    } catch (\RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'الميلاد') || str_contains($e->getMessage(), 'الماضي'), 'Future DOB rejected (Arabic message) — ' . $e->getMessage());
    }

    // Empty nationality rejected — now triggers ISO-3 check.
    $noNat = array_merge($validBase, ['nationality' => '']);
    try {
        $db->exec("UPDATE booking_sessions SET current_step='passengers',expires_at='" . date('Y-m-d H:i:s', strtotime('+1 hour')) . "' WHERE session_key='sess_t4'");
        $svc->savePassengers('sess_t4', [$noNat], 1);
        ok(false, 'Empty nationality rejected');
    } catch (\RuntimeException $e) {
        // Empty value triggers the required-field check first (Arabic label مطلوب).
        ok(str_contains($e->getMessage(), 'مطلوب') || str_contains($e->getMessage(), 'الجنسية'), 'Empty nationality rejected (Arabic message) — ' . $e->getMessage());
    }
})();

// ── W2: ISO-3 nationality validation ─────────────────────────────────────────

section('W2: ISO-3 nationality validation');

(function () {
    $db  = makeDb();
    $svc = makeService($db);
    $exp = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $base = [
        'first_name'      => 'Test',
        'last_name'       => 'User',
        'gender'          => 'male',
        'date_of_birth'   => '1990-06-01',
        'passport_number' => 'Z999999',
        'passport_expiry' => date('Y-m-d', strtotime('+2 years')),
        'type'            => 'adult',
    ];

    // Valid codes accepted.
    foreach (['KWT', 'BHR', 'SAU', 'ARE', 'USA', 'GBR', 'IND'] as $code) {
        $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at) VALUES (:sk,'1','o','passengers',:exp)")
           ->execute([':sk' => "sess_w2_{$code}", ':exp' => $exp]);
        try {
            $svc->savePassengers("sess_w2_{$code}", [array_merge($base, ['nationality' => $code])], 1);
            ok(true, "Valid ISO-3 code '{$code}' accepted");
        } catch (\Throwable $e) {
            ok(false, "Valid ISO-3 code '{$code}' accepted — error: " . $e->getMessage());
        }
    }

    // Invalid codes rejected.
    foreach (['XYZ', 'AAA', 'TEST', '123', 'OTH', ''] as $code) {
        $sk = 'sess_w2_inv_' . md5($code);
        $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at) VALUES (:sk,'1','o','passengers',:exp)")
           ->execute([':sk' => $sk, ':exp' => $exp]);
        try {
            $svc->savePassengers($sk, [array_merge($base, ['nationality' => $code])], 1);
            ok(false, "Invalid code '{$code}' rejected");
        } catch (\RuntimeException $e) {
            $isArabicMsg = str_contains($e->getMessage(), 'الجنسية') || str_contains($e->getMessage(), 'مطلوب');
            ok($isArabicMsg, "Invalid code '{$code}' rejected with Arabic message — " . $e->getMessage());
        }
    }

    // OTH explicitly blocked (would crash Duffel).
    $sk = 'sess_w2_oth';
    $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at) VALUES (:sk,'1','o','passengers',:exp)")
       ->execute([':sk' => $sk, ':exp' => $exp]);
    try {
        $svc->savePassengers($sk, [array_merge($base, ['nationality' => 'OTH'])], 1);
        ok(false, 'OTH nationality blocked');
    } catch (\RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'الجنسية'), 'OTH nationality blocked with Arabic message');
    }
})();

// ── W3: Age-type consistency ──────────────────────────────────────────────────

section('W3: Age-type consistency');

(function () {
    $db  = makeDb();
    $svc = makeService($db);
    $exp = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $base = [
        'first_name'      => 'Test',
        'last_name'       => 'User',
        'gender'          => 'male',
        'nationality'     => 'SAU',
        'passport_number' => 'Z888888',
        'passport_expiry' => date('Y-m-d', strtotime('+2 years')),
    ];

    $cases = [
        // [type, dob_offset, should_pass, label]
        ['adult',  '-30 years', true,  'Adult age 30 — accepted'],
        ['adult',  '-12 years', true,  'Adult age 12 (boundary) — accepted'],
        ['adult',  '-11 years', false, 'Adult age 11 — rejected'],
        ['adult',  '-1 year',   false, 'Adult age 1 — rejected'],
        ['child',  '-5 years',  true,  'Child age 5 — accepted'],
        ['child',  '-2 years',  true,  'Child age 2 (boundary) — accepted'],
        ['child',  '-11 years', true,  'Child age 11 (boundary) — accepted'],
        ['child',  '-12 years', false, 'Child age 12 — rejected'],
        ['child',  '-18 years', false, 'Child age 18 — rejected'],
        ['child',  '-1 year',   false, 'Child age 1 — rejected'],
        ['infant', '-6 months', true,  'Infant age 6 months — accepted'],
        ['infant', '-1 year',   true,  'Infant age 1 — accepted'],
        ['infant', '-2 years',  false, 'Infant age 2 — rejected'],
        ['infant', '-10 years', false, 'Infant age 10 — rejected'],
    ];

    foreach ($cases as [$type, $dobOffset, $pass, $label]) {
        $dob = date('Y-m-d', strtotime($dobOffset));
        $sk  = 'sess_w3_' . md5($label);
        $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at) VALUES (:sk,'1','o','passengers',:exp)")
           ->execute([':sk' => $sk, ':exp' => $exp]);
        $passenger = array_merge($base, ['type' => $type, 'date_of_birth' => $dob]);
        try {
            $svc->savePassengers($sk, [$passenger], 1);
            ok($pass, $label . ($pass ? '' : ' (should have thrown)'));
        } catch (\RuntimeException $e) {
            $isArabicMsg = str_contains($e->getMessage(), 'المسافر') || str_contains($e->getMessage(), 'سن');
            ok(!$pass && $isArabicMsg, $label . ' — ' . $e->getMessage());
        }
    }
})();

// ── W4: Arabic validation messages ───────────────────────────────────────────

section('W4: Arabic validation messages — no internal field names exposed');

(function () {
    $db  = makeDb();
    $svc = makeService($db);
    $exp = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $base = [
        'first_name'      => 'Ali',
        'last_name'       => 'Ahmed',
        'gender'          => 'male',
        'date_of_birth'   => '1990-01-15',
        'nationality'     => 'SAU',
        'passport_number' => 'A123456',
        'passport_expiry' => date('Y-m-d', strtotime('+2 years')),
        'type'            => 'adult',
    ];

    $internalFieldNames = ['passport_number', 'passport_expiry', 'document_number', 'document_expiry',
                           'date_of_birth', 'first_name', 'last_name'];

    $errorCases = [
        array_merge($base, ['passport_number' => '']),
        array_merge($base, ['passport_expiry' => date('Y-m-d', strtotime('-1 day'))]),
        array_merge($base, ['passport_expiry' => date('Y-m-d', strtotime('+3 months'))]),
        array_merge($base, ['date_of_birth'   => date('Y-m-d', strtotime('+1 year'))]),
        array_merge($base, ['nationality'      => 'XYZ']),
    ];

    foreach ($errorCases as $i => $passenger) {
        $sk = "sess_w4_{$i}";
        $db->prepare("INSERT INTO booking_sessions (session_key,user_id,provider_offer_id,current_step,expires_at) VALUES (:sk,'1','o','passengers',:exp)")
           ->execute([':sk' => $sk, ':exp' => $exp]);
        try {
            $svc->savePassengers($sk, [$passenger], 1);
            ok(false, "W4 case {$i}: should have thrown");
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $hasInternal = false;
            foreach ($internalFieldNames as $fn) {
                if (str_contains($msg, $fn)) { $hasInternal = true; break; }
            }
            ok(!$hasInternal, "W4 case {$i}: no internal field names in message — \"{$msg}\"");
        }
    }
})();

// ── TASK 3: confirmed_at capture ──────────────────────────────────────────────

section('TASK 3: confirmed_at captured and stored');

(function () {
    $db = makeDb();
    $duffel = new S4FakeDuffel();
    $svc = makeService($db, $duffel);

    $bookingId = seedBooking($db, 'confirmed');
    // Seed pending cancellation.
    $db->prepare("UPDATE flight_bookings SET pending_cancellation_id='can_test_001', cancellation_refund_amount=150.00,
                  cancellation_refund_currency='GBP', cancellation_refund_to='original_form_of_payment',
                  cancellation_expires_at=:exp WHERE id=:id")
       ->execute([':exp' => date('Y-m-d H:i:s', strtotime('+1 hour')), ':id' => $bookingId]);

    $result = $svc->confirmCancelBooking($bookingId, 'can_test_001', 1);

    ok(isset($result['confirmed_at']), 'confirmed_at returned in result');
    ok(!empty($result['confirmed_at']), 'confirmed_at is non-empty');

    $row = $db->query("SELECT cancellation_confirmed_at FROM flight_bookings WHERE id={$bookingId}")->fetch(PDO::FETCH_ASSOC);
    ok(!empty($row['cancellation_confirmed_at']), 'cancellation_confirmed_at stored in DB');

    // Verify the value matches Duffel response.
    $expected = date('Y-m-d H:i:s', strtotime('2026-06-22T10:00:00Z'));
    ok($row['cancellation_confirmed_at'] === $expected, "DB value matches Duffel confirmed_at ({$expected})");
})();

// ── TASK 3b: confirmed_at fallback when Duffel omits field ───────────────────

section('TASK 3b: confirmed_at fallback');

(function () {
    $db = makeDb();
    $duffel = new S4FakeDuffel();
    $duffel->returnConfirmedAt = false;   // Duffel does not return confirmed_at
    $svc = makeService($db, $duffel);

    $bookingId = seedBooking($db, 'confirmed');
    $db->prepare("UPDATE flight_bookings SET pending_cancellation_id='can_test_002',
                  cancellation_expires_at=:exp WHERE id=:id")
       ->execute([':exp' => date('Y-m-d H:i:s', strtotime('+1 hour')), ':id' => $bookingId]);

    $result = $svc->confirmCancelBooking($bookingId, 'can_test_002', 1);

    ok(isset($result['confirmed_at']), 'confirmed_at present in result even without Duffel value');
    $row = $db->query("SELECT cancellation_confirmed_at FROM flight_bookings WHERE id={$bookingId}")->fetch(PDO::FETCH_ASSOC);
    ok(!empty($row['cancellation_confirmed_at']), 'cancellation_confirmed_at stored (fallback to NOW)');
})();

// ── TASK 5: EmailNotificationService new methods exist ───────────────────────

section('TASK 5: EmailNotificationService new methods');

(function () {
    $db = makeDb();
    $svc = new \App\Services\EmailNotificationService($db);

    ok(method_exists($svc, 'sendScheduleChangeEmail'),   'sendScheduleChangeEmail exists');
    ok(method_exists($svc, 'sendCancellationEmail'),     'sendCancellationEmail exists');
    ok(method_exists($svc, 'sendAirlineCancellationEmail'), 'sendAirlineCancellationEmail exists');
    ok(method_exists($svc, 'sendChangeRejectedEmail'),   'sendChangeRejectedEmail exists');
    ok(method_exists($svc, 'sendAwaitingPaymentEmail'),  'sendAwaitingPaymentEmail exists');
})();

// ── TASK 5: JobWorker dispatch — all new job types handled ───────────────────

section('TASK 5: JobWorker handles all new job types');

(function () {
    // Read JobWorker source and verify all job types are present.
    $src = file_get_contents(BASE_PATH . '/app/Workers/JobWorker.php');
    $types = [
        'sync_flight_booking',
        'flight_schedule_changed',
        'cancel_booking_email',
        'flight_cancelled_by_airline',
        'flight_change_rejected',
        'flight_awaiting_payment',
    ];
    foreach ($types as $type) {
        ok(str_contains($src, "case '{$type}':"), "JobWorker handles '{$type}'");
    }
})();

// ── TASK 5: syncFromDuffelByBookingId exists ──────────────────────────────────

section('TASK 5: FlightBookingService::syncFromDuffelByBookingId');

(function () {
    $db = makeDb();
    $duffel = new S4FakeDuffel();
    $svc = makeService($db, $duffel);

    ok(method_exists($svc, 'syncFromDuffelByBookingId'), 'syncFromDuffelByBookingId method exists');

    $bookingId = seedBooking($db, 'confirmed');
    try {
        $result = $svc->syncFromDuffelByBookingId($bookingId);
        ok(is_array($result), 'syncFromDuffelByBookingId returns array');
    } catch (\Throwable $e) {
        ok(false, 'syncFromDuffelByBookingId — error: ' . $e->getMessage());
    }
})();

// ── W9: order.updated webhook event ──────────────────────────────────────────

section('W9: order.updated Duffel event handled');

(function () {
    $src = file_get_contents(BASE_PATH . '/app/Controllers/Webhook/WebhookController.php');
    ok(str_contains($src, "case 'order.updated':"), "order.updated case present in switch");

    // Verify it routes to the same handler as order.airline_initiated_change.
    // Both must precede the same line (the return call).
    $posChanged = strpos($src, "case 'order.airline_initiated_change':");
    $posUpdated = strpos($src, "case 'order.updated':");
    $posHandler = strpos($src, 'return $this->onDuffelOrderChanged($data)', max($posChanged, $posUpdated));
    ok($posHandler !== false && $posHandler > max($posChanged, $posUpdated),
        'order.updated falls through to onDuffelOrderChanged');

    // Verify existing events are still present (no regression).
    ok(str_contains($src, "case 'order.airline_initiated_change':"), 'order.airline_initiated_change still present');
    ok(str_contains($src, "case 'order.airline_initiated_change.updated':"), 'order.airline_initiated_change.updated still present');
    ok(str_contains($src, "case 'order.cancelled':"), 'order.cancelled still present');
    ok(str_contains($src, "case 'order.payment_status_updated':"), 'order.payment_status_updated still present');
})();

// ── B1: dashboard.html — expires_at field name fix ───────────────────────────

section('B1: dashboard.html cancellation UI + expiry field fix');

(function () {
    $src = file_get_contents(BASE_PATH . '/dashboard.html');

    // Core functions present.
    ok(str_contains($src, 'initiateCancel'), 'initiateCancel function present');
    ok(str_contains($src, 'confirmCancel'),  'confirmCancel function present');
    ok(str_contains($src, 'closeCancelModal'), 'closeCancelModal function present');
    ok(str_contains($src, 'cancelModal'),    'cancelModal element present');
    ok(str_contains($src, 'cancel/confirm'), 'API endpoint /cancel/confirm wired');

    // B1 FIX: reads backend key 'd.expires_at', NOT the wrong 'd.cancellation_expires_at'.
    ok(str_contains($src, 'd.expires_at'),  'B1: reads d.expires_at (correct backend key)');
    ok(!str_contains($src, 'd.cancellation_expires_at'), 'B1: does NOT read d.cancellation_expires_at (bug eliminated)');

    // Countdown and disable wiring.
    ok(str_contains($src, 'cancelExpiryCountdown'), 'expiry countdown element present');
    ok(str_contains($src, "document.getElementById('confirmCancelBtn').disabled = true"), 'confirm button disabled when countdown reaches zero');
    ok(str_contains($src, "setInterval"), 'countdown uses setInterval');
    ok(str_contains($src, "rem === 0"), 'countdown zero-check present');

    // Field name fixes.
    ok(str_contains($src, 'origin_airport'), 'origin_airport field name used');
    ok(str_contains($src, 'destination_airport'), 'destination_airport field name used');
    ok(str_contains($src, 'booking_reference'), 'booking_reference field name used');
    ok(str_contains($src, 'total_amount'), 'total_amount field name used');
})();

// ── TASK 2: admin/bookings.html cancellation fields ──────────────────────────

section('TASK 2: admin/bookings.html cancellation fields');

(function () {
    $src = file_get_contents(BASE_PATH . '/admin/bookings.html');
    ok(str_contains($src, 'pending_cancellation_id'),    'pending_cancellation_id shown');
    ok(str_contains($src, 'cancellation_refund_amount'), 'cancellation_refund_amount shown');
    ok(str_contains($src, 'cancellation_refund_to'),     'cancellation_refund_to shown');
    ok(str_contains($src, 'cancellation_expires_at'),    'cancellation_expires_at shown');
    ok(str_contains($src, 'cancelled_at'),               'cancelled_at shown');
    ok(str_contains($src, 'cancellation_confirmed_at'),  'cancellation_confirmed_at shown');
})();

// ── TASK 3: migration 070 file exists ────────────────────────────────────────

section('TASK 3: migration 070');

(function () {
    $path = BASE_PATH . '/database/migrations/070_cancellation_confirmed_at.sql';
    ok(file_exists($path), 'migration 070 file exists');
    $sql = file_get_contents($path);
    ok(str_contains($sql, 'cancellation_confirmed_at'), 'column name in migration');
    ok(str_contains($sql, 'INFORMATION_SCHEMA'), 'MySQL 8.0-compatible pattern used');
})();

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n\033[1m────────────────────────────────────────\033[0m\n";
echo "\033[1mResults: \033[32m{$passed} passed\033[0m  \033[31m{$failed} failed\033[0m\n";
echo "\033[1m────────────────────────────────────────\033[0m\n";

exit($failed > 0 ? 1 : 0);
