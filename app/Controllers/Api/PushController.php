<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;

class PushController
{
    // =========================================================================
    // POST /api/push/subscribe  (AuthMiddleware)
    // =========================================================================

    public function subscribe(Request $request): void
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized('Authentication required.');
        }

        $errors = $request->validate([
            'endpoint' => 'required',
            'p256dh'   => 'required',
            'auth_key' => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $endpoint = (string)$request->input('endpoint');
        $p256dh   = (string)$request->input('p256dh');
        $authKey  = (string)$request->input('auth_key');

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth_key = VALUES(auth_key)'
        );
        $stmt->execute([$user['id'], $endpoint, $p256dh, $authKey]);

        Response::json(['success' => true, 'message' => 'Push subscription registered.']);
    }

    // =========================================================================
    // DELETE /api/push/unsubscribe  (AuthMiddleware)
    // =========================================================================

    public function unsubscribe(Request $request): void
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized('Authentication required.');
        }

        $endpoint = (string)($request->input('endpoint') ?? '');

        if ($endpoint === '') {
            Response::error('Endpoint is required.', 422, 'missing_endpoint');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?'
        );
        $stmt->execute([$user['id'], $endpoint]);

        Response::noContent();
    }
}
