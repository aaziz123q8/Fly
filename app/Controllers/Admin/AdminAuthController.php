<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;

class AdminAuthController
{
    // -------------------------------------------------------------------------
    // POST /api/admin/auth/login
    // -------------------------------------------------------------------------

    public function login(Request $request): void
    {
        $errors = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $email    = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, email, password, first_name, last_name, role, is_active
             FROM users
             WHERE email = ? AND role IN ("admin","super_admin")
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            Response::error('Invalid email or password.', 401, 'invalid_credentials');
        }

        if (!$user['is_active']) {
            Response::error('Your account has been deactivated.', 403, 'account_inactive');
        }

        // Create admin session (30-day TTL).
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db->prepare(
            'INSERT INTO admin_sessions (user_id, token, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $token,
            $request->ip(),
            $request->userAgent(),
            $expiresAt,
        ]);

        // Update last login.
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
           ->execute([$user['id']]);

        Response::json([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'admin'      => [
                'id'         => $user['id'],
                'email'      => $user['email'],
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name'],
                'role'       => $user['role'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/admin/auth/logout  (requires AdminMiddleware)
    // -------------------------------------------------------------------------

    public function logout(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token !== null && $token !== '') {
            Database::getInstance()
                ->prepare('DELETE FROM admin_sessions WHERE token = ?')
                ->execute([$token]);
        }

        Response::noContent();
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/auth/me  (requires AdminMiddleware)
    // -------------------------------------------------------------------------

    public function me(Request $request): void
    {
        $admin = AdminMiddleware::currentAdmin();

        if ($admin === null) {
            Response::unauthorized('Admin authentication required.');
        }

        Response::json([
            'admin' => [
                'id'         => $admin['user_id'],
                'email'      => $admin['email'],
                'first_name' => $admin['first_name'],
                'last_name'  => $admin['last_name'],
                'role'       => $admin['role'],
            ],
        ]);
    }
}
