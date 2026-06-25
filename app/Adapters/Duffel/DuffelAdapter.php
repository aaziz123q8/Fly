<?php

declare(strict_types=1);

namespace App\Adapters\Duffel;

/**
 * DuffelAdapter — full Duffel Flights REST API v2 wrapper.
 * @see https://duffel.com/docs/api/v2
 */
class DuffelAdapter
{
    private string $apiKey;
    private string $baseUrl;
    private string $version;

    public function __construct(array $config = [])
    {
        if (empty($config) && defined('BASE_PATH')) {
            $cfgFile = BASE_PATH . '/config/apis.php';
            if (file_exists($cfgFile)) {
                $loaded = require $cfgFile;
                $config = $loaded['duffel'] ?? [];
            }
        }
        $this->apiKey  = $config['api_key']  ?? (getenv('DUFFEL_API_KEY')  ?: '');
        $this->baseUrl = $config['base_url'] ?? (getenv('DUFFEL_BASE_URL') ?: 'https://api.duffel.com');
        $this->version = $config['version']  ?? 'v2';
    }

    // =========================================================================
    // Offer Requests (search)
    // =========================================================================

    /**
     * Create an offer request (flight search).
     *
     * @param  array  $slices           [{origin, destination, departure_date}]
     * @param  array  $passengers       [{type: 'adult'|'child'|'infant_without_seat'}]
     * @param  string $cabinClass         economy|premium_economy|business|first
     * @param  array  $options            Optional body params:
     *                                    - max_connections (int, default 1; 0 = direct only)
     *                                    - private_fares (object, keyed by IATA airline code)
     *                                    - airline_credit_ids (string[])
     *                                    - include_split_ticket (bool, requires view=itineraries)
     * @param  int    $supplierTimeout    Milliseconds Duffel waits for supplier responses
     *                                    (default 20000). Sent as query param per Duffel docs.
     * @param  string $view               'offers' (default) or 'itineraries'
     * @param  bool   $returnOffers       When false, creates offer request without fetching offers
     */
    public function searchOffers(
        array $slices,
        array $passengers,
        string $cabinClass = 'economy',
        array $options = [],
        int $supplierTimeout = 20000,
        string $view = 'offers',
        bool $returnOffers = true
    ): array {
        if ($this->apiKey === '') {
            throw new \RuntimeException('خدمة البحث عن الرحلات غير متاحة حالياً. الرجاء المحاولة لاحقاً.');
        }

        $data = array_merge([
            'slices'      => $slices,
            'passengers'  => $passengers,
            'cabin_class' => $cabinClass,
        ], $options);

        $queryParams = http_build_query([
            'return_offers'    => $returnOffers ? 'true' : 'false',
            'supplier_timeout' => $supplierTimeout,
            'view'             => $view,
        ]);

        // curl timeout = supplier timeout in seconds + 10s buffer
        $curlTimeout = (int) ceil($supplierTimeout / 1000) + 10;

        return $this->request('POST', $this->baseUrl . '/air/offer_requests?' . $queryParams, ['data' => $data], $curlTimeout);
    }

    /**
     * List offer requests. Returns one page; use $after cursor for subsequent pages.
     */
    public function listOfferRequests(int $limit = 50, ?string $after = null): array
    {
        $query = ['limit' => $limit];
        if ($after !== null) {
            $query['after'] = $after;
        }
        return $this->get('/air/offer_requests', $query);
    }

    /**
     * Get a single offer request with its offers or itineraries.
     *
     * @param  string $view  'offers' (default) or 'itineraries'
     */
    public function getOfferRequest(string $offerRequestId, string $view = 'offers'): array
    {
        return $this->get('/air/offer_requests/' . $offerRequestId, ['view' => $view]);
    }

    /**
     * List offers for a previously created offer request.
     * Returns one page (up to $limit results). Pass $after cursor for subsequent pages.
     * To auto-fetch all pages use fetchAllPages('/air/offers', $query).
     *
     * @param  string|null $sort            'total_amount', '-total_amount', 'total_duration', '-total_duration'
     * @param  int|null    $maxConnections  Filter by max stops (0 = direct only, 1 = default)
     */
    public function listOffers(
        string $offerRequestId,
        array $filters = [],
        int $limit = 200,
        ?string $after = null,
        ?string $sort = null,
        ?int $maxConnections = null
    ): array {
        $query = array_merge(['offer_request_id' => $offerRequestId, 'limit' => $limit], $filters);
        if ($after !== null) {
            $query['after'] = $after;
        }
        if ($sort !== null) {
            $query['sort'] = $sort;
        }
        if ($maxConnections !== null) {
            $query['max_connections'] = $maxConnections;
        }
        return $this->get('/air/offers', $query);
    }

    /**
     * Fetch every page of a list endpoint, following cursor-based pagination.
     * Returns a merged array: ['data' => [...all items...], 'meta' => [...last page meta...]].
     *
     * @param  string $path    e.g. '/air/orders'
     * @param  array  $query   base query params (do not include 'after')
     * @param  int    $limit   page size, 1–200 (default 200 = fewest requests)
     */
    public function fetchAllPages(string $path, array $query = [], int $limit = 200): array
    {
        $query['limit'] = $limit;
        $allItems       = [];
        $lastMeta       = [];
        $after          = null;

        do {
            if ($after !== null) {
                $query['after'] = $after;
            } else {
                unset($query['after']);
            }

            $page     = $this->get($path, $query);
            $allItems = array_merge($allItems, $page['data'] ?? []);
            $lastMeta = $page['meta'] ?? [];
            $after    = $lastMeta['after'] ?? null;
        } while ($after !== null);

        return ['data' => $allItems, 'meta' => $lastMeta];
    }

    /**
     * Retrieve a single offer by ID with fresh pricing.
     * Set $returnAvailableServices=true just before checkout to get baggage/meal options.
     */
    public function getOffer(string $offerId, bool $returnAvailableServices = false): array
    {
        $query = $returnAvailableServices ? ['return_available_services' => 'true'] : [];
        return $this->get('/air/offers/' . urlencode($offerId), $query);
    }

    /**
     * Price an offer with intended payment methods and optional ancillary services.
     * Returns the final total including any card/payment surcharges.
     *
     * @param  array  $intendedPaymentMethods  Array of payment method type strings, e.g. ['balance'] or ['arc_bsp_cash']
     * @param  array  $intendedServices        Array of service objects: [['id' => '...', 'quantity' => 1], ...]
     */
    public function priceOffer(string $offerId, array $intendedPaymentMethods = ['balance'], array $intendedServices = []): array
    {
        $data = ['intended_payment_methods' => array_map(fn($m) => ['type' => $m], $intendedPaymentMethods)];
        if (!empty($intendedServices)) {
            $data['intended_services'] = $intendedServices;
        }
        return $this->post('/air/offers/' . urlencode($offerId) . '/actions/price', ['data' => $data]);
    }

    /**
     * Update passenger loyalty programme accounts on an offer.
     * Call this before creating the order to earn miles/points.
     */
    public function updateOfferPassenger(string $offerId, string $passengerId, array $data): array
    {
        return $this->patch(
            '/air/offers/' . urlencode($offerId) . '/passengers/' . urlencode($passengerId),
            ['data' => $data]
        );
    }

    // =========================================================================
    // Seat Maps
    // =========================================================================

    /**
     * Get seat maps for an offer (one map per segment).
     * Seats are booked via the services array in createOrder().
     */
    public function getSeatMaps(string $offerId): array
    {
        return $this->get('/air/seat_maps', ['offer_id' => $offerId]);
    }

    // =========================================================================
    // Orders (booking)
    // =========================================================================

    /**
     * Create an instant Duffel order (confirmed immediately).
     *
     * @param  string      $selectedOfferId  Offer ID to book.
     * @param  array       $passengers       Passenger objects with personal details + id field from offer.
     * @param  array       $payments         [{type: 'balance', amount, currency}]
     * @param  array       $services         Optional ancillary services (bags, seats) selected by passenger.
     * @param  array|null  $metadata         Optional key-value metadata.
     */
    public function createOrder(
        string $selectedOfferId,
        array $passengers,
        array $payments,
        array $services = [],
        ?array $metadata = null,
        ?string $deviceIp = null,
        ?string $deviceUserAgent = null
    ): array {
        $data = [
            'type'            => 'instant',
            'selected_offers' => [$selectedOfferId],
            'passengers'      => $passengers,
            'payments'        => $payments,
        ];

        if (!empty($services)) {
            $data['services'] = $services;
        }

        if ($metadata !== null) {
            $data['metadata'] = $metadata;
        }

        $requestBody = json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
        $url         = $this->baseUrl . '/air/orders';

        // Log the full createOrder request before sending
        error_log('[DUFFEL_CREATE_ORDER_REQUEST] url=' . $url . ' body=' . $requestBody);

        // Build optional device-detail headers for fraud detection.
        $deviceHeaders = [];
        if ($deviceIp !== null && filter_var($deviceIp, FILTER_VALIDATE_IP)) {
            $deviceHeaders[] = 'x-duffel-device-ip: ' . $deviceIp;
        }
        if ($deviceUserAgent !== null && $deviceUserAgent !== '') {
            $deviceHeaders[] = 'x-duffel-device-user-agent: ' . $deviceUserAgent;
        }

        try {
            // Airline APIs can take up to 120s; use 130s to guarantee we get a response.
            $response = $this->request('POST', $this->baseUrl . '/air/orders', ['data' => $data], 130, $deviceHeaders);

            // Log successful response summary (201 has full order; 200/202 only has a message).
            $httpStatus = $response['http_status'] ?? 201;
            $xReqId    = $response['x_request_id'] ?? '';
            $orderId = $response['data']['id']                ?? ($httpStatus !== 201 ? 'pending' : 'n/a');
            $bookRef = $response['data']['booking_reference'] ?? ($httpStatus !== 201 ? 'pending' : 'n/a');
            error_log('[DUFFEL_CREATE_ORDER_SUCCESS] http=' . $httpStatus . ' order_id=' . $orderId . ' booking_ref=' . $bookRef . ' x-request-id=' . $xReqId);

            $this->writeDebugLog('createOrder_success', [
                'request_body'  => json_decode($requestBody, true),
                'response_id'   => $orderId,
                'booking_ref'   => $bookRef,
            ]);

            return $response;
        } catch (\Throwable $e) {
            // Log the full failure with request context
            error_log('[DUFFEL_CREATE_ORDER_FAIL] message=' . $e->getMessage());

            // Extract the raw JSON from the exception message to store separately
            $msg       = $e->getMessage();
            $jsonStart = strpos($msg, '{');
            $rawJson   = $jsonStart !== false ? substr($msg, $jsonStart) : null;
            $decoded   = $rawJson ? json_decode($rawJson, true) : null;

            $this->writeDebugLog('createOrder_failure', [
                'request_body'   => json_decode($requestBody, true),
                'http_status'    => $e->getCode(),
                'error_message'  => $msg,
                'duffel_errors'  => $decoded['errors']          ?? null,
                'duffel_meta'    => $decoded['meta']            ?? null,
                'duffel_request_id' => $decoded['meta']['request_id'] ?? null,
            ]);

            throw $e;
        }
    }

    /**
     * Write a structured debug entry to the error_logs table.
     * Silently swallows DB errors (non-critical).
     */
    private function writeDebugLog(string $event, array $context): void
    {
        try {
            if (!defined('BASE_PATH')) return;
            $db = \App\Helpers\Database::getInstance();
            $db->prepare(
                'INSERT INTO error_logs (level, message, context, created_at)
                 VALUES (?, ?, ?, NOW())'
            )->execute(['debug', 'duffel.' . $event, json_encode($context, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) { /* non-critical */ }
    }

    /**
     * Create a hold order (pay later, within payment_required_by deadline).
     */
    public function createHoldOrder(
        string $selectedOfferId,
        array $passengers,
        array $services = [],
        ?array $metadata = null
    ): array {
        $data = [
            'type'            => 'hold',
            'selected_offers' => [$selectedOfferId],
            'passengers'      => $passengers,
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
     * Create a Duffel payment for a hold order.
     */
    public function createDuffelPayment(string $orderId, string $amount, string $currency, string $type = 'balance'): array
    {
        return $this->post('/air/payments', [
            'data' => [
                'order_id' => $orderId,
                'payment'  => [
                    'type'     => $type,
                    'amount'   => $amount,
                    'currency' => $currency,
                ],
            ],
        ]);
    }

    /**
     * Retrieve an existing order by ID.
     */
    public function getOrder(string $orderId): array
    {
        return $this->get('/air/orders/' . urlencode($orderId));
    }

    /**
     * List orders with rich Duffel filter support — one page.
     *
     * Supported scalar filters: booking_reference, offer_id, awaiting_payment,
     * sort, requires_action, user_id.
     * Array filters (pass as PHP arrays, serialised as repeated keys):
     *   owner_id, origin_id, destination_id, passenger_name.
     * Date-range filters (pass as associative arrays with before/after keys):
     *   departing_at, arriving_at, created_at.
     */
    public function listOrders(
        array $filters = [],
        int $limit = 200,
        ?string $after = null,
        ?string $bookingReference = null,
        ?string $offerId = null,
        ?bool $awaitingPayment = null,
        ?string $sort = null,
        array $ownerIds = [],
        array $originIds = [],
        array $destinationIds = [],
        ?array $departingAt = null,
        ?array $arrivingAt = null,
        ?array $createdAt = null,
        array $passengerNames = [],
        ?bool $requiresAction = null,
        ?string $userId = null
    ): array {
        $query = array_merge(['limit' => $limit], $filters);
        if ($after !== null)            { $query['after'] = $after; }
        if ($bookingReference !== null) { $query['booking_reference'] = $bookingReference; }
        if ($offerId !== null)          { $query['offer_id'] = $offerId; }
        if ($awaitingPayment !== null)  { $query['awaiting_payment'] = $awaitingPayment ? 'true' : 'false'; }
        if ($sort !== null)             { $query['sort'] = $sort; }
        if ($requiresAction !== null)   { $query['requires_action'] = $requiresAction ? 'true' : 'false'; }
        if ($userId !== null)           { $query['user_id'] = $userId; }

        // Array params sent as repeated keys: owner_id[], origin_id[], etc.
        foreach ($ownerIds as $id)       { $query['owner_id[]'][] = $id; }
        foreach ($originIds as $id)      { $query['origin_id[]'][] = $id; }
        foreach ($destinationIds as $id) { $query['destination_id[]'][] = $id; }
        foreach ($passengerNames as $n)  { $query['passenger_name[]'][] = $n; }

        // Date-range objects: departing_at[before], departing_at[after], etc.
        foreach (['departing_at' => $departingAt, 'arriving_at' => $arrivingAt, 'created_at' => $createdAt] as $key => $range) {
            if ($range === null) { continue; }
            foreach (['before', 'after'] as $bound) {
                if (isset($range[$bound])) { $query[$key . '[' . $bound . ']'] = $range[$bound]; }
            }
        }

        return $this->get('/air/orders', $query);
    }

    /**
     * Update an order — supports metadata (key/value pairs) and users (array of user objects).
     */
    public function updateOrder(string $orderId, array $data): array
    {
        return $this->patch('/air/orders/' . urlencode($orderId), ['data' => $data]);
    }

    /**
     * Price an order before payment. Returns updated total_amount/tax_amount.
     * $intendedPaymentMethods: list of payment type strings e.g. ['balance'].
     */
    public function priceOrder(string $orderId, array $intendedPaymentMethods = ['balance']): array
    {
        $data = [
            'intended_payment_methods' => array_map(fn($m) => ['type' => $m], $intendedPaymentMethods),
        ];
        return $this->post('/air/orders/' . urlencode($orderId) . '/actions/price', ['data' => $data]);
    }

    /**
     * List available ancillary services that can be added to an existing order.
     */
    public function listAvailableServicesForOrder(string $orderId): array
    {
        return $this->get('/air/orders/' . urlencode($orderId) . '/available_services');
    }

    /**
     * Add ancillary services to an existing order and charge payment.
     * $addServices: [['id' => 'ase_…', 'quantity' => 1], …]
     * $payment: ['type' => 'balance'|'arc_bsp_cash', 'currency' => 'GBP', 'amount' => '10.00']
     * Not supported for hold orders.
     */
    public function addServiceToOrder(string $orderId, array $addServices, ?array $payment = null): array
    {
        $data = ['add_services' => $addServices];
        if ($payment !== null) {
            $data['payment'] = $payment;
        }
        return $this->post('/air/orders/' . urlencode($orderId) . '/services', ['data' => $data]);
    }

    // =========================================================================
    // Seat Maps
    // =========================================================================

    /**
     * Get seat maps for an offer — returns one seat map per segment.
     * Seats are a special service type not returned by getOffer(returnAvailableServices=true);
     * this is the only way to retrieve them. Not available for all airlines.
     */
    public function getSeatMaps(string $offerId): array
    {
        return $this->get('/air/seat_maps', ['offer_id' => $offerId]);
    }

    // =========================================================================
    // Payments
    // =========================================================================

    /**
     * List payments for a specific order — one page.
     */
    public function listPayments(string $orderId, int $limit = 50, ?string $after = null, ?string $before = null): array
    {
        $query = ['order_id' => $orderId, 'limit' => $limit];
        if ($after !== null)  { $query['after']  = $after; }
        if ($before !== null) { $query['before'] = $before; }
        return $this->get('/air/payments', $query);
    }

    /**
     * Create a payment for a hold order.
     * Always retrieve the latest order price before calling this to avoid price_changed errors.
     * $payment: ['type' => 'balance'|'card'|'arc_bsp_cash', 'currency' => 'GBP', 'amount' => '30.20',
     *            'three_d_secure_session_id' => '3ds_…' (required for card)]
     */
    public function createPayment(string $orderId, array $payment): array
    {
        return $this->post('/air/payments', [
            'data' => [
                'order_id' => $orderId,
                'payment'  => $payment,
            ],
        ]);
    }

    /**
     * Retrieve a single payment by its ID.
     */
    public function getPayment(string $paymentId): array
    {
        return $this->get('/air/payments/' . urlencode($paymentId));
    }

    // =========================================================================
    // Order Cancellations
    // =========================================================================

    /**
     * Create a pending order cancellation (get refund quote).
     * Returns refund_amount, refund_to before committing.
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->post('/air/order_cancellations', [
            'data' => ['order_id' => $orderId],
        ]);
    }

    /**
     * Retrieve a single order cancellation (refund quote details).
     */
    public function getOrderCancellation(string $cancellationId): array
    {
        return $this->get('/air/order_cancellations/' . urlencode($cancellationId));
    }

    /**
     * List order cancellations, optionally filtered by order_id — one page.
     */
    public function listOrderCancellations(string $orderId = '', int $limit = 50, ?string $after = null, ?string $before = null): array
    {
        $query = ['limit' => $limit];
        if ($orderId !== '')  { $query['order_id'] = $orderId; }
        if ($after !== null)  { $query['after']    = $after; }
        if ($before !== null) { $query['before']   = $before; }
        return $this->get('/air/order_cancellations', $query);
    }

    /**
     * Confirm an order cancellation — this actually cancels the booking.
     * The refund_amount is returned to your Duffel balance.
     */
    public function confirmCancellation(string $cancellationId): array
    {
        return $this->post('/air/order_cancellations/' . urlencode($cancellationId) . '/actions/confirm', []);
    }

    // =========================================================================
    // Order Changes (flight amendments)
    // =========================================================================

    /**
     * Create an order change request to find available change options.
     *
     * @param  string      $orderId
     * @param  array       $slices       ['add' => [...slices], 'remove' => [...sliceIds]]
     * @param  array|null  $privateFares ['UA' => [['corporate_code' => '…', 'tour_code' => '…']], …]
     */
    public function createOrderChangeRequest(string $orderId, array $slices, ?array $privateFares = null): array
    {
        $data = [
            'order_id' => $orderId,
            'slices'   => $slices,
        ];
        if ($privateFares !== null) {
            $data['private_fares'] = $privateFares;
        }
        return $this->post('/air/order_change_requests', ['data' => $data]);
    }

    /**
     * Get a single order change request (includes order_change_offers).
     */
    public function getOrderChangeRequest(string $orderChangeRequestId): array
    {
        return $this->get('/air/order_change_requests/' . urlencode($orderChangeRequestId));
    }

    /**
     * List order change offers for a given order change request — one page.
     * Sort: 'change_total_amount' or 'total_duration' (prefix '-' for descending).
     */
    public function listOrderChangeOffers(
        string $orderChangeRequestId,
        int $limit = 50,
        ?string $after = null,
        ?string $before = null,
        ?string $sort = null,
        ?int $maxConnections = null
    ): array {
        $query = ['order_change_request_id' => $orderChangeRequestId, 'limit' => $limit];
        if ($after !== null)          { $query['after']           = $after; }
        if ($before !== null)         { $query['before']          = $before; }
        if ($sort !== null)           { $query['sort']            = $sort; }
        if ($maxConnections !== null) { $query['max_connections'] = $maxConnections; }
        return $this->get('/air/order_change_offers', $query);
    }

    /**
     * Get a specific order change offer.
     */
    public function getOrderChangeOffer(string $orderChangeOfferId): array
    {
        return $this->get('/air/order_change_offers/' . urlencode($orderChangeOfferId));
    }

    /**
     * Create a pending order change with a selected order change offer.
     */
    public function createOrderChange(string $orderChangeOfferId): array
    {
        return $this->post('/air/order_changes', [
            'data' => ['selected_order_change_offer' => $orderChangeOfferId],
        ]);
    }

    /**
     * Confirm an order change — actually amends the booking.
     * Pass $payment when change_total_amount > 0; omit when zero or negative (refund).
     * $payment: ['type' => 'balance'|'card', 'currency' => 'GBP', 'amount' => '30.20',
     *            'three_d_secure_session_id' => '3ds_…' (required for card)]
     */
    public function confirmOrderChange(string $orderChangeId, ?array $payment = null): array
    {
        $body = $payment !== null ? ['data' => ['payment' => $payment]] : [];
        return $this->post('/air/order_changes/' . urlencode($orderChangeId) . '/actions/confirm', $body);
    }

    /**
     * Get a single order change.
     */
    public function getOrderChange(string $orderChangeId): array
    {
        return $this->get('/air/order_changes/' . urlencode($orderChangeId));
    }

    // =========================================================================
    // Cards API  (PCI-compliant hostname: api.duffel.cards)
    // =========================================================================

    private const CARDS_BASE_URL = 'https://api.duffel.cards';

    /**
     * Create a Duffel card token for use in createOrder with payment type 'card'.
     * Requires PCI-compliant environment. Cards expire after 25 min or first use.
     *
     * @param  array $cardData  number, name, cvc, expiry_month, expiry_year,
     *                          address_line_1, address_city, address_region,
     *                          address_postal_code, address_country_code
     */
    public function createCard(array $cardData): array
    {
        return $this->request('POST', self::CARDS_BASE_URL . '/payments/cards', ['data' => $cardData]);
    }

    /**
     * Delete a card by ID (useful for multi-use cards or cleanup on booking failure).
     */
    public function deleteCard(string $cardId): void
    {
        $this->request('DELETE', self::CARDS_BASE_URL . '/payments/cards/' . urlencode($cardId));
    }

    /**
     * Create a 3DS session to authenticate a card before use in createOrder.
     * Endpoint is api.duffel.com (NOT api.duffel.cards).
     *
     * Pass exception='secure_corporate_payment' to skip the cardholder challenge
     * (status goes straight to 'ready_for_payment'). Without the exception,
     * status may be 'challenge_required' and the client must render the UI component.
     *
     * @param  string      $cardId      Card token ID from createCard()
     * @param  string      $resourceId  Offer ID (or order/booking ID)
     * @param  array       $services    [{id, quantity}] ancillary services
     * @param  string|null $exception   'secure_corporate_payment' to skip challenge
     */
    public function createThreeDSecureSession(
        string $cardId,
        string $resourceId,
        array  $services  = [],
        ?string $exception = 'secure_corporate_payment'
    ): array {
        $data = [
            'card_id'     => $cardId,
            'resource_id' => $resourceId,
        ];
        if (!empty($services)) {
            $data['services'] = $services;
        }
        if ($exception !== null) {
            $data['exception'] = $exception;
        }
        return $this->request('POST', $this->baseUrl . '/payments/three_d_secure_sessions', ['data' => $data]);
    }

    /**
     * Retrieve a 3DS session by ID to check status:
     * ready_for_payment | challenge_required | failed | expired
     */
    public function getThreeDSecureSession(string $sessionId): array
    {
        return $this->request('GET', $this->baseUrl . '/payments/three_d_secure_sessions/' . urlencode($sessionId));
    }

    // =========================================================================
    // Airports & Airlines (reference data for autocomplete)
    // =========================================================================

    /**
     * Search airports by name, IATA code, or city.
     */
    public function searchAirports(string $query, int $limit = 20): array
    {
        return $this->get('/places/suggestions', ['query' => $query, 'limit' => $limit]);
    }

    /**
     * Search airlines by name/IATA — one page. For full list use fetchAllPages('/air/airlines').
     */
    public function searchAirlines(string $query = '', int $limit = 200, ?string $after = null): array
    {
        $params = ['limit' => $limit];
        if ($query !== '') {
            $params['name'] = $query;
        }
        if ($after !== null) {
            $params['after'] = $after;
        }
        return $this->get('/air/airlines', $params);
    }

    /**
     * Get a single airline by IATA code.
     */
    public function getAirline(string $iataCode): array
    {
        return $this->get('/air/airlines/' . urlencode($iataCode));
    }

    // =========================================================================
    // Webhook Management
    // =========================================================================

    /**
     * List registered webhook endpoints.
     */
    public function listWebhooks(int $limit = 50, ?string $after = null): array
    {
        $query = ['limit' => $limit];
        if ($after !== null) {
            $query['after'] = $after;
        }
        return $this->get('/air/webhooks', $query);
    }

    /**
     * Create a webhook endpoint.
     * Duffel allows only one webhook per live_mode value per organisation.
     * The secret in the response must be saved to config/apis.php immediately —
     * it is not returned again.
     *
     * @param  string   $url     Must be HTTPS; no IPs or localhost.
     * @param  string[] $events  e.g. ['order.created', 'order.airline_initiated_change']
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->post('/air/webhooks', ['data' => ['url' => $url, 'events' => $events]]);
    }

    /**
     * Update a webhook endpoint (url, events, active status).
     *
     * @param  string        $webhookId  e.g. "end_0000A3tQSmKyqOrcySrGbo"
     * @param  array{url?: string, events?: string[], active?: bool} $changes
     */
    public function updateWebhook(string $webhookId, array $changes): array
    {
        return $this->patch('/air/webhooks/' . urlencode($webhookId), ['data' => $changes]);
    }

    /**
     * Delete a webhook endpoint.
     */
    public function deleteWebhook(string $webhookId): void
    {
        $this->request('DELETE', $this->baseUrl . '/air/webhooks/' . urlencode($webhookId));
    }

    /**
     * Ping a webhook — sends a fake event to verify your endpoint is reachable.
     * Returns 204 No Content on success.
     */
    public function pingWebhook(string $webhookId): void
    {
        $this->request('POST', $this->baseUrl . '/air/webhooks/' . urlencode($webhookId) . '/actions/ping');
    }

    // =========================================================================
    // Customer Users  (identity API — base path /identity/customer/users)
    // =========================================================================

    /**
     * List customer users, optionally filtered by email.
     */
    public function listCustomerUsers(array $filters = [], int $limit = 50, ?string $after = null): array
    {
        $query = array_merge(['limit' => $limit], $filters);
        if ($after !== null) {
            $query['after'] = $after;
        }
        $url = $this->baseUrl . '/identity/customer/users';
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    /**
     * Create a Duffel customer user.
     *
     * @param  array{email: string, given_name: string, family_name: string,
     *                phone_number?: string, preferred_language?: string, group_id?: string} $userData
     */
    public function createCustomerUser(array $userData): array
    {
        return $this->request('POST', $this->baseUrl . '/identity/customer/users', ['data' => $userData]);
    }

    /**
     * Retrieve a single customer user by Duffel ID.
     *
     * @param  string $userId  e.g. "icu_0000AgZitpOnQtd3NQxjwO"
     */
    public function getCustomerUser(string $userId): array
    {
        return $this->request('GET', $this->baseUrl . '/identity/customer/users/' . urlencode($userId));
    }

    /**
     * Update a customer user (full replacement — all required fields must be sent).
     *
     * @param  string $userId
     * @param  array{email: string, given_name: string, family_name: string,
     *                phone_number?: string, preferred_language?: string, group_id?: string} $userData
     */
    public function updateCustomerUser(string $userId, array $userData): array
    {
        return $this->request('PUT', $this->baseUrl . '/identity/customer/users/' . urlencode($userId), ['data' => $userData]);
    }

    // =========================================================================
    // Component Client Keys  (/identity/component_client_keys)
    // =========================================================================

    /**
     * Create a component client key for authenticating Duffel UI components
     * (e.g. the 3DS component, ancillaries component).
     *
     * Scope options (mutually exclusive):
     *   - No args          → unscoped key (broadest access)
     *   - $userId only     → scoped to a customer user
     *   - $userId + $orderId   → scoped to a user + order
     *   - $userId + $bookingId → scoped to a user + stays booking
     *
     * @param  string|null $userId     Duffel customer user ID ("icu_...")
     * @param  string|null $orderId    Duffel order ID ("ord_...")
     * @param  string|null $bookingId  Duffel stays booking ID ("bok_...")
     * @return string  The JWT component_client_key
     */
    public function createComponentClientKey(
        ?string $userId    = null,
        ?string $orderId   = null,
        ?string $bookingId = null
    ): string {
        $data = [];
        if ($userId !== null) {
            $data['user_id'] = $userId;
        }
        if ($orderId !== null) {
            $data['order_id'] = $orderId;
        }
        if ($bookingId !== null) {
            $data['booking_id'] = $bookingId;
        }

        $response = $this->request(
            'POST',
            $this->baseUrl . '/identity/component_client_keys',
            empty($data) ? [] : ['data' => $data]
        );

        return $response['data']['component_client_key'] ?? '';
    }

    // =========================================================================
    // Customer User Groups  (/identity/customer/user_groups)
    // =========================================================================

    /**
     * List all customer user groups (paginated).
     */
    public function listCustomerUserGroups(int $limit = 50, ?string $after = null): array
    {
        $query = ['limit' => $limit];
        if ($after !== null) {
            $query['after'] = $after;
        }
        $url = $this->baseUrl . '/identity/customer/user_groups';
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    /**
     * Create a customer user group.
     *
     * @param  string   $name     Group display name, e.g. "Northwind Solutions"
     * @param  string[] $userIds  Optional list of customer user IDs to seed the group
     */
    public function createCustomerUserGroup(string $name, array $userIds = []): array
    {
        $data = ['name' => $name];
        if (!empty($userIds)) {
            $data['user_ids'] = $userIds;
        }
        return $this->request('POST', $this->baseUrl . '/identity/customer/user_groups', ['data' => $data]);
    }

    /**
     * Retrieve a single customer user group by Duffel ID.
     *
     * @param  string $groupId  e.g. "usg_0000AgZitpOnQtd3NQxjwO"
     */
    public function getCustomerUserGroup(string $groupId): array
    {
        return $this->request('GET', $this->baseUrl . '/identity/customer/user_groups/' . urlencode($groupId));
    }

    /**
     * Update a customer user group (partial PATCH — only send fields to change).
     *
     * @param  string        $groupId
     * @param  array{name?: string, user_ids?: string[]} $changes
     */
    public function updateCustomerUserGroup(string $groupId, array $changes): array
    {
        return $this->request('PATCH', $this->baseUrl . '/identity/customer/user_groups/' . urlencode($groupId), ['data' => $changes]);
    }

    /**
     * Delete a customer user group by ID.
     */
    public function deleteCustomerUserGroup(string $groupId): void
    {
        $this->request('DELETE', $this->baseUrl . '/identity/customer/user_groups/' . urlencode($groupId));
    }

    // =========================================================================
    // Webhook Events (admin / debugging)
    // =========================================================================

    /**
     * List webhook events with optional filters.
     *
     * @param  array        $filters  Keys: type, delivery_success, created_at
     * @param  int          $limit    1–200
     * @param  string|null  $after    Pagination cursor
     */
    public function listWebhookEvents(array $filters = [], int $limit = 50, ?string $after = null): array
    {
        $query = array_merge(['limit' => $limit], $filters);
        if ($after !== null) {
            $query['after'] = $after;
        }
        return $this->get('/air/webhooks/events', $query);
    }

    /**
     * Retrieve a single webhook event by its ID.
     *
     * @param  string $eventId  e.g. "wev_0000A3tQSmKyqOrcySrGbo"
     */
    public function getWebhookEvent(string $eventId): array
    {
        return $this->get('/air/webhooks/events/' . urlencode($eventId));
    }

    /**
     * Trigger a re-delivery of a webhook event.
     * Useful for replaying missed events (e.g. an order.created that arrived while
     * the endpoint was down) without waiting for Duffel's 72-hour retry window.
     *
     * @param  string $eventId  e.g. "wev_0000A3tQSmKyqOrcySrGbo"
     */
    public function redeliverWebhookEvent(string $eventId): void
    {
        $this->request('POST', $this->baseUrl . '/air/webhooks/events/' . urlencode($eventId) . '/actions/redeliver');
    }

    // =========================================================================
    // Webhook Deliveries (admin / debugging)
    // =========================================================================

    /**
     * List webhook deliveries with optional filters.
     * Useful for debugging missed or failed deliveries from the admin panel.
     *
     * @param  array        $filters  Keys: type, endpoint_id, delivery_success, created_at
     * @param  int          $limit    1–200 (default 50 per Duffel spec)
     * @param  string|null  $after    Cursor for next page
     */
    public function listWebhookDeliveries(array $filters = [], int $limit = 50, ?string $after = null): array
    {
        $query = array_merge(['limit' => $limit], $filters);
        if ($after !== null) {
            $query['after'] = $after;
        }
        return $this->get('/air/webhooks/deliveries', $query);
    }

    /**
     * Retrieve a single webhook delivery by its ID.
     *
     * @param  string $deliveryId  e.g. "del_0000A3tQSmKyqOrcySrGbo"
     */
    public function getWebhookDelivery(string $deliveryId): array
    {
        return $this->get('/air/webhooks/deliveries/' . urlencode($deliveryId));
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

    private function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
    }

    private function request(string $method, string $url, ?array $body = null, int $timeoutSeconds = 30, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Duffel-Version: ' . $this->version,
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => 'gzip',   // auto-decompress gzip responses
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_HEADERFUNCTION => function($ch, $headerLine) use (&$requestId) {
                if (stripos($headerLine, 'x-request-id:') === 0) {
                    $requestId = trim(substr($headerLine, strlen('x-request-id:')));
                }
                return strlen($headerLine);
            },
        ]);
        $requestId = null;

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

        // 204 No Content — success with empty body (e.g. deleteCard).
        if ($statusCode === 204) {
            return ['http_status' => 204];
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Duffel API returned non-JSON response (HTTP ' . $statusCode . ').');
        }

        if ($statusCode >= 400) {
            $errorMsg  = $decoded['errors'][0]['message'] ?? ($decoded['errors'][0]['title'] ?? 'Unknown Duffel API error');
            $errorCode = $decoded['errors'][0]['code']    ?? '';
            // x-request-id from response header takes precedence; fall back to meta field.
            $requestId = $requestId ?? ($decoded['meta']['request_id'] ?? '');

            // Log full forensic detail to PHP error log
            error_log(sprintf(
                '[DUFFEL_HTTP_%d] url=%s code=%s request_id=%s body=%s',
                $statusCode, $url, $errorCode, $requestId, $response
            ));

            // Include the raw JSON body in the exception message so DuffelErrorMapper
            // can parse the full errors array and map to the correct Arabic message.
            throw new \RuntimeException(
                'Duffel API error (' . $statusCode . '): ' . $errorMsg
                . ($errorCode ? ' [' . $errorCode . ']' : '')
                . ' ' . $response,
                $statusCode
            );
        }

        // Stamp HTTP status and x-request-id so callers can log and distinguish
        // 201 Created (full order), 200 OK (confirmed but not yet available),
        // and 202 Accepted (still processing — do not retry).
        $decoded['http_status']  = $statusCode;
        $decoded['x_request_id'] = $requestId;

        return $decoded;
    }
}
