<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;

class AdminNotificationsController
{
    // =========================================================================
    // Admin: GET /api/admin/notifications
    // =========================================================================

    public function index(Request $request): void
    {
        $db   = Database::getInstance();
        $page   = max(1, (int)($request->query('page') ?? 1));
        $limit  = min(100, max(1, (int)($request->query('limit') ?? 20)));
        $offset = ($page - 1) * $limit;

        $type   = $request->query('type');
        $status = $request->query('status');

        $where  = [];
        $params = [];

        if ($type !== null && $type !== '') {
            // filter notifications by channel (we use channel as type here)
            $where[]  = 'n.channel = ?';
            $params[] = $type;
        }

        if ($status !== null && $status !== '') {
            $where[]  = 'dl.status = ?';
            $params[] = $status;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare(
            "SELECT COUNT(DISTINCT n.id)
             FROM user_notifications n
             LEFT JOIN notification_dispatch_log dl ON dl.notification_id = n.id
             {$whereClause}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Re-run with params
        $stmt = $db->prepare(
            "SELECT n.id, n.user_id, n.channel, n.title_en, n.title_ar,
                    n.is_read, n.created_at,
                    dl.status AS dispatch_status, dl.sent_at
             FROM user_notifications n
             LEFT JOIN notification_dispatch_log dl ON dl.notification_id = n.id
             {$whereClause}
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'data'       => $notifications,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    // =========================================================================
    // Admin: POST /api/admin/notifications/broadcast
    // =========================================================================

    public function broadcast(Request $request): void
    {
        $db = Database::getInstance();

        try {
            $db->prepare(
                'INSERT INTO error_logs (level, message, context, created_at)
                 VALUES (?, ?, ?, NOW())'
            )->execute([
                'warning',
                'broadcast() called but send_notification job type has no worker handler',
                json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? '']),
            ]);
        } catch (\Throwable) {}

        Response::json([
            'error'   => 'not_implemented',
            'message' => 'Broadcast notifications are not yet available. The send_notification job type has no worker handler.',
        ], 501);
    }

    // =========================================================================
    // Admin: GET /api/admin/notifications/:id
    // =========================================================================

    public function show(Request $request): void
    {
        $id = (int)($request->param('id') ?? 0);

        if ($id <= 0) {
            Response::error('Invalid notification ID.', 422);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT n.*, u.email AS user_email, u.first_name, u.last_name
             FROM user_notifications n
             LEFT JOIN users u ON u.id = n.user_id
             WHERE n.id = ?'
        );
        $stmt->execute([$id]);
        $notification = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$notification) {
            Response::error('Notification not found.', 404, 'not_found');
        }

        // Delivery stats
        $statsStmt = $db->prepare(
            'SELECT status, COUNT(*) AS cnt FROM notification_dispatch_log
             WHERE notification_id = ? GROUP BY status'
        );
        $statsStmt->execute([$id]);
        $stats = [];
        foreach ($statsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats[$row['status']] = (int)$row['cnt'];
        }

        Response::json([
            'notification'   => $notification,
            'delivery_stats' => $stats,
        ]);
    }

    // =========================================================================
    // User: GET /api/notifications  (AuthMiddleware)
    // =========================================================================

    public function userNotifications(Request $request): void
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized('Authentication required.');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, channel, title_en, title_ar, body_en, body_ar, is_read, read_at, created_at
             FROM user_notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $unreadStmt = $db->prepare(
            'SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0'
        );
        $unreadStmt->execute([$user['id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        Response::json([
            'data'         => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    // =========================================================================
    // User: PUT /api/notifications/:id/read  (AuthMiddleware)
    // =========================================================================

    public function markRead(Request $request): void
    {
        $user = AuthMiddleware::currentUser();

        if ($user === null) {
            Response::unauthorized('Authentication required.');
        }

        $id = (int)($request->param('id') ?? 0);

        if ($id <= 0) {
            Response::error('Invalid notification ID.', 422);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'UPDATE user_notifications SET is_read = 1, read_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $user['id']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Notification not found.', 404, 'not_found');
        }

        Response::json(['success' => true]);
    }
}
