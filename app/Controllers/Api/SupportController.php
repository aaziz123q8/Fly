<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;

/**
 * User-facing support tickets. Customers open tickets and exchange replies with
 * the support team (whose side is handled by AdminSupportController). Uses the
 * shared support_tickets / support_replies tables.
 */
class SupportController
{
    // GET /api/support — the current user's tickets.
    public function list(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT t.id, t.subject, t.status, t.priority, t.created_at, t.updated_at,
                        (SELECT COUNT(*) FROM support_replies sr WHERE sr.ticket_id = t.id) AS reply_count,
                        (SELECT sr2.message FROM support_replies sr2 WHERE sr2.ticket_id = t.id ORDER BY sr2.id ASC LIMIT 1) AS first_message
                 FROM support_tickets t
                 WHERE t.user_id = ?
                 ORDER BY t.created_at DESC
                 LIMIT 100"
            );
            $stmt->execute([(int) $user['id']]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $rows = [];
        }

        Response::json(['tickets' => $rows]);
    }

    // POST /api/support { subject, message } — open a new ticket.
    public function create(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $subject = trim((string) $request->input('subject'));
        $message = trim((string) $request->input('message'));

        $errors = [];
        if ($subject === '') $errors['subject'] = 'الموضوع مطلوب';
        if ($message === '') $errors['message'] = 'الرسالة مطلوبة';
        if (!empty($errors)) Response::validationError($errors);

        $db = Database::getInstance();
        try {
            $db->prepare(
                'INSERT INTO support_tickets (user_id, subject, status, priority, created_at, updated_at)
                 VALUES (?, ?, "open", "medium", NOW(), NOW())'
            )->execute([(int) $user['id'], mb_substr($subject, 0, 200)]);

            $ticketId = (int) $db->lastInsertId();

            $db->prepare(
                'INSERT INTO support_replies (ticket_id, user_id, message, is_admin, created_at)
                 VALUES (?, ?, ?, 0, NOW())'
            )->execute([$ticketId, (int) $user['id'], $message]);

            Response::created(['id' => $ticketId, 'message' => 'تم إرسال تذكرتك، وسيتواصل معك فريق الدعم قريباً.']);
        } catch (\Throwable) {
            Response::error('تعذّر إنشاء التذكرة حالياً. يرجى المحاولة لاحقاً.', 500, 'server_error');
        }
    }

    // GET /api/support/:id — a ticket the current user owns, with its replies.
    public function show(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $id = (int) $request->param('id');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, (int) $user['id']]);
        $ticket = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ticket) Response::notFound('التذكرة غير موجودة.');

        $rStmt = $db->prepare(
            'SELECT id, message, is_admin, created_at FROM support_replies
             WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $rStmt->execute([$id]);

        Response::json(['ticket' => $ticket, 'replies' => $rStmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // POST /api/support/:id/reply { message } — add a reply to the user's ticket.
    public function reply(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $id      = (int) $request->param('id');
        $message = trim((string) $request->input('message'));
        if ($message === '') Response::validationError(['message' => 'الرسالة مطلوبة']);

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, (int) $user['id']]);
        if (!$stmt->fetch()) Response::notFound('التذكرة غير موجودة.');

        $db->prepare(
            'INSERT INTO support_replies (ticket_id, user_id, message, is_admin, created_at)
             VALUES (?, ?, ?, 0, NOW())'
        )->execute([$id, (int) $user['id'], $message]);

        try { $db->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$id]); } catch (\Throwable) {}

        Response::created(['message' => 'تم إرسال ردك.']);
    }
}
