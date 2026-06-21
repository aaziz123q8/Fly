<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminPricingController
{
    // -------------------------------------------------------------------------
    // GET /api/admin/pricing
    // -------------------------------------------------------------------------

    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) ($request->input('page', 1)));
        $perPage = min(100, max(1, (int) ($request->input('per_page', 20))));
        $offset  = ($page - 1) * $perPage;

        $ruleType  = trim((string) ($request->input('rule_type', '')));
        $appliesTo = trim((string) ($request->input('applies_to', '')));
        $active    = $request->input('is_active', '');

        $where  = ['1=1'];
        $params = [];

        if ($ruleType !== '') {
            $where[]  = 'rule_type = ?';
            $params[] = $ruleType;
        }
        if ($appliesTo !== '') {
            $where[]  = 'applies_to = ?';
            $params[] = $appliesTo;
        }
        if ($active === '1' || $active === 'true') {
            $where[] = 'is_active = 1';
        } elseif ($active === '0' || $active === 'false') {
            $where[] = 'is_active = 0';
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM pricing_rules WHERE $whereClause");
        $countStmt->execute($params);
        $total    = (int) $countStmt->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $dataParams = array_merge($params, [$perPage, $offset]);
        $stmt       = $db->prepare(
            "SELECT id, name, rule_type, applies_to, value_type, value, currency,
                    min_booking_amount, max_booking_amount, is_active, priority,
                    valid_from, valid_until, created_at, updated_at
             FROM pricing_rules
             WHERE $whereClause
             ORDER BY priority DESC, created_at DESC
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
    // GET /api/admin/pricing/:id
    // -------------------------------------------------------------------------

    public function show(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM pricing_rules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $rule = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$rule) {
            Response::notFound('Pricing rule not found.');
        }

        Response::json(['pricing_rule' => $rule]);
    }

    // -------------------------------------------------------------------------
    // POST /api/admin/pricing
    // -------------------------------------------------------------------------

    public function store(Request $request): void
    {
        $errors = $request->validate([
            'name'       => 'required',
            'rule_type'  => 'required',
            'applies_to' => 'required',
            'value_type' => 'required',
            'value'      => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $ruleType  = (string) $request->input('rule_type');
        $appliesTo = (string) $request->input('applies_to');
        $valueType = (string) $request->input('value_type');

        if (!in_array($ruleType, ['commission', 'markup', 'service_fee', 'tax'], true)) {
            Response::error('rule_type must be one of: commission, markup, service_fee, tax.', 422);
        }
        if (!in_array($appliesTo, ['flight', 'hotel', 'both'], true)) {
            Response::error('applies_to must be "flight", "hotel", or "both".', 422);
        }
        if (!in_array($valueType, ['fixed', 'percentage'], true)) {
            Response::error('value_type must be "fixed" or "percentage".', 422);
        }

        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO pricing_rules
             (name, rule_type, applies_to, value_type, value, currency,
              min_booking_amount, max_booking_amount, is_active, priority,
              valid_from, valid_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (string) $request->input('name'),
            $ruleType,
            $appliesTo,
            $valueType,
            (float) $request->input('value'),
            $request->input('currency'),
            $request->input('min_booking_amount'),
            $request->input('max_booking_amount'),
            (int) ($request->input('is_active') ?? 1),
            (int) ($request->input('priority') ?? 0),
            $request->input('valid_from'),
            $request->input('valid_until'),
        ]);

        $newId  = (int) $db->lastInsertId();
        $getStmt = $db->prepare('SELECT * FROM pricing_rules WHERE id = ? LIMIT 1');
        $getStmt->execute([$newId]);

        Response::json(['pricing_rule' => $getStmt->fetch(\PDO::FETCH_ASSOC)], 201);
    }

    // -------------------------------------------------------------------------
    // PUT /api/admin/pricing/:id
    // -------------------------------------------------------------------------

    public function update(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM pricing_rules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Pricing rule not found.');
        }

        $allowed = [
            'name', 'rule_type', 'applies_to', 'value_type', 'value', 'currency',
            'min_booking_amount', 'max_booking_amount', 'is_active', 'priority',
            'valid_from', 'valid_until',
        ];
        $set    = [];
        $params = [];

        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = in_array($field, ['is_active', 'priority'], true) ? (int) $val : $val;
            }
        }

        if (empty($set)) {
            Response::error('No updatable fields provided.', 400);
        }

        $params[] = $id;
        $db->prepare('UPDATE pricing_rules SET ' . implode(', ', $set) . ' WHERE id = ?')
           ->execute($params);

        $getStmt = $db->prepare('SELECT * FROM pricing_rules WHERE id = ? LIMIT 1');
        $getStmt->execute([$id]);

        Response::json(['pricing_rule' => $getStmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/pricing/:id
    // -------------------------------------------------------------------------

    public function destroy(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM pricing_rules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Pricing rule not found.');
        }

        $db->prepare('DELETE FROM pricing_rules WHERE id = ?')->execute([$id]);
        Response::noContent();
    }
}
