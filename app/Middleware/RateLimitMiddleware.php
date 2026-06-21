<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\RateLimiter;

class RateLimitMiddleware
{
    private RateLimiter $limiter;

    /**
     * Per-endpoint configuration:
     * 'endpoint_key' => ['limit' => N, 'window' => seconds, 'block' => seconds]
     */
    private array $config;

    public function __construct(?RateLimiter $limiter = null, array $config = [])
    {
        $this->limiter = $limiter ?? new RateLimiter();

        // Sensible defaults for common endpoints.
        $this->config = array_merge([
            'default'           => ['limit' => 120, 'window' => 60,  'block' => 60],
            'POST /api/search'  => ['limit' => 30,  'window' => 60,  'block' => 120],
            'POST /api/login'   => ['limit' => 10,  'window' => 60,  'block' => 300],
            'POST /api/booking' => ['limit' => 10,  'window' => 60,  'block' => 300],
            'POST /api/payment' => ['limit' => 5,   'window' => 60,  'block' => 600],
        ], $config);
    }

    /**
     * Apply rate limiting.
     * Sends a 429 response and exits if the request is blocked.
     *
     * @param  string      $endpoint       Canonical endpoint label.
     * @param  string|null $userId         Authenticated user ID (if any).
     * @param  string|null $ipAddress      Client IP (falls back to $_SERVER).
     */
    public function handle(
        string $endpoint,
        ?string $userId = null,
        ?string $ipAddress = null
    ): void {
        $ip = $ipAddress ?? $this->resolveIp();

        // Prefer user-based limiting for authenticated requests.
        if ($userId !== null) {
            $identifier     = $userId;
            $identifierType = 'user';
        } else {
            $identifier     = $ip;
            $identifierType = 'ip';
        }

        $cfg = $this->config[$endpoint] ?? $this->config['default'];

        $allowed = $this->limiter->allow(
            $identifier,
            $identifierType,
            $endpoint,
            $cfg['limit'],
            $cfg['window'],
            $cfg['block']
        );

        if (!$allowed) {
            $this->sendTooManyRequests();
        }
    }

    // -------------------------------------------------------------------------

    private function resolveIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                // Take the first IP in a comma-separated list.
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }

    private function sendTooManyRequests(): never
    {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        header('Retry-After: 60');
        echo json_encode([
            'error'   => 'too_many_requests',
            'message' => 'Rate limit exceeded. Please try again later.',
        ]);
        exit;
    }
}
