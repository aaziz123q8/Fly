<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Adapters\Stripe\StripeAdapter;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminPaymentsController
{
    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->input('search', ''));
        $status  = $request->input('status', '');
        $from    = $request->input('from', '');
        $to      = $request->input('to', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(p.stripe_payment_intent_id LIKE ? OR u.email LIKE ? OR u.first_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }
        if ($from !== '')   { $where[] = 'p.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to !== '')     { $where[] = 'p.created_at <= ?'; $params[] = $to . ' 23:59:59'; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        try {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN users u ON u.id = p.user_id $whereClause");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT p.id, p.stripe_payment_intent_id, p.amount, p.currency,
                        p.status, p.booking_type, p.booking_id, p.created_at,
                        u.email AS user_email, u.first_name, u.last_name
                 FROM payments p
                 LEFT JOIN users u ON u.id = p.user_id
                 $whereClause
                 ORDER BY p.created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $statsStmt = $db->query("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM payments WHERE status='succeeded'");
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $total = 0; $data = []; $stats = ['total' => 0, 'count' => 0];
        }

        Response::json([
            'data'  => $data,
            'stats' => $stats,
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
            'SELECT p.*, u.email AS user_email, u.first_name, u.last_name
             FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$payment) { Response::notFound('Payment not found.'); }
        Response::json(['payment' => $payment]);
    }

    public function refund(Request $request): void
    {
        $id     = (int) $request->param('id');
        $db     = Database::getInstance();
        $stmt   = $db->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$payment) { Response::notFound('Payment not found.'); }

        $paymentIntentId = $payment['stripe_payment_intent_id'] ?? '';
        if (empty($paymentIntentId)) {
            Response::error('No Stripe payment intent ID on this payment.', 422);
        }

        try {
            $stripe = new StripeAdapter();
            $refund = $stripe->createRefund(
                $paymentIntentId,
                null,
                null,
                'admin_refund_' . md5((string) $id)
            );
            $refundId = $refund['id'] ?? null;
        } catch (\Throwable $e) {
            Response::json([
                'error'   => 'stripe_refund_failed',
                'message' => $e->getMessage(),
            ], 502);
            return;
        }

        $db->prepare(
            'UPDATE payments SET status = "refunded", stripe_refund_id = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$refundId, $id]);

        Response::json(['success' => true, 'message' => 'Payment refunded via Stripe.', 'refund_id' => $refundId]);
    }
}
