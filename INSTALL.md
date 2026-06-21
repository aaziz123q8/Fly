# FlyMasar — Installation Guide

## Requirements
- PHP 8.1+ with extensions: pdo_mysql, json, curl, mbstring, openssl
- MySQL 8.0+
- Composer 2.x
- Hostinger Business hosting (or equivalent)

## 1. Clone the repository
```bash
git clone https://github.com/aaziz123q8/fly.git
cd fly
```

## 2. Install PHP dependencies
```bash
composer install --no-dev --optimize-autoloader
```

## 3. Configure environment
Copy example config files and fill in your credentials:
```bash
cp config/app.example.php config/app.php
cp config/database.example.php config/database.php
cp config/apis.example.php config/apis.php
```

### Required environment variables (set in Hostinger hPanel → PHP → Environment Variables):
- `APP_MASTER_KEY` — 64-character hex string (generate: `openssl rand -hex 32`)
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `DUFFEL_API_KEY`
- `RATEHAWK_KEY_ID`, `RATEHAWK_API_KEY`
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_DUFFEL_WEBHOOK_SECRET`
- `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`
- `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `APP_URL`

## 4. Run database migrations
```bash
mysql -u YOUR_USER -p YOUR_DATABASE < database/migrations/run_all.sql
```

If `SOURCE` commands don't work from stdin (some MySQL clients), run migrations individually:
```bash
cd database/migrations
for f in 0*.sql; do mysql -u YOUR_USER -p YOUR_DATABASE < "$f"; done
```

## 5. Set directory permissions
```bash
chmod 750 config/
chmod 755 storage/
mkdir -p storage/invoices
chmod 755 storage/invoices
```

## 6. Configure web server
Point document root to the `public/` directory.
All requests route through `public/index.php`.

### Apache (.htaccess included in `public/`):
Ensure `mod_rewrite` is enabled.

### Nginx:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## 7. Set up cron jobs (Hostinger hPanel → Cron Jobs)
```
*/5 * * * *  php /home/user/Fly/app/Cron/OfferCacheCleaner.php
* * * * *    php /home/user/Fly/app/Cron/JobQueueRunner.php
```
