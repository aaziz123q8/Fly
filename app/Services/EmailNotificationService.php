<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;
use RuntimeException;

class EmailNotificationService
{
    private string $fromEmail;
    private string $fromName;
    private string $appUrl;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@flymasar.com';
        $this->fromName  = $_ENV['MAIL_FROM_NAME']  ?? 'FlyMasar';
        $this->appUrl    = $_ENV['APP_URL']          ?? 'https://flymasar.com';
        $this->db        = $db ?? Database::getInstance();
    }

    // =========================================================================
    // Public handlers (called by JobWorker)
    // =========================================================================

    /**
     * Send a password-reset email.
     * Payload: { user_id, token, email }
     */
    public function sendPasswordReset(array $payload): void
    {
        $userId = (int)($payload['user_id'] ?? 0);
        $token  = $payload['token']         ?? '';
        $email  = $payload['email']         ?? '';

        if (!$email || !$token) {
            throw new RuntimeException('sendPasswordReset: missing email or token in payload');
        }

        $user = $this->fetchUser($userId);
        $name = $user ? trim($user['first_name'] . ' ' . $user['last_name']) : 'Valued Customer';

        $resetUrl = $this->appUrl . '/password-reset.html?token=' . urlencode($token);

        $subject = 'Reset Your FlyMasar Password';
        $body    = $this->buildPasswordResetHtml($name, $resetUrl);

        $this->sendHtmlMail($email, $subject, $body);
    }

    /**
     * Send a booking-confirmation email.
     * Payload: { booking_id, booking_type }
     */
    public function sendBookingConfirmation(array $payload): void
    {
        $bookingId   = (int)($payload['booking_id']   ?? 0);
        $bookingType = $payload['booking_type'] ?? '';

        if (!$bookingId || !in_array($bookingType, ['flight', 'hotel'], true)) {
            throw new RuntimeException('sendBookingConfirmation: invalid payload');
        }

        if ($bookingType === 'flight') {
            $this->sendFlightConfirmation($bookingId);
        } else {
            $this->sendHotelConfirmation($bookingId);
        }
    }

    // =========================================================================
    // Internal: flight confirmation
    // =========================================================================

    private function sendFlightConfirmation(int $bookingId): void
    {
        $booking = $this->db->prepare(
            'SELECT fb.*, u.email, u.first_name, u.last_name
             FROM flight_bookings fb
             JOIN users u ON u.id = fb.user_id
             WHERE fb.id = :id LIMIT 1'
        );
        $booking->execute([':id' => $bookingId]);
        $row = $booking->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Flight booking {$bookingId} not found");
        }

        $segments = $this->db->prepare(
            'SELECT * FROM flight_booking_segments WHERE booking_id = :id ORDER BY slice_index, segment_order'
        );
        $segments->execute([':id' => $bookingId]);
        $segRows = $segments->fetchAll(PDO::FETCH_ASSOC);

        $passengers = $this->db->prepare(
            'SELECT * FROM flight_booking_passengers WHERE booking_id = :id ORDER BY id'
        );
        $passengers->execute([':id' => $bookingId]);
        $pasRows = $passengers->fetchAll(PDO::FETCH_ASSOC);

        // Documents (electronic tickets)
        $docRows = [];
        try {
            $docs = $this->db->prepare(
                'SELECT * FROM flight_booking_documents WHERE booking_id = :id ORDER BY id'
            );
            $docs->execute([':id' => $bookingId]);
            $docRows = $docs->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) { /* table may not exist yet */ }

        // Package hotel linked to this flight booking (flight + hotel package).
        $pkgHotel = null;
        try {
            $ph = $this->db->prepare(
                'SELECT hb.*,
                        (SELECT room_type FROM hotel_booking_rooms WHERE booking_id = hb.id LIMIT 1) AS room_type,
                        (SELECT meal_plan FROM hotel_booking_rooms WHERE booking_id = hb.id LIMIT 1) AS meal_plan
                 FROM hotel_bookings hb
                 WHERE hb.user_id = :u AND hb.special_requests LIKE :m
                 ORDER BY hb.id DESC LIMIT 1'
            );
            $ph->execute([':u' => $row['user_id'], ':m' => '%"flight_ref":"' . $row['booking_reference'] . '"%']);
            $pkgHotel = $ph->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable) { /* non-fatal */ }

        $subject = 'Your Flight Booking Confirmation — ' . $row['booking_reference'];
        $body    = $this->buildFlightConfirmationHtml($row, $segRows, $pasRows, $docRows, $pkgHotel);

        $this->sendHtmlMail($row['email'], $subject, $body);
    }

    // =========================================================================
    // Internal: hotel confirmation
    // =========================================================================

    private function sendHotelConfirmation(int $bookingId): void
    {
        $booking = $this->db->prepare(
            'SELECT hb.*, u.email, u.first_name, u.last_name
             FROM hotel_bookings hb
             JOIN users u ON u.id = hb.user_id
             WHERE hb.id = :id LIMIT 1'
        );
        $booking->execute([':id' => $bookingId]);
        $row = $booking->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Hotel booking {$bookingId} not found");
        }

        $rooms = $this->db->prepare(
            'SELECT * FROM hotel_booking_rooms WHERE booking_id = :id ORDER BY id'
        );
        $rooms->execute([':id' => $bookingId]);
        $roomRows = $rooms->fetchAll(PDO::FETCH_ASSOC);

        $guests = $this->db->prepare(
            'SELECT * FROM hotel_booking_guests WHERE booking_id = :id ORDER BY is_lead DESC, id'
        );
        $guests->execute([':id' => $bookingId]);
        $guestRows = $guests->fetchAll(PDO::FETCH_ASSOC);

        $subject = 'Your Hotel Booking Confirmation — ' . $row['booking_reference'];
        $body    = $this->buildHotelConfirmationHtml($row, $roomRows, $guestRows);

        $this->sendHtmlMail($row['email'], $subject, $body);
    }

    /**
     * Notify customer that their flight schedule has changed.
     * Payload: { booking_id, user_id }
     */
    public function sendScheduleChangeEmail(array $payload): void
    {
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $booking   = $this->fetchFlightBookingWithUser($bookingId);
        if (!$booking) return;

        $subject = 'Important: Your Flight Schedule Has Changed — ' . $booking['booking_reference'];
        $body    = $this->buildSimpleNotificationHtml(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            'Flight Schedule Change',
            'We are writing to inform you that your flight schedule has been updated by the airline. ' .
            'Please log in to your account to view the latest itinerary details.',
            $this->appUrl . '/dashboard'
        );
        $this->sendHtmlMail($booking['email'], $subject, $body);
    }

    /**
     * Notify customer their booking was cancelled and refund is processing.
     * Payload: { booking_id, user_id }
     */
    public function sendCancellationEmail(array $payload): void
    {
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $booking   = $this->fetchFlightBookingWithUser($bookingId);
        if (!$booking) return;

        $subject = 'Your Booking Has Been Cancelled — ' . $booking['booking_reference'];
        $body    = $this->buildSimpleNotificationHtml(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            'Booking Cancellation Confirmed',
            'Your flight booking ' . htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') .
            ' has been successfully cancelled. If a refund is applicable, it will be processed within 5–10 business days.',
            $this->appUrl . '/dashboard'
        );
        $this->sendHtmlMail($booking['email'], $subject, $body);
    }

    /**
     * Notify customer the airline has cancelled their flight.
     * Payload: { booking_id, user_id }
     */
    public function sendAirlineCancellationEmail(array $payload): void
    {
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $booking   = $this->fetchFlightBookingWithUser($bookingId);
        if (!$booking) return;

        $subject = 'Urgent: Your Flight Has Been Cancelled by the Airline — ' . $booking['booking_reference'];
        $body    = $this->buildSimpleNotificationHtml(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            'Flight Cancelled by Airline',
            'We regret to inform you that the airline has cancelled your flight for booking ' .
            htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') .
            '. Please contact us for assistance with rebooking or a full refund.',
            $this->appUrl . '/dashboard'
        );
        $this->sendHtmlMail($booking['email'], $subject, $body);
    }

    /**
     * Notify customer that their flight change request was rejected.
     * Payload: { booking_id, user_id }
     */
    public function sendChangeRejectedEmail(array $payload): void
    {
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $booking   = $this->fetchFlightBookingWithUser($bookingId);
        if (!$booking) return;

        $subject = 'Flight Change Request Rejected — ' . $booking['booking_reference'];
        $body    = $this->buildSimpleNotificationHtml(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            'Change Request Rejected',
            'Unfortunately, your flight change request for booking ' .
            htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') .
            ' could not be processed. Please contact our support team for assistance.',
            $this->appUrl . '/dashboard'
        );
        $this->sendHtmlMail($booking['email'], $subject, $body);
    }

    /**
     * Notify customer that payment is required to confirm the booking.
     * Payload: { booking_id, user_id }
     */
    public function sendAwaitingPaymentEmail(array $payload): void
    {
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $booking   = $this->fetchFlightBookingWithUser($bookingId);
        if (!$booking) return;

        $subject = 'Action Required: Payment Needed for Your Booking — ' . $booking['booking_reference'];
        $body    = $this->buildSimpleNotificationHtml(
            trim($booking['first_name'] . ' ' . $booking['last_name']),
            'Payment Required',
            'Your flight booking ' .
            htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') .
            ' is awaiting payment. Please complete payment promptly to confirm your reservation.',
            $this->appUrl . '/dashboard'
        );
        $this->sendHtmlMail($booking['email'], $subject, $body);
    }

    // =========================================================================
    // Internal: mail sending
    // =========================================================================

    private function sendHtmlMail(string $to, string $subject, string $htmlBody): void
    {
        $fromHeader = sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromEmail);

        $headers  = "From: {$fromHeader}\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: FlyMasar\r\n";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $sent = mail($to, $encodedSubject, $htmlBody, $headers);

        if (!$sent) {
            throw new RuntimeException("mail() failed to deliver to {$to}");
        }
    }

    // =========================================================================
    // Internal: DB helpers
    // =========================================================================

    private function fetchFlightBookingWithUser(int $bookingId): ?array
    {
        if ($bookingId <= 0) return null;
        $stmt = $this->db->prepare(
            'SELECT fb.*, u.email, u.first_name, u.last_name
             FROM flight_bookings fb
             JOIN users u ON u.id = fb.user_id
             WHERE fb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    // Internal: HTML builders
    // =========================================================================

    private function buildSimpleNotificationHtml(string $name, string $heading, string $message, string $ctaUrl): string
    {
        $year     = date('Y');
        $appName  = htmlspecialchars($this->fromName, ENT_QUOTES, 'UTF-8');
        $nameHtml = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $headHtml = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $ctaHtml  = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$headHtml}</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;">
      <tr><td style="background:#1a73e8;padding:30px 40px;">
        <h1 style="color:#fff;margin:0;font-size:24px;">{$appName}</h1>
      </td></tr>
      <tr><td style="padding:40px;">
        <p style="font-size:16px;color:#333;">Dear {$nameHtml},</p>
        <h2 style="color:#1a73e8;">{$headHtml}</h2>
        <p style="color:#555;line-height:1.6;">{$message}</p>
        <p style="text-align:center;margin:30px 0;">
          <a href="{$ctaHtml}" style="background:#1a73e8;color:#fff;padding:12px 30px;border-radius:4px;text-decoration:none;font-weight:bold;">View My Bookings</a>
        </p>
        <p style="color:#999;font-size:13px;">If you have any questions, please contact our support team.</p>
      </td></tr>
      <tr><td style="background:#f0f0f0;padding:20px 40px;text-align:center;">
        <p style="color:#aaa;font-size:12px;margin:0;">&copy; {$year} {$appName}. All rights reserved.</p>
      </td></tr>
    </table>
  </td></tr></table>
</body>
</html>
HTML;
    }

    private function buildPasswordResetHtml(string $name, string $resetUrl): string
    {
        $year    = date('Y');
        $appName = htmlspecialchars($this->fromName, ENT_QUOTES, 'UTF-8');
        $nameHtml = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $urlHtml  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Reset Your Password</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#0057a8;padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:26px;">{$appName}</h1>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            <h2 style="color:#333;margin-top:0;">Password Reset Request</h2>
            <p style="color:#555;font-size:15px;line-height:1.6;">Hello {$nameHtml},</p>
            <p style="color:#555;font-size:15px;line-height:1.6;">
              We received a request to reset the password for your {$appName} account.
              Click the button below to choose a new password. This link expires in 60 minutes.
            </p>
            <div style="text-align:center;margin:32px 0;">
              <a href="{$urlHtml}"
                 style="background:#0057a8;color:#ffffff;text-decoration:none;padding:14px 32px;
                        border-radius:6px;font-size:16px;font-weight:bold;display:inline-block;">
                Reset My Password
              </a>
            </div>
            <p style="color:#888;font-size:13px;line-height:1.6;">
              If you did not request a password reset, you can safely ignore this email.
              Your password will not be changed.
            </p>
            <p style="color:#888;font-size:12px;word-break:break-all;">
              Or copy this link into your browser:<br>{$urlHtml}
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f0f0f0;padding:20px 40px;text-align:center;">
            <p style="color:#aaa;font-size:12px;margin:0;">
              &copy; {$year} {$appName}. All rights reserved.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildFlightConfirmationHtml(array $booking, array $segments, array $passengers, array $documents = [], ?array $pkgHotel = null): string
    {
        $year     = date('Y');
        $appName  = htmlspecialchars($this->fromName, ENT_QUOTES, 'UTF-8');
        $ref      = htmlspecialchars($booking['booking_reference'],        ENT_QUOTES, 'UTF-8');
        $duffelRef= !empty($booking['duffel_booking_reference'])
            ? htmlspecialchars($booking['duffel_booking_reference'], ENT_QUOTES, 'UTF-8') : '';
        $amount   = number_format((float)$booking['total_amount'], 2);
        $currency = htmlspecialchars(strtoupper($booking['currency']), ENT_QUOTES, 'UTF-8');
        $status   = htmlspecialchars(ucfirst($booking['status']),     ENT_QUOTES, 'UTF-8');

        // Airline PNR / Duffel booking reference block
        // Also show per-airline PNRs from booking_references[] (multi-carrier itineraries)
        $extraRefsHtml = '';
        $bookingRefs = !empty($booking['booking_references'])
            ? (is_array($booking['booking_references'])
                ? $booking['booking_references']
                : json_decode($booking['booking_references'], true) ?? [])
            : [];
        foreach ($bookingRefs as $bref) {
            $refVal = htmlspecialchars((string)($bref['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $refAirline = htmlspecialchars((string)($bref['airline_iata_code'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($refVal && $refVal !== $duffelRef) {
                $label = $refAirline ? "{$refAirline} PNR" : 'Additional PNR';
                $extraRefsHtml .= "<p style=\"color:#92400e;font-size:13px;margin:4px 0 0;\">{$label}: <strong style=\"letter-spacing:2px\">{$refVal}</strong></p>";
            }
        }
        $pnrBlock = $duffelRef
            ? "<tr><td style=\"background:#fff8e1;padding:14px 40px;text-align:center;\">
                 <p style=\"color:#92400e;font-size:12px;margin:0;\">Airline Booking Reference (PNR)</p>
                 <p style=\"color:#78350f;font-size:22px;font-weight:bold;margin:4px 0 0;letter-spacing:3px;\">{$duffelRef}</p>
                 {$extraRefsHtml}
               </td></tr>"
            : '';

        // Electronic tickets block
        $ticketHtml = '';
        if (!empty($documents)) {
            $ticketHtml = '<h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Electronic Tickets</h3>
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">
                  <tr style="background:#f0f5fb;">
                    <th style="padding:8px;text-align:left;">Ticket Number</th>
                    <th style="padding:8px;text-align:left;">Type</th>
                  </tr>';
            foreach ($documents as $doc) {
                $ticketHtml .= sprintf(
                    '<tr><td style="padding:8px;border-bottom:1px solid #eee;font-family:monospace;font-weight:bold;">%s</td>
                         <td style="padding:8px;border-bottom:1px solid #eee;">%s</td></tr>',
                    htmlspecialchars($doc['unique_identifier'] ?? '', ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type'] ?? '')), ENT_QUOTES, 'UTF-8')
                );
            }
            $ticketHtml .= '</table>';
        }

        // Void window block
        $voidBlock = '';
        if (!empty($booking['void_window_ends_at'])) {
            $voidDt = new \DateTime($booking['void_window_ends_at']);
            $now    = new \DateTime();
            if ($voidDt > $now) {
                $voidFmt  = $voidDt->format('d M Y H:i');
                $voidBlock = "<p style=\"background:#ecfdf5;border-radius:6px;padding:12px;font-size:13px;color:#065f46;margin-top:20px;\">
                    ✅ <strong>Free Cancellation Available</strong> — You may cancel this booking at no charge until {$voidFmt} UTC.
                  </p>";
            }
        }

        // Refund conditions block
        $condBlock = '';
        $refundCond = !empty($booking['refund_conditions']) && is_string($booking['refund_conditions'])
            ? json_decode($booking['refund_conditions'], true)
            : ($booking['refund_conditions'] ?? null);
        $changeCond = !empty($booking['change_conditions']) && is_string($booking['change_conditions'])
            ? json_decode($booking['change_conditions'], true)
            : ($booking['change_conditions'] ?? null);

        if ($refundCond || $changeCond) {
            $condBlock = '<h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Fare Conditions</h3>
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">';
            if ($refundCond !== null) {
                $allowed    = ($refundCond['allowed'] ?? false) ? 'Allowed' : 'Not Allowed';
                $penaltyAmt = $refundCond['penalty_amount']   ?? null;
                $penaltyCur = strtoupper($refundCond['penalty_currency'] ?? $booking['currency'] ?? '');
                $penaltyStr = $penaltyAmt !== null ? " (Penalty: {$penaltyCur} {$penaltyAmt})" : '';
                $condBlock .= "<tr><td style=\"padding:8px;border-bottom:1px solid #eee;\"><strong>Refund Before Departure</strong></td>
                                   <td style=\"padding:8px;border-bottom:1px solid #eee;\">{$allowed}{$penaltyStr}</td></tr>";
            }
            if ($changeCond !== null) {
                $allowed    = ($changeCond['allowed'] ?? false) ? 'Allowed' : 'Not Allowed';
                $penaltyAmt = $changeCond['penalty_amount']   ?? null;
                $penaltyCur = strtoupper($changeCond['penalty_currency'] ?? $booking['currency'] ?? '');
                $penaltyStr = $penaltyAmt !== null ? " (Penalty: {$penaltyCur} {$penaltyAmt})" : '';
                $condBlock .= "<tr><td style=\"padding:8px;border-bottom:1px solid #eee;\"><strong>Change Before Departure</strong></td>
                                   <td style=\"padding:8px;border-bottom:1px solid #eee;\">{$allowed}{$penaltyStr}</td></tr>";
            }
            $condBlock .= '</table>';
        }

        // Build segments HTML
        $segHtml = '';
        foreach ($segments as $s) {
            $dep = date('d M Y H:i', strtotime($s['departure_at']));
            $arr = date('d M Y H:i', strtotime($s['arrival_at']));
            $segHtml .= sprintf(
                '<tr>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s → %s</td>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s</td>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s</td>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s</td>
                 </tr>',
                htmlspecialchars($s['origin_airport'],      ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($s['destination_airport'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($dep,                      ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($arr,                      ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($s['flight_number'],       ENT_QUOTES, 'UTF-8')
            );
        }

        // Build passengers HTML (include ticket number if available)
        $pasHtml = '';
        foreach ($passengers as $p) {
            $ticketCell = !empty($p['ticket_number'])
                ? htmlspecialchars($p['ticket_number'], ENT_QUOTES, 'UTF-8')
                : '—';
            $pasHtml .= sprintf(
                '<tr>
                   <td style="padding:8px;border-bottom:1px solid #eee;">%s %s</td>
                   <td style="padding:8px;border-bottom:1px solid #eee;">%s</td>
                   <td style="padding:8px;border-bottom:1px solid #eee;font-family:monospace;">%s</td>
                 </tr>',
                htmlspecialchars($p['first_name'],    ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($p['last_name'],     ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(ucfirst($p['passenger_type'] ?? 'adult'), ENT_QUOTES, 'UTF-8'),
                $ticketCell
            );
        }

        // Package hotel block (flight + hotel package) — empty for flight-only bookings.
        $hotelHtml = '';
        if (is_array($pkgHotel) && !empty($pkgHotel)) {
            $he   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $ci   = !empty($pkgHotel['check_in_date'])  ? date('d M Y', strtotime((string) $pkgHotel['check_in_date']))  : '—';
            $co   = !empty($pkgHotel['check_out_date']) ? date('d M Y', strtotime((string) $pkgHotel['check_out_date'])) : '—';
            $hRow = static fn($k, $v) => ($v !== '' && $v !== null)
                ? '<tr><td style="padding:8px;color:#888;">' . $k . '</td><td style="padding:8px;text-align:right;font-weight:bold;color:#333;">' . $v . '</td></tr>'
                : '';
            $hotelHtml =
                '<h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Hotel (Package)</h3>'
              . '<p style="font-size:15px;font-weight:bold;color:#333;margin:6px 0;">' . $he($pkgHotel['hotel_name'] ?? '') . '</p>'
              . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">'
              . $hRow('Hotel Booking Ref', $he($pkgHotel['booking_reference']   ?? ''))
              . $hRow('Provider Ref',      $he($pkgHotel['provider_booking_id'] ?? ''))
              . $hRow('Room',              $he($pkgHotel['room_type'] ?? ''))
              . $hRow('Meal plan',         $he($pkgHotel['meal_plan'] ?? ''))
              . $hRow('Check-in',          $he($ci))
              . $hRow('Check-out',         $he($co))
              . $hRow('Nights',            $he(($pkgHotel['nights_count'] ?? 1) . ' x ' . ($pkgHotel['rooms_count'] ?? 1) . ' room(s)'))
              . $hRow('Hotel amount',      $he(strtoupper((string) ($pkgHotel['currency'] ?? 'GBP')) . ' ' . number_format((float) ($pkgHotel['total_amount'] ?? 0), 2)))
              . '</table>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Flight Booking Confirmation</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:40px 0;">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#0057a8;padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:26px;">{$appName}</h1>
            <p style="color:#cde;margin:6px 0 0;font-size:14px;">Flight Booking Confirmation</p>
          </td>
        </tr>
        <!-- FlyMasar Booking reference -->
        <tr>
          <td style="background:#e8f1fb;padding:20px 40px;text-align:center;">
            <p style="color:#0057a8;font-size:13px;margin:0;">Your Booking Reference</p>
            <p style="color:#003d75;font-size:28px;font-weight:bold;margin:6px 0 0;letter-spacing:2px;">{$ref}</p>
          </td>
        </tr>
        <!-- Airline PNR (if available) -->
        {$pnrBlock}
        <!-- Body -->
        <tr>
          <td style="padding:30px 40px;">
            <p style="color:#555;font-size:15px;">Status: <strong style="color:#27ae60;">{$status}</strong></p>

            {$voidBlock}

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;">Flight Itinerary</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">
              <tr style="background:#f0f5fb;">
                <th style="padding:10px;text-align:left;">Route</th>
                <th style="padding:10px;text-align:left;">Departure</th>
                <th style="padding:10px;text-align:left;">Arrival</th>
                <th style="padding:10px;text-align:left;">Flight</th>
              </tr>
              {$segHtml}
            </table>

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Passengers</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">
              <tr style="background:#f0f5fb;">
                <th style="padding:8px;text-align:left;">Name</th>
                <th style="padding:8px;text-align:left;">Type</th>
                <th style="padding:8px;text-align:left;">Ticket No.</th>
              </tr>
              {$pasHtml}
            </table>

            {$hotelHtml}

            {$ticketHtml}

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Payment Summary</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#555;">
              <tr>
                <td style="padding:8px;">Total Amount</td>
                <td style="padding:8px;text-align:right;font-weight:bold;color:#0057a8;">{$currency} {$amount}</td>
              </tr>
            </table>

            {$condBlock}

            <p style="color:#888;font-size:13px;margin-top:28px;line-height:1.6;">
              Thank you for booking with {$appName}. If you have any questions, please contact our support team.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f0f0f0;padding:20px 40px;text-align:center;">
            <p style="color:#aaa;font-size:12px;margin:0;">&copy; {$year} {$appName}. All rights reserved.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildHotelConfirmationHtml(array $booking, array $rooms, array $guests): string
    {
        $year      = date('Y');
        $appName   = htmlspecialchars($this->fromName, ENT_QUOTES, 'UTF-8');
        $ref       = htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8');
        $hotel     = htmlspecialchars($booking['hotel_name'], ENT_QUOTES, 'UTF-8');
        $checkIn   = htmlspecialchars($booking['check_in_date'], ENT_QUOTES, 'UTF-8');
        $checkOut  = htmlspecialchars($booking['check_out_date'], ENT_QUOTES, 'UTF-8');
        $amount    = number_format((float)$booking['total_amount'], 2);
        $currency  = htmlspecialchars(strtoupper($booking['currency']), ENT_QUOTES, 'UTF-8');
        $status    = htmlspecialchars(ucfirst($booking['status']), ENT_QUOTES, 'UTF-8');

        // Rooms
        $roomHtml = '';
        foreach ($rooms as $r) {
            $roomHtml .= sprintf(
                '<tr>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s</td>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%s</td>
                   <td style="padding:10px;border-bottom:1px solid #eee;">%d</td>
                 </tr>',
                htmlspecialchars($r['room_type'] ?? 'Room', ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($r['meal_plan'] ?? 'Room Only', ENT_QUOTES, 'UTF-8'),
                1
            );
        }

        // Lead guest
        $leadGuest = '';
        foreach ($guests as $g) {
            if ($g['is_lead']) {
                $leadGuest = htmlspecialchars(
                    trim($g['first_name'] . ' ' . $g['last_name']),
                    ENT_QUOTES,
                    'UTF-8'
                );
                break;
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Hotel Booking Confirmation</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:40px 0;">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:#0057a8;padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:26px;">{$appName}</h1>
            <p style="color:#cde;margin:6px 0 0;font-size:14px;">Hotel Booking Confirmation</p>
          </td>
        </tr>
        <!-- Booking ref -->
        <tr>
          <td style="background:#e8f1fb;padding:20px 40px;text-align:center;">
            <p style="color:#0057a8;font-size:13px;margin:0;">Booking Reference</p>
            <p style="color:#003d75;font-size:28px;font-weight:bold;margin:6px 0 0;letter-spacing:2px;">{$ref}</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:30px 40px;">
            <p style="color:#555;font-size:15px;">Status: <strong style="color:#27ae60;">{$status}</strong></p>

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;">Hotel Details</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#555;">
              <tr>
                <td style="padding:8px;width:40%;"><strong>Hotel</strong></td>
                <td style="padding:8px;">{$hotel}</td>
              </tr>
              <tr style="background:#f9f9f9;">
                <td style="padding:8px;"><strong>Guest Name</strong></td>
                <td style="padding:8px;">{$leadGuest}</td>
              </tr>
              <tr>
                <td style="padding:8px;"><strong>Check-in</strong></td>
                <td style="padding:8px;">{$checkIn}</td>
              </tr>
              <tr style="background:#f9f9f9;">
                <td style="padding:8px;"><strong>Check-out</strong></td>
                <td style="padding:8px;">{$checkOut}</td>
              </tr>
            </table>

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Room(s)</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">
              <tr style="background:#f0f5fb;">
                <th style="padding:10px;text-align:left;">Room</th>
                <th style="padding:10px;text-align:left;">Board</th>
              </tr>
              {$roomHtml}
            </table>

            <h3 style="color:#0057a8;border-bottom:2px solid #e0e8f4;padding-bottom:8px;margin-top:28px;">Payment Summary</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#555;">
              <tr>
                <td style="padding:8px;">Total Amount</td>
                <td style="padding:8px;text-align:right;font-weight:bold;color:#0057a8;">{$currency} {$amount}</td>
              </tr>
            </table>

            <p style="color:#888;font-size:13px;margin-top:28px;line-height:1.6;">
              Thank you for booking with {$appName}. If you have any questions, please contact our support team.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f0f0f0;padding:20px 40px;text-align:center;">
            <p style="color:#aaa;font-size:12px;margin:0;">&copy; {$year} {$appName}. All rights reserved.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    // =========================================================================
    // Welcome email for guests whose account was auto-created after payment
    // =========================================================================

    public function sendWelcomeGuestEmail(string $email, string $name, string $password, string $bookingRef): void
    {
        $loginUrl = $this->appUrl . '/login.html';
        $subject  = 'مرحباً بك في فلاي مسار — تم إنشاء حسابك تلقائياً';
        $body     = $this->buildWelcomeGuestHtml($name, $email, $password, $bookingRef, $loginUrl);
        $this->sendHtmlMail($email, $subject, $body);
    }

    private function buildWelcomeGuestHtml(string $name, string $email, string $password, string $bookingRef, string $loginUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head><meta charset="UTF-8"><title>مرحباً بك</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f7fa;padding:20px;direction:rtl">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
    <div style="background:linear-gradient(135deg,#0f2444,#1B4F8E);padding:32px;text-align:center">
      <div style="font-size:2rem;color:#fff;font-weight:800">✈️ فلاي مسار</div>
      <div style="color:rgba(255,255,255,.8);margin-top:8px">منصة السفر الأولى في المنطقة</div>
    </div>
    <div style="padding:32px">
      <h2 style="color:#0f2444;margin:0 0 16px">مرحباً {$name}! 🎉</h2>
      <p style="color:#374151;line-height:1.8">تم إنشاء حسابك في فلاي مسار تلقائياً بعد إتمام حجزك رقم <strong style="color:#1B4F8E">{$bookingRef}</strong>.</p>
      <div style="background:#f0f5ff;border-radius:12px;padding:20px;margin:20px 0">
        <div style="font-weight:700;color:#0f2444;margin-bottom:12px">بيانات الدخول:</div>
        <div style="margin-bottom:8px">📧 <strong>البريد الإلكتروني:</strong> {$email}</div>
        <div>🔑 <strong>كلمة المرور:</strong> <span style="font-family:monospace;background:#e5e7eb;padding:2px 8px;border-radius:6px">{$password}</span></div>
      </div>
      <p style="color:#6b7280;font-size:.9rem">يُنصح بتغيير كلمة المرور بعد تسجيل الدخول لأول مرة.</p>
      <div style="text-align:center;margin-top:24px">
        <a href="{$loginUrl}" style="display:inline-block;background:#1B4F8E;color:#fff;padding:14px 32px;border-radius:10px;font-weight:700;text-decoration:none;font-size:1rem">تسجيل الدخول الآن</a>
      </div>
    </div>
    <div style="background:#0f2444;padding:16px;text-align:center;color:rgba(255,255,255,.6);font-size:.8rem">
      © ٢٠٢٦ فلاي مسار — flymasar.com
    </div>
  </div>
</body>
</html>
HTML;
    }
}
