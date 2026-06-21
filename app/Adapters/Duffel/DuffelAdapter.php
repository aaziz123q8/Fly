<?php

declare(strict_types=1);

namespace App\Adapters\Duffel;

/**
 * DuffelAdapter
 *
 * Thin wrapper around the Duffel Flights REST API.
 * @see https://duffel.com/docs/api/v1
 */
class DuffelAdapter
{
    private string $apiKey;
    private string $baseUrl;
    private string $version;

    public function __construct(array $config = [])
    {
        $this->apiKey  = $config['api_key']  ?? (getenv('DUFFEL_API_KEY')  ?: '');
        $this->baseUrl = $config['base_url'] ?? (getenv('DUFFEL_BASE_URL') ?: 'https://api.duffel.com');
        $this->version = $config['version']  ?? 'v1';
    }

    // =========================================================================
    // Offer Requests (search)
    // =========================================================================

    /**
     * Create an offer request (flight search).
     *
     * @param  array $slices  Array of slice objects: [{origin, destination, departure_date}]
     * @param  array $passengers  Array of passenger types: [{type: 'adult'|'child'|'infant_without_seat'}]
     * @param  string $cabinClass  economy | premium_economy | business | first
     * @return array  Duffel API response body (decoded).
     */
    public function searchOffers(array $slices, array $passengers, string $cabinClass = 'economy'): array
    {
        $body = [
            'data' => [
                'slices'       => $slices,
                'passengers'   => $passengers,
                'cabin_class'  => $cabinClass,
            ],
        ];

        return $this->post('/air/offer_requests?return_offers=true', $body);
    }

    /**
     * List offers for a previously created offer request.
     *
     * @param  string $offerRequestId
     * @param  array  $filters  Optional query parameters (max_connections, etc.)
     */
    public function listOffers(string $offerRequestId, array $filters = []): array
    {
        $query = array_merge(['offer_request_id' => $offerRequestId], $filters);
        return $this->get('/air/offers', $query);
    }

    /**
     * Retrieve a single offer by ID.
     */
    public function getOffer(string $offerId): array
    {
        return $this->get('/air/offers/' . urlencode($offerId));
    }

    // =========================================================================
    // Orders (booking)
    // =========================================================================

    /**
     * Create a Duffel order (confirmed booking).
     *
     * @param  string $selectedOfferId  Offer ID chosen by the passenger.
     * @param  array  $passengers       Array of passenger objects with personal details.
     * @param  array  $payments         Payment details (e.g. [{type: 'balance', amount, currency}]).
     * @param  array  $services         Optional ancillary services (bags, seats).
     * @param  string|null $metadata    Optional merchant metadata string.
     */
    public function createOrder(
        string $selectedOfferId,
        array $passengers,
        array $payments,
        array $services = [],
        ?string $metadata = null
    ): array {
        $data = [
            'type'               => 'instant',
            'selected_offers'    => [$selectedOfferId],
            'passengers'         => $passengers,
            'payments'           => $payments,
        ];

        if (!empty($services)) {
            $data['services'] = $services;
        }

        if ($metadata !== null) {
            $data['metadata'] = $metadata;
        }

        return $this->post('/air/orders', ['data' => $data]);
    }

    /**
     * Retrieve an existing order by ID.
     */
    public function getOrder(string $orderId): array
    {
        return $this->get('/air/orders/' . urlencode($orderId));
    }

    /**
     * Cancel an order (initiates cancellation quote).
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->post('/air/order_cancellations', [
            'data' => ['order_id' => $orderId],
        ]);
    }

    /**
     * Confirm an order cancellation.
     */
    public function confirmCancellation(string $cancellationId): array
    {
        return $this->post('/air/order_cancellations/' . urlencode($cancellationId) . '/actions/confirm', []);
    }

    // =========================================================================
    // Seat Maps
    // =========================================================================

    public function getSeatMaps(string $offerId): array
    {
        return $this->get('/air/seat_maps', ['offer_id' => $offerId]);
    }

    // =========================================================================
    // HTTP helpers
    // =========================================================================

    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Duffel-Version: ' . $this->version,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response   = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Duffel API cURL error: ' . $curlError);
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Duffel API returned non-JSON response (HTTP ' . $statusCode . ').');
        }

        if ($statusCode >= 400) {
            $errorMsg = $decoded['errors'][0]['message'] ?? 'Unknown Duffel API error';
            throw new \RuntimeException('Duffel API error (' . $statusCode . '): ' . $errorMsg, $statusCode);
        }

        return $decoded;
    }
}
