<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminCommissionsController
{
    public function index(Request $request): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            'SELECT id, type, name, commission_type, commission_value,
                    applies_to, condition_value, is_active, created_at
             FROM commissions ORDER BY created_at DESC'
        );
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        Response::json(['data' => $data]);
    }

    public function store(Request $request): void
    {
        $db = Database::getInstance();
        $fields = [
            'type'             => (string) $request->input('type', 'flight'),
            'name'             => trim((string) $request->input('name', '')),
            'commission_type'  => (string) $request->input('commission_type', 'percentage'),
            'commission_value' => (float) $request->input('commission_value', 0),
            'applies_to'       => (string) $request->input('applies_to', 'all'),
            'condition_value'  => (string) $request->input('condition_value', ''),
            'is_active'        => (int) $request->input('is_active', 1),
        ];

        if ($fields['name'] === '') { Response::error('Name is required.', 422); }

        $db->prepare(
            'INSERT INTO commissions (type, name, commission_type, commission_value, applies_to, condition_value, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        )->execute(array_values($fields));

        $id = $db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM commissions WHERE id = ?');
        $stmt->execute([$id]);
        Response::json(['commission' => $stmt->fetch(\PDO::FETCH_ASSOC)], 201);
    }

    public function update(Request $request): void
    {
        $id     = (int) $request->param('id');
        $db     = Database::getInstance();
        $allowed = ['type','name','commission_type','commission_value','applies_to','condition_value','is_active'];
        $set    = [];
        $params = [];
        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = $field === 'is_active' ? (int) $val : ($field === 'commission_value' ? (float) $val : (string) $val);
            }
        }
        if (empty($set)) { Response::error('No fields.', 400); }
        $params[] = $id;
        $db->prepare('UPDATE commissions SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        $stmt = $db->prepare('SELECT * FROM commissions WHERE id = ?');
        $stmt->execute([$id]);
        Response::json(['commission' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->param('id');
        Database::getInstance()->prepare('DELETE FROM commissions WHERE id = ?')->execute([$id]);
        Response::noContent();
    }
}
