<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Middleware\AdminMiddleware;

/**
 * Records administrative actions into the (previously unused) admin_activity_log
 * table so every privileged operation is auditable — who did what, to which
 * entity, from which IP, and when. Fully best-effort: logging never breaks the
 * originating request.
 */
class AdminActivityLog
{
    public static function record(
        string $action,
        string $module,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $description = null
    ): void {
        try {
            $admin = AdminMiddleware::currentAdmin();
            if (!$admin) {
                return;
            }

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
            } else {
                $ip = null;
            }

            Database::getInstance()->prepare(
                'INSERT INTO admin_activity_log
                    (admin_id, action, module, entity_type, entity_id, description, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                (int) $admin['user_id'],
                $action,
                $module,
                $entityType,
                $entityId !== null ? (int) $entityId : null,
                $description,
                $ip,
            ]);
        } catch (\Throwable) {
            // Auditing must never interrupt the primary operation.
        }
    }
}
