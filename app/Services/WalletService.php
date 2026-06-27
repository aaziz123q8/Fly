<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;
use RuntimeException;

class WalletService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ── Get or create wallet ──────────────────────────────────────

    public function getWallet(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_wallets WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($w) return $w;

        // Auto-create wallet on first access
        $this->db->prepare(
            'INSERT INTO user_wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)'
        )->execute([':uid' => $userId, ':cur' => 'GBP']);

        return ['user_id' => $userId, 'balance' => '0.00', 'currency' => 'GBP'];
    }

    // ── Credit (add money to wallet) ──────────────────────────────

    public function credit(int $userId, float $amount, string $currency, string $description, ?string $reference = null): array
    {
        if ($amount <= 0) throw new RuntimeException('Credit amount must be positive.', 422);

        $this->db->beginTransaction();
        try {
            // Lock wallet row
            $stmt = $this->db->prepare('SELECT balance FROM user_wallets WHERE user_id = :uid FOR UPDATE');
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // Create wallet first
                $this->db->prepare(
                    'INSERT INTO user_wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)'
                )->execute([':uid' => $userId, ':cur' => $currency]);
                $currentBalance = 0.0;
            } else {
                $currentBalance = (float) $row['balance'];
            }

            $newBalance = $currentBalance + $amount;

            $this->db->prepare(
                'UPDATE user_wallets SET balance = :bal, currency = :cur WHERE user_id = :uid'
            )->execute([':bal' => $newBalance, ':cur' => $currency, ':uid' => $userId]);

            $this->db->prepare(
                'INSERT INTO wallet_transactions (user_id, type, amount, currency, balance_after, description, reference)
                 VALUES (:uid, :type, :amt, :cur, :bal, :desc, :ref)'
            )->execute([
                ':uid'  => $userId,
                ':type' => 'credit',
                ':amt'  => $amount,
                ':cur'  => $currency,
                ':bal'  => $newBalance,
                ':desc' => $description,
                ':ref'  => $reference,
            ]);

            $this->db->commit();
            return ['balance' => $newBalance, 'currency' => $currency];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Debit (use wallet funds for payment) ─────────────────────

    public function debit(int $userId, float $amount, string $currency, string $description, ?string $reference = null): array
    {
        if ($amount <= 0) throw new RuntimeException('Debit amount must be positive.', 422);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT balance, currency FROM user_wallets WHERE user_id = :uid FOR UPDATE');
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || (float)$row['balance'] < $amount) {
                $this->db->rollBack();
                throw new RuntimeException('رصيد المحفظة غير كافٍ.', 422);
            }

            $newBalance = (float)$row['balance'] - $amount;

            $this->db->prepare(
                'UPDATE user_wallets SET balance = :bal WHERE user_id = :uid'
            )->execute([':bal' => $newBalance, ':uid' => $userId]);

            $this->db->prepare(
                'INSERT INTO wallet_transactions (user_id, type, amount, currency, balance_after, description, reference)
                 VALUES (:uid, :type, :amt, :cur, :bal, :desc, :ref)'
            )->execute([
                ':uid'  => $userId,
                ':type' => 'debit',
                ':amt'  => $amount,
                ':cur'  => $currency,
                ':bal'  => $newBalance,
                ':desc' => $description,
                ':ref'  => $reference,
            ]);

            $this->db->commit();
            return ['balance' => $newBalance, 'currency' => $currency];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Transaction history ───────────────────────────────────────

    public function getTransactions(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM wallet_transactions WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
