<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\RateHawk\RateHawkAdapter;
use App\Helpers\Database;
use PDO;
use RuntimeException;

class HotelSearchService
{
    private PDO $db;
    private RateHawkAdapter $rateHawk;

    public function __construct(?PDO $db = null, ?RateHawkAdapter $rateHawk = null)
    {
        $this->db       = $db       ?? Database::getInstance();
        $this->rateHawk = $rateHawk ?? new RateHawkAdapter();
    }

    // =========================================================================
    // search
    // =========================================================================

    /**
     * Search for hotels.
     *
     * @param array $params {
     *   city_id (int, optional if ratehawk_region_id provided),
     *   ratehawk_region_id (int, optional if city_id provided),
     *   check_in  (YYYY-MM-DD, required),
     *   check_out (YYYY-MM-DD, required),
     *   rooms     (int, default 1),
     *   adults    (int, required),
     *   children  (int[], ages, default []),
     * }
     * @param int $userId  0 if unauthenticated
     * @return array
     */
    public function search(array $params, int $userId = 0): array
    {
        $checkIn   = trim($params['check_in']  ?? '');
        $checkOut  = trim($params['check_out'] ?? '');
        $rooms     = max(1, (int) ($params['rooms']  ?? 1));
        $adults    = max(1, (int) ($params['adults'] ?? 1));
        $children  = is_array($params['children'] ?? null) ? $params['children'] : [];
        $cityId    = isset($params['city_id']) ? (int) $params['city_id'] : null;
        $regionId  = isset($params['ratehawk_region_id']) ? (int) $params['ratehawk_region_id'] : null;

        // ── Date validation ──────────────────────────────────────────────────
        $now      = new \DateTimeImmutable('today');
        $inDate   = \DateTimeImmutable::createFromFormat('Y-m-d', $checkIn);
        $outDate  = \DateTimeImmutable::createFromFormat('Y-m-d', $checkOut);

        if ($inDate === false || $outDate === false) {
            throw new RuntimeException('Invalid date format. Use YYYY-MM-DD.', 422);
        }

        if ($inDate < $now) {
            throw new RuntimeException('check_in must be a future date.', 422);
        }

        if ($outDate <= $inDate) {
            throw new RuntimeException('check_out must be after check_in.', 422);
        }

        $nights = (int) $inDate->diff($outDate)->days;

        // ── Resolve region ID from city if needed ────────────────────────────
        if ($regionId === null && $cityId !== null) {
            $regionId = $this->resolveRegionId($cityId);
        }

        if ($regionId === null) {
            throw new RuntimeException('Either city_id or ratehawk_region_id is required.', 422);
        }

        // ── Build guests array (distribute adults evenly across rooms) ───────
        $baseAdults   = intdiv($adults, $rooms);
        $extraAdults  = $adults % $rooms;
        $guestsPayload = [];

        for ($i = 0; $i < $rooms; $i++) {
            $roomAdults = $baseAdults + ($i < $extraAdults ? 1 : 0);
            // Assign all children to first room for simplicity
            $roomChildren = ($i === 0) ? array_map('intval', $children) : [];

            $guestsPayload[] = [
                'adults'   => max(1, $roomAdults),
                'children' => $roomChildren,
            ];
        }

        // ── Call RateHawk ────────────────────────────────────────────────────
        $searchPayload = [
            'checkin'   => $checkIn,
            'checkout'  => $checkOut,
            'guests'    => $guestsPayload,
            'region_id' => $regionId,
            'currency'  => 'GBP',
            'language'  => 'en',
            'timeout'   => 15,
        ];

        $response = $this->rateHawk->search($searchPayload);

        $rawHotels = $response['data']['hotels'] ?? $response['hotels'] ?? [];

        // ── Enrich results with local DB content ─────────────────────────────
        $formattedHotels = [];

        foreach ($rawHotels as $hotel) {
            $formattedHotels[] = $this->enrichHotelResult($hotel);
        }

        // ── Log search ───────────────────────────────────────────────────────
        $this->logSearch(
            userId:         $userId,
            cityId:         $cityId,
            checkIn:        $checkIn,
            checkOut:       $checkOut,
            adults:         $adults,
            childrenCount:  count($children),
            resultsCount:   count($formattedHotels)
        );

        return $formattedHotels;
    }

    // =========================================================================
    // getHotelDetail
    // =========================================================================

    /**
     * Get local hotel content + images for a given provider hotel ID.
     *
     * @param string $providerHotelId  RateHawk hotel ID
     * @return array|null
     */
    public function getHotelDetail(string $providerHotelId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT hc.*
             FROM hotels_content hc
             WHERE hc.provider_hotel_id = :phid AND hc.provider_id = 2
             LIMIT 1'
        );
        $stmt->execute([':phid' => $providerHotelId]);
        $hotel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hotel) {
            return null;
        }

        // Decode amenities JSON
        if (isset($hotel['amenities']) && is_string($hotel['amenities'])) {
            $hotel['amenities'] = json_decode($hotel['amenities'], true) ?? [];
        }

        // Fetch images
        $imgStmt = $this->db->prepare(
            'SELECT id, url, caption_en, is_main, display_order, category
             FROM hotel_images
             WHERE hotel_id = :hid
             ORDER BY display_order ASC
             LIMIT 20'
        );
        $imgStmt->execute([':hid' => $hotel['id']]);
        $hotel['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

        return $hotel;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Look up the RateHawk region_id from cities table.
     */
    private function resolveRegionId(int $cityId): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT ratehawk_region_id FROM cities WHERE id = :cid LIMIT 1'
            );
            $stmt->execute([':cid' => $cityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['ratehawk_region_id'])) {
                return (int) $row['ratehawk_region_id'];
            }
        } catch (\Throwable) {
            // Column may not exist; fall through to null
        }

        return null;
    }

    /**
     * Enrich a raw RateHawk hotel result with local DB content if available.
     */
    private function enrichHotelResult(array $raw): array
    {
        $providerHotelId = $raw['id'] ?? $raw['hotel_id'] ?? '';

        $local = null;
        if ($providerHotelId !== '') {
            try {
                $stmt = $this->db->prepare(
                    'SELECT id, name_en, name_ar, star_rating, guest_rating,
                            main_image_url, amenities, city_id
                     FROM hotels_content
                     WHERE provider_hotel_id = :phid AND provider_id = 2
                     LIMIT 1'
                );
                $stmt->execute([':phid' => $providerHotelId]);
                $local = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable) {
                // Non-critical; proceed without local data
            }
        }

        $rates = $raw['rates'] ?? $raw['rooms'] ?? [];

        return [
            'provider_hotel_id' => $providerHotelId,
            'name_en'           => $local['name_en']       ?? ($raw['name'] ?? $raw['hotel_name'] ?? ''),
            'name_ar'           => $local['name_ar']       ?? null,
            'star_rating'       => $local['star_rating']   ?? ($raw['star_rating'] ?? null),
            'guest_rating'      => $local['guest_rating']  ?? ($raw['guest_rating'] ?? null),
            'main_image_url'    => $local['main_image_url'] ?? ($raw['main_photo_url'] ?? null),
            'amenities'         => $local
                                    ? (json_decode($local['amenities'] ?? '[]', true) ?? [])
                                    : ($raw['amenities'] ?? []),
            'city_id'           => $local['city_id']       ?? null,
            'local_hotel_id'    => $local['id']            ?? null,
            'rates'             => $rates,
            'raw'               => $raw,
        ];
    }

    /**
     * Log search to search_logs table.
     */
    private function logSearch(
        int  $userId,
        ?int $cityId,
        string $checkIn,
        string $checkOut,
        int  $adults,
        int  $childrenCount,
        int  $resultsCount
    ): void {
        $ip = $this->resolveIp();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO search_logs
                   (user_id, search_type, destination_city_id, travel_date,
                    return_date, adults_count, children_count, results_count, ip_address)
                 VALUES
                   (:user_id, :search_type, :city_id, :travel_date,
                    :return_date, :adults, :children, :results, :ip)'
            );
            $stmt->execute([
                ':user_id'     => $userId > 0 ? $userId : null,
                ':search_type' => 'hotel',
                ':city_id'     => $cityId,
                ':travel_date' => $checkIn,
                ':return_date' => $checkOut,
                ':adults'      => $adults,
                ':children'    => $childrenCount,
                ':results'     => $resultsCount,
                ':ip'          => $ip,
            ]);
        } catch (\Throwable) {
            // Non-critical — swallow logging errors silently.
        }
    }

    private function resolveIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
