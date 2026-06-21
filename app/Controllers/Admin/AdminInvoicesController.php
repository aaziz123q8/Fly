<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Services\InvoicePdfService;

class AdminInvoicesController
{
    // =========================================================================
    // GET /api/admin/invoices/:type/:id
    // =========================================================================

    public function show(Request $request): void
    {
        $type = $request->param('type') ?? '';
        $id   = (int)($request->param('id') ?? 0);

        $this->validateTypeAndId($type, $id);

        $db      = Database::getInstance();
        $booking = $this->fetchBooking($db, $type, $id);

        if (!$booking) {
            Response::error('Booking not found.', 404, 'not_found');
        }

        $filePath = BASE_PATH . '/storage/invoices/' . ($booking['booking_reference'] ?? "inv_{$type}_{$id}") . '.html';

        if (!file_exists($filePath)) {
            Response::error(
                'Invoice has not been generated yet. Use /regenerate to queue generation.',
                404,
                'invoice_not_found'
            );
        }

        $html = file_get_contents($filePath);

        Response::json([
            'booking_type'      => $type,
            'booking_id'        => $id,
            'booking_reference' => $booking['booking_reference'] ?? null,
            'file_path'         => $filePath,
            'content'           => $html,
        ]);
    }

    // =========================================================================
    // POST /api/admin/invoices/:type/:id/regenerate
    // =========================================================================

    public function regenerate(Request $request): void
    {
        $type = $request->param('type') ?? '';
        $id   = (int)($request->param('id') ?? 0);

        $this->validateTypeAndId($type, $id);

        $db      = Database::getInstance();
        $booking = $this->fetchBooking($db, $type, $id);

        if (!$booking) {
            Response::error('Booking not found.', 404, 'not_found');
        }

        // Queue the job
        $payload = json_encode([
            'booking_id'   => $id,
            'booking_type' => $type,
        ]);

        $stmt = $db->prepare(
            'INSERT INTO job_queue (job_type, payload, status, created_at)
             VALUES ("generate_invoice_pdf", ?, "pending", NOW())'
        );
        $stmt->execute([$payload]);
        $jobId = (int)$db->lastInsertId();

        Response::json([
            'success'  => true,
            'job_id'   => $jobId,
            'message'  => 'Invoice regeneration has been queued.',
            'booking'  => [
                'type'      => $type,
                'id'        => $id,
                'reference' => $booking['booking_reference'] ?? null,
            ],
        ]);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function validateTypeAndId(string $type, int $id): void
    {
        if (!in_array($type, ['flight', 'hotel'], true)) {
            Response::error('Invalid booking type. Must be "flight" or "hotel".', 422, 'invalid_type');
        }
        if ($id <= 0) {
            Response::error('Invalid booking ID.', 422, 'invalid_id');
        }
    }

    private function fetchBooking(\PDO $db, string $type, int $id): array|false
    {
        $table = $type === 'flight' ? 'flight_bookings' : 'hotel_bookings';
        $stmt  = $db->prepare("SELECT * FROM " . $table . " WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
