<?php
// ONE-TIME SETUP SCRIPT — DELETE AFTER USE
// Runs all missing migrations to create new tables

$cfg = require __DIR__ . '/config/database.php';
try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $results = [];

    // ── 1. Fix offer_cache if missing ──────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_cache (
      id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      offer_request_id VARCHAR(100)    NOT NULL DEFAULT '',
      offer_id         VARCHAR(100)    NOT NULL,
      provider_id      INT             NOT NULL DEFAULT 1,
      search_hash      CHAR(32)        NOT NULL,
      offer_data       LONGTEXT        NOT NULL,
      total_amount     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
      currency         CHAR(3)         NOT NULL DEFAULT 'GBP',
      expires_at       DATETIME        NOT NULL,
      created_at       TIMESTAMP       NOT NULL DEFAULT NOW(),
      PRIMARY KEY (id),
      UNIQUE KEY uq_offer (offer_id),
      KEY idx_hash_exp (search_hash, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ offer_cache — OK';

    // ── 2. commissions ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS commissions (
      id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      type             ENUM('flight','hotel','all') NOT NULL DEFAULT 'flight',
      name             VARCHAR(200) NOT NULL,
      commission_type  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
      commission_value DECIMAL(10,4) NOT NULL DEFAULT 0,
      applies_to       VARCHAR(50) NOT NULL DEFAULT 'all',
      condition_value  VARCHAR(200) NULL,
      is_active        TINYINT(1) NOT NULL DEFAULT 1,
      created_at       TIMESTAMP NOT NULL DEFAULT NOW(),
      updated_at       TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ commissions — OK';

    // ── 3. currencies ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS currencies (
      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code         CHAR(3) NOT NULL,
      name         VARCHAR(100) NOT NULL,
      symbol       VARCHAR(10) NULL,
      rate_to_gbp  DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
      is_active    TINYINT(1) NOT NULL DEFAULT 1,
      updated_at   TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
      PRIMARY KEY (id),
      UNIQUE KEY uq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default currencies
    $pdo->exec("INSERT IGNORE INTO currencies (code, name, symbol, rate_to_gbp, is_active) VALUES
      ('GBP', 'الجنيه الإسترليني',  '£',   1.000000, 1),
      ('USD', 'الدولار الأمريكي',    '\$',   1.270000, 1),
      ('KWD', 'الدينار الكويتي',     'ك.د', 0.391000, 1),
      ('SAR', 'الريال السعودي',      'ر.س', 4.765000, 1),
      ('AED', 'الدرهم الإماراتي',    'د.إ', 4.665000, 1),
      ('EUR', 'اليورو',              '€',   1.175000, 1)");
    $results[] = '✅ currencies — OK (seeded GBP/USD/KWD/SAR/AED/EUR)';

    // ── 4. api_settings ────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_settings (
      id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      provider      VARCHAR(50) NOT NULL,
      setting_key   VARCHAR(100) NOT NULL,
      setting_value TEXT NULL,
      is_secret     TINYINT(1) NOT NULL DEFAULT 0,
      updated_at    TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
      PRIMARY KEY (id),
      UNIQUE KEY uq_provider_key (provider, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ api_settings — OK';

    // ── 5. payments ────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
      id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id                   BIGINT UNSIGNED NULL,
      stripe_payment_intent_id  VARCHAR(200) NULL,
      amount                    DECIMAL(12,2) NOT NULL DEFAULT 0,
      currency                  CHAR(3) NOT NULL DEFAULT 'GBP',
      status                    ENUM('pending','processing','succeeded','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
      booking_type              ENUM('flight','hotel') NULL,
      booking_id                BIGINT UNSIGNED NULL,
      metadata                  JSON NULL,
      created_at                TIMESTAMP NOT NULL DEFAULT NOW(),
      updated_at                TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
      PRIMARY KEY (id),
      KEY idx_user (user_id),
      KEY idx_intent (stripe_payment_intent_id),
      KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ payments — OK';

    // ── 6. support_tickets ─────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
      id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id    BIGINT UNSIGNED NULL,
      subject    VARCHAR(300) NOT NULL,
      body       TEXT NULL,
      status     ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
      priority   ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
      created_at TIMESTAMP NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
      PRIMARY KEY (id),
      KEY idx_user (user_id),
      KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ support_tickets — OK';

    // ── 7. support_replies ─────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_replies (
      id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ticket_id  BIGINT UNSIGNED NOT NULL,
      user_id    BIGINT UNSIGNED NULL,
      message    TEXT NOT NULL,
      is_admin   TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT NOW(),
      PRIMARY KEY (id),
      KEY idx_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ support_replies — OK';

    // ── 8. Check travelers blueprint columns ───────────────────────────────
    $cols = $pdo->query("SHOW COLUMNS FROM travelers")->fetchAll(PDO::FETCH_COLUMN);
    $needed = ['middle_name','gender','nationality','country','phone_country_code','phone_number','document_type','document_number','issue_date','expiry_date'];
    $missing = array_diff($needed, $cols);
    if (!empty($missing)) {
        $alters = [];
        if (in_array('middle_name', $missing))       $alters[] = "ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name";
        if (in_array('gender', $missing))            $alters[] = "ADD COLUMN gender ENUM('male','female','other') NULL";
        if (in_array('nationality', $missing))       $alters[] = "ADD COLUMN nationality VARCHAR(60) NULL";
        if (in_array('country', $missing))           $alters[] = "ADD COLUMN country VARCHAR(60) NULL";
        if (in_array('phone_country_code', $missing))$alters[] = "ADD COLUMN phone_country_code VARCHAR(10) NULL";
        if (in_array('phone_number', $missing))      $alters[] = "ADD COLUMN phone_number VARCHAR(30) NULL";
        if (in_array('document_type', $missing))     $alters[] = "ADD COLUMN document_type ENUM('passport','national_id','residence_permit','other') NULL";
        if (in_array('document_number', $missing))   $alters[] = "ADD COLUMN document_number VARCHAR(50) NULL";
        if (in_array('issue_date', $missing))        $alters[] = "ADD COLUMN issue_date DATE NULL";
        if (in_array('expiry_date', $missing))       $alters[] = "ADD COLUMN expiry_date DATE NULL";
        if (!empty($alters)) {
            $pdo->exec("ALTER TABLE travelers " . implode(', ', $alters));
        }
        $results[] = '✅ travelers — added missing blueprint columns: ' . implode(', ', $missing);
    } else {
        $results[] = '✅ travelers — all blueprint columns present';
    }

    echo "<html dir='rtl'><head><meta charset='UTF-8'><title>Setup</title><style>body{font-family:Arial;padding:30px;background:#f5f7fa}h2{color:#22c55e}.card{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.08)}.item{padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:.9rem}.danger{color:#ef4444;font-weight:700}</style></head><body>";
    echo "<h2>✅ تم تثبيت الجداول بنجاح!</h2>";
    echo "<div class='card'>";
    foreach ($results as $r) {
        echo "<div class='item'>" . htmlspecialchars($r) . "</div>";
    }
    echo "</div>";
    echo "<p class='danger'>⚠️ احذف هذا الملف الآن: <code>rm setup_tables.php</code></p>";
    echo "<p><a href='admin/api-settings.html'>← إعدادات API</a> &nbsp; <a href='index.html'>← الرئيسية</a></p>";
    echo "</body></html>";

} catch (Exception $e) {
    echo "<p style='color:red;font-family:monospace'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
