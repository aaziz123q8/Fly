<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AuthMiddleware;
use App\Services\WalletService;

class WalletController
{
    private WalletService $walletService;

    public function __construct()
    {
        $this->walletService = new WalletService();
    }

    public function getBalance(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $wallet = $this->walletService->getWallet((int) $user['id']);
        Response::json([
            'balance'  => number_format((float)$wallet['balance'], 2, '.', ''),
            'currency' => $wallet['currency'] ?? 'GBP',
        ]);
    }

    public function getTransactions(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $txs = $this->walletService->getTransactions((int) $user['id'], 100);
        Response::json(['transactions' => $txs]);
    }

    // GET /api/wallet/lookup?fu=FU-000123 — resolve a member number to a name so
    // the sender can confirm the recipient before transferring.
    public function lookup(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $id = self::idFromFu((string) $request->query('fu', ''));
        if ($id <= 0)                    Response::error('رقم المستخدم غير صحيح.', 422, 'invalid_fu');
        if ($id === (int) $user['id'])   Response::error('لا يمكنك التحويل إلى نفسك.', 422, 'self_transfer');

        $row = self::findUser($id);
        if (!$row) Response::error('لا يوجد مستخدم بهذا الرقم.', 404, 'not_found');
        if (!self::hasProfile($row)) {
            Response::error('هذا المستخدم لم يُكمل بيانات حسابه (الاسم ورقم الهاتف)، لا يمكن التحويل إليه.', 422, 'incomplete_recipient');
        }

        Response::json([
            'fu_number' => self::fu($id),
            'name'      => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        ]);
    }

    // POST /api/wallet/transfer { to_fu, amount } — move balance between members.
    public function transfer(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $fromId = (int) $user['id'];
        $toId   = self::idFromFu((string) $request->input('to_fu', ''));
        $amount = round((float) $request->input('amount', 0), 2);

        if ($toId <= 0)         Response::error('رقم المستخدم غير صحيح.', 422, 'invalid_fu');
        if ($toId === $fromId)  Response::error('لا يمكنك التحويل إلى نفسك.', 422, 'self_transfer');
        if ($amount <= 0)       Response::error('المبلغ غير صحيح.', 422, 'invalid_amount');

        // Both parties must have a completed profile (name + phone).
        $sender = self::findUser($fromId);
        if (!$sender || !self::hasProfile($sender)) {
            Response::error('أكمل بياناتك (الاسم ورقم الهاتف) في ملفك الشخصي قبل إجراء التحويل.', 422, 'incomplete_sender');
        }
        $recipient = self::findUser($toId);
        if (!$recipient) Response::error('لا يوجد مستخدم بهذا الرقم.', 404, 'not_found');
        if (!self::hasProfile($recipient)) {
            Response::error('هذا المستخدم لم يُكمل بيانات حسابه (الاسم ورقم الهاتف)، لا يمكن التحويل إليه.', 422, 'incomplete_recipient');
        }

        $wallet     = $this->walletService->getWallet($fromId);
        $currency   = $wallet['currency'] ?? 'GBP';
        $recName    = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
        $senderName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        // Debit the sender first (throws 422 when the balance is insufficient).
        try {
            $this->walletService->debit($fromId, $amount, $currency,
                'تحويل إلى ' . $recName . ' (' . self::fu($toId) . ')', 'transfer_out:' . self::fu($toId));
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage() ?: 'تعذّر الخصم من المحفظة.', ((int) $e->getCode()) ?: 422, 'debit_failed');
        }

        // Credit the recipient; on any failure, refund the sender so no money is lost.
        try {
            $this->walletService->credit($toId, $amount, $currency,
                'تحويل من ' . $senderName . ' (' . self::fu($fromId) . ')', 'transfer_in:' . self::fu($fromId));
        } catch (\Throwable $e) {
            try {
                $this->walletService->credit($fromId, $amount, $currency, 'استرداد تحويل غير مكتمل', 'transfer_refund');
            } catch (\Throwable) {}
            Response::error('تعذّر إتمام التحويل. تمت إعادة المبلغ إلى محفظتك.', 500, 'transfer_failed');
        }

        $after = $this->walletService->getWallet($fromId);
        Response::json([
            'message'  => 'تم تحويل المبلغ بنجاح إلى ' . $recName . '.',
            'balance'  => number_format((float) $after['balance'], 2, '.', ''),
            'currency' => $currency,
        ]);
    }

    private static function fu(int $id): string
    {
        return 'FU-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private static function idFromFu(string $fu): int
    {
        return preg_match('/(\d+)/', $fu, $m) ? (int) $m[1] : 0;
    }

    private static function findUser(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT id, first_name, last_name, phone_number FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** A wallet transfer requires both parties to have a name and a phone number. */
    private static function hasProfile(array $u): bool
    {
        return trim((string) ($u['first_name'] ?? '')) !== ''
            && trim((string) ($u['phone_number'] ?? '')) !== '';
    }
}
