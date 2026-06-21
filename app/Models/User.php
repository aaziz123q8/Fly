<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use PDO;

class User
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Find a user by primary key. Returns the row as an array or null.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, phone_country_code, phone_number,
                    is_verified, is_active, email_verified_at, last_login_at, created_at, updated_at
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a user by email address (includes password for verification).
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password, first_name, last_name, phone_country_code, phone_number,
                    is_verified, is_active, email_verified_at, last_login_at, created_at, updated_at
               FROM users
              WHERE email = ?
              LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new user row.
     *
     * @param  array{email: string, password: string, first_name: string, last_name: string,
     *                phone_country_code: string, phone_number: string} $data
     * @return int  The new user's ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password, first_name, last_name, phone_country_code, phone_number)
                  VALUES (:email, :password, :first_name, :last_name, :phone_country_code, :phone_number)'
        );

        $stmt->execute([
            ':email'              => strtolower(trim($data['email'])),
            ':password'           => $data['password'],
            ':first_name'         => $data['first_name'],
            ':last_name'          => $data['last_name'],
            ':phone_country_code' => $data['phone_country_code'],
            ':phone_number'       => $data['phone_number'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update arbitrary columns for a user.
     *
     * @param  array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $setClauses = [];
        $params     = [];

        $allowed = ['first_name', 'last_name', 'phone_country_code', 'phone_number',
                    'is_active', 'is_verified', 'password'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $setClauses[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $params[':id'] = $id;

        $sql  = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stamp last_login_at for a user.
     */
    public function updateLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Mark email as verified.
     */
    public function verifyEmail(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_verified = 1, email_verified_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strip the password field from a user row before returning to the API.
     */
    public static function withoutPassword(array $user): array
    {
        unset($user['password']);
        return $user;
    }
}
