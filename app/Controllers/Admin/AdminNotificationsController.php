<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Services\AdminActivityLog;

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

        $where  = ['1=1'];
        $params = [];

        if ($type !== null && $type !== '') {
            $where[]  = 'n.channel = ?';
            $params[] = $type;
        }
        // status: 'sent' = every stored notification, 'read'/'unread' by flag
        if ($status === 'read') {
            $where[] = 'n.is_read = 1';
        } elseif ($status === 'unread' || $status === 'pending') {
            $where[] = 'n.is_read = 0';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM user_notifications n {$whereClause}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT n.id, n.user_id, n.channel AS type,
                    COALESCE(NULLIF(n.title_ar,''), n.title_en) AS title,
                    COALESCE(NULLIF(n.body_ar,''), n.body_en)  AS message,
                    n.is_read, n.created_at, n.created_at AS sent_at,
                    CASE WHEN n.is_read = 1 THEN 'read' ELSE 'sent' END AS status,
                    u.email AS recipient_email,
                    TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS recipient_name
             FROM user_notifications n
             LEFT JOIN users u ON u.id = n.user_id
             {$whereClause}
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'data'       => $notifications,
            'meta'       => ['total' => $total, 'page' => $page],
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

        $title   = trim((string) $request->input('title'));
        $message = trim((string) $request->input('message'));
        $type    = (string) ($request->input('type') ?? 'push');
        $target  = (string) ($request->input('target') ?? 'all');

        $errors = [];
        if ($title === '')   { $errors['title']   = 'العنوان مطلوب.'; }
        if ($message === '') { $errors['message'] = 'نص الرسالة مطلوب.'; }
        if (!empty($errors)) {
            Response::validationError($errors);
        }

        // user_notifications.channel only allows email|whatsapp|push — map safely.
        $channel = in_array($type, ['email', 'whatsapp', 'push'], true) ? $type : 'push';

        // Resolve the audience to a WHERE clause over active customers.
        $audience = 'u.role = "user" AND u.is_active = 1';
        if ($target === 'flight_bookers') {
            $audience .= ' AND EXISTS (SELECT 1 FROM flight_bookings fb WHERE fb.user_id = u.id)';
        } elseif ($target === 'hotel_bookers') {
            $audience .= ' AND EXISTS (SELECT 1 FROM hotel_bookings hb WHERE hb.user_id = u.id)';
        }

        // Bulk create in-app notifications in one INSERT … SELECT (atomic, fast).
        try {
            $stmt = $db->prepare(
                "INSERT INTO user_notifications
                    (user_id, channel, title_ar, title_en, body_ar, body_en, is_read, created_at)
                 SELECT u.id, ?, ?, ?, ?, ?, 0, NOW()
                 FROM users u
                 WHERE {$audience}"
            );
            $stmt->execute([$channel, $title, $title, $message, $message]);
            $recipients = $stmt->rowCount();
        } catch (\Throwable $e) {
            Response::error('تعذر إرسال الإشعار الجماعي.', 500, 'broadcast_failed');
            return;
        }

        AdminActivityLog::record(
            'broadcast',
            'notifications',
            'notification',
            null,
            "إشعار جماعي «{$title}» إلى {$recipients} مستخدم ({$target})"
        );

        Response::json([
            'message'    => "تم إرسال الإشعار إلى {$recipients} مستخدم.",
            'recipients' => $recipients,
        ]);
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
