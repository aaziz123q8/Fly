<?php

declare(strict_types=1);

namespace App\Workers;

use App\Helpers\Database;
use App\Services\EmailNotificationService;
use App\Services\InvoicePdfService;
use App\Services\WhatsAppService;
use PDO;
use Throwable;

class JobWorker
{
    private PDO $db;

    /** Maximum jobs to claim per run */
    private const BATCH_SIZE = 10;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    public function run(): void
    {
        $processed = 0;
        $failed    = 0;

        $jobIds = $this->claimJobs();

        if (empty($jobIds)) {
            // Nothing to do — exit quietly
            return;
        }

        foreach ($jobIds as $jobId) {
            $job = $this->fetchJob($jobId);
            if (!$job) {
                continue;
            }

            try {
                $this->dispatch($job);
                $this->markDone($jobId);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $this->markFailed($job, $e);
                $this->logError(
                    "JobWorker: job {$jobId} ({$job['job_type']}) failed: " . $e->getMessage(),
                    [
                        'job_id'    => $jobId,
                        'job_type'  => $job['job_type'],
                        'attempts'  => (int)$job['attempts'],
                        'exception' => get_class($e),
                    ]
                );
            }
        }

        // Summary log
        $this->logInfo('JobWorker run complete', [
            'processed' => $processed,
            'failed'    => $failed,
            'total'     => count($jobIds),
            'ran_at'    => date('c'),
        ]);
    }

    // =========================================================================
    // Claim jobs atomically
    // =========================================================================

    /**
     * Claim up to BATCH_SIZE pending jobs using SELECT ... FOR UPDATE SKIP LOCKED.
     * Returns an array of claimed job IDs.
     */
    private function claimJobs(): array
    {
        $this->db->beginTransaction();

        try {
            // Select IDs to claim
            $select = $this->db->prepare(
                'SELECT id FROM job_queue
                 WHERE status = \'pending\'
                   AND attempts < max_attempts
                   AND (run_at IS NULL OR run_at <= NOW())
                 ORDER BY id ASC
                 LIMIT ' . self::BATCH_SIZE . '
                 FOR UPDATE SKIP LOCKED'
            );
            $select->execute();
            $ids = $select->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                $this->db->rollBack();
                return [];
            }

            // Claim them: bump attempts and mark processing
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare(
                "UPDATE job_queue
                 SET status = 'processing', attempts = attempts + 1, started_at = NOW()
                 WHERE id IN ({$placeholders})"
            );
            $update->execute($ids);

            $this->db->commit();
            return $ids;
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->logError('JobWorker: failed to claim jobs: ' . $e->getMessage(), []);
            return [];
        }
    }

    // =========================================================================
    // Fetch single job row
    // =========================================================================

    private function fetchJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM job_queue WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    // Dispatch to handler
    // =========================================================================

    private function dispatch(array $job): void
    {
        $payload = is_string($job['payload'])
            ? (json_decode($job['payload'], true) ?? [])
            : ($job['payload'] ?? []);

        switch ($job['job_type']) {
            case 'send_password_reset_email':
                (new EmailNotificationService($this->db))->sendPasswordReset($payload);
                break;

            case 'send_booking_confirmation_email':
                (new EmailNotificationService($this->db))->sendBookingConfirmation($payload);
                break;

            case 'send_whatsapp_booking_confirmation':
                (new WhatsAppService($this->db))->sendBookingConfirmation($payload);
                break;

            case 'generate_invoice_pdf':
                (new InvoicePdfService($this->db))->generate($payload);
                break;

            case 'sync_flight_booking':
                (new \App\Services\FlightBookingService($this->db))->syncFromDuffelByBookingId((int)($payload['booking_id'] ?? 0));
                break;

            case 'flight_schedule_changed':
                (new EmailNotificationService($this->db))->sendScheduleChangeEmail($payload);
                break;

            case 'cancel_booking_email':
                (new EmailNotificationService($this->db))->sendCancellationEmail($payload);
                break;

            case 'flight_cancelled_by_airline':
                (new EmailNotificationService($this->db))->sendAirlineCancellationEmail($payload);
                break;

            case 'flight_change_rejected':
                (new EmailNotificationService($this->db))->sendChangeRejectedEmail($payload);
                break;

            case 'flight_awaiting_payment':
                (new EmailNotificationService($this->db))->sendAwaitingPaymentEmail($payload);
                break;

            // Queued by WebhookController::queueNotificationJob — payload only
            // carries { booking_id, user_id } and is always a flight booking.
            case 'flight_booking_confirmed':
            case 'flight_payment_confirmed':
                (new EmailNotificationService($this->db))
                    ->sendBookingConfirmation($payload + ['booking_type' => 'flight']);
                break;

            case 'flight_cancellation_confirmed':
                (new EmailNotificationService($this->db))->sendCancellationEmail($payload);
                break;

            // Ops alerts — no customer email; record so the job is not retried
            // forever and ops can monitor via the error log / job history.
            case 'order_creation_failed_alert':
            case 'payment_failed_alert':
                error_log('[OPS_ALERT|' . $job['job_type'] . '] ' . json_encode($payload));
                break;

            default:
                throw new \RuntimeException("Unknown job type: {$job['job_type']}");
        }
    }

    // =========================================================================
    // Status updates
    // =========================================================================

    private function markDone(int $id): void
    {
        $this->db->prepare(
            "UPDATE job_queue SET status = 'done', completed_at = NOW() WHERE id = :id"
        )->execute([':id' => $id]);
    }

    private function markFailed(array $job, Throwable $e): void
    {
        $attempts    = (int)$job['attempts']; // already incremented during claim
        $maxAttempts = (int)$job['max_attempts'];

        // If we have exhausted all attempts → permanent failure, else allow retry
        $newStatus = ($attempts >= $maxAttempts) ? 'failed' : 'pending';

        $this->db->prepare(
            "UPDATE job_queue
             SET status = :status, error_message = :msg, completed_at = NOW()
             WHERE id = :id"
        )->execute([
            ':status' => $newStatus,
            ':msg'    => mb_substr($e->getMessage(), 0, 65535),
            ':id'     => $job['id'],
        ]);
    }

    // =========================================================================
    // Logging helpers
    // =========================================================================

    private function logInfo(string $message, array $context = []): void
    {
        $this->insertLog('info', $message, $context);
    }

    private function logError(string $message, array $context = []): void
    {
        $this->insertLog('error', $message, $context);
    }

    private function insertLog(string $level, string $message, array $context): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO error_logs (level, message, context) VALUES (:level, :msg, :ctx)"
            )->execute([
                ':level' => $level,
                ':msg'   => $message,
                ':ctx'   => json_encode($context),
            ]);
        } catch (Throwable) {
            // Non-fatal: if DB logging fails, we can't do much
        }
    }
}
