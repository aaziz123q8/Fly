<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Models\User;
use PDO;
use RuntimeException;

class AuthService
{
    private PDO   $pdo;
    private User  $userModel;

    /** Session lifetime in seconds (30 days). */
    private const SESSION_TTL = 30 * 24 * 60 * 60;

    /** Max failed attempts per IP before lockout. */
    private const MAX_ATTEMPTS = 5;

    /** Sliding window for attempt tracking (seconds). */
    private const ATTEMPT_WINDOW = 15 * 60;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo       = $pdo ?? Database::getInstance();
        $this->userModel = new User($this->pdo);
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    /**
     * Create a new user account.
     *
     * @throws RuntimeException if the email is already taken.
     * @return array  User data (no password field).
     */
    public function register(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        string $phoneCountryCode,
        string $phoneNumber
    ): array {
        $existing = $this->userModel->findByEmail($email);
        if ($existing !== null) {
            throw new RuntimeException('email_taken', 409);
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $id = $this->userModel->create([
            'email'              => $email,
            'password'           => $hash,
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'phone_country_code' => $phoneCountryCode,
            'phone_number'       => $phoneNumber,
        ]);

        $user = $this->userModel->findById($id);

        return $user !== null ? User::withoutPassword($user) : [];
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    /**
     * Authenticate a user.
     *
     * @throws RuntimeException on invalid credentials, inactive account, or lockout.
     * @return array{user: array, session_token: string, expires_at: string}
     */
    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        // Check IP-based lockout.
        if ($this->isLockedOut($ip, $email)) {
            throw new RuntimeException('too_many_attempts', 429);
        }

        $user = $this->userModel->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password'] ?? '')) {
            $this->recordAttempt($ip, $email, false);
            throw new RuntimeException('invalid_credentials', 401);
        }

        if (!(bool) $user['is_active']) {
            $this->recordAttempt($ip, $email, false);
            throw new RuntimeException('account_inactive', 403);
        }

        // Successful login.
        $this->recordAttempt($ip, $email, true);
        $this->userModel->updateLastLogin($user['id']);

        $token      = bin2hex(random_bytes(32));          // 64-char hex (raw, returned to client)
        $tokenHash  = hash('sha256', $token);             // stored in DB
        $expiresAt  = date('Y-m-d H:i:s', time() + self::SESSION_TTL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                  VALUES (:user_id, :token, :ip, :ua, :expires_at)'
        );
        $stmt->execute([
            ':user_id'    => $user['id'],
            ':token'      => $tokenHash,
            ':ip'         => $ip,
            ':ua'         => $userAgent,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'user'          => User::withoutPassword($user),
            'session_token' => $token,
            'expires_at'    => $expiresAt,
        ];
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------

    public function logout(string $sessionToken): void
    {
        $tokenHash = hash('sha256', $sessionToken);
        $stmt = $this->pdo->prepare('DELETE FROM user_sessions WHERE session_token = ?');
        $stmt->execute([$tokenHash]);
    }

    // -------------------------------------------------------------------------
    // Validate session
    // -------------------------------------------------------------------------

    /**
     * Validate a session token and return the associated user, or null if invalid.
     */
    public function validateSession(string $sessionToken): ?array
    {
        $tokenHash = hash('sha256', $sessionToken);
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.phone_country_code, u.phone_number,
                    u.is_verified, u.is_active, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at
               FROM user_sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.session_token = ?
                AND s.expires_at > NOW()
                AND u.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch();

        if ($user === false) {
            return null;
        }

        // Touch last_active_at.
        $touch = $this->pdo->prepare(
            'UPDATE user_sessions SET last_active_at = NOW() WHERE session_token = ?'
        );
        $touch->execute([$tokenHash]);

        return $user;
    }

    // -------------------------------------------------------------------------
    // Password reset
    // -------------------------------------------------------------------------

    /**
     * Initiate a password reset — returns the raw token (caller sends the email).
     *
     * @throws RuntimeException if no account exists for that email.
     */
    public function initiatePasswordReset(string $email): string
    {
        $user = $this->userModel->findByEmail($email);
        if ($user === null) {
            throw new RuntimeException('email_not_found', 404);
        }

        $token     = bin2hex(random_bytes(32));          // raw token returned to caller for email link
        $tokenHash = hash('sha256', $token);              // hash stored in DB
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$user['id'], $tokenHash, $expiresAt]);

        return $token;
    }

    /**
     * Complete a password reset using a valid token.
     *
     * @throws RuntimeException if the token is invalid or expired.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM password_resets
              WHERE token = ?
                AND expires_at > NOW()
                AND used_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch();

        if ($reset === false) {
            throw new RuntimeException('invalid_or_expired_token', 400);
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $this->userModel->update((int) $reset['user_id'], ['password' => $hash]);

        // Mark token as used.
        $markUsed = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE id = ?'
        );
        $markUsed->execute([$reset['id']]);

        // Invalidate all sessions for security.
        $deleteSessions = $this->pdo->prepare(
            'DELETE FROM user_sessions WHERE user_id = ?'
        );
        $deleteSessions->execute([$reset['user_id']]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function isLockedOut(string $ip, string $email): bool
    {
        $windowStart = date('Y-m-d H:i:s', time() - self::ATTEMPT_WINDOW);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ?
                AND email = ?
                AND succeeded = 0
                AND attempted_at >= ?'
        );
        $stmt->execute([$ip, strtolower(trim($email)), $windowStart]);
        $count = (int) $stmt->fetchColumn();

        return $count >= self::MAX_ATTEMPTS;
    }

    private function recordAttempt(string $ip, string $email, bool $succeeded): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, email, succeeded) VALUES (?, ?, ?)'
        );
        $stmt->execute([$ip, strtolower(trim($email)), $succeeded ? 1 : 0]);
    }
}
