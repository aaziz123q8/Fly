# FlyMasar — Testing Guide

## Local setup

1. Start PHP built-in server:
   ```bash
   php -S 127.0.0.1:8080 -t public/
   ```

2. Set up test database (see INSTALL.md)

## Health check

```bash
curl -s http://127.0.0.1:8080/health
```
Expected: `{"status":"ok","time":"..."}`

## Auth endpoints

### Register
```bash
curl -s -X POST http://127.0.0.1:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!","password_confirmation":"Password123!","first_name":"Ahmed","last_name":"Ali","phone_country_code":"+966","phone_number":"512345678"}'
```
Expected: `201 Created` with user object

### Login
```bash
curl -s -X POST http://127.0.0.1:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}'
```
Expected: `200` with `session_token`

### Get current user
```bash
TOKEN="your_session_token_here"
curl -s http://127.0.0.1:8080/api/auth/me \
  -H "Authorization: Bearer $TOKEN"
```
Expected: `200` with user object

### Logout
```bash
curl -s -X POST http://127.0.0.1:8080/api/auth/logout \
  -H "Authorization: Bearer $TOKEN"
```
Expected: `204 No Content`

## Flight endpoints

### Search flights
```bash
curl -s -X POST http://127.0.0.1:8080/api/flights/search \
  -H "Content-Type: application/json" \
  -d '{"origin":"LHR","destination":"DXB","departure_date":"2026-08-01","cabin_class":"economy","adults":1}'
```
Expected: `200` with offers array (or API error if Duffel key not configured)

## Hotel endpoints

### Search hotels
```bash
curl -s -X POST http://127.0.0.1:8080/api/hotels/search \
  -H "Content-Type: application/json" \
  -d '{"city_id":1,"check_in":"2026-08-01","check_out":"2026-08-05","adults":2,"rooms":1}'
```
Expected: `200` with hotels array (or API error if RateHawk key not configured)

## Job queue

### Manual run
```bash
php app/Cron/JobQueueRunner.php
```

### Insert a test job and process it
```sql
INSERT INTO job_queue (job_type, payload)
VALUES ('send_password_reset_email', '{"user_id":1,"token":"test","email":"test@example.com"}');
```
```bash
php app/Cron/JobQueueRunner.php
```

### Check job status
```sql
SELECT id, job_type, status, attempts, error_message FROM job_queue ORDER BY id DESC LIMIT 10;
```

## Offer cache cleaner
```bash
php app/Cron/OfferCacheCleaner.php
```

## Webhook testing (Stripe CLI)
```bash
stripe listen --forward-to http://127.0.0.1:8080/webhooks/stripe
stripe trigger payment_intent.succeeded
```
