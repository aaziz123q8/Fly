<?php

declare(strict_types=1);

namespace App\Controllers\Traveler;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;
use App\Services\FlightBookingService;

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

    /**
     * Validate + normalise traveler input against the NOT NULL / enum columns.
     * Returns an error message (Arabic) or null when the payload is valid.
     * Normalises gender and document_type in place.
     */
    private function validateTraveler(array &$b): ?string
    {
        $required = [
            'first_name'    => 'الاسم الأول',
            'last_name'     => 'اسم العائلة',
            'gender'        => 'الجنس',
            'nationality'   => 'الجنسية',
            'date_of_birth' => 'تاريخ الميلاد',
        ];
        foreach ($required as $field => $label) {
            if (empty($b[$field])) {
                return "الحقل المطلوب مفقود: {$label}";
            }
        }

        // expiry_date is NOT NULL in the schema (accept document_expiry alias).
        if (empty($b['expiry_date']) && empty($b['document_expiry'])) {
            return 'تاريخ انتهاء الوثيقة مطلوب';
        }

        $gender = strtolower((string) $b['gender']);
        if (!in_array($gender, ['male', 'female'], true)) {
            return 'قيمة الجنس غير صالحة';
        }
        $b['gender'] = $gender;

        $docType = strtolower((string) ($b['document_type'] ?? 'passport'));
        if (!in_array($docType, ['passport', 'civil_id', 'national_id'], true)) {
            $docType = 'passport';
        }
        $b['document_type'] = $docType;

        return null;
    }

    public function store(Request $request): void
    {
        $userId = $this->userId();
        $b = $request->json();
        if (!is_array($b)) $b = [];

        if ($err = $this->validateTraveler($b)) {
            Response::error($err, 422);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO travelers (user_id, title, first_name, middle_name, last_name, gender, nationality,
                date_of_birth, country, phone_country_code, phone_number, phone, email,
                document_type, document_number, issue_date, expiry_date,
                document_issue, document_expiry, document_country, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $docIssue  = $b['issue_date'] ?? $b['document_issue'] ?? null;
        $docExpiry = $b['expiry_date'] ?? $b['document_expiry'] ?? null;
        $phoneE164 = $b['phone_number'] ?? $b['phone'] ?? null;
        $validTitles = ['mr','ms','mrs','miss','dr'];
        $title = in_array(strtolower($b['title'] ?? ''), $validTitles, true) ? strtolower($b['title']) : null;
        $stmt->execute([
            $userId,
            $title,
            $b['first_name'] ?? '',
            $b['middle_name'] ?? null,
            $b['last_name'] ?? '',
            $b['gender'] ?? null,
            $b['nationality'] ?? null,
            $b['date_of_birth'] ?? null,
            $b['country'] ?? null,
            '',
            $phoneE164,
            $phoneE164,
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

        if ($err = $this->validateTraveler($b)) {
            Response::error($err, 422);
            return;
        }

        $docIssue  = $b['issue_date'] ?? $b['document_issue'] ?? null;
        $docExpiry = $b['expiry_date'] ?? $b['document_expiry'] ?? null;
        $phoneE164 = $b['phone_number'] ?? $b['phone'] ?? null;
        $validTitles = ['mr','ms','mrs','miss','dr'];
        $title = in_array(strtolower($b['title'] ?? ''), $validTitles, true) ? strtolower($b['title']) : null;
        $stmt = $this->pdo->prepare('
            UPDATE travelers SET
                title = ?, first_name = ?, middle_name = ?, last_name = ?, gender = ?, nationality = ?,
                country = ?, phone_country_code = ?, phone_number = ?, phone = ?,
                date_of_birth = ?, email = ?,
                document_type = ?, document_number = ?,
                issue_date = ?, expiry_date = ?,
                document_issue = ?, document_expiry = ?, document_country = ?,
                updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([
            $title,
            $b['first_name'] ?? '',
            $b['middle_name'] ?? null,
            $b['last_name'] ?? '',
            $b['gender'] ?? null,
            $b['nationality'] ?? null,
            $b['country'] ?? null,
            '',
            $phoneE164,
            $phoneE164,
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
                SELECT fb.*, 'flight' AS type
                FROM flight_bookings fb
                JOIN flight_booking_passengers fbp ON fbp.booking_id = fb.id
                WHERE fb.booking_reference = ? AND LOWER(fbp.last_name) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$pnr, $lastName]);
            $row = $stmt->fetch();
            if ($row) {
                Response::json(['success' => true, 'booking' => $row]);
                return;
            }
        } catch (\PDOException $e) {
            throw $e;
        }

        // Try hotel bookings
        try {
            $stmt = $this->pdo->prepare("
                SELECT hb.*, 'hotel' AS type
                FROM hotel_bookings hb
                JOIN hotel_booking_guests hbg ON hbg.booking_id = hb.id
                WHERE hb.booking_reference = ? AND LOWER(hbg.last_name) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$pnr, $lastName]);
            $row = $stmt->fetch();
            if ($row) {
                Response::json(['success' => true, 'booking' => $row]);
                return;
            }
        } catch (\PDOException $e) {
            throw $e;
        }

        Response::json(['error' => 'not_found', 'message' => 'لم يتم العثور على حجز بهذه البيانات.'], 404);
    }

    public function guestInvoice(Request $request): void
    {
        $ref      = strtoupper(trim($request->query('ref') ?? ''));
        $lastName = trim($request->query('last_name') ?? '');

        if (!$ref || !$lastName) {
            Response::json(['error' => 'validation_error', 'message' => 'ref and last_name required.'], 422);
        }

        $service = new FlightBookingService();
        $booking = $service->getBookingByReferenceGuest($ref, $lastName);
        if ($booking) {
            Response::json(['booking' => $booking]);
            return;
        }

        // Try hotel bookings
        $stmt = $this->pdo->prepare(
            'SELECT hb.*, \'hotel\' AS type FROM hotel_bookings hb
             JOIN hotel_booking_guests hbg ON hbg.booking_id = hb.id
             WHERE hb.booking_reference = :ref AND LOWER(hbg.last_name) = LOWER(:ln)
             LIMIT 1'
        );
        $stmt->execute([':ref' => $ref, ':ln' => trim($lastName)]);
        $hotelBooking = $stmt->fetch();
        if ($hotelBooking) {
            Response::json(['booking' => $hotelBooking]);
            return;
        }

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
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPassword, $row['password'] ?? '')) {
            Response::json(['error' => 'unauthorized', 'message' => 'كلمة المرور الحالية غير صحيحة.'], 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $stmt2 = $this->pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
        $stmt2->execute([$newHash, $userId]);

        Response::json(['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح.']);
    }
}
