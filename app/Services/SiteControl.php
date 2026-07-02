<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use App\Helpers\Database;

/**
 * Reads the admin-controlled site flags (settings table) that gate public
 * behaviour — currently the "bookings enabled" master switch used to pause new
 * bookings during maintenance. Fails open: any read error leaves bookings ON so
 * a settings glitch can never lock customers out of paying.
 */
class SiteControl
{
    private static function flag(string $key, string $default): string
    {
        try {
            $st = Database::getInstance()->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? $default : (string) $v;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function bookingsEnabled(): bool
    {
        // Only an explicit "0" disables bookings.
        return self::flag('bookings_enabled', '1') !== '0';
    }

    /**
     * Guard for new-booking / payment endpoints. Sends 503 and stops the
     * request when the admin has paused bookings.
     */
    public static function assertBookingsEnabled(): void
    {
        if (!self::bookingsEnabled()) {
            Response::error(
                'الحجز متوقف مؤقتاً للصيانة. يرجى المحاولة لاحقاً.',
                503,
                'bookings_disabled'
            );
        }
    }
}
