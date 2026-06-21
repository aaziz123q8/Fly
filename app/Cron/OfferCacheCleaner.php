<?php

declare(strict_types=1);

/**
 * OfferCacheCleaner — standalone cron script.
 *
 * Deletes expired offer_cache and booking_sessions rows.
 * Schedule via Hostinger hPanel Cron Jobs: every 5 minutes.
 *   Command: php /path/to/app/Cron/OfferCacheCleaner.php
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class) && file_exists(BASE_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();
}

use App\Helpers\Database;

$db = Database::getInstance();

$startedAt = date('Y-m-d H:i:s');
$results   = [];

// ---------------------------------------------------------------------------
// 1. Clean expired offer_cache rows (batch of 1 000)
// ---------------------------------------------------------------------------

try {
    $stmt = $db->prepare(
        'DELETE FROM offer_cache
         WHERE expires_at < NOW()
         ORDER BY expires_at ASC
         LIMIT 1000'
    );
    $stmt->execute();
    $deletedOffers = $stmt->rowCount();
    $results[]     = "offer_cache: deleted {$deletedOffers} expired rows.";
} catch (\Throwable $e) {
    $results[] = 'offer_cache cleanup error: ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// 2. Clean expired booking_sessions (exclude completed sessions)
// ---------------------------------------------------------------------------

try {
    $stmt = $db->prepare(
        "DELETE FROM booking_sessions
         WHERE expires_at <= NOW()
           AND current_step != 'complete'"
    );
    $stmt->execute();
    $deletedSessions = $stmt->rowCount();
    $results[]       = "booking_sessions: deleted {$deletedSessions} expired rows.";
} catch (\Throwable $e) {
    $results[] = 'booking_sessions cleanup error: ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// 3. Log results to error_logs table
// ---------------------------------------------------------------------------

$message = implode(' | ', $results);

try {
    $logStmt = $db->prepare(
        "INSERT INTO error_logs (level, message, context, created_at)
         VALUES ('info', :msg, :ctx, NOW())"
    );
    $logStmt->execute([
        ':msg' => '[OfferCacheCleaner] ' . $message,
        ':ctx' => json_encode(['started_at' => $startedAt, 'results' => $results]),
    ]);
} catch (\Throwable) {
    // Fallback: write to stderr if DB log fails.
    fwrite(STDERR, '[OfferCacheCleaner] ' . $message . PHP_EOL);
}

echo '[OfferCacheCleaner] ' . $message . PHP_EOL;
exit(0);
