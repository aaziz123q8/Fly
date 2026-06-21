<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminDashboardController
{
    // -------------------------------------------------------------------------
    // GET /api/admin/dashboard
    // -------------------------------------------------------------------------

    public function index(Request $request): void
    {
        $db = Database::getInstance();

        // --- Stats ---
        $totalUsers = (int) $db->query(
            'SELECT COUNT(*) FROM users WHERE role = "user"'
        )->fetchColumn();

        $activeUsers30d = (int) $db->query(
            'SELECT COUNT(*) FROM users WHERE role = "user" AND last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        )->fetchColumn();

        $totalFlightBookings = (int) $db->query(
            'SELECT COUNT(*) FROM flight_bookings'
        )->fetchColumn();

        $totalHotelBookings = (int) $db->query(
            'SELECT COUNT(*) FROM hotel_bookings'
        )->fetchColumn();

        $flightRevenue = (string) ($db->query(
            'SELECT COALESCE(SUM(total_amount), 0) FROM flight_bookings WHERE status IN ("confirmed","pending")'
        )->fetchColumn() ?? '0.00');

        $hotelRevenue = (string) ($db->query(
            'SELECT COALESCE(SUM(total_amount), 0) FROM hotel_bookings WHERE status IN ("confirmed","pending")'
        )->fetchColumn() ?? '0.00');

        $totalRevenue = number_format((float) $flightRevenue + (float) $hotelRevenue, 2, '.', '');

        $pendingJobs = (int) $db->query(
            'SELECT COUNT(*) FROM job_queue WHERE status = "pending"'
        )->fetchColumn();

        $failedJobs = (int) $db->query(
            'SELECT COUNT(*) FROM job_queue WHERE status = "failed"'
        )->fetchColumn();

        $recentErrors = (int) $db->query(
            'SELECT COUNT(*) FROM error_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        )->fetchColumn();

        // --- Recent bookings (last 10 flight + hotel combined) ---
        $recentFlights = $db->query(
            'SELECT fb.id, "flight" AS booking_type, fb.booking_reference,
                    fb.status, fb.total_amount, fb.currency,
                    fb.origin_airport, fb.destination_airport, fb.departure_at,
                    fb.created_at,
                    u.email AS user_email, u.first_name, u.last_name
             FROM flight_bookings fb
             JOIN users u ON u.id = fb.user_id
             ORDER BY fb.created_at DESC
             LIMIT 10'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $recentHotels = $db->query(
            'SELECT hb.id, "hotel" AS booking_type, hb.booking_reference,
                    hb.status, hb.total_amount, hb.currency,
                    hb.check_in_date, hb.check_out_date,
                    hb.created_at,
                    u.email AS user_email, u.first_name, u.last_name
             FROM hotel_bookings hb
             JOIN users u ON u.id = hb.user_id
             ORDER BY hb.created_at DESC
             LIMIT 10'
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Merge and sort by created_at desc, take top 10.
        $allBookings = array_merge($recentFlights, $recentHotels);
        usort($allBookings, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $recentBookings = array_slice($allBookings, 0, 10);

        // --- Recent users (last 10) ---
        $recentUsers = $db->query(
            'SELECT id, email, first_name, last_name, is_active, created_at, last_login_at
             FROM users
             WHERE role = "user"
             ORDER BY created_at DESC
             LIMIT 10'
        )->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'stats' => [
                'total_users'           => $totalUsers,
                'active_users_30d'      => $activeUsers30d,
                'total_flight_bookings' => $totalFlightBookings,
                'total_hotel_bookings'  => $totalHotelBookings,
                'total_revenue'         => $totalRevenue,
                'revenue_currency'      => 'GBP',
                'pending_jobs'          => $pendingJobs,
                'failed_jobs'           => $failedJobs,
                'recent_errors'         => $recentErrors,
            ],
            'recent_bookings' => $recentBookings,
            'recent_users'    => $recentUsers,
        ]);
    }
}
