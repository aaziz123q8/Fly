<?php

declare(strict_types=1);

namespace App\Adapters\RateHawk;

/**
 * RateHawkAdapter
 *
 * Thin wrapper around the RateHawk (WorldOTA) B2B hotel API.
 * @see https://docs.emergingtravel.com/
 */
class RateHawkAdapter
{
    private string $keyId;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(array $config = [])
    {
        if (empty($config)) {
            $config = \App\Helpers\ConfigLoader::load('apis')['ratehawk'] ?? [];
        }
        $this->keyId   = $config['key_id']   ?? (getenv('RATEHAWK_KEY_ID')  ?: '');
        $this->apiKey  = $config['api_key']  ?? (getenv('RATEHAWK_API_KEY') ?: '');
        $this->baseUrl = $config['base_url'] ?? (getenv('RATEHAWK_BASE_URL') ?: 'https://api.worldota.net/api/b2b/v3');
    }

    // =========================================================================
    // Hotel Search
    // =========================================================================

    /**
     * Search for hotels in a region or by IDs.
     *
     * @param  array  $params  RateHawk search parameters.
     *   Required: checkin (YYYY-MM-DD), checkout (YYYY-MM-DD), guests (array of room guest counts),
     *             region_id OR ids (array of hotel IDs).
     *   Optional: currency, language, residency, timeout.
     */
    public function search(array $params): array
    {
        return $this->post('/hotel/search/', $params);
    }

    /**
     * Get a detailed dump of hotels in a region (for content sync).
     */
    public function searchByRegion(
        int $regionId,
        string $checkin,
        string $checkout,
        array $guests,
        string $currency = 'GBP',
        string $language = 'en'
    ): array {
        return $this->search([
            'checkin'   => $checkin,
            'checkout'  => $checkout,
            'guests'    => $guests,
            'region_id' => $regionId,
            'currency'  => $currency,
            'language'  => $language,
            'timeout'   => 15,
        ]);
    }

    // =========================================================================
    // Prebook
    // =========================================================================

    /**
     * Pre-book a hotel rate to lock the price before payment.
     *
     * @param  string $bookHash  The book_hash from the search results.
     */
    public function prebook(string $bookHash): array
    {
        return $this->post('/hotel/prebook/', ['book_hash' => $bookHash]);
    }

    // =========================================================================
    // Booking
    // =========================================================================

    /**
     * Create a hotel booking.
     *
     * @param  string $prebookId   Session ID returned by prebook().
     * @param  array  $leadGuest   Lead guest info: {first_name, last_name, email, phone}.
     * @param  array  $rooms       Room-level guest details.
     * @param  string $partnerRef  Your internal booking reference.
     * @param  string|null $specialRequests
     */
    public function createBooking(
        string $prebookId,
        array $leadGuest,
        array $rooms,
        string $partnerRef,
        ?string $specialRequests = null
    ): array {
        $payload = [
            'prebook_id'    => $prebookId,
            'partner_order_id' => $partnerRef,
            'guests'        => $rooms,
            'leader_guest'  => $leadGuest,
            'language'      => 'en',
        ];

        if ($specialRequests !== null) {
            $payload['special_requests'] = $specialRequests;
        }

        return $this->post('/hotel/order/booking/finish/', $payload);
    }

    /**
     * Retrieve booking details by RateHawk order ID.
     */
    public function getBooking(string $orderId): array
    {
        return $this->post('/hotel/order/info/', ['order_id' => $orderId]);
    }

    /**
     * Cancel a booking.
     */
    public function cancelBooking(string $orderId): array
    {
        return $this->post('/hotel/order/cancel/', ['order_id' => $orderId]);
    }

    // =========================================================================
    // Hotel Content
    // =========================================================================

    /**
     * Fetch static hotel content (description, images, amenities).
     *
     * @param  array $hotelIds  Array of RateHawk hotel IDs.
     */
    public function getHotelInfo(array $hotelIds, string $language = 'en'): array
    {
        return $this->post('/hotel/info/', [
            'ids'      => $hotelIds,
            'language' => $language,
        ]);
    }

    // =========================================================================
    // HTTP helpers
    // =========================================================================

    private function post(string $path, array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->apiKey,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('RateHawk API cURL error: ' . $curlError);
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('RateHawk API returned non-JSON response (HTTP ' . $statusCode . ').');
        }

        if ($statusCode >= 400 || ($decoded['status'] ?? '') === 'error') {
            $errorMsg = $decoded['error'] ?? $decoded['message'] ?? 'Unknown RateHawk error';
            throw new \RuntimeException('RateHawk API error (' . $statusCode . '): ' . $errorMsg, $statusCode);
        }

        return $decoded;
    }
}
