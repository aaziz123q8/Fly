<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminAnalyticsController
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function periodToDays(string $period): int
    {
        return match ($period) {
            '7d'  => 7,
            '90d' => 90,
            '1y'  => 365,
            default => 30, // 30d
        };
    }

    private function periodStartDate(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    // =========================================================================
    // GET /api/admin/analytics/overview
    // =========================================================================

    public function overview(Request $request): void
    {
        $period = (string)($request->query('period') ?? '30d');
        $days   = $this->periodToDays($period);
        $start  = $this->periodStartDate($days);

        $db = Database::getInstance();

        // Searches
        $searchStmt = $db->prepare(
            'SELECT search_type, COUNT(*) AS cnt
             FROM search_logs
             WHERE created_at >= ?
             GROUP BY search_type'
        );
        $searchStmt->execute([$start]);
        $searchRows    = $searchStmt->fetchAll(\PDO::FETCH_ASSOC);
        $flightSearches = 0;
        $hotelSearches  = 0;
        foreach ($searchRows as $row) {
            if ($row['search_type'] === 'flight') {
                $flightSearches = (int)$row['cnt'];
            } elseif ($row['search_type'] === 'hotel') {
                $hotelSearches = (int)$row['cnt'];
            }
        }

        // Bookings — flight_bookings
        $fbStmt = $db->prepare(
            'SELECT COUNT(*) FROM flight_bookings WHERE created_at >= ?'
        );
        $fbStmt->execute([$start]);
        $flightBookings = (int)$fbStmt->fetchColumn();

        // hotel_bookings
        $hbStmt = $db->prepare(
            'SELECT COUNT(*) FROM hotel_bookings WHERE created_at >= ?'
        );
        $hbStmt->execute([$start]);
        $hotelBookings = (int)$hbStmt->fetchColumn();

        $totalBookings  = $flightBookings + $hotelBookings;
        $totalSearches  = $flightSearches + $hotelSearches;
        $conversionRate = $totalSearches > 0
            ? number_format(($totalBookings / $totalSearches) * 100, 2) . '%'
            : '0.00%';

        // Revenue from payments
        $revStmt = $db->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
             FROM payments
             WHERE status = "succeeded" AND created_at >= ?'
        );
        $revStmt->execute([$start]);
        $revRow  = $revStmt->fetch(\PDO::FETCH_ASSOC);
        $revenue = number_format((float)$revRow['total'], 2, '.', '');
        $avgVal  = $revRow['cnt'] > 0
            ? number_format((float)$revRow['total'] / (int)$revRow['cnt'], 2, '.', '')
            : '0.00';

        // Top routes
        $routesStmt = $db->prepare(
            'SELECT origin_airport AS origin, destination_airport AS destination, COUNT(*) AS count
             FROM search_logs
             WHERE search_type = "flight"
               AND origin_airport IS NOT NULL
               AND destination_airport IS NOT NULL
               AND created_at >= ?
             GROUP BY origin_airport, destination_airport
             ORDER BY count DESC
             LIMIT 5'
        );
        $routesStmt->execute([$start]);
        $topRoutes = $routesStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Top destinations
        $destStmt = $db->prepare(
            'SELECT destination_airport AS destination, COUNT(*) AS count
             FROM search_logs
             WHERE search_type = "flight"
               AND destination_airport IS NOT NULL
               AND created_at >= ?
             GROUP BY destination_airport
             ORDER BY count DESC
             LIMIT 5'
        );
        $destStmt->execute([$start]);
        $topDest = $destStmt->fetchAll(\PDO::FETCH_ASSOC);

        // New users
        $newUsersStmt = $db->prepare(
            'SELECT COUNT(*) FROM users WHERE created_at >= ?'
        );
        $newUsersStmt->execute([$start]);
        $newUsers = (int)$newUsersStmt->fetchColumn();

        // Active users (those with a session active in period)
        $activeUsersStmt = $db->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE created_at >= ?'
        );
        $activeUsersStmt->execute([$start]);
        $activeUsers = (int)$activeUsersStmt->fetchColumn();

        Response::json([
            'period'           => $period,
            'searches'         => [
                'total'   => $totalSearches,
                'flights' => $flightSearches,
                'hotels'  => $hotelSearches,
            ],
            'bookings'         => [
                'total'           => $totalBookings,
                'flights'         => $flightBookings,
                'hotels'          => $hotelBookings,
                'conversion_rate' => $conversionRate,
            ],
            'revenue'          => [
                'total'              => $revenue,
                'currency'           => 'GBP',
                'avg_booking_value'  => $avgVal,
            ],
            'top_routes'       => $topRoutes,
            'top_destinations' => $topDest,
            'new_users'        => $newUsers,
            'active_users'     => $activeUsers,
        ]);
    }

    // =========================================================================
    // GET /api/admin/analytics/searches
    // =========================================================================

    public function searches(Request $request): void
    {
        $period = (string)($request->query('period') ?? '30d');
        $days   = $this->periodToDays($period);
        $start  = $this->periodStartDate($days);

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT DATE(created_at) AS label, search_type, COUNT(*) AS cnt
             FROM search_logs
             WHERE created_at >= ?
             GROUP BY DATE(created_at), search_type
             ORDER BY label ASC'
        );
        $stmt->execute([$start]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build date range
        $labels  = [];
        $flights = [];
        $hotels  = [];

        // Index rows by date+type
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['label']][$row['search_type']] = (int)$row['cnt'];
        }

        // Generate all dates in range
        $current = strtotime($start);
        $end     = strtotime(date('Y-m-d'));
        while ($current <= $end) {
            $date     = date('Y-m-d', $current);
            $labels[] = $date;
            $flights[] = $indexed[$date]['flight'] ?? 0;
            $hotels[]  = $indexed[$date]['hotel'] ?? 0;
            $current   = strtotime('+1 day', $current);
        }

        Response::json([
            'period'  => $period,
            'labels'  => $labels,
            'flights' => $flights,
            'hotels'  => $hotels,
        ]);
    }

    // =========================================================================
    // GET /api/admin/analytics/revenue
    // =========================================================================

    public function revenue(Request $request): void
    {
        $period = (string)($request->query('period') ?? '30d');
        $days   = $this->periodToDays($period);
        $start  = $this->periodStartDate($days);

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT DATE(created_at) AS label, SUM(amount) AS total
             FROM payments
             WHERE status = "succeeded" AND created_at >= ?
             GROUP BY DATE(created_at)
             ORDER BY label ASC'
        );
        $stmt->execute([$start]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['label']] = number_format((float)$row['total'], 2, '.', '');
        }

        $labels  = [];
        $amounts = [];
        $current = strtotime($start);
        $end     = strtotime(date('Y-m-d'));
        while ($current <= $end) {
            $date     = date('Y-m-d', $current);
            $labels[] = $date;
            $amounts[] = $indexed[$date] ?? '0.00';
            $current   = strtotime('+1 day', $current);
        }

        Response::json([
            'period'  => $period,
            'labels'  => $labels,
            'amounts' => $amounts,
        ]);
    }

    // =========================================================================
    // GET /api/admin/analytics/popular-routes
    // =========================================================================

    public function popularRoutes(Request $request): void
    {
        $period = (string)($request->query('period') ?? '30d');
        $days   = $this->periodToDays($period);
        $start  = $this->periodStartDate($days);

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT origin_airport AS origin, destination_airport AS destination,
                    COUNT(*) AS count
             FROM search_logs
             WHERE search_type = "flight"
               AND origin_airport IS NOT NULL
               AND destination_airport IS NOT NULL
               AND created_at >= ?
             GROUP BY origin_airport, destination_airport
             ORDER BY count DESC
             LIMIT 10'
        );
        $stmt->execute([$start]);
        $routes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'period' => $period,
            'data'   => $routes,
        ]);
    }
}
