<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

class RateLimiter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Check whether the identifier is currently blocked.
     *
     * @param  string $identifier   IP address, user ID, or API key value.
     * @param  string $identifierType 'ip' | 'user' | 'api_key'
     * @param  string $endpoint     Route/endpoint label (e.g. 'POST /api/search').
     */
    public function isBlocked(string $identifier, string $identifierType, string $endpoint): bool
    {
        $stmt = $this->db->prepare(
            'SELECT blocked_until FROM rate_limits
             WHERE identifier = :id AND identifier_type = :type AND endpoint = :ep
               AND blocked_until IS NOT NULL AND blocked_until > NOW()
             LIMIT 1'
        );
        $stmt->execute([':id' => $identifier, ':type' => $identifierType, ':ep' => $endpoint]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Increment the request counter for the current 1-minute window.
     * Returns the updated request count.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic increment.
     *
     * @param  string $identifier
     * @param  string $identifierType
     * @param  string $endpoint
     * @param  int    $limit          Max requests allowed per window.
     * @param  int    $windowSeconds  Window size in seconds (default 60).
     * @param  int    $blockSeconds   How long to block after exceeding limit (default 300).
     */
    public function increment(
        string $identifier,
        string $identifierType,
        string $endpoint,
        int $limit,
        int $windowSeconds = 60,
        int $blockSeconds = 300
    ): int {
        // Truncate the current timestamp to the window boundary.
        $windowStart = date('Y-m-d H:i:s', (int)(time() / $windowSeconds) * $windowSeconds);

        $stmt = $this->db->prepare(
            'INSERT INTO rate_limits
               (identifier, identifier_type, endpoint, window_start, request_count)
             VALUES
               (:id, :type, :ep, :ws, 1)
             ON DUPLICATE KEY UPDATE
               request_count = request_count + 1,
               updated_at    = NOW()'
        );
        $stmt->execute([
            ':id'   => $identifier,
            ':type' => $identifierType,
            ':ep'   => $endpoint,
            ':ws'   => $windowStart,
        ]);

        // Fetch the current count.
        $countStmt = $this->db->prepare(
            'SELECT request_count FROM rate_limits
             WHERE identifier = :id AND identifier_type = :type
               AND endpoint = :ep AND window_start = :ws
             LIMIT 1'
        );
        $countStmt->execute([
            ':id'   => $identifier,
            ':type' => $identifierType,
            ':ep'   => $endpoint,
            ':ws'   => $windowStart,
        ]);
        $count = (int)($countStmt->fetchColumn() ?: 1);

        // If over limit, set blocked_until.
        if ($count >= $limit) {
            $blockedUntil = date('Y-m-d H:i:s', time() + $blockSeconds);
            $blockStmt = $this->db->prepare(
                'UPDATE rate_limits
                 SET blocked_until = :bu, updated_at = NOW()
                 WHERE identifier = :id AND identifier_type = :type
                   AND endpoint = :ep AND window_start = :ws'
            );
            $blockStmt->execute([
                ':bu'   => $blockedUntil,
                ':id'   => $identifier,
                ':type' => $identifierType,
                ':ep'   => $endpoint,
                ':ws'   => $windowStart,
            ]);
        }

        return $count;
    }

    /**
     * Convenience: check + increment in one call.
     * Returns true if the request should be allowed, false if rate-limited.
     */
    public function allow(
        string $identifier,
        string $identifierType,
        string $endpoint,
        int $limit,
        int $windowSeconds = 60,
        int $blockSeconds = 300
    ): bool {
        if ($this->isBlocked($identifier, $identifierType, $endpoint)) {
            return false;
        }

        $count = $this->increment($identifier, $identifierType, $endpoint, $limit, $windowSeconds, $blockSeconds);

        return $count <= $limit;
    }
}
