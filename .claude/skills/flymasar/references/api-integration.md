# FlyMasar — API Integration Reference

Read this before touching any Duffel, RateHawk, or Stripe call, or any webhook handler. All three are reached over **PHP cURL** (no async runtimes), with keys loaded from `config/apis.php` (env-sourced). This file describes the integration patterns and where the critical invariants land.

## General rules for all three integrations

- One thin **client/service per provider**; controllers never call cURL directly.
- Time out and handle failures explicitly — a hung supplier call must not hang the request. Surface a clean error to the user and log it.
- Treat supplier responses as untrusted input: validate before persisting.
- Never expose raw supplier IDs/keys to the browser beyond what's needed; map to internal references (`FM…`, `HM…`).

## Duffel — Flights

Model: book against **Duffel Payments / Duffel Balance** for flights (avoids holding customer cash-flow risk for same-day issuance).

Flow:
1. **Search** → offer request → offers list. Cache nothing stale; offers expire.
2. **Offer expiry is real and must be handled explicitly (audit fix).** Before creating the order at checkout, re-validate the selected offer; if expired, re-fetch and show the user the updated price instead of failing silently or charging on a dead offer.
3. **Order creation** happens after payment authorization succeeds. Persist the Duffel order reference against the flight booking.
4. **Multi-city**: each slice maps to a `flight_booking_segments` row keyed by `slice_index` (H-02). Don't flatten slices.
5. **Webhooks** (order status, schedule changes): process under `GET_LOCK()` (C-02), update booking state idempotently.

## RateHawk (WorldOTA) — Hotels

Model: net-rate sourcing; consumer price = net + margin. A **credit line** with RateHawk covers same-day bookings (so liquidity isn't blocked per-booking).

Flow:
1. **Search** → hotel results; static hotel content lives in `hotels_content`, images in `hotel_images` (H-03 — keep them separate).
2. **Rate/availability check** before booking — rates move; re-validate the selected rate at checkout.
3. **Booking** against the credit line after payment authorization; persist the RateHawk booking reference against the hotel booking (`HM…`).
4. **Cancellation/refund** flows follow the provider's policy; reflect status changes through the webhook/event path under `GET_LOCK`.

## Stripe — Payments

Flow:
1. Create a **PaymentIntent** with an **`idempotency_key`** (C-01) that is generated once, persisted on `payments`, and reused on every retry so a retry never creates a second charge.
2. Confirm payment, then proceed to supplier order/booking creation. Order of operations: **authorize money → create supplier booking → capture/settle**. If the supplier booking fails after authorization, handle the reversal/refund explicitly.
3. **Webhooks** (`payment_intent.succeeded`, `.payment_failed`, refunds): the handler acquires `GET_LOCK()` (C-02) on the payment/booking before mutating state, processes idempotently (a given event id is handled once), then `RELEASE_LOCK()`.
4. Checkout state for the in-flight booking is read/written from **`booking_sessions`** (C-04), not `$_SESSION`, so a webhook arriving alongside the user redirect sees consistent state.
5. Invoices are written to `invoices` with the correct `invoice_type` (M-04).

## Webhook handler checklist (applies to Stripe + Duffel)

For every webhook:
- Verify the signature / authenticity first; reject unverified payloads.
- Acquire `GET_LOCK()` keyed on the booking/payment id (C-02).
- Check whether this event id was already processed (idempotency) before mutating.
- Mutate booking/payment state inside the lock.
- `RELEASE_LOCK()` and return the expected 2xx quickly; defer heavy work (emails, etc.) to the Cron queue.

## Money & margin

- Hotel consumer price = supplier net + configured margin; compute and store both so invoices and reporting are accurate.
- Flights priced from the live Duffel offer (re-validated at checkout per the expiry rule).
- Format all displayed amounts via `CurrencyHelper`.
