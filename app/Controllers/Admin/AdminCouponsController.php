<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminCouponsController
{
    // -------------------------------------------------------------------------
    // GET /api/admin/coupons
    // -------------------------------------------------------------------------

    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) ($request->input('page', 1)));
        $perPage = min(100, max(1, (int) ($request->input('per_page', 20))));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) ($request->input('search', '')));
        $active  = $request->input('is_active', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = 'code LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($active === '1' || $active === 'true') {
            $where[] = 'is_active = 1';
        } elseif ($active === '0' || $active === 'false') {
            $where[] = 'is_active = 0';
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM coupons WHERE $whereClause");
        $countStmt->execute($params);
        $total    = (int) $countStmt->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $dataParams = array_merge($params, [$perPage, $offset]);
        $stmt       = $db->prepare(
            "SELECT id, code, discount_type, discount_value, currency,
                    max_discount_amount, applies_to, min_booking_amount,
                    usage_limit, usage_count, user_id,
                    valid_from, valid_until, is_active, created_at
             FROM coupons
             WHERE $whereClause
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'data' => $data,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/coupons/:id
    // -------------------------------------------------------------------------

    public function show(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $coupon = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$coupon) {
            Response::notFound('Coupon not found.');
        }

        // Recent usages (last 10).
        $usageStmt = $db->prepare(
            'SELECT cu.*, u.email AS user_email, u.first_name, u.last_name
             FROM coupon_usages cu
             JOIN users u ON u.id = cu.user_id
             WHERE cu.coupon_id = ?
             ORDER BY cu.used_at DESC
             LIMIT 10'
        );
        $usageStmt->execute([$id]);
        $coupon['recent_usages'] = $usageStmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(['coupon' => $coupon]);
    }

    // -------------------------------------------------------------------------
    // POST /api/admin/coupons
    // -------------------------------------------------------------------------

    public function store(Request $request): void
    {
        $errors = $request->validate([
            'code'           => 'required',
            'discount_type'  => 'required',
            'discount_value' => 'required',
            'applies_to'     => 'required',
            'valid_from'     => 'required',
            'valid_until'    => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $code          = strtoupper(trim((string) $request->input('code')));
        $discountType  = (string) $request->input('discount_type');
        $discountValue = (float) $request->input('discount_value');
        $appliesTo     = (string) $request->input('applies_to');
        $validFrom     = (string) $request->input('valid_from');
        $validUntil    = (string) $request->input('valid_until');

        if (!in_array($discountType, ['fixed', 'percentage'], true)) {
            Response::error('discount_type must be "fixed" or "percentage".', 422);
        }
        if (!in_array($appliesTo, ['flight', 'hotel', 'both'], true)) {
            Response::error('applies_to must be "flight", "hotel", or "both".', 422);
        }
        if ($discountType === 'percentage' && ($discountValue <= 0 || $discountValue > 100)) {
            Response::error('Percentage discount_value must be between 0 and 100.', 422);
        }

        $db = Database::getInstance();

        // Check unique code.
        $dupStmt = $db->prepare('SELECT id FROM coupons WHERE code = ? LIMIT 1');
        $dupStmt->execute([$code]);
        if ($dupStmt->fetch()) {
            Response::error('Coupon code already exists.', 409, 'duplicate_code');
        }

        $db->prepare(
            'INSERT INTO coupons
             (code, discount_type, discount_value, currency, max_discount_amount,
              applies_to, min_booking_amount, usage_limit, user_id, valid_from, valid_until, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $code,
            $discountType,
            $discountValue,
            $request->input('currency'),
            $request->input('max_discount_amount'),
            $appliesTo,
            $request->input('min_booking_amount'),
            $request->input('usage_limit'),
            $request->input('user_id'),
            $validFrom,
            $validUntil,
            (int) ($request->input('is_active') ?? 1),
        ]);

        $newId  = (int) $db->lastInsertId();
        $getStmt = $db->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $getStmt->execute([$newId]);

        Response::json(['coupon' => $getStmt->fetch(\PDO::FETCH_ASSOC)], 201);
    }

    // -------------------------------------------------------------------------
    // PUT /api/admin/coupons/:id
    // -------------------------------------------------------------------------

    public function update(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM coupons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Coupon not found.');
        }

        $allowed = [
            'discount_type', 'discount_value', 'currency', 'max_discount_amount',
            'applies_to', 'min_booking_amount', 'usage_limit',
            'valid_from', 'valid_until', 'is_active',
        ];
        $set    = [];
        $params = [];

        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = in_array($field, ['is_active'], true) ? (int) $val : $val;
            }
        }

        if (empty($set)) {
            Response::error('No updatable fields provided.', 400);
        }

        $params[] = $id;
        $db->prepare('UPDATE coupons SET ' . implode(', ', $set) . ' WHERE id = ?')
           ->execute($params);

        $getStmt = $db->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $getStmt->execute([$id]);

        Response::json(['coupon' => $getStmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/coupons/:id
    // -------------------------------------------------------------------------

    public function destroy(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM coupons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Coupon not found.');
        }

        // Check for usages first.
        $useStmt = $db->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?');
        $useStmt->execute([$id]);
        if ((int) $useStmt->fetchColumn() > 0) {
            // Soft-deactivate instead of hard delete.
            $db->prepare('UPDATE coupons SET is_active = 0 WHERE id = ?')->execute([$id]);
            Response::json(['message' => 'Coupon deactivated (has existing usages, cannot be deleted).']);
            return;
        }

        $db->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
        Response::noContent();
    }

    // -------------------------------------------------------------------------
    // POST /api/coupons/validate  (traveler auth — public, requires AuthMiddleware)
    // -------------------------------------------------------------------------

    public function validate(Request $request): void
    {
        $errors = $request->validate([
            'code'         => 'required',
            'booking_type' => 'required',
            'amount'       => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $code        = strtoupper(trim((string) $request->input('code')));
        $bookingType = (string) $request->input('booking_type');
        $amount      = (float) $request->input('amount');

        // Map booking_type to coupon applies_to column values.
        if (!in_array($bookingType, ['flight', 'hotel'], true)) {
            Response::error('booking_type must be "flight" or "hotel".', 422);
        }

        if ($amount <= 0) {
            Response::error('amount must be greater than zero.', 422);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT * FROM coupons
             WHERE code = ?
               AND is_active = 1
               AND valid_from <= NOW()
               AND valid_until >= NOW()
               AND (applies_to = ? OR applies_to = "both")
             LIMIT 1'
        );
        $stmt->execute([$code, $bookingType]);
        $coupon = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$coupon) {
            Response::json(['valid' => false, 'message' => 'Invalid or expired coupon code.']);
        }

        // Check overall usage limit.
        if ($coupon['usage_limit'] !== null) {
            $usedStmt = $db->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?');
            $usedStmt->execute([$coupon['id']]);
            $usedCount = (int) $usedStmt->fetchColumn();
            if ($usedCount >= (int) $coupon['usage_limit']) {
                Response::json(['valid' => false, 'message' => 'Coupon usage limit has been reached.']);
            }
        }

        // Check minimum booking amount.
        if ($coupon['min_booking_amount'] !== null && $amount < (float) $coupon['min_booking_amount']) {
            Response::json([
                'valid'   => false,
                'message' => 'Booking amount is below the minimum required of ' . number_format((float) $coupon['min_booking_amount'], 2, '.', ''),
            ]);
        }

        // Check if coupon is locked to a specific user.
        if ($coupon['user_id'] !== null) {
            $currentUser = \App\Middleware\AuthMiddleware::currentUser();
            if ($currentUser === null || (int) $currentUser['id'] !== (int) $coupon['user_id']) {
                Response::json(['valid' => false, 'message' => 'This coupon is not valid for your account.']);
            }
        }

        // Calculate discount.
        if ($coupon['discount_type'] === 'percentage') {
            $discountAmount = $amount * ((float) $coupon['discount_value'] / 100.0);
            if (!empty($coupon['max_discount_amount'])) {
                $discountAmount = min($discountAmount, (float) $coupon['max_discount_amount']);
            }
        } else {
            $discountAmount = (float) $coupon['discount_value'];
        }

        $discountAmount = min($discountAmount, $amount);
        $finalAmount    = $amount - $discountAmount;

        Response::json([
            'valid'           => true,
            'coupon_id'       => (int) $coupon['id'],
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'final_amount'    => number_format($finalAmount, 2, '.', ''),
        ]);
    }
}
