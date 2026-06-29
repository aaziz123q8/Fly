# FlyMasar — Database Schema Reference

**65 tables** (the project evolved past the original 56-table blueprint). The **authoritative, exact schema** — every column, type, default, key, and foreign key — is bundled in **`references/schema-full.sql`** (the live `SHOW CREATE TABLE` output after applying all migrations `001`→`078`). Read that file for exact column definitions before writing any migration, model, or query. This file is the human-readable map over it.

## How to use

- Need an exact column / type / FK? → open `schema-full.sql` and search the table.
- Need to know where a new table belongs, or which columns carry the invariants? → use this map.
- Match the DDL/PHP conventions in `conventions.md`.

## Table inventory by domain (65 tables)

**Auth & authorization (9):** `users`, `roles`, `permissions`, `role_permissions`, `user_sessions`, `password_resets`, `login_attempts`, `admin_sessions`, `push_subscriptions`

**Travelers (3):** `travelers`, `traveler_documents`, `traveler_frequent_flyers`

**Providers / multi-supplier (3):** `providers`, `provider_credentials`, `api_settings`

**Geography cache (4):** `countries`, `cities`, `airports`, `airlines`

**Flight bookings (7):** `flight_bookings`, `flight_booking_passengers`, `flight_booking_segments`, `flight_booking_services`, `flight_booking_documents`, `offer_cache`, `booking_sessions`

**Hotel bookings (5):** `hotel_bookings`, `hotel_booking_rooms`, `hotel_booking_guests`, `hotels_content`, `hotel_images`

**Payments & financial (12):** `payments`, `refunds`, `invoices`, `booking_pricing_breakdown`, `pricing_rules`, `coupons`, `coupon_usages`, `commissions`, `currencies`, `exchange_rates`, `user_wallets`, `wallet_transactions`

**Notifications (4):** `notification_templates`, `user_notifications`, `notification_dispatch_log`, `user_notification_preferences`

**WhatsApp (2):** `whatsapp_providers`, `whatsapp_templates`

**Support (4):** `support_tickets`, `ticket_messages`, `ticket_attachments`, `support_replies`

**Audit & logs (4):** `booking_audit_log`, `admin_activity_log`, `webhook_logs`, `error_logs`

**System (4):** `settings`, `job_queue`, `attachments`, `cms_pages`

**Search & analytics (4):** `search_logs`, `popular_routes`, `rate_limits`, `saved_searches`

## Audit-critical columns — verified against the live schema

These exist for correctness. Don't drop or bypass them (exact names confirmed from `schema-full.sql`):

| Concern | Table.column | Notes |
|---|---|---|
| No double-charge (C-01) | `payments.idempotency_key` | `varchar(64) NOT NULL`, `UNIQUE KEY uq_idempotency` |
| Checkout durability (C-04) | `booking_sessions.*` | DB-backed checkout: `session_key`, `current_step`, `provider_offer_id`, `offer_expires_at`, `payment_intent_id`, `idempotency_key`, `expires_at` |
| Duffel offer expiry | `booking_sessions.offer_expires_at`, `flight_bookings.price_guarantee_expires_at`, `void_window_ends_at`, `payment_required_by` | re-validate before order/charge |
| Multi-city (H-02) | `flight_booking_segments.slice_index` | `tinyint NOT NULL DEFAULT 0` (+ `segment_order`) |
| Hotel images split (H-03) | `hotel_images` separate from `hotels_content` | — |
| Webhook safety (C-02) | `webhook_logs` | handlers process under MySQL `GET_LOCK()` |
| Payment methods | `payments.payment_method` / `payment_type` | `enum('stripe','duffel_balance')` / `enum('stripe','duffel_balance','duffel_card')` |
| Refund lifecycle | `payments.status` | enum incl. `refund_pending`, `refund_failed`, `refund_pending_manual`; see `refunds`, `payments.stripe_refund_id`, `auto_refunded_at` |
| Supplier abstraction | `providers`, `provider_credentials` | multi-supplier; credentials stored separately (encrypt secrets) |
| Wallet system | `user_wallets`, `wallet_transactions` | added late (mig. 077) |
| Money base currency | `*.currency char(3) DEFAULT 'GBP'` | UK Ltd; convert via `exchange_rates`/`currencies` |

## Conventions observed in the real schema

- PK `id int(10) unsigned AUTO_INCREMENT`; timestamps `created_at`/`updated_at TIMESTAMP DEFAULT current_timestamp()` (often `ON UPDATE current_timestamp()`).
- JSON-ish columns are `longtext` with `CHECK (json_valid(col))` (e.g. `payments.gateway_response`, `flight_bookings.available_actions`).
- Enums are used heavily for status/type fields — reuse the existing enum values, don't invent new ones without a migration.
- Charset is `utf8mb4`; collation varies by table (`utf8mb4_unicode_ci` / `utf8mb4_general_ci`) — match the existing table when adding columns.
- FKs are explicit (e.g. `payments_ibfk_1` → `users(id)`); keep them when extending.

## When extending the schema

1. Confirm the table doesn't already exist (check the inventory + `schema-full.sql`).
2. Place it in the right domain group; next migration number after `078`.
3. Follow the real conventions above; encrypt PII; keep FKs/indexes explicit.
4. Touching payments/webhooks/checkout? Re-read C-01→C-04 in SKILL.md first.
