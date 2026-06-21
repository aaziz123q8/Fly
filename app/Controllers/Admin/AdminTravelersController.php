<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;

class AdminTravelersController
{
    // -------------------------------------------------------------------------
    // GET /api/admin/travelers
    // -------------------------------------------------------------------------

    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) ($request->input('page', 1)));
        $perPage = min(100, max(1, (int) ($request->input('per_page', 20))));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) ($request->input('search', '')));
        $status  = $request->input('status', '');

        $where  = ['u.role = "user"'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.is_active = 0';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Total count.
        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u $whereClause");
        $countStmt->execute($params);
        $total    = (int) $countStmt->fetchColumn();
        $lastPage = (int) ceil($total / $perPage);

        // Data.
        $dataParams   = array_merge($params, [$perPage, $offset]);
        $stmt         = $db->prepare(
            "SELECT u.id, u.email, u.first_name, u.last_name,
                    u.phone_country_code, u.phone_number,
                    u.is_active, u.email_verified_at, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM flight_bookings fb WHERE fb.user_id = u.id)
                    + (SELECT COUNT(*) FROM hotel_bookings hb WHERE hb.user_id = u.id)
                    AS bookings_count
             FROM users u
             $whereClause
             ORDER BY u.created_at DESC
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
    // GET /api/admin/travelers/:id
    // -------------------------------------------------------------------------

    public function show(Request $request): void
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT id, email, first_name, last_name, phone_country_code, phone_number,
                    is_active, email_verified_at, created_at, last_login_at
             FROM users
             WHERE id = ? AND role = "user"
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            Response::notFound('Traveler not found.');
        }

        // Traveler profiles with all blueprint fields.
        $travelerStmt = $db->prepare(
            'SELECT id, first_name, middle_name, last_name, gender, nationality,
                    country, phone_country_code, phone_number, phone, email,
                    document_type, document_number, issue_date, expiry_date,
                    document_issue, document_expiry, document_country,
                    date_of_birth, created_at
             FROM travelers
             WHERE user_id = ? AND archived_at IS NULL
             ORDER BY is_default DESC, created_at ASC'
        );
        $travelerStmt->execute([$id]);
        $travelerProfiles = $travelerStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Last 5 flight bookings.
        $flightStmt = $db->prepare(
            'SELECT id, booking_reference, status, total_amount, currency,
                    origin_airport, destination_airport, departure_at, created_at
             FROM flight_bookings
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 5'
        );
        $flightStmt->execute([$id]);
        $flightBookings = $flightStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Last 5 hotel bookings.
        $hotelStmt = $db->prepare(
            'SELECT id, booking_reference, status, total_amount, currency,
                    check_in_date, check_out_date, created_at
             FROM hotel_bookings
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 5'
        );
        $hotelStmt->execute([$id]);
        $hotelBookings = $hotelStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Total spent.
        $totalSpentStmt = $db->prepare(
            'SELECT
               (SELECT COALESCE(SUM(total_amount),0) FROM flight_bookings WHERE user_id = ? AND status IN ("confirmed","pending"))
             + (SELECT COALESCE(SUM(total_amount),0) FROM hotel_bookings WHERE user_id = ? AND status IN ("confirmed","pending"))
             AS total_spent'
        );
        $totalSpentStmt->execute([$id, $id]);
        $totalSpent = number_format((float) $totalSpentStmt->fetchColumn(), 2, '.', '');

        Response::json([
            'user'             => $user,
            'travelers'        => $travelerProfiles,
            'flight_bookings'  => $flightBookings,
            'hotel_bookings'   => $hotelBookings,
            'total_spent'      => $totalSpent,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/admin/travelers/:id
    // -------------------------------------------------------------------------

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        // Check traveler exists.
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND role = "user" LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Traveler not found.');
        }

        $allowed = ['first_name', 'last_name', 'phone_country_code', 'phone_number', 'is_active'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = $field === 'is_active' ? (int) $val : (string) $val;
            }
        }

        if (empty($set)) {
            Response::error('No updatable fields provided.', 400);
        }

        $params[] = $id;
        $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')
           ->execute($params);

        // Return updated user.
        $stmt = $db->prepare(
            'SELECT id, email, first_name, last_name, phone_country_code, phone_number,
                    is_active, email_verified_at, created_at, last_login_at
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json(['user' => $user]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/travelers/:id
    // -------------------------------------------------------------------------

    public function destroy(Request $request): void
    {
        $id    = (int) $request->param('id');
        $admin = AdminMiddleware::currentAdmin();

        // Prevent deleting own account.
        if ($admin !== null && (int) $admin['user_id'] === $id) {
            Response::error('You cannot deactivate your own account.', 403, 'forbidden');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? AND role = "user" LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Traveler not found.');
        }

        $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);

        Response::noContent();
    }
}
