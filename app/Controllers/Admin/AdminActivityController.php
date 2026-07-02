<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

/**
 * Read-only view over the admin activity/audit log.
 */
class AdminActivityController
{
    // GET /api/admin/activity
    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) ($request->query('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->query('per_page') ?? 30)));
        $offset  = ($page - 1) * $perPage;
        $module  = trim((string) ($request->query('module') ?? ''));
        $search  = trim((string) ($request->query('search') ?? ''));

        $where  = ['1=1'];
        $params = [];

        if ($module !== '') {
            $where[]  = 'a.module = ?';
            $params[] = $module;
        }
        if ($search !== '') {
            $where[]  = '(a.action LIKE ? OR a.description LIKE ? OR u.email LIKE ? OR u.first_name LIKE ?)';
            $like     = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereClause = implode(' AND ', $where);

        try {
            $countStmt = $db->prepare(
                "SELECT COUNT(*) FROM admin_activity_log a
                 LEFT JOIN users u ON u.id = a.admin_id
                 WHERE $whereClause"
            );
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT a.id, a.admin_id, a.action, a.module, a.entity_type, a.entity_id,
                        a.description, a.ip_address, a.created_at,
                        u.first_name, u.last_name, u.email
                 FROM admin_activity_log a
                 LEFT JOIN users u ON u.id = a.admin_id
                 WHERE $whereClause
                 ORDER BY a.created_at DESC, a.id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $rows  = [];
            $total = 0;
        }

        // Distinct modules for the filter dropdown.
        try {
            $modules = $db->query('SELECT DISTINCT module FROM admin_activity_log ORDER BY module')
                          ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            $modules = [];
        }

        Response::json([
            'data'    => $rows,
            'modules' => $modules,
            'meta'    => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }
}
