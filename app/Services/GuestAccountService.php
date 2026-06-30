<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;

/**
 * GuestAccountService
 *
 * Upgrades a guest account (is_guest = 1) to a real one after their first
 * confirmed booking: generates a password (first 3 letters of the first name +
 * last 4 phone digits, random fallback), stores it, clears the guest flag, and
 * emails the welcome credentials. Shared by flight and hotel checkout.
 *
 * Requires the users.is_guest column (migration 080). If it is missing the
 * SELECT throws and the caller treats this as non-fatal.
 */
class GuestAccountService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function upgrade(int $userId, string $bookingRef): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, first_name, last_name, phone_number, is_guest
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || empty($userRow['is_guest'])) {
            return; // Not a guest — nothing to do.
        }

        $email     = $userRow['email']        ?? '';
        $firstName = $userRow['first_name']   ?? '';
        $phone     = $userRow['phone_number'] ?? '';
        if (!$email) {
            return;
        }

        // Password: first 3 letters of the name + last 4 phone digits.
        $namePart    = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $firstName), 0, 3));
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $phonePart   = strlen($phoneDigits) >= 4 ? substr($phoneDigits, -4) : '';

        $plainPassword = (strlen($namePart) >= 2 && strlen($phonePart) === 4)
            ? $namePart . $phonePart
            : substr(bin2hex(random_bytes(5)), 0, 8);

        $hash = password_hash($plainPassword, PASSWORD_ARGON2ID);
        $this->db->prepare('UPDATE users SET password = :pwd, is_guest = 0 WHERE id = :id')
                 ->execute([':pwd' => $hash, ':id' => $userId]);

        $name = trim($firstName . ' ' . ($userRow['last_name'] ?? ''));
        (new EmailNotificationService($this->db))
            ->sendWelcomeGuestEmail($email, $name ?: $email, $plainPassword, $bookingRef);
    }
}
