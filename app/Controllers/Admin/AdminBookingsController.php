<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;

class AdminBookingsController
{
    // -------------------------------------------------------------------------
    // GET /api/admin/bookings
    // -------------------------------------------------------------------------

    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) ($request->input('page', 1)));
        $perPage = min(100, max(1, (int) ($request->input('per_page', 20))));
        $offset  = ($page - 1) * $perPage;
        $type    = $request->input('type', 'all');
        if ($type === '') $type = 'all';
        $status  = trim((string) ($request->input('status', '')));
        $search  = trim((string) ($request->input('search', '')));

        $bookings = [];
        $total    = 0;

        if ($type === 'flight' || $type === 'all') {
            [$flights, $flightTotal] = $this->queryFlightBookings($db, $status, $search, $perPage, $offset, $type === 'all');
            $bookings = array_merge($bookings, $flights);
            $total   += $flightTotal;
        }

        if ($type === 'hotel' || $type === 'all') {
            [$hotels, $hotelTotal] = $this->queryHotelBookings($db, $status, $search, $perPage, $offset, $type === 'all');
            $bookings = array_merge($bookings, $hotels);
            $total   += $hotelTotal;
        }

        if ($type === 'all') {
            usort($bookings, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
            $bookings = array_slice($bookings, 0, $perPage);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        Response::json([
            'data' => $bookings,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }

    private function queryFlightBookings(\PDO $db, string $status, string $search, int $limit, int $offset, bool $all): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'fb.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[]  = '(fb.booking_reference LIKE ? OR u.email LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM flight_bookings fb LEFT JOIN users u ON u.id = fb.user_id WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if (!$all) {
            $params[] = $limit;
            $params[] = $offset;
            $limitClause = 'LIMIT ? OFFSET ?';
        } else {
            $limitClause = 'LIMIT 50';
        }

        $stmt = $db->prepare(
            "SELECT fb.id, 'flight' AS booking_type, fb.booking_reference,
                    fb.status, fb.total_amount, fb.currency,
                    fb.origin_airport, fb.destination_airport, fb.departure_at,
                    fb.created_at,
                    u.id AS user_id, u.email AS user_email,
                    u.first_name, u.last_name
             FROM flight_bookings fb
             LEFT JOIN users u ON u.id = fb.user_id
             WHERE $whereClause
             ORDER BY fb.created_at DESC
             $limitClause"
        );
        $stmt->execute($params);

        return [$stmt->fetchAll(\PDO::FETCH_ASSOC), $total];
    }

    private function queryHotelBookings(\PDO $db, string $status, string $search, int $limit, int $offset, bool $all): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'hb.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[]  = '(hb.booking_reference LIKE ? OR u.email LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM hotel_bookings hb LEFT JOIN users u ON u.id = hb.user_id WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if (!$all) {
            $params[] = $limit;
            $params[] = $offset;
            $limitClause = 'LIMIT ? OFFSET ?';
        } else {
            $limitClause = 'LIMIT 50';
        }

        $stmt = $db->prepare(
            "SELECT hb.id, 'hotel' AS booking_type, hb.booking_reference,
                    hb.status, hb.total_amount, hb.currency,
                    hb.check_in_date, hb.check_out_date,
                    hb.created_at,
                    u.id AS user_id, u.email AS user_email,
                    u.first_name, u.last_name
             FROM hotel_bookings hb
             LEFT JOIN users u ON u.id = hb.user_id
             WHERE $whereClause
             ORDER BY hb.created_at DESC
             $limitClause"
        );
        $stmt->execute($params);

        return [$stmt->fetchAll(\PDO::FETCH_ASSOC), $total];
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/bookings/:type/:id
    // -------------------------------------------------------------------------

    public function show(Request $request): void
    {
        $type = $request->param('type');
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();

        if ($type === 'flight') {
            $stmt = $db->prepare(
                'SELECT fb.*, u.email AS user_email, u.first_name, u.last_name
                 FROM flight_bookings fb
                 LEFT JOIN users u ON u.id = fb.user_id
                 WHERE fb.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$booking) {
                Response::notFound('Flight booking not found.');
            }

            // Passengers.
            $passengerStmt = $db->prepare(
                'SELECT * FROM flight_booking_passengers WHERE booking_id = ? ORDER BY id'
            );
            $passengerStmt->execute([$id]);
            $booking['passengers'] = $passengerStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Segments.
            $segmentStmt = $db->prepare(
                'SELECT * FROM flight_booking_segments WHERE booking_id = ? ORDER BY slice_index, segment_order'
            );
            $segmentStmt->execute([$id]);
            $booking['segments'] = $segmentStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Documents (electronic tickets / itineraries).
            try {
                $docStmt = $db->prepare(
                    'SELECT * FROM flight_booking_documents WHERE booking_id = ? ORDER BY id'
                );
                $docStmt->execute([$id]);
                $docs = $docStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($docs as &$doc) {
                    if (!empty($doc['passenger_ids']) && is_string($doc['passenger_ids'])) {
                        $doc['passenger_ids'] = json_decode($doc['passenger_ids'], true);
                    }
                }
                $booking['documents'] = $docs;
            } catch (\Throwable) {
                $booking['documents'] = [];
            }

            // Decode JSON columns.
            foreach (['available_actions', 'refund_conditions', 'change_conditions', 'booking_references'] as $col) {
                if (!empty($booking[$col]) && is_string($booking[$col])) {
                    $booking[$col] = json_decode($booking[$col], true);
                }
            }

        } elseif ($type === 'hotel') {
            $stmt = $db->prepare(
                'SELECT hb.*, u.email AS user_email, u.first_name, u.last_name
                 FROM hotel_bookings hb
                 LEFT JOIN users u ON u.id = hb.user_id
                 WHERE hb.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$booking) {
                Response::notFound('Hotel booking not found.');
            }

            // Rooms.
            $roomStmt = $db->prepare(
                'SELECT * FROM hotel_booking_rooms WHERE booking_id = ?'
            );
            $roomStmt->execute([$id]);
            $booking['rooms'] = $roomStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Guests.
            $guestStmt = $db->prepare(
                'SELECT * FROM hotel_booking_guests WHERE booking_id = ?'
            );
            $guestStmt->execute([$id]);
            $booking['guests'] = $guestStmt->fetchAll(\PDO::FETCH_ASSOC);

        } else {
            Response::error('Invalid booking type. Use "flight" or "hotel".', 400);
        }

        // Payment info.
        $paymentStmt = $db->prepare(
            'SELECT * FROM payments WHERE booking_type = ? AND booking_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $paymentStmt->execute([$type, $id]);
        $booking['payment'] = $paymentStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        Response::json(['booking' => $booking]);
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/bookings/:id  (lookup by numeric id — tries flight first)
    // -------------------------------------------------------------------------

    public function showById(Request $request): void
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        // Try flight booking.
        $stmt = $db->prepare(
            'SELECT fb.*, "flight" AS booking_type, u.email AS user_email, u.first_name, u.last_name
             FROM flight_bookings fb LEFT JOIN users u ON u.id = fb.user_id
             WHERE fb.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
        $type = 'flight';

        if (!$booking) {
            // Try hotel booking.
            $stmt = $db->prepare(
                'SELECT hb.*, "hotel" AS booking_type, u.email AS user_email, u.first_name, u.last_name
                 FROM hotel_bookings hb LEFT JOIN users u ON u.id = hb.user_id
                 WHERE hb.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
            $type = 'hotel';
        }

        if (!$booking) {
            Response::notFound('Booking not found.');
        }

        if ($type === 'flight') {
            // Decode JSON columns.
            foreach (['available_actions', 'refund_conditions', 'change_conditions', 'booking_references'] as $col) {
                if (!empty($booking[$col]) && is_string($booking[$col])) {
                    $booking[$col] = json_decode($booking[$col], true);
                }
            }
            // Passengers.
            $stmt = $db->prepare('SELECT * FROM flight_booking_passengers WHERE booking_id = ? ORDER BY id');
            $stmt->execute([$id]);
            $booking['passengers'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            // Segments.
            $stmt = $db->prepare('SELECT * FROM flight_booking_segments WHERE booking_id = ? ORDER BY slice_index, segment_order');
            $stmt->execute([$id]);
            $booking['segments'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            // Documents.
            try {
                $stmt = $db->prepare('SELECT * FROM flight_booking_documents WHERE booking_id = ? ORDER BY id');
                $stmt->execute([$id]);
                $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($docs as &$doc) {
                    if (!empty($doc['passenger_ids']) && is_string($doc['passenger_ids'])) {
                        $doc['passenger_ids'] = json_decode($doc['passenger_ids'], true);
                    }
                }
                $booking['documents'] = $docs;
            } catch (\Throwable) {
                $booking['documents'] = [];
            }
        } else {
            // Hotel rooms.
            $stmt = $db->prepare('SELECT * FROM hotel_booking_rooms WHERE booking_id = ?');
            $stmt->execute([$id]);
            $booking['rooms'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            // Hotel guests.
            $stmt = $db->prepare('SELECT * FROM hotel_booking_guests WHERE booking_id = ?');
            $stmt->execute([$id]);
            $booking['guests'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Payment.
        $stmt = $db->prepare('SELECT * FROM payments WHERE booking_type = ? AND booking_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$type, $id]);
        $booking['payment'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        Response::json(['booking' => $booking, 'type' => $type]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/admin/bookings/:id/status  (used by admin bookings page)
    // -------------------------------------------------------------------------

    public function patchStatus(Request $request): void
    {
        $id        = (int) $request->param('id');
        $newStatus = trim((string) ($request->input('status', '')));
        $db        = Database::getInstance();

        // Find booking in flight_bookings first.
        $stmt = $db->prepare('SELECT id, status, user_id FROM flight_bookings WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
        $type = 'flight';
        $table = 'flight_bookings';

        if (!$booking) {
            $stmt = $db->prepare('SELECT id, status, user_id FROM hotel_bookings WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
            $type = 'hotel';
            $table = 'hotel_bookings';
        }

        if (!$booking) {
            Response::notFound('Booking not found.');
        }

        if (!in_array($newStatus, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
            Response::error('Invalid status. Allowed: pending, confirmed, completed, cancelled.', 422);
        }
        $dbStatus = $newStatus;
        $setCancelled = $dbStatus === 'cancelled' ? ', cancelled_at = NOW()' : '';
        $db->prepare("UPDATE $table SET status = ? $setCancelled WHERE id = ?")->execute([$dbStatus, $id]);

        Response::json(['message' => 'Status updated.', 'status' => $dbStatus]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/admin/bookings/:type/:id/status
    // -------------------------------------------------------------------------

    public function updateStatus(Request $request): void
    {
        $type      = $request->param('type');
        $id        = (int) $request->param('id');
        $newStatus = trim((string) ($request->input('status', '')));
        $db        = Database::getInstance();

        $allowedStatuses = ['confirmed', 'cancelled', 'refunded'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Response::error('Invalid status. Allowed: confirmed, cancelled, refunded.', 422);
        }

        if ($type === 'flight') {
            $table        = 'flight_bookings';
            $validStatuses = ['pending', 'confirmed', 'cancelled', 'changed'];
        } elseif ($type === 'hotel') {
            $table        = 'hotel_bookings';
            $validStatuses = ['pending', 'confirmed', 'cancelled', 'no_show'];
        } else {
            Response::error('Invalid booking type. Use "flight" or "hotel".', 400);
        }

        // Fetch current booking.
        $safeTable = $type === 'flight' ? 'flight_bookings' : 'hotel_bookings';
        $stmt = $db->prepare("SELECT * FROM " . $safeTable . " WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$booking) {
            Response::notFound('Booking not found.');
        }

        // Map refunded -> cancelled for DB (refunded not in enum, store as cancelled).
        $dbStatus = $newStatus === 'refunded' ? 'cancelled' : $newStatus;

        // Only update if status is valid for this table.
        if (!in_array($dbStatus, $validStatuses, true)) {
            Response::error("Status '$newStatus' is not valid for $type bookings.", 422);
        }

        if ($dbStatus === 'cancelled') {
            $db->prepare("UPDATE " . $safeTable . " SET status = ?, cancelled_at = NOW() WHERE id = ?")
               ->execute([$dbStatus, $id]);
        } else {
            $db->prepare("UPDATE " . $safeTable . " SET status = ? WHERE id = ?")
               ->execute([$dbStatus, $id]);
        }

        // Log to booking_audit_log.
        $admin = AdminMiddleware::currentAdmin();
        $db->prepare(
            'INSERT INTO booking_audit_log
             (booking_type, booking_id, action, changed_by, changed_by_type, before_data, after_data)
             VALUES (?, ?, ?, ?, "admin", ?, ?)'
        )->execute([
            $type,
            $id,
            'status_changed',
            $admin['user_id'] ?? null,
            json_encode(['status' => $booking['status']]),
            json_encode(['status' => $dbStatus]),
        ]);

        // If cancelled, queue cancellation email.
        if ($dbStatus === 'cancelled') {
            $db->prepare(
                'INSERT INTO job_queue (job_type, payload) VALUES ("cancel_booking_email", ?)'
            )->execute([
                json_encode([
                    'booking_type' => $type,
                    'booking_id'   => $id,
                    'user_id'      => $booking['user_id'],
                ]),
            ]);
        }

        Response::json(['message' => 'Booking status updated.', 'status' => $dbStatus]);
    }
}
