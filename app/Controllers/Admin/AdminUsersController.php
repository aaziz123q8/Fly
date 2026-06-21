<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminUsersController
{
    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->input('search', ''));
        $status  = $request->input('status', '');
        $role    = $request->input('role', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ($status === 'active') { $where[] = 'is_active = 1'; }
        elseif ($status === 'inactive') { $where[] = 'is_active = 0'; }
        if ($role !== '') { $where[] = 'role = ?'; $params[] = $role; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM users $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT id, email, first_name, last_name, phone_country_code, phone_number,
                    role, is_active, email_verified_at, created_at, last_login_at
             FROM users $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'data' => $data,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function show(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id, email, first_name, last_name, phone_country_code, phone_number, role, is_active, email_verified_at, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) { Response::notFound('User not found.'); }
        Response::json(['user' => $user]);
    }

    public function update(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) { Response::notFound('User not found.'); }

        $allowed = ['first_name', 'last_name', 'phone_country_code', 'phone_number', 'role', 'is_active'];
        $set     = [];
        $params  = [];
        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = in_array($field, ['is_active']) ? (int) $val : (string) $val;
            }
        }
        if (empty($set)) { Response::error('No updatable fields.', 400); }
        $params[] = $id;
        $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        $stmt = $db->prepare('SELECT id, email, first_name, last_name, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json(['user' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    public function destroy(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        Response::noContent();
    }
}
