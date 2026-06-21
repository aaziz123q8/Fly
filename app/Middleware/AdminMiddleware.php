<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminMiddleware
{
    private static ?array $currentAdmin = null;

    public static function handle(): callable
    {
        return function (Request $request, callable $next): void {
            $token = $request->bearerToken();

            if ($token === null || $token === '') {
                Response::unauthorized('Admin authentication required.');
            }

            $tokenHash = hash('sha256', $token);
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT s.*, u.email, u.first_name, u.last_name, u.role
                 FROM admin_sessions s
                 JOIN users u ON u.id = s.user_id
                 WHERE s.token = ?
                   AND s.expires_at > NOW()
                   AND u.role IN ("admin","super_admin")
                   AND u.is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([$tokenHash]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$session) {
                Response::unauthorized('Invalid or expired admin session.');
            }

            $db->prepare('UPDATE admin_sessions SET last_active_at = NOW() WHERE id = ?')
               ->execute([(int)$session['id']]);

            self::$currentAdmin = $session;
            $next($request);
        };
    }

    public static function currentAdmin(): ?array
    {
        return self::$currentAdmin;
    }

    public static function reset(): void
    {
        self::$currentAdmin = null;
    }
}
