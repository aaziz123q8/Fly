<?php

declare(strict_types=1);

namespace App\Controllers\Webhook;

use App\Helpers\Database;
use App\Helpers\SecurityHelper;
use App\Services\FlightBookingService;
use App\Services\HotelBookingService;
use PDO;

/**
 * WebhookController
 *
 * Handles inbound webhooks from Stripe and Duffel.
 *
 * Security checklist:
 *  1. HMAC signature validation before any processing.
 *  2. Replay protection via webhook_logs (source + event_id unique key).
 *  3. GET_LOCK advisory lock so concurrent duplicate deliveries don't race.
 *  4. Raw payload read before any framework/body parsing.
 */
class WebhookController
{
    private PDO $db;
    private array $config;

    public function __construct(?PDO $db = null)
    {
        $this->db     = $db ?? Database::getInstance();
        $this->config = file_exists(dirname(__DIR__, 3) . '/config/apis.php')
            ? require dirname(__DIR__, 3) . '/config/apis.php'
            : [];
    }

    // =========================================================================
    // Stripe
    // =========================================================================

    public function handleStripe(): void
    {
        $rawPayload = $this->getRawInput();
        $sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret     = $this->config['stripe']['webhook_secret'] ?? (getenv('STRIPE_WEBHOOK_SECRET') ?: '');

        $signatureValid = $this->validateStripeSignature($rawPayload, $sigHeader, $secret);

        $payload = json_decode($rawPayload, true);
        $eventId = $payload['id'] ?? ('stripe_' . uniqid());

        // Replay protection + idempotency via DB unique key.
        if (!$this->insertWebhookLog('stripe', $eventId, $payload['type'] ?? null, $signatureValid, $rawPayload)) {
            // Already processed — acknowledge and exit.
            $this->respond(200, ['status' => 'already_processed']);
        }

        if (!$signatureValid) {
            $this->updateWebhookLog('stripe', $eventId, false, 'invalid_signature');
            $this->respond(400, ['error' => 'invalid_signature']);
        }

        $lockName   = 'stripe_webhook_' . $eventId;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            $this->respond(503, ['error' => 'lock_timeout']);
        }

        try {
            $result = $this->processStripeEvent($payload);
            $this->updateWebhookLog('stripe', $eventId, true, $result);
            $this->respond(200, ['status' => 'ok', 'result' => $result]);
        } catch (\Throwable $e) {
            $this->updateWebhookLog('stripe', $eventId, true, 'error: ' . $e->getMessage());
            $this->respond(500, ['error' => 'processing_error']);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    // =========================================================================
    // Duffel
    // =========================================================================

    public function handleDuffel(): void
    {
        $rawPayload = $this->getRawInput();
        $sigHeader  = $_SERVER['HTTP_X_DUFFEL_SIGNATURE'] ?? '';
        $secret     = $this->config['duffel']['webhook_secret'] ?? (getenv('DUFFEL_WEBHOOK_SECRET') ?: '');

        $signatureValid = $this->validateDuffelSignature($rawPayload, $sigHeader, $secret);

        $payload = json_decode($rawPayload, true);
        $eventId = $payload['data']['id'] ?? ('duffel_' . uniqid());

        if (!$this->insertWebhookLog('duffel', $eventId, $payload['type'] ?? null, $signatureValid, $rawPayload)) {
            $this->respond(200, ['status' => 'already_processed']);
        }

        if (!$signatureValid) {
            $this->updateWebhookLog('duffel', $eventId, false, 'invalid_signature');
            $this->respond(400, ['error' => 'invalid_signature']);
        }

        $lockName    = 'duffel_webhook_' . $eventId;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            $this->respond(503, ['error' => 'lock_timeout']);
        }

        try {
            $result = $this->processDuffelEvent($payload);
            $this->updateWebhookLog('duffel', $eventId, true, $result);
            $this->respond(200, ['status' => 'ok', 'result' => $result]);
        } catch (\Throwable $e) {
            $this->updateWebhookLog('duffel', $eventId, true, 'error: ' . $e->getMessage());
            $this->respond(500, ['error' => 'processing_error']);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    // =========================================================================
    // Private — Stripe processing
    // =========================================================================

    private function processStripeEvent(array $payload): string
    {
        $type = $payload['type'] ?? '';
        $data = $payload['data']['object'] ?? [];

        switch ($type) {
            case 'payment_intent.succeeded':
                return $this->onStripePaymentSucceeded($data);

            case 'payment_intent.payment_failed':
                return $this->onStripePaymentFailed($data);

            case 'charge.refunded':
                return $this->onStripeChargeRefunded($data);

            default:
                return 'unhandled_event_type';
        }
    }

    private function onStripePaymentSucceeded(array $data): string
    {
        $intentId = $data['id'] ?? null;
        if (!$intentId) {
            return 'missing_intent_id';
        }

        // Update the payment row first
        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status = :status, gateway_status = :gs,
                 gateway_response = :gr, paid_at = NOW()
             WHERE stripe_payment_intent_id = :pi_id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => 'succeeded',
            ':gs'      => 'succeeded',
            ':gr'      => json_encode($data),
            ':pi_id'   => $intentId,
            ':pending' => 'pending',
        ]);

        // Look up booking session to route to correct booking service
        $sessionStmt = $this->db->prepare(
            'SELECT session_key, booking_type
             FROM booking_sessions
             WHERE payment_intent_id = :pi
             LIMIT 1'
        );
        $sessionStmt->execute([':pi' => $intentId]);
        $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sessionRow) {
            return 'payment_updated_no_session';
        }

        $sessionKey  = $sessionRow['session_key'];
        $bookingType = $sessionRow['booking_type'] ?? '';

        try {
            if ($bookingType === 'hotel') {
                (new HotelBookingService($this->db))->completeBooking($sessionKey, $intentId);
                return 'hotel_booking_completed';
            }

            if ($bookingType === 'flight') {
                (new FlightBookingService($this->db))->completeBooking($sessionKey, $intentId);
                return 'flight_booking_completed';
            }
        } catch (\Throwable $e) {
            // Log and return error detail so webhook_logs captures it
            return 'booking_completion_error: ' . $e->getMessage();
        }

        return 'payment_updated';
    }

    private function onStripePaymentFailed(array $data): string
    {
        $intentId = $data['id'] ?? null;
        $reason   = $data['last_payment_error']['message'] ?? 'unknown';

        if (!$intentId) {
            return 'missing_intent_id';
        }

        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status = :status, gateway_status = :gs,
                 failure_reason = :reason, gateway_response = :gr
             WHERE stripe_payment_intent_id = :pi_id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => 'failed',
            ':gs'      => 'failed',
            ':reason'  => $reason,
            ':gr'      => json_encode($data),
            ':pi_id'   => $intentId,
            ':pending' => 'pending',
        ]);

        return 'payment_failed_recorded';
    }

    private function onStripeChargeRefunded(array $data): string
    {
        $chargeId = $data['id'] ?? null;
        if (!$chargeId) {
            return 'missing_charge_id';
        }

        $this->db->prepare(
            'UPDATE payments SET status = :status WHERE stripe_charge_id = :cid'
        )->execute([':status' => 'refunded', ':cid' => $chargeId]);

        return 'refund_recorded';
    }

    // =========================================================================
    // Private — Duffel processing
    // =========================================================================

    private function processDuffelEvent(array $payload): string
    {
        $type = $payload['type'] ?? '';
        $data = $payload['data'] ?? [];

        switch ($type) {
            case 'order.airline_initiated_change':
                return $this->onDuffelOrderChanged($data);

            case 'order.cancelled':
                return $this->onDuffelOrderCancelled($data);

            default:
                return 'unhandled_event_type';
        }
    }

    private function onDuffelOrderChanged(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) {
            return 'missing_order_id';
        }

        $this->db->prepare(
            'UPDATE flight_bookings SET status = :status, updated_at = NOW()
             WHERE provider_order_id = :oid AND status = :confirmed'
        )->execute([
            ':status'    => 'changed',
            ':oid'       => $orderId,
            ':confirmed' => 'confirmed',
        ]);

        return 'booking_status_changed';
    }

    private function onDuffelOrderCancelled(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) {
            return 'missing_order_id';
        }

        $this->db->prepare(
            'UPDATE flight_bookings
             SET status = :status, cancelled_at = NOW(), updated_at = NOW()
             WHERE provider_order_id = :oid AND status != :cancelled'
        )->execute([
            ':status'    => 'cancelled',
            ':oid'       => $orderId,
            ':cancelled' => 'cancelled',
        ]);

        return 'booking_cancelled';
    }

    // =========================================================================
    // Private — Signature validation
    // =========================================================================

    /**
     * Stripe uses a "t=timestamp,v1=signature" header.
     * @see https://stripe.com/docs/webhooks/signatures
     */
    private function validateStripeSignature(string $payload, string $header, string $secret): bool
    {
        if (empty($secret) || empty($header)) {
            return false;
        }

        $parts     = [];
        $tolerance = 300; // 5 minutes

        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = (int)($parts['t'] ?? 0);
        $v1        = $parts['v1'] ?? '';

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected      = hash_hmac('sha256', $signedPayload, $secret);

        return SecurityHelper::safeCompare($expected, $v1);
    }

    /**
     * Duffel uses HMAC-SHA256 with the raw body.
     * @see https://duffel.com/docs/guides/webhooks
     */
    private function validateDuffelSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($secret) || empty($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return SecurityHelper::safeCompare($expected, $signature);
    }

    // =========================================================================
    // Private — DB helpers
    // =========================================================================

    /**
     * Insert a webhook log row.
     * Returns false if the event was already logged (duplicate = already processed).
     */
    private function insertWebhookLog(
        string $source,
        string $eventId,
        ?string $eventType,
        bool $signatureValid,
        string $rawPayload
    ): bool {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO webhook_logs (source, event_id, event_type, signature_valid, payload)
                 VALUES (:src, :eid, :et, :sv, :pl)'
            );
            $stmt->execute([
                ':src' => $source,
                ':eid' => $eventId,
                ':et'  => $eventType,
                ':sv'  => (int)$signatureValid,
                ':pl'  => $rawPayload,
            ]);
            return true;
        } catch (\PDOException $e) {
            // Duplicate entry = already processed.
            if (str_contains($e->getMessage(), '1062')) {
                return false;
            }
            throw $e;
        }
    }

    private function updateWebhookLog(string $source, string $eventId, bool $processed, string $result): void
    {
        $stmt = $this->db->prepare(
            'UPDATE webhook_logs
             SET processed = :p, processing_result = :r, processed_at = NOW()
             WHERE source = :src AND event_id = :eid'
        );
        $stmt->execute([
            ':p'   => (int)$processed,
            ':r'   => substr($result, 0, 100),
            ':src' => $source,
            ':eid' => $eventId,
        ]);
    }

    // =========================================================================
    // Private — Advisory locks
    // =========================================================================

    private function acquireLock(string $lockName, int $timeoutSeconds): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, :timeout)');
        $stmt->execute([':name' => $lockName, ':timeout' => $timeoutSeconds]);
        return (bool)$stmt->fetchColumn();
    }

    private function releaseLock(string $lockName): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute([':name' => $lockName]);
    }

    // =========================================================================
    // Private — I/O helpers
    // =========================================================================

    private function getRawInput(): string
    {
        return (string)file_get_contents('php://input');
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($body);
        exit;
    }
}
