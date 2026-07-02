<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;
use App\Services\WalletService;

class AdminUsersController
{
    /** Public FlyMasar member number derived from the account id (FU-000123). */
    public static function fuNumber(int $id): string
    {
        return 'FU-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function index(Request $request): void
    {
        $db      = Database::getInstance();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->input('search', ''));
        $status  = $request->input('status', '');
        $role    = $request->input('role', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ($status === 'active') { $where[] = 'is_active = 1'; }
        elseif ($status === 'inactive') { $where[] = 'is_active = 0'; }
        if ($role !== '') { $where[] = 'role = ?'; $params[] = $role; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM users $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT id, email, first_name, last_name, phone_country_code, phone_number,
                    role, is_active, email_verified_at, created_at, last_login_at
             FROM users $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($data as &$u) { $u['fu_number'] = self::fuNumber((int) $u['id']); } unset($u);

        Response::json([
            'data' => $data,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function show(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id, email, first_name, last_name, phone_country_code, phone_number, role, is_active, email_verified_at, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) { Response::notFound('User not found.'); }
        $user['fu_number'] = self::fuNumber((int) $user['id']);
        try {
            $w = (new WalletService())->getWallet($id);
            $user['wallet_balance']  = number_format((float) ($w['balance'] ?? 0), 2, '.', '');
            $user['wallet_currency'] = $w['currency'] ?? 'GBP';
        } catch (\Throwable) {}
        Response::json(['user' => $user]);
    }

    public function update(Request $request): void
    {
        $id   = (int) $request->param('id');
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) { Response::notFound('User not found.'); }

        $allowed = ['first_name', 'last_name', 'phone_country_code', 'phone_number', 'role', 'is_active'];
        $set     = [];
        $params  = [];
        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = in_array($field, ['is_active']) ? (int) $val : (string) $val;
            }
        }
        if (empty($set)) { Response::error('No updatable fields.', 400); }
        $params[] = $id;
        $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        $stmt = $db->prepare('SELECT id, email, first_name, last_name, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json(['user' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    public function destroy(Request $request): void
    {
        $id   = (int) $request->param('id');

        // Prevent an admin from deactivating their own account (lock-out).
        $admin = AdminMiddleware::currentAdmin();
        if ($id === (int) ($admin['user_id'] ?? 0)) {
            Response::error('لا يمكنك تعطيل حسابك الخاص.', 422);
            return;
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('المستخدم غير موجود.', 404);
            return;
        }

        $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        Response::noContent();
    }

    // POST /api/admin/users — create a new user account.
    public function store(Request $request): void
    {
        $db    = Database::getInstance();
        $email = strtolower(trim((string) $request->input('email')));
        $first = trim((string) $request->input('first_name'));
        $last  = trim((string) $request->input('last_name'));
        $phone = trim((string) $request->input('phone_number'));
        $cc    = trim((string) $request->input('phone_country_code', ''));
        $role  = in_array($request->input('role'), ['user', 'admin', 'super_admin'], true) ? (string) $request->input('role') : 'user';
        $pass  = (string) $request->input('password');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'بريد إلكتروني غير صحيح';
        if ($first === '') $errors['first_name'] = 'الاسم الأول مطلوب';
        if ($last === '')  $errors['last_name']  = 'اسم العائلة مطلوب';
        if (strlen($pass) < 8) $errors['password'] = 'كلمة المرور 8 أحرف على الأقل';
        if (!empty($errors)) Response::validationError($errors);

        $exists = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetch()) Response::error('هذا البريد مسجّل بالفعل.', 409, 'email_taken');

        $hash = password_hash($pass, PASSWORD_ARGON2ID);
        $db->prepare(
            'INSERT INTO users (email, password, first_name, last_name, phone_country_code, phone_number, role, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        )->execute([$email, $hash, $first, $last, $cc, $phone, $role]);

        $id = (int) $db->lastInsertId();
        Response::created([
            'user' => [
                'id' => $id, 'email' => $email, 'first_name' => $first, 'last_name' => $last,
                'phone_number' => $phone, 'role' => $role, 'is_active' => 1,
                'fu_number' => self::fuNumber($id),
            ],
            'message' => 'تم إنشاء المستخدم بنجاح — رقمه: ' . self::fuNumber($id),
        ]);
    }

    // POST /api/admin/users/:id/wallet — add or deduct wallet balance with a reason.
    public function adjustWallet(Request $request): void
    {
        $id     = (int) $request->param('id');
        $type   = $request->input('type') === 'debit' ? 'debit' : 'credit';
        $amount = round((float) $request->input('amount', 0), 2);
        $reason = trim((string) $request->input('reason'));

        if ($amount <= 0)   Response::error('المبلغ غير صحيح.', 422, 'invalid_amount');
        if ($reason === '') Response::error('يرجى إدخال سبب العملية.', 422, 'reason_required');

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id, first_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) Response::error('المستخدم غير موجود.', 404, 'not_found');

        $admin  = AdminMiddleware::currentAdmin();
        $byName = $admin['first_name'] ?? $admin['email'] ?? 'الإدارة';
        $desc   = ($type === 'credit' ? 'إضافة رصيد من الإدارة' : 'خصم رصيد من الإدارة') . ' — ' . $reason;
        $wallet = new WalletService();

        try {
            $w = ($type === 'credit')
                ? $wallet->credit($id, $amount, 'GBP', $desc, 'admin_adjust:' . $byName)
                : $wallet->debit($id, $amount, 'GBP', $desc, 'admin_adjust:' . $byName);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage() ?: 'تعذّرت العملية.', ((int) $e->getCode()) ?: 422, 'wallet_error');
        }

        Response::json([
            'message'  => ($type === 'credit' ? 'تمت إضافة الرصيد بنجاح.' : 'تم خصم الرصيد بنجاح.'),
            'balance'  => number_format((float) ($w['balance'] ?? 0), 2, '.', ''),
            'currency' => $w['currency'] ?? 'GBP',
        ]);
    }

    // POST /api/admin/users/:id/reset-password — set a new password for a user.
    public function resetPassword(Request $request): void
    {
        $id   = (int) $request->param('id');
        $pass = (string) $request->input('password');
        if (strlen($pass) < 8) Response::error('كلمة المرور 8 أحرف على الأقل.', 422, 'weak_password');

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) Response::error('المستخدم غير موجود.', 404, 'not_found');

        $hash = password_hash($pass, PASSWORD_ARGON2ID);
        $db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $id]);
        Response::json(['message' => 'تم تعيين كلمة المرور الجديدة بنجاح.']);
    }
}
