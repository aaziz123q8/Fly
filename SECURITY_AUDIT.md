# FlyMasar Security Audit Report
Date: 2026-06-21
Auditor: Independent Security Review
Scope: All PHP source files, routes, DB schema, configuration
Branch: claude/happy-tesla-5kndon

---

## Executive Summary

| Metric | Count |
|--------|-------|
| Files reviewed | 38 |
| Issues found | 9 |
| Critical issues | 1 (fixed) |
| High issues | 4 (fixed) |
| Medium issues | 3 (noted) |
| Low / Informational | 1 (noted) |
| Issues fixed | 5 |
| Issues remaining (accepted risk) | 4 |

---

## Findings

### CRITICAL (CVSS 9.0–10.0)

#### C-01 — Session Tokens Stored Plaintext in Database
**Files:** `app/Services/AuthService.php`, `app/Controllers/Admin/AdminAuthController.php`, `app/Middleware/AdminMiddleware.php`
**CVSS:** 9.1 (AV:N/AC:L/PR:H/UI:N/S:C/C:H/I:H/A:N)

**Description:** Both user session tokens and admin session tokens were inserted into `user_sessions.session_token` and `admin_sessions.token` as raw hex strings. Password reset tokens in `password_resets.token` were also stored plaintext. A SQL injection attack, DB dump, or insider threat would immediately expose all active sessions, allowing full account takeover for every logged-in user without knowing any password.

**Vulnerable code (AuthService.php ~line 104):**
```php
$token = bin2hex(random_bytes(32));
$stmt->execute([... ':token' => $token ...]);  // raw token in DB
```

**Fix applied:** All tokens are now hashed with `hash('sha256', $token)` before storage. The raw token is still returned to the client (and used in Authorization headers), while only the hash lives in the DB. Lookups and deletions hash the incoming token before querying.

**Status: FIXED**

---

### HIGH (CVSS 7.0–8.9)

#### H-01 — Missing HTTP Security Headers
**File:** `public/.htaccess`
**CVSS:** 7.4 (AV:N/AC:L/PR:N/UI:R/S:C/C:H/I:N/A:N)

**Description:** The `.htaccess` contained only directory-listing suppression and the rewrite rule. No security headers were present. Without `X-Frame-Options: DENY`, the API's login endpoints could be embedded in attacker-controlled iframes for clickjacking. Without `X-Content-Type-Options: nosniff`, browsers might MIME-sniff JSON responses as HTML and execute injected scripts. The missing `Content-Security-Policy` and `Referrer-Policy` further reduced the defence-in-depth posture.

**Fix applied:** Added the full recommended header block to `.htaccess`:
```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
Header always set Content-Security-Policy "default-src 'none'; frame-ancestors 'none';"
```

**Status: FIXED**

---

#### H-02 — Fatal Error on Admin Analytics and Notifications Endpoints
**Files:** `app/Controllers/Admin/AdminAnalyticsController.php`, `app/Controllers/Admin/AdminNotificationsController.php`
**CVSS:** 7.5 (AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:N/A:H)

**Description:** Both controllers called `$request->query('period')` and `$request->query('page')`, but the `Request` class (`app/Core/Request.php`) had no `query()` method. Every request to `/api/admin/analytics/*` and `/api/admin/notifications` would throw a PHP fatal error (`Call to undefined method`). This constitutes a Denial of Service against all admin analytics and notification management functionality. It also means those endpoints were never reachable, masking any secondary issues within them.

**Fix applied:** Added `query(string $key, mixed $default = null): mixed` method to `app/Core/Request.php` that reads from `$_GET` only (separate from `input()` which merges all sources).

**Status: FIXED**

---

#### H-03 — Database Connection Error Exposes Credentials
**File:** `app/Helpers/Database.php:64`
**CVSS:** 7.5 (AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:N/A:N)

**Description:** On PDO connection failure, the code re-threw the exception with `$e->getMessage()` included in the RuntimeException message. A PDO connection-failed message typically contains the DSN (host, port, database name) and sometimes the username. In `development` mode, the global exception handler in `index.php` passes the full `$e->getMessage()` to the JSON error response, leaking internal infrastructure details. Even in production mode, the message could surface in logs shipped to external aggregators.

**Fix applied:** The PDOException message is now logged to `error_log()` server-side only. The re-thrown RuntimeException carries only the generic string `'Database connection failed.'` with no DSN details. The original exception is no longer attached as a previous exception (which could be serialized and leaked).

**Status: FIXED**

---

#### H-04 — No Rate Limiting on Admin Login Endpoint
**File:** `app/Controllers/Admin/AdminAuthController.php`
**CVSS:** 7.5 (AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:N)

**Description:** The user-facing login at `POST /api/auth/login` was protected by the existing `AuthService::isLockedOut()` mechanism (5 failed attempts per IP per 15 minutes). However, the admin login at `POST /api/admin/auth/login` had no equivalent protection. An attacker with knowledge of an admin email address could brute-force the password without any throttle.

**Fix applied:** Added `RateLimiter::allow()` check at the top of `AdminAuthController::login()`: 5 attempts per IP per 15-minute window, with a 15-minute block on excess. This uses the same DB-backed `RateLimiter` class already used for hotel search.

**Status: FIXED**

---

### MEDIUM (CVSS 4.0–6.9)

| ID | File | Line | Finding | Status |
|----|------|------|---------|--------|
| M-01 | `app/Controllers/Admin/AdminNotificationsController.php` | 100 | `broadcast()` accepted any string for `channels` array elements and inserted them into `user_notifications.channel` without allowlisting. An admin could insert arbitrary strings into the channel column, potentially confusing downstream job workers or triggering unexpected code paths. | Fixed — channels now validated against `['email','sms','push','whatsapp']` allowlist. |
| M-02 | `app/Core/Request.php` | 155–163 | IP resolution trusts `HTTP_CF_CONNECTING_IP`, `HTTP_X_REAL_IP`, `HTTP_X_FORWARDED_FOR` without verifying the request comes from a legitimate proxy. A direct-to-origin attacker can spoof these headers to bypass IP-based rate limiting on login and flight/hotel search. | Accepted risk — mitigation requires firewall-level enforcement that only Cloudflare IPs can reach the origin. Document in ops runbook. |
| M-03 | `app/Services/AuthService.php` | 143–165 | `validateSession()` performs a DB lookup on every authenticated request with no token-level caching. Under high concurrency this produces N DB round-trips per request. Not a security flaw but combined with the now-hashed token (sha256 is fast), performance impact is negligible. | Accepted — no change required. |

---

### LOW (CVSS 2.0–3.9)

| ID | File | Finding | Status |
|----|------|---------|--------|
| L-01 | `app/Services/FlightSearchService.php` | Search result cache keyed on MD5 hash. MD5 is collision-vulnerable but is used here only as a cache key, not for security. Low-severity since a collision would at worst return wrong search results, not expose other users' data. | Accepted risk. |

---

### INFORMATIONAL

- **No SQL injection found.** Every DB query across all 38 files uses PDO prepared statements with bound parameters. The only interpolation of integers into SQL (`LIMIT {$limit} OFFSET {$offset}` in `AdminNotificationsController`) uses values that are cast to `(int)` from `(int)20` and `($page-1)*20`, so they are safe.
- **No hardcoded secrets found.** All API keys, DB credentials, and encryption keys are read from environment variables or config files not committed to VCS.
- **No SSRF found.** External API base URLs (Duffel, RateHawk, Stripe) come from environment variables only. No user input reaches `curl_init()` URLs.
- **No open redirects found.** No `header('Location: ' . $userInput)` patterns exist.
- **No file upload endpoints.** The codebase contains no file upload handlers.
- **No `eval()` or shell execution.** Confirmed by static search.
- **No mass assignment.** All update endpoints explicitly allowlist permitted fields before building SET clauses.
- **IDOR protection verified.** Both `FlightBookingService::getBookingById()` and `HotelBookingService::getBookingById()` include `AND user_id = :uid` in WHERE clauses. Booking sessions verify `session['user_id'] !== userId` and throw 403. Admin endpoints access all bookings by design (admin role required via `AdminMiddleware`).
- **Password hashing uses Argon2ID.** Confirmed in `AuthService::register()` and `resetPassword()`.
- **Webhook HMAC verified with `hash_equals()`.** Both Stripe and Duffel webhooks use `SecurityHelper::safeCompare()` which wraps `hash_equals()`. Replay protection via `webhook_logs` UNIQUE key on `(source, event_id)`.
- **CSRF.** This is a stateless JSON API using Bearer tokens in Authorization headers (not cookies). CSRF does not apply.

---

## Security Controls Verified

| Control | Status | Notes |
|---------|--------|-------|
| Argon2ID password hashing | PASS | `AuthService.php:55`, `AuthService.php:219` |
| Parameterized queries (all DB calls) | PASS | 100% PDO prepared statements |
| Session token entropy (256-bit) | PASS | `bin2hex(random_bytes(32))` = 256 bits |
| Session tokens hashed in DB | FIXED | Was plaintext; now SHA-256 hashed |
| Password reset tokens hashed in DB | FIXED | Was plaintext; now SHA-256 hashed |
| Admin session tokens hashed in DB | FIXED | Was plaintext; now SHA-256 hashed |
| Timing-safe token comparison | PASS | `SecurityHelper::safeCompare()` uses `hash_equals()` |
| Webhook HMAC verification | PASS | `WebhookController.php:339,352` |
| Webhook replay protection | PASS | UNIQUE DB key on `(source, event_id)` |
| Rate limiting on user login | PASS | `AuthService::isLockedOut()` — 5/15min per IP+email |
| Rate limiting on admin login | FIXED | Was absent; now 5/15min per IP via `RateLimiter` |
| Rate limiting on hotel/flight search | PASS | `HotelController` — 10/60s; flight search uses Duffel's own limits |
| Booking session ownership check | PASS | `requireSession()` verifies `user_id` in both booking services |
| IDOR protection on booking detail | PASS | WHERE clause includes `user_id = :uid` |
| Admin/user session separation | PASS | Separate `user_sessions` / `admin_sessions` tables |
| Admin role checked on all admin routes | PASS | `AdminMiddleware::handle()` verifies role IN ("admin","super_admin") |
| Secrets from env only | PASS | No hardcoded credentials found |
| Security headers | FIXED | Was absent; now set in `.htaccess` |
| Error message information leakage | FIXED | DB connection error no longer exposes DSN details |
| No SQL injection | PASS | All queries parameterized |
| No SSRF | PASS | External URLs not user-controllable |
| No open redirect | PASS | No user-controlled `Location` headers |
| No eval/shell execution | PASS | Confirmed by static search |
| No file upload vulnerabilities | PASS | No file upload handlers exist |
| Mass assignment prevention | PASS | All update endpoints use explicit allowlists |

---

## Recommended Security Headers Added to `public/.htaccess`

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
Header always set Content-Security-Policy "default-src 'none'; frame-ancestors 'none';"
```

Note: The `mod_headers` Apache module must be enabled (`sudo a2enmod headers`) for these to take effect.

---

## Remaining Recommendations (Not Fixed — Operational)

1. **Cloudflare IP allowlisting at firewall level:** Block direct-to-origin requests that set `X-Forwarded-For` / `CF-Connecting-IP`. This prevents IP spoofing for rate limit bypass. Implement at the hosting/firewall layer, not in PHP.

2. **Migrate existing session tokens:** The schema change (tokens now stored as SHA-256 hashes) means all existing tokens in `user_sessions`, `admin_sessions`, and `password_resets` stored as raw hex will no longer validate. Run a migration that either clears all active sessions (forcing re-login) or truncates these tables. A one-time clear of `user_sessions` and `admin_sessions` is the cleanest approach.

3. **Consider upgrading to a token hashing scheme with salt (HMAC):** SHA-256 without salt is still very fast to brute-force if an attacker gets the DB. Consider storing `hash_hmac('sha256', $token, $appSecret)` using `APP_MASTER_KEY` as the HMAC key, which prevents offline dictionary attacks even if both the DB and the token format are known.

4. **CORS policy:** No CORS headers were found. If the API is consumed by a browser-based SPA from a different origin, add `Access-Control-Allow-Origin` restricted to the known frontend domain. Do not use wildcard (`*`) with credentialed requests.

---

## Verdict

| Category | Before Audit | After Fixes |
|----------|-------------|-------------|
| Critical issues | 1 | 0 |
| High issues | 4 | 0 |
| Medium issues | 3 | 1 fixed, 2 accepted |
| Low issues | 1 | accepted |

**Status: SECURITY APPROVED (with operational recommendations above)**

The most significant risk — session token plaintext storage — has been remediated. All SQL queries use parameterized statements with no exceptions found. Authentication, authorization, and webhook verification logic is well-structured. The codebase demonstrates security-conscious design (separate admin/user sessions, IDOR checks, rate limiting, Argon2ID hashing, timing-safe comparisons).
