<?php

declare(strict_types=1);

/**
 * DuffelPayloadTest — validates that mapPassengersForDuffel produces
 * a correct, complete Duffel-ready payload and that savePassengers()
 * rejects invalid data with Arabic messages.
 *
 * Run: php tests/DuffelPayloadTest.php
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $file = BASE_PATH . '/app/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

require_once BASE_PATH . '/app/Services/DuffelErrorMapper.php';

// ── Minimal stubs so FlightBookingService can be instantiated without a real DB ──

class StubPDO extends PDO {
    public function __construct() {} // no-op
    public function prepare($sql, $options = []) { return new StubPDOStatement(); }
    public function query($sql, ...$args): StubPDOStatement { return new StubPDOStatement(); }
    public function exec($sql): int|false { return 0; }
}
class StubPDOStatement {
    public function execute($params = []): bool { return true; }
    public function fetchColumn() { return 0; }
    public function fetchAll($mode = null, ...$args): array { return []; }
    public function fetch($mode = null, ...$args): mixed { return false; }
    public function bindValue($p, $v, $t = null): bool { return true; }
}

// ── Test runner ──────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        echo "\033[32m  ✓ {$label}\033[0m\n";
        $passed++;
    } else {
        echo "\033[31m  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\033[0m\n";
        $failed++;
    }
}
function section(string $t): void { echo "\n\033[1;34m── {$t} ──\033[0m\n"; }
function throws(callable $fn, int $code = 0): ?RuntimeException {
    try { $fn(); return null; }
    catch (RuntimeException $e) {
        if ($code && $e->getCode() !== $code) return null;
        return $e;
    } catch (\Throwable) { return null; }
}

// ── Access private methods via Reflection ────────────────────────────────────

require_once BASE_PATH . '/app/Services/BookingSessionService.php';
require_once BASE_PATH . '/app/Adapters/Duffel/DuffelAdapter.php';
require_once BASE_PATH . '/app/Adapters/Stripe/StripeAdapter.php';
require_once BASE_PATH . '/app/Services/FlightBookingService.php';

function callPrivate(object $obj, string $method, array $args = []): mixed {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

$svc = new \App\Services\FlightBookingService(new StubPDO());

// ── Sample data ──────────────────────────────────────────────────────────────

$goodPassenger = [
    'type'            => 'adult',
    'title'           => 'mr',
    'first_name'      => 'Ahmed',
    'last_name'       => 'AlKuwaiti',
    'gender'          => 'male',
    'date_of_birth'   => '1990-05-15',
    'nationality'     => 'KWT',
    'passport_number' => 'P1234567',
    'passport_expiry' => '2030-01-01',
    'document_type'   => 'passport',
    'document_issue'  => '2020-01-01',
    'email'           => 'ahmed@example.com',
    'phone_number'    => '+96599123456',
];

$offerData = [
    'passengers' => [
        ['id' => 'pas_0001', 'type' => 'adult'],
    ],
];

// ── SECTION 1: toAlpha2 conversion ──────────────────────────────────────────
section('1. Nationality alpha-3 → alpha-2 conversion');

$tests = [
    'KWT' => 'KW', 'SAU' => 'SA', 'ARE' => 'AE', 'EGY' => 'EG',
    'GBR' => 'GB', 'USA' => 'US', 'FRA' => 'FR', 'DEU' => 'DE',
    'QAT' => 'QA', 'OMN' => 'OM', 'BHR' => 'BH', 'JOR' => 'JO',
];
foreach ($tests as $a3 => $expected) {
    $result = callPrivate($svc, 'toAlpha2', [$a3]);
    ok($result === $expected, "toAlpha2($a3) === $expected (got: $result)");
}

// ── SECTION 2: mapPassengersForDuffel complete payload ───────────────────────
section('2. mapPassengersForDuffel — complete payload');

$mapped = callPrivate($svc, 'mapPassengersForDuffel', [[$goodPassenger], $offerData]);
$p = $mapped[0];

ok(($p['title'] ?? '') === 'mr',        'title present and lowercase');
ok($p['given_name']  === 'Ahmed',       'given_name');
ok($p['family_name'] === 'AlKuwaiti',   'family_name');
ok($p['gender']      === 'm',           'gender mapped to m');
ok($p['born_on']     === '1990-05-15',  'born_on');
ok($p['nationality'] === 'KW',          'nationality is alpha-2 KW (not KWT)');
ok($p['email']       === 'ahmed@example.com', 'email present');
ok($p['phone_number'] === '+96599123456',     'phone_number present');
ok($p['id']          === 'pas_0001',    'Duffel passenger id assigned');
ok(isset($p['identity_documents']),     'identity_documents array present');
ok(($p['identity_documents'][0]['type'] ?? '') === 'passport', 'doc type = passport');
ok(($p['identity_documents'][0]['unique_identifier'] ?? '') === 'P1234567', 'doc unique_identifier');
ok(($p['identity_documents'][0]['expires_on'] ?? '') === '2030-01-01', 'doc expires_on');
ok(($p['identity_documents'][0]['issuing_country_code'] ?? '') === 'KW', 'issuing_country_code alpha-2');
ok(($p['identity_documents'][0]['issued_on'] ?? '') === '2020-01-01', 'issued_on');

// female gender
$femalePax = array_merge($goodPassenger, ['gender' => 'female']);
$mappedF = callPrivate($svc, 'mapPassengersForDuffel', [[$femalePax], $offerData]);
ok(($mappedF[0]['gender'] ?? '') === 'f', 'female gender maps to f');

// ── SECTION 3: validateDuffelPassengers ──────────────────────────────────────
section('3. Pre-flight validator');

$goodMapped = callPrivate($svc, 'mapPassengersForDuffel', [[$goodPassenger], $offerData]);
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$goodMapped]));
ok($ex === null, 'valid payload passes validator without exception');

// Missing title
$noTitle = $goodMapped;
$noTitle[0]['title'] = '';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$noTitle]), 422);
ok($ex !== null && str_contains($ex->getMessage(), 'اللقب'), 'missing title → Arabic 422');

// Missing email
$noEmail = $goodMapped;
$noEmail[0]['email'] = '';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$noEmail]), 422);
ok($ex !== null && str_contains($ex->getMessage(), 'البريد'), 'missing email → Arabic 422');

// Bad email format
$badEmail = $goodMapped;
$badEmail[0]['email'] = 'not-an-email';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$badEmail]), 422);
ok($ex !== null && str_contains($ex->getMessage(), 'البريد'), 'bad email format → Arabic 422');

// Missing phone
$noPhone = $goodMapped;
$noPhone[0]['phone_number'] = '';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$noPhone]), 422);
ok($ex !== null && str_contains($ex->getMessage(), 'الهاتف'), 'missing phone → Arabic 422');

// Short phone
$shortPhone = $goodMapped;
$shortPhone[0]['phone_number'] = '+123';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$shortPhone]), 422);
ok($ex !== null, 'short phone → 422');

// Missing nationality
$noNat = $goodMapped;
$noNat[0]['nationality'] = '';
$ex = throws(fn() => callPrivate($svc, 'validateDuffelPassengers', [$noNat]), 422);
ok($ex !== null && str_contains($ex->getMessage(), 'الجنسية'), 'missing nationality → Arabic 422');

// ── SECTION 4: DuffelErrorMapper ─────────────────────────────────────────────
section('4. DuffelErrorMapper — known codes');

$knownCodes = [
    'insufficient_balance', 'offer_expired', 'offer_no_longer_available',
    'already_paid', 'already_cancelled', 'invalid_passenger_identity_document',
    'airline_error', 'schedule_changed', 'invalid_email', 'invalid_phone_number',
];

$makeEx = fn(string $code) => new RuntimeException(
    'Duffel API error (422): msg [' . $code . '] {"errors":[{"code":"' . $code . '","title":"T","message":"M"}],"meta":{"request_id":"r1"}}',
    422
);

foreach ($knownCodes as $code) {
    $mapped = \App\Services\DuffelErrorMapper::fromDuffelException($makeEx($code));
    $parts  = \App\Services\DuffelErrorMapper::split($mapped->getMessage());
    ok(
        $parts['customer'] !== '' && !str_starts_with($parts['customer'], 'خطأ Duffel [' . $code . ']'),
        "code={$code} → mapped to specific Arabic message (not generic fallback)"
    );
}

// validation_required (unmapped) should still show real detail
$valEx = new RuntimeException(
    'Duffel API error (422): Field email can\'t be blank [validation_required] {"errors":[{"code":"validation_required","title":"Validation required","message":"Field \'email\' can\'t be blank","source":{"pointer":"/data/passengers/0/email"}}],"meta":{"request_id":"req_test"}}',
    422
);
$mapped = \App\Services\DuffelErrorMapper::fromDuffelException($valEx);
$parts  = \App\Services\DuffelErrorMapper::split($mapped->getMessage());
ok(
    str_contains($parts['customer'], 'validation_required') || str_contains($parts['customer'], 'email'),
    "validation_required fallback shows real Duffel detail (not generic Arabic)"
);

// ── SECTION 5: normalizePhone ─────────────────────────────────────────────────
section('5. normalizePhone');

$phoneTests = [
    '+96599123456'   => '+96599123456',
    '96599123456'    => '+96599123456',
    '+965 9912 3456' => '+96599123456',
    '+44 20 7946 0958' => '+442079460958',
];
foreach ($phoneTests as $input => $expected) {
    $result = callPrivate($svc, 'normalizePhone', [$input]);
    ok($result === $expected, "normalizePhone('$input') === '$expected' (got: '$result')");
}

// ── SECTION 6: Sample complete payload ───────────────────────────────────────
section('6. Final payload example');

$finalPayload = [
    'selected_offers' => ['off_000TEST'],
    'passengers'      => $goodMapped,
    'payments'        => [['type' => 'balance', 'amount' => '250.00', 'currency' => 'GBP']],
    'metadata'        => ['booking_reference' => 'FM00000001', 'platform' => 'flymasar'],
];
echo "\n  Sample Duffel createOrder payload:\n";
echo "  " . str_replace("\n", "\n  ", json_encode($finalPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n";
ok(true, 'payload shown above');

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n\033[1m── Results: {$passed} passed, {$failed} failed ──\033[0m\n\n";
exit($failed > 0 ? 1 : 0);
