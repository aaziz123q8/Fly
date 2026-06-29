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

        // Lock on the affected resource (the PaymentIntent), not the event id, so
        // that two *different* events touching the same payment serialize.
        $resourceId = $payload['data']['object']['id'] ?? $eventId;
        $lockName   = 'stripe_pi_' . $resourceId;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            $this->respond(503, ['error' => 'lock_timeout']);
        }

        try {
            $result = $this->processStripeEvent($payload);
            $this->updateWebhookLog('stripe', $eventId, true, $result);
            $this->respond(200, ['status' => 'ok', 'result' => $result]);
        } catch (\Throwable $e) {
            // Mark unprocessed (processed = false) so the failure is visible to
            // ops monitoring instead of being recorded as a success.
            $this->updateWebhookLog('stripe', $eventId, false, 'error: ' . $e->getMessage());
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

        // Lock on the affected order, not the event id, so different events for
        // the same order (created / payment_status_updated / cancelled) serialize.
        $resourceId  = $payload['data']['order_id'] ?? $payload['data']['id'] ?? $eventId;
        $lockName    = 'duffel_order_' . $resourceId;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            $this->respond(503, ['error' => 'lock_timeout']);
        }

        try {
            $result = $this->processDuffelEvent($payload);
            $this->updateWebhookLog('duffel', $eventId, true, $result);
            $this->respond(200, ['status' => 'ok', 'result' => $result]);
        } catch (\Throwable $e) {
            // Mark unprocessed (processed = false) so the failure is visible to ops.
            $this->updateWebhookLog('duffel', $eventId, false, 'error: ' . $e->getMessage());
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
            // The frontend confirmCheckout is the primary completion path, so a
            // failure here is not necessarily a lost booking — but it must be
            // visible to ops. Log loudly and return the detail for webhook_logs.
            error_log(sprintf(
                '[WEBHOOK_BOOKING_COMPLETION_FAILED] intent=%s type=%s | %s',
                $intentId, $bookingType, $e->getMessage()
            ));
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
            case 'ping':
                return 'pong';

            // Sent ~30s after a 200/202 createOrder response once the order is ready.
            case 'order.created':
                return $this->onDuffelOrderCreated($data);

            // Sent ~30s after a 202 createOrder when the order ultimately fails.
            case 'order.creation_failed':
                return $this->onDuffelOrderCreationFailed($data);

            case 'order.airline_initiated_change':
            case 'order.airline_initiated_change.updated':
            case 'order.updated':
                return $this->onDuffelOrderChanged($data);

            case 'order.airline_initiated_change.accepted':
                return $this->onDuffelChangeAccepted($data);

            case 'order.airline_initiated_change.rejected':
                return $this->onDuffelChangeRejected($data);

            case 'order.cancelled':
                return $this->onDuffelOrderCancelled($data);

            case 'order.payment_status_updated':
                return $this->onDuffelPaymentStatusUpdated($data);

            // Sent after a card payment on a hold order resolves (200/202 from /air/payments).
            case 'payment.created':
            case 'air.payment.succeeded':
                return $this->onDuffelPaymentCreated($data);

            case 'air.payment.failed':
                return $this->onDuffelPaymentFailed($data);

            case 'air.payment.cancelled':
            case 'air.payment.pending':
                return 'payment_status_noted:' . $type;

            case 'order_cancellation.created':
                return 'cancellation_created:awaiting_confirmation';

            case 'order_cancellation.confirmed':
                return $this->onDuffelCancellationConfirmed($data);

            case 'air.airline_credit.created':
            case 'air.airline_credit.spent':
            case 'air.airline_credit.invalidated':
                return 'airline_credit_noted:' . $type;

            case 'air.order.changed':
                return $this->onDuffelOrderChanged($data);

            // Alias used in some older deliveries
            case 'order.airline_initiated_change_detected':
                return $this->onDuffelOrderChanged($data);

            case 'ping.triggered':
                return 'pong';

            default:
                error_log('[Duffel|webhook] unhandled event type: ' . $type);
                return 'unhandled_event_type:' . $type;
        }
    }

    /**
     * order.created — fires ~30s after a 200/202 createOrder card payment response.
     * The payload contains the full order object. Find the pending booking row by
     * offer_id (embedded in the webhook) and promote it to confirmed.
     */
    private function onDuffelOrderCreated(array $data): string
    {
        $orderId  = $data['id']                ?? null;
        $bookRef  = $data['booking_reference'] ?? null;
        $offerId  = $data['selected_offers'][0]['id'] ?? ($data['offer_id'] ?? null);

        error_log(sprintf('[Duffel|order.created] order=%s ref=%s offer=%s', $orderId, $bookRef, $offerId));

        if (!$orderId) {
            return 'missing_order_id';
        }

        // Try to locate the pending booking by provider_order_id (if we stored it on the 202 row)
        // or by the offer_id stored in the booking session / flight_bookings.
        $updated = 0;
        if ($offerId) {
            $stmt = $this->db->prepare(
                "UPDATE flight_bookings
                 SET provider_order_id        = COALESCE(NULLIF(provider_order_id,''), :oid),
                     duffel_booking_reference = COALESCE(duffel_booking_reference, :ref),
                     status                   = 'confirmed',
                     updated_at               = NOW()
                 WHERE status = 'pending'
                   AND provider_offer_id = :offer_id"
            );
            $stmt->execute([':oid' => $orderId, ':ref' => $bookRef, ':offer_id' => $offerId]);
            $updated = $stmt->rowCount();
        }

        // Fallback: match on provider_order_id already recorded during the pending insert.
        if ($updated === 0) {
            $stmt = $this->db->prepare(
                "UPDATE flight_bookings
                 SET duffel_booking_reference = COALESCE(duffel_booking_reference, :ref),
                     status                   = 'confirmed',
                     updated_at               = NOW()
                 WHERE provider_order_id = :oid AND status = 'pending'"
            );
            $stmt->execute([':oid' => $orderId, ':ref' => $bookRef]);
            $updated = $stmt->rowCount();
        }

        if ($updated > 0) {
            $this->queueNotificationJob($orderId, 'flight_booking_confirmed');
        } else {
            error_log('[Duffel|order.created] no pending booking found for order=' . $orderId . ' offer=' . $offerId);
        }

        return 'order_created:updated=' . $updated;
    }

    /**
     * order.creation_failed — fires ~30s after a 202 createOrder when the order fails.
     * The payload contains the offer_id. Mark the pending booking as failed and alert ops.
     */
    private function onDuffelOrderCreationFailed(array $data): string
    {
        $offerId = $data['offer_id'] ?? null;
        $reason  = $data['failure_reason'] ?? ($data['message'] ?? 'unknown');

        error_log(sprintf('[Duffel|order.creation_failed] offer=%s reason=%s', $offerId, $reason));

        $updated = 0;
        if ($offerId) {
            $stmt = $this->db->prepare(
                "UPDATE flight_bookings
                 SET status     = 'failed',
                     updated_at = NOW()
                 WHERE status = 'pending'
                   AND provider_offer_id = :offer_id"
            );
            $stmt->execute([':offer_id' => $offerId]);
            $updated = $stmt->rowCount();
        }

        // Queue an ops alert regardless — money may have left the card.
        try {
            $this->db->prepare(
                'INSERT INTO job_queue (job_type, payload) VALUES (:jt, :pl)'
            )->execute([
                ':jt' => 'order_creation_failed_alert',
                ':pl' => json_encode(['offer_id' => $offerId, 'reason' => $reason, 'rows_updated' => $updated]),
            ]);
        } catch (\Throwable) {}

        return 'order_creation_failed:offer=' . $offerId . ':updated=' . $updated;
    }

    /**
     * payment.created — fires ~30s after a card payment on a hold order resolves.
     * Updates the payment record and confirms the booking if it was awaiting payment.
     */
    private function onDuffelPaymentCreated(array $data): string
    {
        $orderId   = $data['order_id'] ?? null;
        $paymentId = $data['id']       ?? null;
        $amount    = $data['amount']   ?? null;
        $currency  = $data['currency'] ?? null;

        error_log(sprintf('[Duffel|payment.created] order=%s payment=%s amount=%s %s',
            $orderId, $paymentId, $amount, $currency));

        if (!$orderId) {
            return 'missing_order_id';
        }

        // Mark the booking as confirmed and clear the awaiting_payment flag.
        $stmt = $this->db->prepare(
            "UPDATE flight_bookings
             SET awaiting_payment = 0,
                 paid_at          = NOW(),
                 status           = CASE WHEN status = 'awaiting_payment' THEN 'confirmed' ELSE status END,
                 updated_at       = NOW()
             WHERE provider_order_id = :oid"
        );
        $stmt->execute([':oid' => $orderId]);

        // Update the payments table if a matching row exists.
        $this->db->prepare(
            "UPDATE payments SET status = 'succeeded', paid_at = NOW()
             WHERE booking_type = 'flight'
               AND booking_id   = (SELECT id FROM flight_bookings WHERE provider_order_id = :oid LIMIT 1)
               AND status != 'succeeded'"
        )->execute([':oid' => $orderId]);

        $this->queueNotificationJob($orderId, 'flight_payment_confirmed');

        return 'payment_created:order=' . $orderId;
    }

    private function onDuffelOrderChanged(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) return 'missing_order_id';

        $this->db->prepare(
            'UPDATE flight_bookings SET status = :status, updated_at = NOW()
             WHERE provider_order_id = :oid AND status = :confirmed'
        )->execute([':status' => 'changed', ':oid' => $orderId, ':confirmed' => 'confirmed']);

        // Sync fresh data from Duffel so segments reflect the new schedule.
        $this->queueNotificationJob($orderId, 'sync_flight_booking');
        // Notify customer after sync completes.
        $this->queueNotificationJob($orderId, 'flight_schedule_changed');

        return 'booking_status_changed';
    }

    private function onDuffelChangeAccepted(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) return 'missing_order_id';

        $this->db->prepare(
            'UPDATE flight_bookings SET status = :status, updated_at = NOW()
             WHERE provider_order_id = :oid'
        )->execute([':status' => 'confirmed', ':oid' => $orderId]);

        return 'change_accepted';
    }

    private function onDuffelChangeRejected(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) return 'missing_order_id';

        $this->queueNotificationJob($orderId, 'flight_change_rejected');
        return 'change_rejected_notified';
    }

    private function onDuffelOrderCancelled(array $data): string
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) return 'missing_order_id';

        $this->db->prepare(
            'UPDATE flight_bookings
             SET status = :status, cancelled_at = NOW(), updated_at = NOW()
             WHERE provider_order_id = :oid AND status != :cancelled'
        )->execute([
            ':status'    => 'cancelled',
            ':oid'       => $orderId,
            ':cancelled' => 'cancelled',
        ]);

        $this->queueNotificationJob($orderId, 'flight_cancelled_by_airline');
        return 'booking_cancelled';
    }

    private function onDuffelPaymentStatusUpdated(array $data): string
    {
        $orderId       = $data['id'] ?? null;
        $paymentStatus = $data['payment_status'] ?? [];
        if (!$orderId) return 'missing_order_id';

        $awaitingPayment         = (bool)($paymentStatus['awaiting_payment'] ?? false);
        $paidAt                  = !empty($paymentStatus['paid_at'])
            ? date('Y-m-d H:i:s', strtotime($paymentStatus['paid_at'])) : null;
        $paymentRequiredBy       = !empty($paymentStatus['payment_required_by'])
            ? date('Y-m-d H:i:s', strtotime($paymentStatus['payment_required_by'])) : null;
        $priceGuaranteeExpiresAt = !empty($paymentStatus['price_guarantee_expires_at'])
            ? date('Y-m-d H:i:s', strtotime($paymentStatus['price_guarantee_expires_at'])) : null;
        $failureReason           = !empty($paymentStatus['failure_reason'])
            ? substr((string)$paymentStatus['failure_reason'], 0, 500) : null;

        // Build status: only change to awaiting_payment if the flag is set.
        // Do not override terminal statuses (cancelled, failed).
        $statusClause = $awaitingPayment
            ? ", status = CASE WHEN status NOT IN ('cancelled','failed') THEN 'awaiting_payment' ELSE status END"
            : '';

        $this->db->prepare(
            "UPDATE flight_bookings
             SET awaiting_payment            = :awaiting,
                 paid_at                     = COALESCE(:paid_at, paid_at),
                 payment_required_by         = COALESCE(:prb, payment_required_by),
                 price_guarantee_expires_at  = COALESCE(:pge, price_guarantee_expires_at),
                 duffel_payment_failure      = :failure,
                 updated_at                  = NOW()
                 {$statusClause}
             WHERE provider_order_id = :oid"
        )->execute([
            ':awaiting' => (int)$awaitingPayment,
            ':paid_at'  => $paidAt,
            ':prb'      => $paymentRequiredBy,
            ':pge'      => $priceGuaranteeExpiresAt,
            ':failure'  => $failureReason,
            ':oid'      => $orderId,
        ]);

        // Structured log for operations visibility
        error_log(sprintf(
            '[Duffel|payment_status_updated] order=%s awaiting=%s paid_at=%s prb=%s failure=%s',
            $orderId,
            $awaitingPayment ? 'true' : 'false',
            $paidAt           ?? 'null',
            $paymentRequiredBy ?? 'null',
            $failureReason     ?? 'none'
        ));

        // Queue an operations notification if the booking is now awaiting payment
        if ($awaitingPayment) {
            $this->queueNotificationJob($orderId, 'flight_awaiting_payment');
        }

        return 'payment_status_updated:awaiting=' . ($awaitingPayment ? '1' : '0')
            . ':failure=' . ($failureReason ? 'yes' : 'no');
    }

    private function onDuffelPaymentFailed(array $data): string
    {
        $orderId = $data['order_id'] ?? ($data['id'] ?? null);
        $reason  = $data['failure_reason'] ?? ($data['message'] ?? 'unknown');

        error_log(sprintf('[Duffel|air.payment.failed] order=%s reason=%s', $orderId, $reason));

        if (!$orderId) {
            return 'missing_order_id';
        }

        $this->db->prepare(
            "UPDATE flight_bookings
             SET duffel_payment_failure = :reason, updated_at = NOW()
             WHERE provider_order_id = :oid"
        )->execute([':reason' => substr($reason, 0, 500), ':oid' => $orderId]);

        try {
            $this->db->prepare(
                'INSERT INTO job_queue (job_type, payload) VALUES (:jt, :pl)'
            )->execute([
                ':jt' => 'payment_failed_alert',
                ':pl' => json_encode(['order_id' => $orderId, 'reason' => $reason]),
            ]);
        } catch (\Throwable) {}

        return 'payment_failed:order=' . $orderId;
    }

    private function onDuffelCancellationConfirmed(array $data): string
    {
        $cancellationId = $data['id']       ?? null;
        $orderId        = $data['order_id'] ?? null;
        $refundTo       = $data['refund_to'] ?? null;
        $refundAmount   = $data['refund_amount'] ?? null;

        error_log(sprintf('[Duffel|order_cancellation.confirmed] cancellation=%s order=%s refund_to=%s amount=%s',
            $cancellationId, $orderId, $refundTo, $refundAmount));

        if (!$orderId) {
            return 'missing_order_id';
        }

        $this->db->prepare(
            "UPDATE flight_bookings
             SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
             WHERE provider_order_id = :oid AND status != 'cancelled'"
        )->execute([':oid' => $orderId]);

        $this->queueNotificationJob($orderId, 'flight_cancellation_confirmed');

        return 'cancellation_confirmed:order=' . $orderId . ':refund_to=' . $refundTo;
    }

    private function queueNotificationJob(string $providerOrderId, string $jobType): void
    {
        try {
            $booking = $this->db->prepare(
                'SELECT id, user_id FROM flight_bookings WHERE provider_order_id = :oid LIMIT 1'
            );
            $booking->execute([':oid' => $providerOrderId]);
            $row = $booking->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $this->db->prepare(
                'INSERT INTO job_queue (job_type, payload) VALUES (:jt, :pl)'
            )->execute([
                ':jt' => $jobType,
                ':pl' => json_encode(['booking_id' => $row['id'], 'user_id' => $row['user_id']]),
            ]);
        } catch (\Throwable) {
            // Non-critical.
        }
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
