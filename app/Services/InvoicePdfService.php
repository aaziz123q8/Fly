<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;
use RuntimeException;

class InvoicePdfService
{
    private string $storagePath;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->storagePath = BASE_PATH . '/storage/invoices';
        $this->db          = $db ?? Database::getInstance();
    }

    // =========================================================================
    // Public handler (called by JobWorker)
    // =========================================================================

    /**
     * Generate an HTML invoice and save it to storage/invoices/{ref}.html.
     * Payload: { booking_id, booking_type }
     */
    public function generate(array $payload): void
    {
        $bookingId   = (int)($payload['booking_id']   ?? 0);
        $bookingType = $payload['booking_type'] ?? '';

        if (!$bookingId || !in_array($bookingType, ['flight', 'hotel'], true)) {
            throw new RuntimeException('InvoicePdfService: invalid payload');
        }

        $this->ensureStorageDir();

        if ($bookingType === 'flight') {
            $this->generateFlightInvoice($bookingId);
        } else {
            $this->generateHotelInvoice($bookingId);
        }
    }

    // =========================================================================
    // Internal: flight invoice
    // =========================================================================

    private function generateFlightInvoice(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT fb.*, u.email, u.first_name, u.last_name
             FROM flight_bookings fb
             JOIN users u ON u.id = fb.user_id
             WHERE fb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException("Flight booking {$bookingId} not found");
        }

        $segments = $this->db->prepare(
            'SELECT * FROM flight_booking_segments WHERE booking_id = :id ORDER BY slice_index, departure_at'
        );
        $segments->execute([':id' => $bookingId]);
        $segRows = $segments->fetchAll(PDO::FETCH_ASSOC);

        $passengers = $this->db->prepare(
            'SELECT * FROM flight_booking_passengers WHERE booking_id = :id ORDER BY id'
        );
        $passengers->execute([':id' => $bookingId]);
        $pasRows = $passengers->fetchAll(PDO::FETCH_ASSOC);

        $payment = $this->fetchPayment($bookingId, 'flight');

        $html = $this->buildFlightInvoiceHtml($booking, $segRows, $pasRows, $payment);
        $this->saveInvoice($booking['booking_reference'], $html);

        $this->logInfo('Invoice generated', [
            'booking_reference' => $booking['booking_reference'],
            'booking_type'      => 'flight',
            'booking_id'        => $bookingId,
        ]);
    }

    // =========================================================================
    // Internal: hotel invoice
    // =========================================================================

    private function generateHotelInvoice(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT hb.*, u.email, u.first_name, u.last_name
             FROM hotel_bookings hb
             JOIN users u ON u.id = hb.user_id
             WHERE hb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException("Hotel booking {$bookingId} not found");
        }

        $rooms = $this->db->prepare(
            'SELECT * FROM hotel_booking_rooms WHERE booking_id = :id ORDER BY id'
        );
        $rooms->execute([':id' => $bookingId]);
        $roomRows = $rooms->fetchAll(PDO::FETCH_ASSOC);

        $guests = $this->db->prepare(
            'SELECT * FROM hotel_booking_guests WHERE booking_id = :id ORDER BY is_lead_guest DESC, id'
        );
        $guests->execute([':id' => $bookingId]);
        $guestRows = $guests->fetchAll(PDO::FETCH_ASSOC);

        $payment = $this->fetchPayment($bookingId, 'hotel');

        $html = $this->buildHotelInvoiceHtml($booking, $roomRows, $guestRows, $payment);
        $this->saveInvoice($booking['booking_reference'], $html);

        $this->logInfo('Invoice generated', [
            'booking_reference' => $booking['booking_reference'],
            'booking_type'      => 'hotel',
            'booking_id'        => $bookingId,
        ]);
    }

    // =========================================================================
    // Internal: file I/O
    // =========================================================================

    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->storagePath}");
            }
        }
    }

    private function saveInvoice(string $ref, string $html): void
    {
        // Sanitise reference for use as filename
        $safeRef  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $ref);
        $filePath = $this->storagePath . '/' . $safeRef . '.html';

        if (file_put_contents($filePath, $html) === false) {
            throw new RuntimeException("Failed to write invoice file: {$filePath}");
        }
    }

    // =========================================================================
    // Internal: DB helpers
    // =========================================================================

    private function fetchPayment(int $bookingId, string $bookingType): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payments WHERE booking_id = :id AND booking_type = :type ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId, ':type' => $bookingType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function logInfo(string $message, array $context = []): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO error_logs (level, message, context) VALUES ('info', :msg, :ctx)"
            )->execute([
                ':msg' => $message,
                ':ctx' => json_encode($context),
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    // =========================================================================
    // Internal: HTML builders
    // =========================================================================

    private function buildFlightInvoiceHtml(
        array $booking,
        array $segments,
        array $passengers,
        ?array $payment
    ): string {
        $year      = date('Y');
        $ref       = htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8');
        $guestName = htmlspecialchars(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            ENT_QUOTES, 'UTF-8'
        );
        $email     = htmlspecialchars($booking['email'], ENT_QUOTES, 'UTF-8');
        $amount    = number_format((float)$booking['total_amount'], 2);
        $currency  = htmlspecialchars(strtoupper($booking['currency']), ENT_QUOTES, 'UTF-8');
        $status    = htmlspecialchars(ucfirst($booking['status']), ENT_QUOTES, 'UTF-8');
        $issuedAt  = date('d M Y');

        $payMethod = $payment ? htmlspecialchars($payment['stripe_payment_intent_id'] ?? 'Card', ENT_QUOTES, 'UTF-8') : 'N/A';

        // Segments rows
        $segRows = '';
        foreach ($segments as $s) {
            $dep = date('d M Y H:i', strtotime($s['departure_at']));
            $arr = date('d M Y H:i', strtotime($s['arrival_at']));
            $segRows .= sprintf(
                '<tr>
                  <td class="cell">%s → %s</td>
                  <td class="cell">%s</td>
                  <td class="cell">%s</td>
                  <td class="cell">%s%s</td>
                  <td class="cell">%s</td>
                </tr>',
                htmlspecialchars($s['origin_iata'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($s['destination_iata'], ENT_QUOTES, 'UTF-8'),
                $dep, $arr,
                htmlspecialchars($s['airline_iata'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($s['flight_number'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(ucfirst($s['cabin_class']), ENT_QUOTES, 'UTF-8')
            );
        }

        // Passenger rows
        $pasRows = '';
        foreach ($passengers as $p) {
            $pasRows .= sprintf(
                '<tr>
                  <td class="cell">%s %s %s</td>
                  <td class="cell">%s</td>
                 </tr>',
                htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($p['first_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($p['last_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(ucfirst($p['type']), ENT_QUOTES, 'UTF-8')
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice {$ref}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #fff; }
  .page { max-width: 800px; margin: 0 auto; padding: 40px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0057a8; padding-bottom: 20px; margin-bottom: 30px; }
  .brand h1 { color: #0057a8; margin: 0; font-size: 28px; }
  .brand p  { margin: 4px 0 0; color: #888; font-size: 13px; }
  .invoice-meta { text-align: right; }
  .invoice-meta h2 { color: #0057a8; margin: 0; font-size: 22px; }
  .invoice-meta p  { margin: 4px 0; font-size: 13px; color: #555; }
  .ref-box { background: #e8f1fb; border-left: 4px solid #0057a8; padding: 12px 16px; margin-bottom: 24px; }
  .ref-box p { margin: 0; font-size: 13px; color: #555; }
  .ref-box span { font-size: 20px; font-weight: bold; color: #003d75; }
  h3 { color: #0057a8; border-bottom: 1px solid #e0e8f4; padding-bottom: 6px; margin-top: 28px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { background: #0057a8; color: #fff; padding: 10px; text-align: left; }
  .cell { padding: 10px; border-bottom: 1px solid #eee; }
  .total-row td { font-weight: bold; font-size: 15px; background: #e8f1fb; padding: 12px; }
  .footer { border-top: 1px solid #eee; margin-top: 40px; padding-top: 16px; text-align: center; color: #aaa; font-size: 12px; }
  .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; background: #27ae60; color: #fff; font-size: 12px; font-weight: bold; }
</style>
</head>
<body>
<div class="page">
  <!-- Header -->
  <div class="header">
    <div class="brand">
      <h1>FlyMasar</h1>
      <p>flymasar.com</p>
    </div>
    <div class="invoice-meta">
      <h2>INVOICE</h2>
      <p>Date: {$issuedAt}</p>
      <p>Status: <span class="status-badge">{$status}</span></p>
    </div>
  </div>

  <!-- Booking Reference -->
  <div class="ref-box">
    <p>Booking Reference</p>
    <span>{$ref}</span>
  </div>

  <!-- Bill To -->
  <h3>Bill To</h3>
  <p style="font-size:14px;color:#555;">{$guestName}<br>{$email}</p>

  <!-- Flight Itinerary -->
  <h3>Flight Itinerary</h3>
  <table>
    <tr>
      <th>Route</th><th>Departure</th><th>Arrival</th><th>Flight</th><th>Class</th>
    </tr>
    {$segRows}
  </table>

  <!-- Passengers -->
  <h3>Passengers</h3>
  <table>
    <tr><th>Name</th><th>Type</th></tr>
    {$pasRows}
  </table>

  <!-- Payment -->
  <h3>Payment</h3>
  <table>
    <tr>
      <td class="cell">Payment Reference</td>
      <td class="cell">{$payMethod}</td>
    </tr>
    <tr class="total-row">
      <td>Total Amount</td>
      <td>{$currency} {$amount}</td>
    </tr>
  </table>

  <div class="footer">
    <p>&copy; {$year} FlyMasar. All rights reserved. &mdash; This document was generated automatically.</p>
  </div>
</div>
</body>
</html>
HTML;
    }

    private function buildHotelInvoiceHtml(
        array $booking,
        array $rooms,
        array $guests,
        ?array $payment
    ): string {
        $year      = date('Y');
        $ref       = htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8');
        $hotel     = htmlspecialchars($booking['hotel_name'], ENT_QUOTES, 'UTF-8');
        $checkIn   = htmlspecialchars($booking['check_in_date'], ENT_QUOTES, 'UTF-8');
        $checkOut  = htmlspecialchars($booking['check_out_date'], ENT_QUOTES, 'UTF-8');
        $amount    = number_format((float)$booking['total_amount'], 2);
        $currency  = htmlspecialchars(strtoupper($booking['currency']), ENT_QUOTES, 'UTF-8');
        $status    = htmlspecialchars(ucfirst($booking['status']), ENT_QUOTES, 'UTF-8');
        $issuedAt  = date('d M Y');

        $guestName = '';
        $guestEmail = htmlspecialchars($booking['email'], ENT_QUOTES, 'UTF-8');
        foreach ($guests as $g) {
            if ($g['is_lead_guest']) {
                $guestName = htmlspecialchars(trim($g['first_name'] . ' ' . $g['last_name']), ENT_QUOTES, 'UTF-8');
                break;
            }
        }
        if (!$guestName) {
            $guestName = htmlspecialchars(trim($booking['first_name'] . ' ' . $booking['last_name']), ENT_QUOTES, 'UTF-8');
        }

        $payMethod = $payment ? htmlspecialchars($payment['stripe_payment_intent_id'] ?? 'Card', ENT_QUOTES, 'UTF-8') : 'N/A';

        // Room rows
        $roomRows = '';
        foreach ($rooms as $r) {
            $ages = json_decode($r['children_ages'] ?? '[]', true);
            $agesStr = $ages ? implode(', ', $ages) : 'None';
            $roomRows .= sprintf(
                '<tr>
                  <td class="cell">%s</td>
                  <td class="cell">%s</td>
                  <td class="cell">%d</td>
                  <td class="cell">%s</td>
                </tr>',
                htmlspecialchars($r['room_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($r['board_type'] ?? 'Room Only', ENT_QUOTES, 'UTF-8'),
                (int)$r['adults'],
                htmlspecialchars($agesStr, ENT_QUOTES, 'UTF-8')
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice {$ref}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #fff; }
  .page { max-width: 800px; margin: 0 auto; padding: 40px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0057a8; padding-bottom: 20px; margin-bottom: 30px; }
  .brand h1 { color: #0057a8; margin: 0; font-size: 28px; }
  .brand p  { margin: 4px 0 0; color: #888; font-size: 13px; }
  .invoice-meta { text-align: right; }
  .invoice-meta h2 { color: #0057a8; margin: 0; font-size: 22px; }
  .invoice-meta p  { margin: 4px 0; font-size: 13px; color: #555; }
  .ref-box { background: #e8f1fb; border-left: 4px solid #0057a8; padding: 12px 16px; margin-bottom: 24px; }
  .ref-box p { margin: 0; font-size: 13px; color: #555; }
  .ref-box span { font-size: 20px; font-weight: bold; color: #003d75; }
  h3 { color: #0057a8; border-bottom: 1px solid #e0e8f4; padding-bottom: 6px; margin-top: 28px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { background: #0057a8; color: #fff; padding: 10px; text-align: left; }
  .cell { padding: 10px; border-bottom: 1px solid #eee; }
  .total-row td { font-weight: bold; font-size: 15px; background: #e8f1fb; padding: 12px; }
  .footer { border-top: 1px solid #eee; margin-top: 40px; padding-top: 16px; text-align: center; color: #aaa; font-size: 12px; }
  .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; background: #27ae60; color: #fff; font-size: 12px; font-weight: bold; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; font-size: 14px; }
  .info-grid .label { color: #888; padding: 8px 8px 8px 0; }
  .info-grid .value { color: #333; padding: 8px; }
</style>
</head>
<body>
<div class="page">
  <!-- Header -->
  <div class="header">
    <div class="brand">
      <h1>FlyMasar</h1>
      <p>flymasar.com</p>
    </div>
    <div class="invoice-meta">
      <h2>INVOICE</h2>
      <p>Date: {$issuedAt}</p>
      <p>Status: <span class="status-badge">{$status}</span></p>
    </div>
  </div>

  <!-- Booking Reference -->
  <div class="ref-box">
    <p>Booking Reference</p>
    <span>{$ref}</span>
  </div>

  <!-- Bill To -->
  <h3>Bill To</h3>
  <p style="font-size:14px;color:#555;">{$guestName}<br>{$guestEmail}</p>

  <!-- Stay Details -->
  <h3>Stay Details</h3>
  <div class="info-grid">
    <div class="label">Hotel</div><div class="value">{$hotel}</div>
    <div class="label">Check-in</div><div class="value">{$checkIn}</div>
    <div class="label">Check-out</div><div class="value">{$checkOut}</div>
  </div>

  <!-- Rooms -->
  <h3>Rooms</h3>
  <table>
    <tr><th>Room</th><th>Board Type</th><th>Adults</th><th>Children Ages</th></tr>
    {$roomRows}
  </table>

  <!-- Payment -->
  <h3>Payment</h3>
  <table>
    <tr>
      <td class="cell">Payment Reference</td>
      <td class="cell">{$payMethod}</td>
    </tr>
    <tr class="total-row">
      <td>Total Amount</td>
      <td>{$currency} {$amount}</td>
    </tr>
  </table>

  <div class="footer">
    <p>&copy; {$year} FlyMasar. All rights reserved. &mdash; This document was generated automatically.</p>
  </div>
</div>
</body>
</html>
HTML;
    }
}
