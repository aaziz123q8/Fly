<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';

use App\Helpers\Database;

try {
    $db = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, '[OfferCacheCleaner] Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Delete expired offer_cache rows (LIMIT 1000 to avoid table lock)
$stmt1 = $db->query(
    'DELETE FROM offer_cache WHERE expires_at < NOW() ORDER BY expires_at LIMIT 1000'
);
$deleted1 = $stmt1 ? $stmt1->rowCount() : 0;

// Delete expired booking_sessions (not completed)
$stmt2 = $db->query(
    "DELETE FROM booking_sessions WHERE expires_at <= NOW() AND current_step != 'complete'"
);
$deleted2 = $stmt2 ? $stmt2->rowCount() : 0;

// Log result
if ($deleted1 > 0 || $deleted2 > 0) {
    $db->prepare(
        "INSERT INTO error_logs (level, message, context) VALUES ('info', :msg, :ctx)"
    )->execute([
        ':msg' => 'Cron: offer_cache and booking_sessions cleanup',
        ':ctx' => json_encode([
            'offer_cache_deleted'     => $deleted1,
            'booking_sessions_deleted'=> $deleted2,
            'ran_at'                  => date('c'),
        ]),
    ]);
}

echo "Done. Deleted: offer_cache={$deleted1}, booking_sessions={$deleted2}\n";
