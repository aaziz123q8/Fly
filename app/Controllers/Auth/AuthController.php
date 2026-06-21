<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use RuntimeException;

class AuthController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/register
    // -------------------------------------------------------------------------

    public function register(Request $request): void
    {
        $errors = $request->validate([
            'email'              => 'required|email|max:180',
            'password'           => 'required|min:8|confirmed',
            'first_name'         => 'required|max:80',
            'last_name'          => 'required|max:80',
            'phone_country_code' => 'required|max:6',
            'phone_number'       => 'required|max:20',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        try {
            $user = $this->authService->register(
                email:            (string) $request->input('email'),
                password:         (string) $request->input('password'),
                firstName:        (string) $request->input('first_name'),
                lastName:         (string) $request->input('last_name'),
                phoneCountryCode: (string) $request->input('phone_country_code'),
                phoneNumber:      (string) $request->input('phone_number'),
            );
        } catch (RuntimeException $e) {
            if ($e->getCode() === 409) {
                Response::error('An account with this email address already exists.', 409, 'email_taken');
            }
            Response::error('Registration failed. Please try again.', 500, 'server_error');
        }

        Response::created(['user' => $user]);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/login
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

        try {
            $result = $this->authService->login(
                email:     (string) $request->input('email'),
                password:  (string) $request->input('password'),
                ip:        $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            match ($e->getCode()) {
                429     => Response::tooManyRequests('Too many failed login attempts. Please try again in 15 minutes.'),
                401     => Response::error('Invalid email or password.', 401, 'invalid_credentials'),
                403     => Response::error('Your account has been deactivated.', 403, 'account_inactive'),
                default => Response::error('Login failed. Please try again.', 500, 'server_error'),
            };
        }

        Response::json($result);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/logout   (requires auth middleware)
    // -------------------------------------------------------------------------

    public function logout(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token !== null && $token !== '') {
            $this->authService->logout($token);
        }

        Response::noContent();
    }

    // -------------------------------------------------------------------------
    // GET /api/auth/me   (requires auth middleware)
    // -------------------------------------------------------------------------

    public function me(Request $request): void
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized();
        }

        Response::json(['user' => $user]);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/password/forgot
    // -------------------------------------------------------------------------

    public function forgotPassword(Request $request): void
    {
        $errors = $request->validate(['email' => 'required|email']);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $email = strtolower(trim((string) $request->input('email')));

        try {
            $token = $this->authService->initiatePasswordReset($email);

            // Look up user_id for the job payload.
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $row    = $stmt->fetch();
            $userId = $row ? (int) $row['id'] : null;

            if ($userId !== null) {
                $payload = json_encode(['user_id' => $userId, 'token' => $token, 'email' => $email]);
                $jStmt   = $pdo->prepare(
                    "INSERT INTO job_queue (job_type, payload) VALUES ('send_password_reset_email', ?)"
                );
                $jStmt->execute([$payload]);
            }
        } catch (RuntimeException) {
            // Intentionally swallow — do not reveal whether the email exists.
        }

        // Always return 200 regardless of outcome.
        Response::json(['message' => 'If an account with that email exists, you will receive a password reset link shortly.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/password/reset
    // -------------------------------------------------------------------------

    public function resetPassword(Request $request): void
    {
        $errors = $request->validate([
            'token'    => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        try {
            $this->authService->resetPassword(
                token:       (string) $request->input('token'),
                newPassword: (string) $request->input('password'),
            );
        } catch (RuntimeException $e) {
            Response::error('Invalid or expired password reset token.', 400, 'invalid_token');
        }

        Response::json(['message' => 'Password has been reset successfully. Please log in with your new password.']);
    }
}
