<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\SecurityHelper;
use PDO;

class BookingSessionService
{
    private PDO $db;

    /** Session TTL in seconds (default 30 minutes). */
    private int $ttl;

    public function __construct(?PDO $db = null, int $ttl = 1800)
    {
        $this->db  = $db ?? Database::getInstance();
        $this->ttl = $ttl;
    }

    /**
     * Create a new booking session and return its session key.
     *
     * @param  int    $userId
     * @param  string $bookingType 'flight' | 'hotel'
     * @return string The session key (opaque token to hand to the client).
     */
    public function create(int $userId, string $bookingType = 'flight'): string
    {
        $sessionKey = SecurityHelper::generateToken(32); // 64-char hex
        $expiresAt  = date('Y-m-d H:i:s', time() + $this->ttl);

        $stmt = $this->db->prepare(
            'INSERT INTO booking_sessions
               (session_key, user_id, booking_type, current_step, expires_at)
             VALUES
               (:sk, :uid, :bt, :step, :exp)'
        );
        $stmt->execute([
            ':sk'   => $sessionKey,
            ':uid'  => $userId,
            ':bt'   => $bookingType,
            ':step' => 'search',
            ':exp'  => $expiresAt,
        ]);

        return $sessionKey;
    }

    /**
     * Retrieve an active (non-expired) booking session by its key.
     *
     * @return array|null Session row, or null if not found / expired.
     */
    public function get(string $sessionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM booking_sessions
             WHERE session_key = :sk AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':sk' => $sessionKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Update fields on an existing booking session.
     *
     * Allowed update fields (all optional):
     *   current_step, provider_offer_id, offer_expires_at, prebook_session_id,
     *   passengers_data, services_data, guests_data, pricing_snapshot,
     *   coupon_code, payment_intent_id, idempotency_key
     *
     * JSON array values are automatically encoded.
     *
     * @param  string $sessionKey
     * @param  array  $fields
     * @return bool   True if a row was updated.
     * @throws \InvalidArgumentException on unknown field names.
     */
    public function update(string $sessionKey, array $fields): bool
    {
        $allowed = [
            'current_step', 'provider_offer_id', 'offer_expires_at',
            'prebook_session_id', 'passengers_data', 'services_data',
            'guests_data', 'pricing_snapshot', 'coupon_code',
            'payment_intent_id', 'idempotency_key',
        ];

        $jsonFields = ['passengers_data', 'services_data', 'guests_data', 'pricing_snapshot'];

        $sets   = [];
        $params = [':sk' => $sessionKey];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown booking session field: {$key}");
            }

            if (in_array($key, $jsonFields, true) && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $placeholder    = ':f_' . $key;
            $sets[]         = "`{$key}` = {$placeholder}";
            $params[$placeholder] = $value;
        }

        if (empty($sets)) {
            return false;
        }

        // Also refresh expiry on every update.
        $newExpiry          = date('Y-m-d H:i:s', time() + $this->ttl);
        $sets[]             = '`expires_at` = :new_exp';
        $params[':new_exp'] = $newExpiry;

        $sql  = 'UPDATE booking_sessions SET ' . implode(', ', $sets) . ' WHERE session_key = :sk AND expires_at > NOW()';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Advance the booking step (search → passengers → services → payment → confirmed).
     */
    public function advanceStep(string $sessionKey, string $newStep): bool
    {
        return $this->update($sessionKey, ['current_step' => $newStep]);
    }

    /**
     * Expire (delete) a session immediately.
     */
    public function expire(string $sessionKey): void
    {
        $stmt = $this->db->prepare('DELETE FROM booking_sessions WHERE session_key = :sk');
        $stmt->execute([':sk' => $sessionKey]);
    }

    /**
     * Purge all expired sessions (run via cron or job queue).
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $stmt = $this->db->query('DELETE FROM booking_sessions WHERE expires_at <= NOW()');
        return $stmt ? (int)$stmt->rowCount() : 0;
    }
}
