<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminSupportController
{
    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->input('search', ''));
        $status  = $request->input('status', '');
        $priority = $request->input('priority', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(t.subject LIKE ? OR u.email LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like]);
        }
        if ($status !== '')   { $where[] = 't.status = ?';   $params[] = $status; }
        if ($priority !== '') { $where[] = 't.priority = ?'; $params[] = $priority; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        try {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id $whereClause");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT t.id, t.subject, t.status, t.priority, t.created_at, t.updated_at,
                        u.email AS user_email, u.first_name, u.last_name,
                        (SELECT COUNT(*) FROM support_replies sr WHERE sr.ticket_id = t.id) AS reply_count
                 FROM support_tickets t
                 LEFT JOIN users u ON u.id = t.user_id
                 $whereClause
                 ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $open   = (int) $db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
            $closed = (int) $db->query("SELECT COUNT(*) FROM support_tickets WHERE status='closed'")->fetchColumn();
        } catch (\Throwable) {
            $total = 0; $data = []; $open = 0; $closed = 0;
        }

        Response::json([
            'data'  => $data,
            'stats' => ['open' => $open, 'closed' => $closed],
            'meta'  => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*, u.email AS user_email, u.first_name, u.last_name
             FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ticket) { Response::notFound('Ticket not found.'); }

        $repliesStmt = $db->prepare(
            'SELECT sr.*, u.first_name, u.last_name, u.role
             FROM support_replies sr LEFT JOIN users u ON u.id = sr.user_id
             WHERE sr.ticket_id = ? ORDER BY sr.created_at ASC'
        );
        $repliesStmt->execute([$id]);
        $replies = $repliesStmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(['ticket' => $ticket, 'replies' => $replies]);
    }

    public function reply(Request $request): void
    {
        $id      = (int) $request->param('id');
        $db      = Database::getInstance();
        $message = trim((string) $request->input('message', ''));
        if ($message === '') { Response::error('Message is required.', 422); }

        $stmt = $db->prepare('SELECT id, user_id FROM support_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ticket) { Response::notFound('Ticket not found.'); }

        // Admin user id from session token
        $adminUserId = null;
        $authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $tokenHash = hash('sha256', $m[1]);
            $tokenStmt = $db->prepare('SELECT user_id FROM admin_sessions WHERE token = ? AND expires_at > NOW() LIMIT 1');
            $tokenStmt->execute([$tokenHash]);
            $sess = $tokenStmt->fetch(\PDO::FETCH_ASSOC);
            if ($sess) { $adminUserId = (int) $sess['user_id']; }
        }

        $db->prepare(
            'INSERT INTO support_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?,?,?,1,NOW())'
        )->execute([$id, $adminUserId, $message]);

        $db->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")->execute([$id]);

        Response::json(['success' => true]);
    }

    public function updateStatus(Request $request): void
    {
        $id     = (int) $request->param('id');
        $status = (string) $request->input('status', '');
        $allowed = ['open', 'in_progress', 'closed'];
        if (!in_array($status, $allowed, true)) { Response::error('Invalid status.', 422); }

        $db = Database::getInstance();
        $db->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        Response::json(['success' => true]);
    }
}
