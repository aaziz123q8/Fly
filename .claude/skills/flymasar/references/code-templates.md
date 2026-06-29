# FlyMasar — Code Templates Reference

Copy-ready skeletons for the common file types. They encode the real conventions (strict types, DI, core-class reuse, the four invariants). Fill in the project-specific logic; don't change the structural patterns. Method names on core classes (`Database`, `Encryption`, `Validator`, `Session`, `SecurityHelper`) must match the existing core — these skeletons reference them by intent, so confirm the exact signature in `app/Core/` before relying on it.

## Migration

```sql
-- FlyMasar Migration NNN: <short description>
-- Adds: <what this introduces>

CREATE TABLE IF NOT EXISTS `<table>` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `<fk>_id`    INT UNSIGNED NOT NULL,
    -- ... columns ...
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `archived_at` TIMESTAMP   NULL,                  -- if soft-deletable
    PRIMARY KEY (`id`),
    KEY `idx_<abbr>_<fk>` (`<fk>_id`),
    CONSTRAINT `fk_<abbr>_<fk>` FOREIGN KEY (`<fk>_id`)
        REFERENCES `<ref_table>` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Model (data access only)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * FlyMasar — <Entity> Model
 *
 * Data access for `<table>`. No business logic here.
 */
final class <Entity>
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?array
    {
        return $this->db->table('<table>')
            ->where('id', $id)
            ->whereNull('archived_at')      // respect soft-delete
            ->first();                      // prepared statement under the hood
    }

    public function create(array $data): int
    {
        return $this->db->table('<table>')->insertGetId($data);
    }
}
```

## Service (business logic)

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, Validator};
use App\Models\<Entity>;

/**
 * FlyMasar — <Name> Service
 *
 * <purpose>. Assumes input was validated by the controller.
 */
final class <Name>Service
{
    public function __construct(
        private Database $db,
        private <Entity> $model,
    ) {}

    public function doThing(array $input): array
    {
        // business rules here; persist via $this->model
        return $this->model->find($this->model->create($input));
    }
}
```

## Controller (thin HTTP layer)

```php
<?php

declare(strict_types=1);

namespace App\Controllers;          // App\Controllers\Admin for admin

use App\Core\{Request, Response, Validator};
use App\Helpers\SecurityHelper;
use App\Services\<Name>Service;

/**
 * FlyMasar — <Name> Controller
 */
final class <Name>Controller
{
    public function __construct(
        private <Name>Service $service,
        private Validator $validator,
    ) {}

    public function store(Request $request): Response
    {
        SecurityHelper::verifyCsrf($request);           // every state-changing POST

        $data = $this->validator->validate($request->all(), [
            // rules
        ]);

        $result = $this->service->doThing($data);

        return Response::json(['ok' => true, 'data' => $result]);
    }
}
```

## Webhook handler (Stripe / Duffel) — invariants baked in

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Database};
use App\Jobs\NotifyJob;

final class WebhookController
{
    public function __construct(private Database $db) {}

    public function stripe(Request $request): Response
    {
        $event = $this->verifySignature($request);      // 1. reject unverified

        $lockKey = "payment_{$paymentId}";
        $this->db->getLock($lockKey);                    // 2. C-02 race safety
        try {
            if ($this->alreadyProcessed($event->id)) {   // 3. idempotent on event id
                return Response::ok();
            }
            // 4. mutate booking/payment state inside the lock (prepared statements)
            // ... update payments / invoices (invoice_type per M-04) ...
        } finally {
            $this->db->releaseLock($lockKey);            // 5. always release
        }

        $this->db->table('jobs')->insert([/* NotifyJob */]); // 6. defer email to Cron
        return Response::ok();                                //    return 2xx fast
    }
}
```

## Stripe charge creation — C-01

```php
// idempotency key generated once, persisted on `payments`, reused on retry
$idempotencyKey = $payment['idempotency_key']
    ?? $this->payments->reserveIdempotencyKey($paymentId);

$intent = $this->stripe->createPaymentIntent([
    'amount'   => $amountMinor,
    'currency' => $currency,
], ['idempotency_key' => $idempotencyKey]);   // never omit
```

## Field-level encryption — C-03

```php
use App\Core\Encryption;

// store ciphertext, never plaintext PII (passport, DOB, document numbers)
$ciphertext = $this->encryption->encrypt($passportNumber); // PBKDF2-derived key + AES-256-CBC
// ... later ...
$plain = $this->encryption->decrypt($ciphertext);
```
