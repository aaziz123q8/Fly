---
name: flymasar
description: Project conventions, database schema, and API-integration patterns for FlyMasar (فلاي مسار) — a B2C travel booking platform built in pure PHP 8.x + MySQL on Hostinger, integrating Duffel (flights), RateHawk (hotels), and Stripe (payments). Use this skill WHENEVER writing, reviewing, debugging, or extending any FlyMasar code — controllers, models, services, migrations, views, or API integrations — even when the user only says "the platform", "the booking site", "flymasar", "فلاي مسار", or references a table, endpoint, or flow from the project without naming it. Also use when the task touches Duffel/RateHawk/Stripe integration in this project's context. Do NOT use for unrelated PHP projects or the older React/Node "Flymas/TravelPro" prototype.
---

# FlyMasar — Project Engineering Skill

FlyMasar (فلاي مسار) is a full-stack **B2C** travel booking platform. The job of this skill is to make every piece of FlyMasar code match the approved architecture (Blueprint v2.1, audited 100/100) without re-deriving it each session. When you write code for this project, follow the constraints, invariants, conventions, and integration patterns below. Deviating from them silently is the main failure mode to avoid — if a requirement genuinely conflicts with the blueprint, flag it explicitly rather than quietly changing the design.

## Hard environment constraints (never violate)

These come from Hostinger Business (shared) hosting and the audit. Code that breaks them will not run in production:

- **Pure PHP 8.x + MySQL only.** No Node.js, no external runtimes, no Composer packages that need shell/exec or background daemons. The only outbound services are Duffel, RateHawk, and Stripe.
- **No long-running processes.** Anything asynchronous (emails, webhooks fan-out, cleanup) runs through the **Cron queue worker**, not daemons or websockets.
- **HTTP via cURL** (PHP cURL extension), not Guzzle-with-async or fibers. Keep dependencies minimal and shared-host-safe.
- **Secrets come from Hostinger hPanel environment variables.** `APP_MASTER_KEY` is read from the environment, never hardcoded, and all at-rest encryption keys are **derived from it via PBKDF2** (see C-03 below).

## The four critical invariants (C-01 → C-04)

These were the audit's blocking issues. Every relevant code path must honor them. Treat them as non-negotiable:

- **C-01 — Stripe idempotency.** Every Stripe charge/PaymentIntent creation passes an `idempotency_key`, persisted on the `payments` table, so retries never double-charge. Generate it before the first attempt and reuse it on retry.
- **C-02 — Webhook race safety.** Webhook handlers (Stripe, Duffel) acquire a MySQL `GET_LOCK()` keyed on the booking/payment before mutating state, and `RELEASE_LOCK()` after. This prevents duplicate processing when a webhook and a redirect arrive together.
- **C-03 — Key management.** Never store a raw master key in the DB or code. `APP_MASTER_KEY` lives in hPanel env vars; derive per-purpose keys with **PBKDF2**, then use **AES-256-CBC** for field-level encryption (passenger PII, documents). This is implemented in the `Encryption` core class — use it, don't reinvent.
- **C-04 — Checkout state in the DB, not PHP Session.** Checkout/booking state lives in the **`booking_sessions`** table, not `$_SESSION`. Shared hosting recycles sessions unpredictably; the table is the source of truth for an in-progress booking.

## Architecture overview

Custom lightweight MVC framework (hand-rolled, no Laravel/Symfony). The core layer lives under `app/Core/` and is already built — reuse it, don't rebuild:

- `Application` (bootstrap) · `Container` (dependency injection) · `Router` (with a middleware pipeline)
- `Request` · `Response` · `Session`
- `Database` (PDO wrapper with a `QueryBuilder` and `GET_LOCK` support) · `Cache`
- `Validator` · `Encryption` (PBKDF2 + AES-256-CBC)

Request flow: `public/index.php` → `Application` boots → `Router` matches → middleware pipeline → Controller → Service (business logic) → Model (data) → `Response` → View.

Keep business logic in **Services**, data access in **Models**, and HTTP concerns in **Controllers**. Controllers stay thin.

## Reference files — read the relevant one before writing code

This SKILL.md is the always-loaded summary. For detail, open the matching reference:

- **`references/conventions.md`** — folder structure, naming, internal-reference formats (FM/HM/TK), helpers, security helpers (CSRF/XSS), validation, error handling, RTL/Arabic + email conventions, migration style. Read this before adding any new file, model, or controller.
- **`references/schema.md`** — the **65-table** schema (the project grew past the 56-table blueprint): domain map, audit-critical columns verified against the live schema, and real conventions. Read this before writing any migration, model, or query.
- **`references/schema-full.sql`** — the **authoritative exact DDL** for all 65 tables (every column, type, default, key, FK), generated by applying migrations `001`→`078` and dumping the result. Open this for exact column definitions.
- **`references/api-integration.md`** — Duffel (flights), RateHawk (hotels), and Stripe (payments) integration patterns, the offer-expiry / Duffel-Balance / Stripe-Intent flows, webhook handling, and how C-01/C-02 land in each. Read this before touching any API call or webhook.
- **`references/code-templates.md`** — copy-ready skeletons (migration, model, service, controller, webhook handler, Stripe charge, field encryption) with the conventions and the four invariants already wired in. Use these as the starting shape for any new file.
- **`references/deployment.md`** — how FlyMasar reaches production on Hostinger: SSH coordinates, git-based deploy, migration safety, and which operations require explicit owner approval. Read this before any deploy, migration, or server-side command.

## Working defaults

- When asked to "add a feature", first state which tables, services, and controllers it touches, then implement — surfacing any new migration up front.
- Write **prepared statements** for every query (no string-concatenated SQL). Use the `QueryBuilder`/`Database` core, not raw `mysqli`.
- Sanitize all output through the security helper (XSS) and verify CSRF on every state-changing POST.
- Match the existing file style and the numbered-migration convention rather than introducing a new pattern.
- Arabic-facing views are **RTL**; user-facing emails ship in **AR + EN** templates.
- If the user asks for something the blueprint already solved a particular way, follow the blueprint's way and say so; if you believe the blueprint is wrong, say that explicitly and propose the change rather than silently diverging.

## A note on completeness of this skill

The exact, column-level schema for all 65 tables is bundled in `references/schema-full.sql` (real DDL produced by running every migration `001`→`078`). When a query needs an exact column, type, enum value, or FK, consult that file rather than guessing — it is the source of truth, kept faithful to the actual database.
