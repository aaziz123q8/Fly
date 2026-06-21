# FlyMasar — Deployment Guide (Hostinger Business)

## Zero-downtime deployment

1. Upload files via SFTP or Git pull on server
2. Run `composer install --no-dev --optimize-autoloader`
3. Run new migrations (if any)
4. No restart needed (PHP-FPM handles it)

## Environment variables on Hostinger

Go to hPanel → Advanced → PHP Configuration → Environment Variables.
Set all variables listed in INSTALL.md.

**IMPORTANT:** `APP_MASTER_KEY` must be set here, never in any file committed to git.

## Stripe webhook configuration

Add these endpoints in Stripe Dashboard → Webhooks:
- `https://yourdomain.com/webhooks/stripe`
  Events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`

## Duffel webhook configuration

Add in Duffel Dashboard → Webhooks:
- `https://yourdomain.com/webhooks/duffel`
  Events: `order.airline_initiated_change`, `order.cancelled`

## WhatsApp template approval

In Meta Business Manager, submit the `booking_confirmation` template:
- Language: English (or Arabic if needed)
- Parameters: `{{1}}` booking ref, `{{2}}` name, `{{3}}` route/hotel, `{{4}}` date

## Monitoring

- Check error_logs table regularly:
  ```sql
  SELECT * FROM error_logs ORDER BY created_at DESC LIMIT 50;
  ```
- Check failed jobs:
  ```sql
  SELECT * FROM job_queue WHERE status='failed';
  ```
- Monitor offer_cache size:
  ```sql
  SELECT COUNT(*) FROM offer_cache;
  ```

## Rolling back

Each migration is in a separate file. To rollback a specific table:
```sql
DROP TABLE IF EXISTS table_name;
```
