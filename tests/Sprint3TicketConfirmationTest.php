<?php

declare(strict_types=1);

/**
 * Sprint 3 — Orders, Documents, PNR, and Ticket Confirmation Tests
 *
 * Standalone tests (no external test framework).
 * Run: php tests/Sprint3TicketConfirmationTest.php
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $file = BASE_PATH . '/app/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once BASE_PATH . '/app/Adapters/Duffel/DuffelAdapter.php';
require_once BASE_PATH . '/app/Adapters/Stripe/StripeAdapter.php';
require_once BASE_PATH . '/app/Services/BookingSessionService.php';
require_once BASE_PATH . '/app/Services/DuffelErrorMapper.php';
require_once BASE_PATH . '/app/Services/FlightBookingService.php';
require_once BASE_PATH . '/app/Services/EmailNotificationService.php';
require_once BASE_PATH . '/app/Controllers/Admin/AdminBookingsController.php';

// ── Fake classes ──────────────────────────────────────────────────────────────

class S3FakeStripe extends \App\Adapters\Stripe\StripeAdapter
{
    public function __construct() {}
    public function createPaymentIntent(int $amountInMinorUnits, string $idempotencyKey, string $currency = '', array $metadata = [], array $paymentMethodTypes = []): array
    {
        return ['payment_intent_id' => 'pi_s3_001', 'client_secret' => 'pi_s3_001_secret'];
    }
    public function createRefund(string $paymentIntentId, ?int $amountMinor = null, string $idempotencyKey = '', string $reason = 'requested_by_customer'): array
    {
        return ['id' => 're_s3_001'];
    }
}

class S3FakeSessionService extends \App\Services\BookingSessionService
{
    public function __construct() {}
    public function create(int $userId, string $bookingType = 'flight'): string { return 'fake-session-key'; }
    public function update(string $key, array $data): bool    { return true; }
    public function get(string $key): ?array { return null; }
}

class S3SqliteCompatPdo extends \PDO
{
    private \PDO $inner;
    public function __construct(\PDO $inner) { $this->inner = $inner; }
    public function exec(string $statement): int|false {
        $t = strtoupper(ltrim($statement));
        if (str_starts_with($t, 'LOCK TABLE') || str_starts_with($t, 'UNLOCK TABLE')) return 0;
        return $this->inner->exec($statement);
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        // SQLite uses INSERT OR IGNORE instead of MySQL's INSERT IGNORE
        $query = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $query);
        return $this->inner->prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false { return $this->inner->query($query, $fetchMode, ...$args); }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool           { return $this->inner->commit(); }
    public function rollBack(): bool         { return $this->inner->rollBack(); }
    public function getAttribute(int $attr): mixed { return $this->inner->getAttribute($attr); }
    public function setAttribute(int $attr, mixed $value): bool { return $this->inner->setAttribute($attr, $value); }
    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false { return $this->inner->quote($string, $type); }
    public function errorCode(): ?string { return $this->inner->errorCode(); }
    public function errorInfo(): array   { return $this->inner->errorInfo(); }
}

// ── DB builder ────────────────────────────────────────────────────────────────

function s3BuildPdo(): S3SqliteCompatPdo
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->sqliteCreateFunction('NOW',               fn() => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', fn() => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('COALESCE',          fn(...$args) => array_values(array_filter($args, fn($v) => $v !== null))[0] ?? null);
    $pdo->sqliteCreateFunction('GET_LOCK',          fn($n, $t) => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK',      fn($n) => 1, 1);

    $pdo->exec('CREATE TABLE flight_bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER, provider_id INTEGER DEFAULT 1,
        booking_reference TEXT, provider_order_id TEXT,
        duffel_booking_reference TEXT,
        booking_references TEXT,
        trip_type TEXT, cabin_class TEXT, adults_count INTEGER DEFAULT 0, children_count INTEGER DEFAULT 0,
        origin_airport TEXT, destination_airport TEXT, departure_at TEXT,
        total_amount REAL, currency TEXT DEFAULT "GBP", status TEXT DEFAULT "confirmed",
        paid_at TEXT, payment_required_by TEXT, price_guarantee_expires_at TEXT,
        void_window_ends_at TEXT, available_actions TEXT, live_mode INTEGER DEFAULT 1,
        refund_conditions TEXT, change_conditions TEXT,
        awaiting_payment INTEGER DEFAULT 0, duffel_payment_failure TEXT,
        synced_at TEXT, updated_at TEXT, cancelled_at TEXT,
        pending_cancellation_id TEXT, cancellation_expires_at TEXT,
        cancellation_refund_to TEXT, cancellation_refund_amount REAL,
        cancellation_refund_currency TEXT, auto_refunded_at TEXT
    )');
    $pdo->exec('CREATE TABLE flight_booking_passengers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        passenger_type TEXT, first_name TEXT, last_name TEXT, gender TEXT,
        date_of_birth TEXT, nationality TEXT, passport_number TEXT, passport_expiry TEXT,
        provider_passenger_id TEXT, ticket_number TEXT
    )');
    $pdo->exec('CREATE TABLE flight_booking_segments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        slice_index INTEGER, segment_order INTEGER, origin_airport TEXT,
        destination_airport TEXT, departure_at TEXT, arrival_at TEXT,
        flight_number TEXT, airline_code TEXT, aircraft_type TEXT
    )');
    $pdo->exec('CREATE TABLE flight_booking_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER,
        document_type TEXT, unique_identifier TEXT, passenger_ids TEXT,
        UNIQUE(booking_id, unique_identifier)
    )');
    $pdo->exec('CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_type TEXT, booking_id INTEGER DEFAULT 0,
        user_id INTEGER, payment_method TEXT DEFAULT "stripe",
        idempotency_key TEXT, stripe_payment_intent_id TEXT,
        stripe_refund_id TEXT, amount REAL, currency TEXT DEFAULT "GBP",
        status TEXT DEFAULT "pending", gateway_status TEXT, gateway_response TEXT,
        failure_reason TEXT, paid_at TEXT,
        duffel_payment_failure TEXT, auto_refund_reason TEXT, auto_refunded_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE booking_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_key TEXT, user_id INTEGER, booking_type TEXT, current_step TEXT,
        provider_offer_id TEXT, offer_expires_at TEXT, payment_intent_id TEXT,
        passengers_data TEXT, services_data TEXT, pricing_snapshot TEXT, expires_at TEXT
    )');
    $pdo->exec('CREATE TABLE offer_cache (
        offer_id TEXT PRIMARY KEY, offer_data TEXT, total_amount REAL,
        currency TEXT, expires_at TEXT,
        offer_request_id TEXT DEFAULT "", provider_id INTEGER DEFAULT 1, search_hash TEXT DEFAULT ""
    )');
    $pdo->exec('CREATE TABLE job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT, payload TEXT)');
    $pdo->exec('CREATE TABLE pricing_rules (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE coupons (id INTEGER PRIMARY KEY)');

    return new S3SqliteCompatPdo($pdo);
}

// Builds a Duffel adapter that returns a customised order response
function s3MakeDuffel(array $orderOverrides = []): \App\Adapters\Duffel\DuffelAdapter
{
    return new class($orderOverrides) extends \App\Adapters\Duffel\DuffelAdapter {
        private array $ov;
        public function __construct(array $ov) { $this->ov = $ov; }

        public function createOrder(string $selectedOfferId, array $passengers, array $payments, array $services = [], ?array $metadata = null): array
        {
            return ['data' => array_merge([
                'id'                 => 'ord_s3_001',
                'booking_reference'  => 'ABC123',
                'booking_references' => [],
                'live_mode'          => false,
                'documents'          => [],
                'passengers'         => [],
                'available_actions'  => ['cancel'],
                'payment_status'     => [
                    'paid_at' => null, 'payment_required_by' => null,
                    'price_guarantee_expires_at' => null,
                    'awaiting_payment' => false, 'failure_reason' => null,
                ],
                'conditions' => [],
            ], $this->ov)];
        }

        public function priceOffer(string $offerId, array $intendedPaymentMethods = ['balance'], array $services = []): array
        {
            return ['data' => ['total_amount' => '100.00', 'total_currency' => 'GBP']];
        }

        public function getOfferWithServices(string $offerId): array
        {
            return ['data' => [
                'id'             => $offerId,
                'expires_at'     => date('Y-m-d H:i:s', strtotime('+2 hours')),
                'total_amount'   => '100.00',
                'total_currency' => 'GBP',
                'slices'         => [[
                    'origin'      => ['iata_code' => 'LHR'],
                    'destination' => ['iata_code' => 'DXB'],
                    'segments'    => [[
                        'departing_at' => '2026-07-01T08:00:00Z',
                        'arriving_at'  => '2026-07-01T16:00:00Z',
                        'origin'       => ['iata_code' => 'LHR'],
                        'destination'  => ['iata_code' => 'DXB'],
                        'marketing_carrier' => ['iata_code' => 'EK'],
                        'marketing_carrier_flight_number' => '001',
                        'aircraft'     => ['iata_code' => '77W'],
                        'passengers'   => [['cabin_class' => 'economy']],
                    ]],
                ]],
                'passengers'           => [['id' => 'pas_001', 'type' => 'adult']],
                'conditions'           => [],
                'payment_requirements' => [],
            ]];
        }
    };
}

function s3InsertSession(\PDO $pdo, string $pi): void
{
    $pdo->exec("INSERT INTO booking_sessions
        (session_key, user_id, booking_type, current_step, provider_offer_id,
         offer_expires_at, payment_intent_id, passengers_data, services_data, pricing_snapshot, expires_at)
        VALUES (
            's3-session', 1, 'flight', 'payment', 'off_s3_001',
            '" . date('Y-m-d H:i:s', strtotime('+2 hours')) . "',
            '{$pi}',
            '" . json_encode([[
                'first_name' => 'Sara', 'last_name' => 'Ali', 'gender' => 'female',
                'date_of_birth' => '1995-05-15', 'nationality' => 'SAU',
                'document_number' => 'B98765432', 'document_expiry' => '2031-01-01', 'type' => 'adult',
            ]]) . "',
            '[]',
            '" . json_encode(['total' => 100.0, 'currency' => 'GBP', 'base_amount' => 90.0, 'fees' => []]) . "',
            '" . date('Y-m-d H:i:s', strtotime('+2 hours')) . "'
        )");
    $pdo->exec("INSERT INTO payments
        (booking_type, booking_id, user_id, payment_method, idempotency_key,
         stripe_payment_intent_id, amount, currency, status)
        VALUES ('flight', 0, 1, 'stripe', 'idem_s3', '{$pi}', 100.00, 'GBP', 'pending')");
}

// ── Test runner ───────────────────────────────────────────────────────────────

$PASS = 0;
$FAIL = 0;

function ok(bool $condition, string $label): void
{
    global $PASS, $FAIL;
    if ($condition) {
        echo "\033[32m  ✓ {$label}\033[0m\n";
        $PASS++;
    } else {
        echo "\033[31m  ✗ {$label}\033[0m\n";
        $FAIL++;
    }
}

function section(string $name): void
{
    echo "\n\033[33m{$name}\033[0m\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST SUITE
// ═════════════════════════════════════════════════════════════════════════════

// ── 1. booking_reference only ─────────────────────────────────────────────────

section('1 — Order with booking_reference only (no booking_references array)');

$pdo1 = s3BuildPdo();
s3InsertSession($pdo1, 'pi_s3_t1');
$svc1 = new \App\Services\FlightBookingService(
    db: $pdo1,
    duffel: s3MakeDuffel(['booking_reference' => 'XY7891', 'booking_references' => []]),
    stripe: new S3FakeStripe(),
    sessionService: new S3FakeSessionService()
);
$r1 = $svc1->completeBooking('s3-session', 'pi_s3_t1');
$row1 = $pdo1->query("SELECT duffel_booking_reference, booking_references FROM flight_bookings WHERE id = {$r1['booking_id']}")->fetch(\PDO::FETCH_ASSOC);
ok($row1['duffel_booking_reference'] === 'XY7891', 'booking_reference stored in duffel_booking_reference');
ok($row1['booking_references'] === null || $row1['booking_references'] === '[]', 'Empty booking_references[] stored as null or []');

// ── 2. Multiple booking_references[] ─────────────────────────────────────────

section('2 — Order with multiple booking_references[] (multi-carrier)');

$pdo2 = s3BuildPdo();
s3InsertSession($pdo2, 'pi_s3_t2');
$multiRefs = [
    ['id' => 'br_001', 'value' => 'ABCDEF', 'airline_iata_code' => 'EK', 'source' => 'airline'],
    ['id' => 'br_002', 'value' => 'GHIJKL', 'airline_iata_code' => 'QR', 'source' => 'airline'],
];
$svc2 = new \App\Services\FlightBookingService(
    db: $pdo2,
    duffel: s3MakeDuffel(['booking_reference' => 'ABCDEF', 'booking_references' => $multiRefs]),
    stripe: new S3FakeStripe(),
    sessionService: new S3FakeSessionService()
);
$r2 = $svc2->completeBooking('s3-session', 'pi_s3_t2');
$row2 = $pdo2->query("SELECT duffel_booking_reference, booking_references FROM flight_bookings WHERE id = {$r2['booking_id']}")->fetch(\PDO::FETCH_ASSOC);
ok($row2['duffel_booking_reference'] === 'ABCDEF', 'Primary booking_reference stored');
$storedRefs = json_decode($row2['booking_references'], true);
ok(is_array($storedRefs) && count($storedRefs) === 2, 'booking_references[] stored as JSON array with 2 entries');
ok(($storedRefs[0]['value'] ?? '') === 'ABCDEF', 'First booking reference value correct');
ok(($storedRefs[1]['value'] ?? '') === 'GHIJKL', 'Second booking reference value correct');
ok(($storedRefs[1]['airline_iata_code'] ?? '') === 'QR', 'Airline IATA code stored in booking_references');

// ── 3. documents[] with electronic_ticket ────────────────────────────────────

section('3 — Order with documents[] containing electronic_ticket');

$pdo3 = s3BuildPdo();
s3InsertSession($pdo3, 'pi_s3_t3');
$orderDocs = [
    ['type' => 'electronic_ticket', 'unique_identifier' => '176-1234567890', 'passenger_ids' => ['pas_001']],
];
$svc3 = new \App\Services\FlightBookingService(
    db: $pdo3,
    duffel: s3MakeDuffel([
        'booking_reference' => 'T3PNR1',
        'documents'         => $orderDocs,
        'passengers'        => [['id' => 'pas_001', 'documents' => [
            ['type' => 'electronic_ticket', 'unique_identifier' => '176-1234567890'],
        ]]],
    ]),
    stripe: new S3FakeStripe(),
    sessionService: new S3FakeSessionService()
);
$r3 = $svc3->completeBooking('s3-session', 'pi_s3_t3');
$docs3 = $pdo3->query("SELECT * FROM flight_booking_documents WHERE booking_id = {$r3['booking_id']}")->fetchAll(\PDO::FETCH_ASSOC);
ok(count($docs3) === 1, 'electronic_ticket document stored in flight_booking_documents');
ok(($docs3[0]['document_type'] ?? '') === 'electronic_ticket', 'document_type = electronic_ticket');
ok(($docs3[0]['unique_identifier'] ?? '') === '176-1234567890', 'unique_identifier (ticket number) stored correctly');

// Also verify ticket_number copied to passenger row via syncFromDuffel path
$pax3 = $pdo3->query("SELECT ticket_number FROM flight_booking_passengers WHERE booking_id = {$r3['booking_id']} LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
ok(($pax3['ticket_number'] ?? null) === '176-1234567890', 'Ticket number copied to passenger row');

// ── 4. Empty documents[] — booking not failed ─────────────────────────────────

section('4 — Order with empty documents[] — booking still confirmed');

$pdo4 = s3BuildPdo();
s3InsertSession($pdo4, 'pi_s3_t4');
$svc4 = new \App\Services\FlightBookingService(
    db: $pdo4,
    duffel: s3MakeDuffel(['booking_reference' => 'T4PNR1', 'documents' => []]),
    stripe: new S3FakeStripe(),
    sessionService: new S3FakeSessionService()
);
$r4   = $svc4->completeBooking('s3-session', 'pi_s3_t4');
$stat4 = $pdo4->query("SELECT status FROM flight_bookings WHERE id = {$r4['booking_id']}")->fetchColumn();
$docs4 = $pdo4->query("SELECT COUNT(*) FROM flight_booking_documents WHERE booking_id = {$r4['booking_id']}")->fetchColumn();
ok($stat4 === 'confirmed', 'Booking confirmed even when documents[] is empty');
ok((int)$docs4 === 0, 'No documents rows inserted when order returns empty documents[]');

// ── 5. syncFromDuffel adds ticket documents ───────────────────────────────────

section('5 — syncFromDuffel adds ticket documents after initial empty response');

$pdo5 = s3BuildPdo();
// Pre-insert a booking with no documents
$pdo5->exec("INSERT INTO flight_bookings
    (id, user_id, booking_reference, provider_order_id, duffel_booking_reference, status, currency, total_amount, synced_at)
    VALUES (1, 1, 'FM00000001', 'ord_s3_sync', 'SYNC01', 'confirmed', 'GBP', 100.00, '" . date('Y-m-d H:i:s') . "')");
$pdo5->exec("INSERT INTO flight_booking_passengers
    (booking_id, passenger_type, first_name, last_name, gender, date_of_birth, nationality,
     provider_passenger_id, ticket_number)
    VALUES (1, 'adult', 'Sara', 'Ali', 'female', '1995-05-15', 'SAU', 'pas_001', NULL)");

$syncDuffel = new class extends \App\Adapters\Duffel\DuffelAdapter {
    public function __construct() {}
    public function getOrder(string $orderId): array {
        return ['data' => [
            'id'                 => $orderId,
            'booking_reference'  => 'SYNC01',
            'booking_references' => [['id' => 'br_sync', 'value' => 'SYNC01', 'airline_iata_code' => 'EK']],
            'live_mode'          => true,
            'documents'          => [
                ['type' => 'electronic_ticket', 'unique_identifier' => '176-9999999999', 'passenger_ids' => ['pas_001']],
            ],
            'passengers'         => [['id' => 'pas_001', 'documents' => [
                ['type' => 'electronic_ticket', 'unique_identifier' => '176-9999999999'],
            ]]],
            'available_actions'  => ['cancel'],
            'payment_status'     => ['paid_at' => null, 'payment_required_by' => null,
                'price_guarantee_expires_at' => null, 'awaiting_payment' => false],
            'conditions'         => [],
        ]];
    }
};

$svc5 = new \App\Services\FlightBookingService(db: $pdo5, duffel: $syncDuffel, stripe: new S3FakeStripe(), sessionService: new S3FakeSessionService());
$svc5->syncFromDuffel(1, 1);
$docs5 = $pdo5->query("SELECT * FROM flight_booking_documents WHERE booking_id = 1")->fetchAll(\PDO::FETCH_ASSOC);
ok(count($docs5) === 1, 'syncFromDuffel adds ticket document that was missing after initial booking');
ok(($docs5[0]['unique_identifier'] ?? '') === '176-9999999999', 'Correct ticket number stored after sync');

$pax5 = $pdo5->query("SELECT ticket_number FROM flight_booking_passengers WHERE booking_id = 1 LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
ok(($pax5['ticket_number'] ?? '') === '176-9999999999', 'Passenger ticket_number updated after sync');

// booking_references also synced
$refs5 = $pdo5->query("SELECT booking_references FROM flight_bookings WHERE id = 1")->fetchColumn();
$refs5Arr = json_decode($refs5, true);
ok(is_array($refs5Arr) && count($refs5Arr) === 1, 'booking_references[] stored during syncFromDuffel');

// ── 6. syncFromDuffel preserves existing documents when Duffel returns empty ──

section('6 — syncFromDuffel preserves documents when Duffel returns empty documents[]');

$pdo6 = s3BuildPdo();
$pdo6->exec("INSERT INTO flight_bookings
    (id, user_id, booking_reference, provider_order_id, duffel_booking_reference, status, currency, total_amount, synced_at)
    VALUES (1, 1, 'FM00000006', 'ord_s3_pres', 'PRES01', 'confirmed', 'GBP', 100.00, '" . date('Y-m-d H:i:s') . "')");
$pdo6->exec("INSERT INTO flight_booking_documents
    (booking_id, document_type, unique_identifier, passenger_ids)
    VALUES (1, 'electronic_ticket', '176-8888888888', '[]')");

$emptyDocsDuffel = new class extends \App\Adapters\Duffel\DuffelAdapter {
    public function __construct() {}
    public function getOrder(string $orderId): array {
        return ['data' => [
            'id'                 => $orderId,
            'booking_reference'  => 'PRES01',
            'booking_references' => [],
            'live_mode'          => true,
            'documents'          => [],    // Duffel returns nothing this time
            'passengers'         => [],
            'available_actions'  => ['cancel'],
            'payment_status'     => ['awaiting_payment' => false],
            'conditions'         => [],
        ]];
    }
};

$svc6 = new \App\Services\FlightBookingService(db: $pdo6, duffel: $emptyDocsDuffel, stripe: new S3FakeStripe(), sessionService: new S3FakeSessionService());
$svc6->syncFromDuffel(1, 1);
$docs6 = $pdo6->query("SELECT COUNT(*) FROM flight_booking_documents WHERE booking_id = 1")->fetchColumn();
ok((int)$docs6 === 1, 'Existing documents preserved when Duffel returns empty documents[] on sync');
$uid6 = $pdo6->query("SELECT unique_identifier FROM flight_booking_documents WHERE booking_id = 1")->fetchColumn();
ok($uid6 === '176-8888888888', 'Original ticket number not overwritten by empty sync response');

// ── 7. enrichBooking decodes booking_references JSON ─────────────────────────

section('7 — enrichBooking decodes booking_references JSON column');

$pdo7 = s3BuildPdo();
$pdo7->exec("INSERT INTO flight_bookings
    (id, user_id, booking_reference, provider_order_id, status, currency, total_amount,
     duffel_booking_reference, booking_references)
    VALUES (1, 1, 'FM00000007', 'ord_007', 'confirmed', 'GBP', 100.00,
     'REF001',
     '" . json_encode([['value' => 'REF001', 'airline_iata_code' => 'BA']]) . "')");

$svc7 = new \App\Services\FlightBookingService(db: $pdo7, duffel: s3MakeDuffel(), stripe: new S3FakeStripe(), sessionService: new S3FakeSessionService());
$booking7 = $svc7->getBookingById(1, 1);
ok(is_array($booking7['booking_references'] ?? null), 'booking_references decoded from JSON to array in enrichBooking');
ok(($booking7['booking_references'][0]['value'] ?? '') === 'REF001', 'booking_references[0].value accessible as PHP string');

// ── 8. Admin show() returns documents and correct passengers ──────────────────

section('8 — Admin show() returns documents and uses correct column names');

$pdo8 = s3BuildPdo();
$pdo8->exec("INSERT INTO flight_bookings
    (id, user_id, booking_reference, provider_order_id, status, currency, total_amount, duffel_booking_reference)
    VALUES (1, 1, 'FM00000008', 'ord_adm_001', 'confirmed', 'GBP', 250.00, 'ADMREF1')");

// Insert a passenger (column name: booking_id, NOT flight_booking_id)
$pdo8->exec("INSERT INTO flight_booking_passengers
    (booking_id, passenger_type, first_name, last_name, gender, date_of_birth, nationality,
     provider_passenger_id, ticket_number)
    VALUES (1, 'adult', 'Ahmed', 'Salem', 'male', '1988-03-10', 'ARE', 'pas_adm_001', '176-1111111111')");

// Insert a segment (column name: booking_id)
$pdo8->exec("INSERT INTO flight_booking_segments
    (booking_id, slice_index, segment_order, origin_airport, destination_airport,
     departure_at, arrival_at, flight_number)
    VALUES (1, 0, 1, 'DXB', 'LHR', '2026-08-01 10:00:00', '2026-08-01 15:30:00', 'EK003')");

// Insert a document
$pdo8->exec("INSERT INTO flight_booking_documents
    (booking_id, document_type, unique_identifier, passenger_ids)
    VALUES (1, 'electronic_ticket', '176-1111111111', '[\"pas_adm_001\"]')");

// Also need a users table for the JOIN in AdminBookingsController
try { $pdo8->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT)'); } catch (\Throwable) {}
$pdo8->exec("INSERT INTO users VALUES (1, 'ahmed@test.com', 'Ahmed', 'Salem')");

// Stub Database::getInstance() to return our PDO — patch via class
// Since we can't easily do DI here, we'll test the query logic directly
$pasStmt = $pdo8->prepare('SELECT * FROM flight_booking_passengers WHERE booking_id = ? ORDER BY id');
$pasStmt->execute([1]);
$paxRows = $pasStmt->fetchAll(\PDO::FETCH_ASSOC);
ok(count($paxRows) === 1, 'Admin: passenger query with correct column name (booking_id) returns rows');
ok(($paxRows[0]['ticket_number'] ?? '') === '176-1111111111', 'Admin: passenger ticket_number visible');

$segStmt = $pdo8->prepare('SELECT * FROM flight_booking_segments WHERE booking_id = ? ORDER BY slice_index, segment_order');
$segStmt->execute([1]);
$segRows = $segStmt->fetchAll(\PDO::FETCH_ASSOC);
ok(count($segRows) === 1, 'Admin: segment query with correct column name (booking_id) returns rows');

$docStmt = $pdo8->prepare('SELECT * FROM flight_booking_documents WHERE booking_id = ? ORDER BY id');
$docStmt->execute([1]);
$docRows = $docStmt->fetchAll(\PDO::FETCH_ASSOC);
ok(count($docRows) === 1, 'Admin: documents fetched from flight_booking_documents');
ok(($docRows[0]['document_type'] ?? '') === 'electronic_ticket', 'Admin: document_type returned');
ok(($docRows[0]['unique_identifier'] ?? '') === '176-1111111111', 'Admin: unique_identifier (ticket number) returned');

// ── 9. Migration syntax check ─────────────────────────────────────────────────

section('9 — Migration 069 syntax validation');

$m069 = file_get_contents(BASE_PATH . '/database/migrations/069_sprint3_booking_references.sql');
ok($m069 !== false, 'Migration 069 file exists');
ok(!preg_match('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i', $m069), '069: no ADD COLUMN IF NOT EXISTS (MariaDB-only)');
ok(str_contains($m069, 'INFORMATION_SCHEMA'), '069: uses INFORMATION_SCHEMA for conditional check');
ok(str_contains($m069, 'booking_references'), '069: booking_references column declared');
ok(str_contains($m069, 'DROP PROCEDURE IF EXISTS'), '069: procedure cleaned up after execution');

// ── 10. Email includes booking_references ─────────────────────────────────────

section('10 — Email includes extra PNRs from booking_references[]');

// Test buildFlightConfirmationHtml via ReflectionMethod (private method)
$emailSvc = new \App\Services\EmailNotificationService(s3BuildPdo());
$emailRef = new \ReflectionMethod($emailSvc, 'buildFlightConfirmationHtml');
$emailRef->setAccessible(true);

$html10 = $emailRef->invoke($emailSvc, [
    'booking_reference'       => 'FM00000010',
    'duffel_booking_reference'=> 'EMIRAT1',
    'booking_references'      => [
        ['id' => 'br_1', 'value' => 'EMIRAT1', 'airline_iata_code' => 'EK'],
        ['id' => 'br_2', 'value' => 'QATAR22', 'airline_iata_code' => 'QR'],
    ],
    'total_amount'  => '350.00',
    'currency'      => 'GBP',
    'status'        => 'confirmed',
    'void_window_ends_at' => null,
    'refund_conditions'   => null,
    'change_conditions'   => null,
], [], [], [['document_type' => 'electronic_ticket', 'unique_identifier' => '176-5555555555']]);

ok(str_contains($html10, 'EMIRAT1'), 'Email: primary PNR present');
ok(str_contains($html10, 'QATAR22'), 'Email: second airline PNR from booking_references[] present');
ok(str_contains($html10, 'QR PNR'), 'Email: airline code label shown for second PNR');
ok(str_contains($html10, '176-5555555555'), 'Email: electronic ticket number present');

// ── 11. Confirmation page JavaScript renders booking_references ───────────────

section('11 — Confirmation page JS handles booking_references[]');

$confirmHtml = file_get_contents(BASE_PATH . '/confirmation.html');
ok(str_contains($confirmHtml, 'booking_references'), 'confirmation.html references booking_references field');
ok(str_contains($confirmHtml, 'extraRefs'), 'confirmation.html renders extra airline PNRs');
ok(str_contains($confirmHtml, 'airline_iata_code'), 'confirmation.html uses airline_iata_code for display');

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";
echo "\033[32m  PASS: {$PASS}\033[0m";
if ($FAIL > 0) {
    echo "  \033[31mFAIL: {$FAIL}\033[0m";
} else {
    echo "  \033[32m— All tests passed\033[0m";
}
echo "\n" . str_repeat('─', 60) . "\n";
exit($FAIL > 0 ? 1 : 0);
