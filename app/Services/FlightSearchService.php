<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\Duffel\DuffelAdapter;
use App\Helpers\Database;
use PDO;

class FlightSearchService
{
    private PDO $db;
    private DuffelAdapter $duffel;

    public function __construct(?PDO $db = null, ?DuffelAdapter $duffel = null)
    {
        $this->db     = $db     ?? Database::getInstance();
        $this->duffel = $duffel ?? new DuffelAdapter();
    }

    /**
     * Search for flight offers, with offer_cache caching.
     *
     * @param  array $params {
     *   origin, destination, departure_date,
     *   return_date (nullable), cabin_class,
     *   adults (int), children (int[]) ages
     * }
     * @param  int $userId  0 if unauthenticated
     * @return array  Formatted offer list
     */
    public function search(array $params, int $userId = 0): array
    {
        $origin        = strtoupper(trim($params['origin']        ?? ''));
        $destination   = strtoupper(trim($params['destination']   ?? ''));
        $departureDate = $this->normalizeDate(trim($params['departure_date'] ?? ''));
        $returnDateRaw = !empty($params['return_date']) ? trim($params['return_date']) : null;
        $returnDate    = $returnDateRaw ? $this->normalizeDate($returnDateRaw) : null;
        $cabinClass    = $params['cabin_class'] ?? 'economy';
        $adults        = max(1, (int) ($params['adults'] ?? 1));
        $children      = is_array($params['children'] ?? null) ? $params['children'] : [];
        $infantCount   = (int) ($params['infants'] ?? 0);

        // Sort children ages so hash is deterministic regardless of input order.
        $childrenSorted = $children;
        sort($childrenSorted);

        $searchHash = md5(
            $origin . $destination . $departureDate .
            ($returnDate ?? '') . $cabinClass . $adults .
            implode(',', $childrenSorted) . 'i' . $infantCount
        );

        // ── 1. Cache lookup ──────────────────────────────────────────────────
        try {
            $cachedOffers = $this->fetchFromCache($searchHash);
        } catch (\Throwable) {
            $cachedOffers = null;
        }

        if ($cachedOffers !== null) {
            $this->logSearch($params, $userId, count($cachedOffers), $origin, $destination, $departureDate, $adults, count($children), $cabinClass);
            return $cachedOffers;
        }

        // ── 2. Build Duffel request ──────────────────────────────────────────
        $slices = [
            ['origin' => $origin, 'destination' => $destination, 'departure_date' => $departureDate],
        ];

        if ($returnDate !== null) {
            $slices[] = [
                'origin'         => $destination,
                'destination'    => $origin,
                'departure_date' => $returnDate,
            ];
        }

        $passengers = array_fill(0, $adults, ['type' => 'adult']);
        foreach ($children as $age) {
            $passengers[] = ['type' => 'child', 'age' => max(2, min(11, (int) $age))];
        }
        for ($i = 0; $i < $infantCount; $i++) {
            $passengers[] = ['type' => 'infant_without_seat', 'age' => 0];
        }

        // ── 3. Call Duffel ───────────────────────────────────────────────────
        $response = $this->duffel->searchOffers($slices, $passengers, $cabinClass);

        $offerRequestId = $response['data']['id'] ?? '';
        $rawOffers      = $response['data']['offers'] ?? [];

        // ── 4. Cache each offer ──────────────────────────────────────────────
        $formattedOffers = [];

        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO offer_cache
                   (offer_request_id, offer_id, provider_id, search_hash,
                    offer_data, total_amount, currency, expires_at)
                 VALUES
                   (:offer_request_id, :offer_id, 1, :search_hash,
                    :offer_data, :total_amount, :currency, :expires_at)'
            );
        } catch (\Throwable) {
            $stmt = null;
        }

        foreach ($rawOffers as $offer) {
            $offerId    = $offer['id']              ?? '';
            $amount     = $offer['total_amount']    ?? '0.00';
            $currency   = strtoupper($offer['total_currency'] ?? 'GBP');
            $expiresAt  = $offer['expires_at']      ?? date('Y-m-d H:i:s', time() + 3600);
            $offerJson  = json_encode($offer, JSON_UNESCAPED_UNICODE);

            if ($stmt !== null) {
                try {
                    $stmt->execute([
                        ':offer_request_id' => $offerRequestId,
                        ':offer_id'         => $offerId,
                        ':search_hash'      => $searchHash,
                        ':offer_data'       => $offerJson,
                        ':total_amount'     => $amount,
                        ':currency'         => $currency,
                        ':expires_at'       => date('Y-m-d H:i:s', strtotime($expiresAt)),
                    ]);
                } catch (\Throwable) {
                    // Cache insert failed — non-critical.
                }
            }

            $formattedOffers[] = $this->formatOffer($offer);
        }

        $this->logSearch($params, $userId, count($formattedOffers), $origin, $destination, $departureDate, $adults, count($children), $cabinClass);

        return $formattedOffers;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function fetchFromCache(string $searchHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT offer_id, offer_data, total_amount, currency, expires_at
             FROM offer_cache
             WHERE search_hash = :hash AND expires_at > NOW()'
        );
        $stmt->execute([':hash' => $searchHash]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return null;
        }

        $offers = [];
        foreach ($rows as $row) {
            $offerData = json_decode($row['offer_data'], true);
            if (!is_array($offerData)) {
                continue;
            }
            $offers[] = $this->formatOffer($offerData);
        }

        return empty($offers) ? null : $offers;
    }

    private function formatOffer(array $offer): array
    {
        return [
            'offer_id'               => $offer['id']                      ?? '',
            'total_amount'           => $offer['total_amount']             ?? '0.00',
            'currency'               => strtoupper($offer['total_currency'] ?? 'GBP'),
            'expires_at'             => $offer['expires_at']               ?? null,
            'slices'                 => $offer['slices']                   ?? [],
            'passengers'             => $offer['passengers']               ?? [],  // contains baggages per passenger
            'conditions'             => $offer['conditions']               ?? [],  // refund_before_departure / change_before_departure
            'partial_offer_requests' => $offer['partial_offer_requests']   ?? [],
        ];
    }

    private function logSearch(
        array  $params,
        int    $userId,
        int    $resultsCount,
        string $origin,
        string $destination,
        string $travelDate,
        int    $adults,
        int    $childrenCount,
        string $cabinClass
    ): void {
        $ip = $this->resolveIp();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO search_logs
                   (user_id, search_type, origin_airport, destination_airport,
                    travel_date, adults_count, children_count, cabin_class,
                    results_count, ip_address)
                 VALUES
                   (:user_id, :search_type, :origin, :destination,
                    :travel_date, :adults, :children, :cabin_class,
                    :results, :ip)'
            );
            $stmt->execute([
                ':user_id'     => $userId > 0 ? $userId : null,
                ':search_type' => 'flight',
                ':origin'      => $origin,
                ':destination' => $destination,
                ':travel_date' => $travelDate,
                ':adults'      => $adults,
                ':children'    => $childrenCount,
                ':cabin_class' => $cabinClass,
                ':results'     => $resultsCount,
                ':ip'          => $ip,
            ]);
        } catch (\Throwable) {
            // Non-critical — swallow logging errors silently.
        }
    }

    /**
     * Normalize a date string to YYYY-MM-DD for Duffel API.
     * Handles display formats like "30 Jun 2026" or "30/06/2026".
     */
    private function normalizeDate(string $date): string
    {
        if (empty($date)) return $date;
        // Already ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
        // Try PHP date parsing
        $ts = strtotime($date);
        if ($ts !== false) return date('Y-m-d', $ts);
        return $date;
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
