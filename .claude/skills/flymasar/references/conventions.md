# FlyMasar — Conventions Reference

Read this before adding any new file, model, controller, service, or migration. The goal is that new code is indistinguishable from existing code.

## Folder structure (Hostinger `public_html/`)

```
public_html/
├── public/                  ← web root (index.php front controller, assets)
│   └── index.php
├── app/
│   ├── Core/                ← framework (DO NOT rebuild — reuse)
│   │   ├── Application.php
│   │   ├── Container.php
│   │   ├── Router.php
│   │   ├── Request.php  Response.php  Session.php
│   │   ├── Database.php  QueryBuilder.php  Cache.php
│   │   ├── Validator.php  Encryption.php
│   ├── Controllers/         ← thin HTTP layer (incl. Admin/ subfolder)
│   ├── Services/            ← business logic
│   ├── Models/              ← data access
│   ├── Middleware/          ← auth, CSRF, rate-limit, etc.
│   ├── Jobs/                ← queue jobs run by Cron worker (e.g. CleanExpiredFilesJob)
│   └── Helpers/
│       ├── DateHelper.php          ← AR/EN date formatting + age calculation
│       ├── CurrencyHelper.php      ← amount formatting
│       ├── SecurityHelper.php      ← XSS sanitize + CSRF
│       └── InternalRefHelper.php   ← FM……, HM……, TK-YYYY-…… reference codes
├── config/                  ← .htaccess "Deny from all"
│   ├── app.php  database.php  mail.php  routes.php
│   └── apis.php             ← Duffel / RateHawk / Stripe keys (from env)
├── database/                ← .htaccess "Deny from all"
│   ├── migrations/          ← NNN_create_<table>.sql, numbered, one table each
│   └── seeds/               ← countries, airports, airlines, roles_permissions, admin_user
└── views/
    ├── layouts/   ← main.php / main-rtl.php / admin.php / mobile.php (bottom nav)
    ├── pages/     ← home, about, contact, offers, booking-lookup
    ├── auth/      ← login, register, forgot, reset
    ├── flights/   ← search, results, checkout
    ├── hotels/    ← search, results, hotel-detail, checkout
    ├── booking/   ← confirmation, payment-failed
    ├── account/   ← dashboard, bookings, booking-detail, travelers, notifications, support, profile
    ├── admin/     ← dashboard + all admin sections
    ├── errors/    ← 404, 500, 403
    └── emails/    ← HTML templates, AR + EN
```

`config/` and `database/` are protected by `.htaccess` (`Deny from all`) — nothing under them is ever web-reachable.

## Naming

- Classes: `PascalCase`, one class per file, filename matches class (`TravelerService.php`).
- Methods/variables: `camelCase`. Table/column names: `snake_case`, tables plural (`travelers`, `flight_booking_segments`).
- Controllers end in `Controller`, services in `Service`, models are the singular-ish entity name (`User`, `Traveler`, `TravelerDocument`).
- Admin controllers live under `app/Controllers/Admin/`.
- Migrations: `NNN_create_<table>.sql` (zero-padded, sequential). One table per migration file.

## Internal reference codes (InternalRefHelper)

Human-facing references follow fixed formats — generate them through `InternalRefHelper`, never ad hoc:

- Flight booking ref: `FM` + zero-padded sequence (e.g. `FM00000001`).
- Hotel booking ref: `HM` + zero-padded sequence (e.g. `HM00000001`).
- Ticket / support ref: `TK-YYYY-NNNNNN` (e.g. `TK-2026-000001`).

## Security conventions

- **CSRF**: every state-changing POST validates a CSRF token via `SecurityHelper`. No exceptions for "internal" forms.
- **XSS**: all dynamic output in views is escaped through `SecurityHelper` sanitize. Never echo raw request data.
- **SQL**: only prepared statements through `Database`/`QueryBuilder`. No string concatenation into SQL, ever.
- **PII at rest**: passenger personal data and travel documents are encrypted with the `Encryption` core (PBKDF2-derived key + AES-256-CBC). Don't store raw passport numbers / DOBs in plaintext columns.
- **Auth**: routes that require login go through the auth middleware; admin routes additionally check role/permission.
- **Rate limiting**: sensitive endpoints (login, search, API-proxy) use the `rate_limits` table + middleware.

## Validation & errors

- Validate input with the `Validator` core before it reaches a Service. Services assume validated input.
- Return structured responses through `Response`; API-style endpoints return JSON with consistent shape, page endpoints render views.
- Error pages: `errors/404.php`, `errors/500.php`, `errors/403.php`. Don't leak stack traces to users in production.

## Localization & comms

- Arabic UI is **RTL** (`main-rtl.php` layout); keep markup direction-aware.
- All user-facing email templates exist in **Arabic and English** under `views/emails/`.
- Dates formatted via `DateHelper` (handles AR/EN + age calculation for travelers).
- Money formatted via `CurrencyHelper`.

## Exact migration DDL style (match this verbatim)

Every migration file follows this shape — header comment, `CREATE TABLE IF NOT EXISTS`, aligned columns, explicit keys, InnoDB + utf8mb4:

```sql
-- FlyMasar Migration NNN: <short description>
-- Adds: <what this migration introduces>

CREATE TABLE IF NOT EXISTS `email_change_requests` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `new_email`     VARCHAR(180)    NOT NULL,
    `token_hash`    VARCHAR(255)    NOT NULL,
    `expires_at`    TIMESTAMP       NOT NULL,
    `used_at`       TIMESTAMP       NULL,
    `ip_address`    VARCHAR(45)     NOT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ecr_user`  (`user_id`),
    KEY `idx_ecr_token` (`token_hash`),
    CONSTRAINT `fk_ecr_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Fixed rules in DDL:
- PK is always `id INT UNSIGNED NOT NULL AUTO_INCREMENT`.
- Timestamps: `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`; nullable event timestamps (`used_at`, `archived_at`, `last_login_at`) are `TIMESTAMP NULL`.
- IP columns: `VARCHAR(45)` (IPv6-safe). Email columns: `VARCHAR(180)`. One-time tokens stored as `token_hash VARCHAR(255)` (hash, never raw).
- Index name: `idx_<tableAbbr>_<col>`. FK name: `fk_<tableAbbr>_<ref>`. FKs are explicit with `ON DELETE … ON UPDATE …`.
- Engine/charset always `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Status fields are `ENUM(...)` — e.g. `users.status ENUM('active','suspended','unverified','deleted') NOT NULL DEFAULT 'unverified'`.

## Exact PHP file conventions (match this verbatim)

Every PHP class file opens the same way:

```php
<?php

declare(strict_types=1);

namespace App\Services;            // or App\Controllers, App\Models, App\Controllers\Admin

use App\Core\{Database, Session};
use App\Helpers\SecurityHelper;

/**
 * FlyMasar — <Component Name>
 *
 * <one-line purpose>
 */
final class TravelerService
{
    public function __construct(
        private Database $db,
        private Session $session,
    ) {}
    // ...
}
```

- `declare(strict_types=1);` on every file. Namespaces mirror folders (`App\Core`, `App\Services`, `App\Models`, `App\Controllers`, `App\Controllers\Admin`, `App\Helpers`, `App\Middleware`, `App\Jobs`).
- Constructor dependency injection via the `Container` — type-hint core classes; don't `new` them inside methods.
- Each file starts with a `FlyMasar — <name>` docblock.
- Reuse core method names as they exist (e.g. `AuthService::createDbSession($userId, $remember)`, `$db->getLock()/releaseLock()`); don't invent parallel helpers.

## Async / background work

- No daemons. Deferred work (sending emails, post-booking tasks, expired-file cleanup) is enqueued and processed by the **Cron queue worker** running `app/Jobs/*`.
- Jobs must be idempotent — the worker may retry.
