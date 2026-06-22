<?php

declare(strict_types=1);

namespace App\Controllers\Traveler;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;

class TravelerController
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    private function userId(): int
    {
        $user = AuthMiddleware::currentUser();
        return (int) ($user['id'] ?? 0);
    }

    public function index(Request $request): void
    {
        $userId = $this->userId();
        $stmt = $this->pdo->prepare('SELECT * FROM travelers WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $travelers = $stmt->fetchAll();
        Response::json(['success' => true, 'data' => $travelers]);
    }

    public function store(Request $request): void
    {
        $userId = $this->userId();
        $b = $request->json();
        if (!is_array($b)) $b = [];

        $stmt = $this->pdo->prepare('
            INSERT INTO travelers (user_id, first_name, middle_name, last_name, gender, nationality,
                date_of_birth, country, phone_country_code, phone_number, phone, email,
                document_type, document_number, issue_date, expiry_date,
                document_issue, document_expiry, document_country, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $docIssue  = $b['issue_date'] ?? $b['document_issue'] ?? null;
        $docExpiry = $b['expiry_date'] ?? $b['document_expiry'] ?? null;
        $phoneNum  = $b['phone_number'] ?? $b['phone'] ?? null;
        $stmt->execute([
            $userId,
            $b['first_name'] ?? '',
            $b['middle_name'] ?? null,
            $b['last_name'] ?? '',
            $b['gender'] ?? null,
            $b['nationality'] ?? null,
            $b['date_of_birth'] ?? null,
            $b['country'] ?? null,
            $b['phone_country_code'] ?? '',
            $phoneNum,
            $b['phone'] ?? $phoneNum,
            $b['email'] ?? null,
            $b['document_type'] ?? 'passport',
            $b['document_number'] ?? null,
            $docIssue,
            $docExpiry,
            $docIssue,
            $docExpiry,
            $b['document_country'] ?? null,
        ]);
        $id = $this->pdo->lastInsertId();
        $stmt2 = $this->pdo->prepare('SELECT * FROM travelers WHERE id = ?');
        $stmt2->execute([$id]);
        Response::json(['success' => true, 'data' => $stmt2->fetch()], 201);
    }

    public function update(Request $request): void
    {
        $userId = $this->userId();
        $id = (int) $request->param('id');
        $b = $request->json();
        if (!is_array($b)) $b = [];

        $docIssue  = $b['issue_date'] ?? $b['document_issue'] ?? null;
        $docExpiry = $b['expiry_date'] ?? $b['document_expiry'] ?? null;
        $phoneNum  = $b['phone_number'] ?? $b['phone'] ?? null;
        $stmt = $this->pdo->prepare('
            UPDATE travelers SET
                first_name = ?, middle_name = ?, last_name = ?, gender = ?, nationality = ?,
                country = ?, phone_country_code = ?, phone_number = ?, phone = ?,
                date_of_birth = ?, email = ?,
                document_type = ?, document_number = ?,
                issue_date = ?, expiry_date = ?,
                document_issue = ?, document_expiry = ?, document_country = ?,
                updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([
            $b['first_name'] ?? '',
            $b['middle_name'] ?? null,
            $b['last_name'] ?? '',
            $b['gender'] ?? null,
            $b['nationality'] ?? null,
            $b['country'] ?? null,
            $b['phone_country_code'] ?? '',
            $phoneNum,
            $b['phone'] ?? $phoneNum,
            $b['date_of_birth'] ?? null,
            $b['email'] ?? null,
            $b['document_type'] ?? 'passport',
            $b['document_number'] ?? null,
            $docIssue,
            $docExpiry,
            $docIssue,
            $docExpiry,
            $b['document_country'] ?? null,
            $id,
            $userId,
        ]);
        Response::json(['success' => true, 'message' => 'Traveler updated.']);
    }

    public function destroy(Request $request): void
    {
        $userId = $this->userId();
        $id = (int) $request->param('id');
        $stmt = $this->pdo->prepare('DELETE FROM travelers WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        Response::json(['success' => true, 'message' => 'Traveler deleted.']);
    }

    public function lookupBooking(Request $request): void
    {
        $b = $request->json();
        $pnr = strtoupper(trim($b['pnr'] ?? ''));
        $lastName = strtolower(trim($b['last_name'] ?? ''));

        if (!$pnr) {
            Response::json(['error' => 'validation_error', 'message' => 'PNR required.'], 422);
        }

        // Try flight bookings
        try {
            $stmt = $this->pdo->prepare("
                SELECT fb.*, f.origin, f.destination, f.departure_date, f.departure_time,
                       'flight' AS type
                FROM flight_bookings fb
                LEFT JOIN flights f ON f.id = fb.flight_id
                WHERE fb.booking_number = ? AND LOWER(fb.passenger_last_name) = ?
                LIMIT 1
            ");
            $stmt->execute([$pnr, $lastName]);
            $row = $stmt->fetch();
            if ($row) {
                Response::json(['success' => true, 'booking' => $row]);
                return;
            }
        } catch (\Throwable $e) { /* table may not exist */ }

        // Try hotel bookings
        try {
            $stmt = $this->pdo->prepare("
                SELECT hb.*, 'hotel' AS type
                FROM hotel_bookings hb
                WHERE hb.booking_number = ? AND LOWER(hb.guest_last_name) = ?
                LIMIT 1
            ");
            $stmt->execute([$pnr, $lastName]);
            $row = $stmt->fetch();
            if ($row) {
                Response::json(['success' => true, 'booking' => $row]);
                return;
            }
        } catch (\Throwable $e) { /* table may not exist */ }

        Response::json(['error' => 'not_found', 'message' => 'لم يتم العثور على حجز بهذه البيانات.'], 404);
    }

    public function changePassword(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        $userId = (int) ($user['id'] ?? 0);
        $b = $request->json();
        $currentPassword = $b['current_password'] ?? '';
        $newPassword = $b['new_password'] ?? '';

        if (strlen($newPassword) < 8) {
            Response::json(['error' => 'validation_error', 'message' => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.'], 422);
        }

        // Verify current password
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPassword, $row['password_hash'] ?? '')) {
            Response::json(['error' => 'unauthorized', 'message' => 'كلمة المرور الحالية غير صحيحة.'], 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt2 = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
        $stmt2->execute([$newHash, $userId]);

        Response::json(['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح.']);
    }
}
