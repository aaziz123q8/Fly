<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

class AuthMiddleware
{
    /**
     * Stores the authenticated user for the current request lifecycle.
     */
    private static ?array $currentUser = null;

    // -------------------------------------------------------------------------
    // Middleware callable
    // -------------------------------------------------------------------------

    /**
     * Returns a middleware callable suitable for the Router's middleware stack.
     *
     * Usage:
     *   $router->post('/api/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::handle(...)]);
     */
    public static function handle(?AuthService $authService = null): callable
    {
        return function (Request $request, callable $next) use ($authService): void {
            $svc = $authService ?? new AuthService();
            $token = $request->bearerToken();

            if ($token === null || $token === '') {
                Response::unauthorized('No authentication token provided.');
            }

            $user = $svc->validateSession($token);

            if ($user === null) {
                Response::unauthorized('Invalid or expired session token.');
            }

            self::$currentUser = $user;

            $next($request);
        };
    }

    // -------------------------------------------------------------------------
    // Current user accessor
    // -------------------------------------------------------------------------

    /**
     * Return the authenticated user for the current request, or null.
     */
    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }

    /**
     * Reset for testing purposes.
     */
    public static function reset(): void
    {
        self::$currentUser = null;
    }
}
