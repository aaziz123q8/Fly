<?php
/**
 * FLYMASAR ONE-TIME FIX SCRIPT
 * Upload to: public_html/fix_deploy.php
 * Visit:     https://your-domain.com/fix_deploy.php?key=Alfezair65fix
 * DELETE THIS FILE after running it successfully.
 */

declare(strict_types=1);

// ── Security key ─────────────────────────────────────────────────────────────
if (($_GET['key'] ?? '') !== 'Alfezair65fix') {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/html; charset=utf-8');
echo '<html><head><meta charset="utf-8"><style>
body{font-family:monospace;padding:20px;background:#111;color:#0f0}
.ok{color:#0f0}.err{color:#f44}.warn{color:#fa0}.info{color:#08f}
pre{white-space:pre-wrap;word-break:break-all}
</style></head><body>';
echo '<h2>🛠 Flymasar Fix Script</h2><pre>';

// ── Load database config ──────────────────────────────────────────────────────
$configFile = dirname(__DIR__) . '/config/database.php';
$altConfig  = __DIR__          . '/../config/database.php';

if (file_exists($configFile)) {
    $cfg = require $configFile;
} elseif (file_exists($altConfig)) {
    $cfg = require $altConfig;
} else {
    $cfg = [
        'host'     => getenv('DB_HOST')     ?: 'localhost',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'dbname'   => getenv('DB_NAME')     ?: '',
        'username' => getenv('DB_USER')     ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
    ];
}

echo '<span class="info">DB host: ' . htmlspecialchars($cfg['host']) . ' | db: ' . htmlspecialchars($cfg['dbname']) . '</span>' . PHP_EOL;

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $dsn = "mysql:host={$cfg['host']};port=" . ($cfg['port'] ?? 3306)
         . ";dbname={$cfg['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo '<span class="ok">✓ Database connected</span>' . PHP_EOL . PHP_EOL;
} catch (\Throwable $e) {
    echo '<span class="err">✗ DB connection failed: ' . htmlspecialchars($e->getMessage()) . '</span>';
    echo '</pre></body></html>';
    exit;
}

// ── Migration 075 SQL statements ──────────────────────────────────────────────
$statements = [
    // providers seed — Duffel provider must exist for FK on flight_bookings
    "INSERT IGNORE INTO providers (id, name, type, is_active) VALUES (1, 'Duffel', 'flight', 1)",
    // flight_bookings columns
    "ALTER TABLE flight_bookings ADD COLUMN provider_offer_id            VARCHAR(100)  NULL          COMMENT 'Duffel offer ID'",
    "ALTER TABLE flight_bookings ADD COLUMN pending_cancellation_id      VARCHAR(100)  NULL          COMMENT 'Duffel cancellation ID pending'",
    "ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_amount   DECIMAL(12,2) NULL          COMMENT 'Refund amount from Duffel'",
    "ALTER TABLE flight_bookings ADD COLUMN duffel_booking_reference     VARCHAR(30)   NULL          COMMENT 'Airline PNR from Duffel'",
    "ALTER TABLE flight_bookings ADD COLUMN paid_at                      DATETIME      NULL          COMMENT 'payment_status.paid_at'",
    "ALTER TABLE flight_bookings ADD COLUMN payment_required_by          DATETIME      NULL          COMMENT 'booking expires at'",
    "ALTER TABLE flight_bookings ADD COLUMN price_guarantee_expires_at   DATETIME      NULL          COMMENT 'price guarantee deadline'",
    "ALTER TABLE flight_bookings ADD COLUMN void_window_ends_at          DATETIME      NULL          COMMENT 'free-cancel deadline'",
    "ALTER TABLE flight_bookings ADD COLUMN available_actions            JSON          NULL          COMMENT 'order.available_actions'",
    "ALTER TABLE flight_bookings ADD COLUMN synced_at                    DATETIME      NULL          COMMENT 'Last Duffel sync'",
    "ALTER TABLE flight_bookings ADD COLUMN live_mode                    TINYINT(1)    NOT NULL DEFAULT 1 COMMENT 'live vs test'",
    "ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_to       VARCHAR(60)   NULL          COMMENT 'refund_to from Duffel'",
    "ALTER TABLE flight_bookings ADD COLUMN cancellation_expires_at      DATETIME      NULL          COMMENT 'cancellation quote expiry'",
    "ALTER TABLE flight_bookings ADD COLUMN refund_conditions            JSON          NULL          COMMENT 'refund conditions'",
    "ALTER TABLE flight_bookings ADD COLUMN change_conditions            JSON          NULL          COMMENT 'change conditions'",
    "ALTER TABLE flight_bookings ADD COLUMN awaiting_payment             TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'awaiting payment flag'",
    "ALTER TABLE flight_bookings ADD COLUMN duffel_payment_failure       VARCHAR(500)  NULL          COMMENT 'Duffel payment failure message'",
    "ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_currency CHAR(3)       NULL          COMMENT 'currency of refund'",
    "ALTER TABLE flight_bookings ADD COLUMN auto_refunded_at             DATETIME      NULL          COMMENT 'auto-refund timestamp'",
    "ALTER TABLE flight_bookings ADD COLUMN booking_references           JSON          NULL          COMMENT 'all PNRs from Duffel'",
    "ALTER TABLE flight_bookings ADD COLUMN cancellation_confirmed_at    DATETIME      NULL          COMMENT 'cancellation confirmed timestamp'",
    // flight_bookings ENUM
    "ALTER TABLE flight_bookings MODIFY COLUMN status ENUM('pending','confirmed','cancelled','changed','awaiting_payment','failed') NOT NULL DEFAULT 'pending'",
    // flight_bookings indexes
    "CREATE INDEX idx_payment_deadline   ON flight_bookings (payment_required_by)",
    "CREATE INDEX idx_duffel_booking_ref ON flight_bookings (duffel_booking_reference)",
    "CREATE INDEX idx_synced_at          ON flight_bookings (synced_at)",
    // payments columns
    "ALTER TABLE payments ADD COLUMN stripe_refund_id         VARCHAR(200)  NULL              COMMENT 'Stripe refund ID'",
    "ALTER TABLE payments ADD COLUMN duffel_payment_id        VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Duffel payment ID'",
    "ALTER TABLE payments ADD COLUMN payment_type             ENUM('stripe','duffel_balance','duffel_card') NOT NULL DEFAULT 'stripe' COMMENT 'payment processor'",
    "ALTER TABLE payments ADD COLUMN duffel_payment_failure   VARCHAR(500)  NULL              COMMENT 'Duffel payment failure'",
    "ALTER TABLE payments ADD COLUMN auto_refund_reason       VARCHAR(255)  NULL              COMMENT 'auto refund reason'",
    "ALTER TABLE payments ADD COLUMN auto_refunded_at         DATETIME      NULL              COMMENT 'auto refund timestamp'",
    "ALTER TABLE payments ADD COLUMN updated_at               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'last update'",
    // payments ENUM
    "ALTER TABLE payments MODIFY COLUMN status ENUM('pending','succeeded','failed','refunded','cancelled','refund_pending','refund_failed','refund_pending_manual') NOT NULL DEFAULT 'pending'",
    // payments index
    "CREATE INDEX idx_payments_booking_status ON payments (booking_type, booking_id, status)",
    // booking_sessions columns
    "ALTER TABLE booking_sessions ADD COLUMN device_ip          VARCHAR(45)   NULL COMMENT 'client IP'",
    "ALTER TABLE booking_sessions ADD COLUMN device_user_agent  VARCHAR(500)  NULL COMMENT 'client user-agent'",
    "ALTER TABLE booking_sessions ADD COLUMN total_amount       DECIMAL(10,2) NULL COMMENT 'total with services'",
    // flight_booking_documents table
    "CREATE TABLE IF NOT EXISTS flight_booking_documents (
        id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        booking_id        INT UNSIGNED  NOT NULL,
        document_type     VARCHAR(50)   NOT NULL,
        unique_identifier VARCHAR(50)   NOT NULL,
        passenger_ids     JSON          NULL,
        created_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
        PRIMARY KEY (id),
        UNIQUE KEY uq_booking_doc (booking_id, unique_identifier),
        KEY idx_booking (booking_id),
        FOREIGN KEY (booking_id) REFERENCES flight_bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$ok   = 0;
$skip = 0;
$fail = 0;

echo '<b>── Running migration 075 ──────────────────────────────</b>' . PHP_EOL;

foreach ($statements as $sql) {
    // Extract short label for display
    $label = preg_replace('/\s+/', ' ', substr(trim($sql), 0, 80));
    try {
        $pdo->exec($sql);
        echo '<span class="ok">✓</span> ' . htmlspecialchars($label) . PHP_EOL;
        $ok++;
    } catch (\PDOException $e) {
        $code = (int)$e->getCode();
        $msg  = $e->getMessage();
        // 1060 = Duplicate column name, 1061 = Duplicate key name, 1050 = Table already exists
        if (str_contains($msg, 'Duplicate column')
            || str_contains($msg, 'Duplicate key')
            || str_contains($msg, 'already exists')) {
            echo '<span class="warn">↷ (already exists, skipped)</span> ' . htmlspecialchars($label) . PHP_EOL;
            $skip++;
        } else {
            echo '<span class="err">✗ ' . htmlspecialchars($msg) . '</span>' . PHP_EOL;
            echo '  SQL: ' . htmlspecialchars($label) . PHP_EOL;
            $fail++;
        }
    }
}

echo PHP_EOL . '<b>── Migration result: '
    . '<span class="ok">' . $ok . ' applied</span>, '
    . '<span class="warn">' . $skip . ' skipped (already existed)</span>, '
    . '<span class="err">' . $fail . ' failed</span></b>' . PHP_EOL . PHP_EOL;

// ── Verify critical columns ───────────────────────────────────────────────────
echo '<b>── Verifying critical columns ────────────────────────</b>' . PHP_EOL;

// Verify providers row
$provRow = $pdo->query("SELECT id FROM providers WHERE id = 1")->fetch();
if ($provRow) {
    echo '<span class="ok">✓</span> providers row id=1 (Duffel)' . PHP_EOL;
} else {
    echo '<span class="err">✗ MISSING: providers row id=1</span>' . PHP_EOL;
    $allOk = false;
}

$checks = [
    ['flight_bookings',  'duffel_booking_reference'],
    ['flight_bookings',  'provider_offer_id'],
    ['flight_bookings',  'paid_at'],
    ['flight_bookings',  'payment_required_by'],
    ['flight_bookings',  'available_actions'],
    ['flight_bookings',  'awaiting_payment'],
    ['flight_bookings',  'cancellation_refund_currency'],
    ['flight_bookings',  'cancellation_confirmed_at'],
    ['payments',         'stripe_refund_id'],
    ['payments',         'duffel_payment_failure'],
    ['payments',         'auto_refund_reason'],
    ['payments',         'updated_at'],
    ['booking_sessions', 'device_ip'],
    ['booking_sessions', 'total_amount'],
];

$allOk = true;
foreach ($checks as [$table, $col]) {
    $row = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetch();
    if ($row) {
        echo '<span class="ok">✓</span> ' . $table . '.' . $col . PHP_EOL;
    } else {
        echo '<span class="err">✗ MISSING: ' . $table . '.' . $col . '</span>' . PHP_EOL;
        $allOk = false;
    }
}

// Check flight_booking_documents table
$row = $pdo->query("SHOW TABLES LIKE 'flight_booking_documents'")->fetch();
if ($row) {
    echo '<span class="ok">✓</span> flight_booking_documents table' . PHP_EOL;
} else {
    echo '<span class="err">✗ MISSING: flight_booking_documents table</span>' . PHP_EOL;
    $allOk = false;
}

echo PHP_EOL;
if ($allOk) {
    echo '<span class="ok" style="font-size:1.4em">✅ ALL CHECKS PASSED — Migration complete!</span>' . PHP_EOL;
    echo '<span class="ok">🗑  DELETE this file now: public_html/fix_deploy.php</span>' . PHP_EOL;
} else {
    echo '<span class="err">⚠ Some columns still missing — check errors above.</span>' . PHP_EOL;
}

echo '</pre></body></html>';
