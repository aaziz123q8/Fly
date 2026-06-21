<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;
use RuntimeException;

class WhatsAppService
{
    private string $apiUrl = 'https://graph.facebook.com/v19.0';
    private string $phoneNumberId;
    private string $accessToken;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->phoneNumberId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '';
        $this->accessToken   = $_ENV['WHATSAPP_ACCESS_TOKEN']    ?? '';
        $this->db            = $db ?? Database::getInstance();
    }

    // =========================================================================
    // Public handlers (called by JobWorker)
    // =========================================================================

    /**
     * Send a booking-confirmation WhatsApp message.
     * Payload: { booking_id, booking_type }
     */
    public function sendBookingConfirmation(array $payload): void
    {
        $bookingId   = (int)($payload['booking_id']   ?? 0);
        $bookingType = $payload['booking_type'] ?? '';

        if (!$bookingId || !in_array($bookingType, ['flight', 'hotel'], true)) {
            throw new RuntimeException('WhatsAppService: invalid payload');
        }

        if ($bookingType === 'flight') {
            $this->sendFlightConfirmation($bookingId);
        } else {
            $this->sendHotelConfirmation($bookingId);
        }
    }

    // =========================================================================
    // Internal: flight
    // =========================================================================

    private function sendFlightConfirmation(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT fb.booking_reference, fb.total_amount, fb.currency,
                    u.phone_country_code, u.phone_number, u.first_name, u.last_name
             FROM flight_bookings fb
             JOIN users u ON u.id = fb.user_id
             WHERE fb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException("Flight booking {$bookingId} not found");
        }

        // First segment: origin → destination + departure date
        $segStmt = $this->db->prepare(
            'SELECT origin_iata, destination_iata, departure_at
             FROM flight_booking_segments
             WHERE booking_id = :id ORDER BY slice_index, departure_at LIMIT 1'
        );
        $segStmt->execute([':id' => $bookingId]);
        $seg = $segStmt->fetch(PDO::FETCH_ASSOC);

        $route = $seg
            ? $seg['origin_iata'] . ' → ' . $seg['destination_iata']
            : 'N/A';
        $date = $seg
            ? date('d M Y', strtotime($seg['departure_at']))
            : 'N/A';

        $guestName = trim($booking['first_name'] . ' ' . $booking['last_name']);
        $phone     = $this->normalisePhone($booking['phone_country_code'], $booking['phone_number']);

        $components = [
            [
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $booking['booking_reference']],
                    ['type' => 'text', 'text' => $guestName],
                    ['type' => 'text', 'text' => $route],
                    ['type' => 'text', 'text' => $date],
                ],
            ],
        ];

        $this->sendMessage($phone, 'booking_confirmation', $components);
    }

    // =========================================================================
    // Internal: hotel
    // =========================================================================

    private function sendHotelConfirmation(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT hb.booking_reference, hb.hotel_name, hb.check_in_date,
                    hb.total_amount, hb.currency,
                    u.phone_country_code, u.phone_number, u.first_name, u.last_name
             FROM hotel_bookings hb
             JOIN users u ON u.id = hb.user_id
             WHERE hb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException("Hotel booking {$bookingId} not found");
        }

        $guestName = trim($booking['first_name'] . ' ' . $booking['last_name']);
        $phone     = $this->normalisePhone($booking['phone_country_code'], $booking['phone_number']);
        $date      = date('d M Y', strtotime($booking['check_in_date']));

        $components = [
            [
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $booking['booking_reference']],
                    ['type' => 'text', 'text' => $guestName],
                    ['type' => 'text', 'text' => $booking['hotel_name']],
                    ['type' => 'text', 'text' => $date],
                ],
            ],
        ];

        $this->sendMessage($phone, 'booking_confirmation', $components);
    }

    // =========================================================================
    // Low-level: Meta Cloud API call
    // =========================================================================

    /**
     * POST a template message to the WhatsApp Business API.
     *
     * @param string  $to           Phone number in E.164 format (digits only, with country code)
     * @param string  $templateName Approved template name in the Business Manager
     * @param array   $components   Template component parameters
     */
    public function sendMessage(string $to, string $templateName, array $components): void
    {
        if (!$this->phoneNumberId || !$this->accessToken) {
            throw new RuntimeException('WhatsAppService: WHATSAPP_PHONE_NUMBER_ID or WHATSAPP_ACCESS_TOKEN not set');
        }

        $url = "{$this->apiUrl}/{$this->phoneNumberId}/messages";

        $body = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => 'en'],
                'components' => $components,
            ],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException("WhatsApp cURL error: {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                "WhatsApp API returned HTTP {$httpCode}: " . ($response ?: '(empty response)')
            );
        }

        $decoded = json_decode((string)$response, true);
        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException("WhatsApp API error: {$msg}");
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Combine phone_country_code + phone_number into E.164 digits-only string.
     * e.g. "966" + "0501234567" → "966501234567"
     */
    private function normalisePhone(string $countryCode, string $number): string
    {
        // Strip everything that is not a digit
        $cc  = preg_replace('/\D/', '', $countryCode);
        $num = preg_replace('/\D/', '', $number);

        // Drop leading zero from local number if present
        $num = ltrim($num, '0');

        return $cc . $num;
    }
}
