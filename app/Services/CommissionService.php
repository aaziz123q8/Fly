<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use PDO;

/**
 * CommissionService
 *
 * Single source of truth for the platform commission/markup the admin manages
 * on the "Commissions" page (`commissions` table). Given a net supplier price
 * and a booking type, it returns the commission amount and the customer-facing
 * total. Percentage rules apply to the base price; fixed rules add a flat
 * amount. Active rules whose `type` matches the booking type (or 'all') are
 * summed, so a single rule is the common case but several can stack.
 */
class CommissionService
{
    private PDO $db;

    /** @var array<string, list<array{name:string,commission_type:string,commission_value:float}>> */
    private array $rulesCache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Fetch (and cache) active rules for a type, so applying the markup across
     * many offers in one search costs a single query.
     */
    private function rulesFor(string $type): array
    {
        if (isset($this->rulesCache[$type])) {
            return $this->rulesCache[$type];
        }
        $rows = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT name, commission_type, commission_value
                 FROM commissions
                 WHERE is_active = 1 AND (type = :t OR type = 'all')
                 ORDER BY id ASC"
            );
            $stmt->execute([':t' => $type]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            // commissions table absent / unreadable → no markup.
        }
        return $this->rulesCache[$type] = $rows;
    }

    /**
     * @param float  $base Net supplier price.
     * @param string $type 'flight' | 'hotel'
     * @return array{base: float, commission: float, total: float, rules: list<array{name: string, amount: float}>}
     */
    public function apply(float $base, string $type): array
    {
        $base       = max(0.0, round($base, 2));
        $commission = 0.0;
        $rules      = [];

        foreach ($this->rulesFor($type) as $row) {
            $value  = (float) $row['commission_value'];
            $amount = ($row['commission_type'] === 'percentage')
                ? round($base * $value / 100, 2)
                : round($value, 2);

            if ($amount <= 0) {
                continue;
            }

            $commission += $amount;
            $rules[]     = ['name' => $row['name'] ?: 'commission', 'amount' => $amount];
        }

        $commission = round($commission, 2);

        return [
            'base'       => $base,
            'commission' => $commission,
            'total'      => round($base + $commission, 2),
            'rules'      => $rules,
        ];
    }

    /**
     * Convenience: the customer-facing total for a net price.
     */
    public function markup(float $base, string $type): float
    {
        return $this->apply($base, $type)['total'];
    }
}
