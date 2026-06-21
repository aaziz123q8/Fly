-- FlyMasar Master Migration Runner
-- Run this file to create all 56 tables in order
-- Usage: mysql -u USER -p DATABASE < run_all.sql

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';
SET NAMES utf8mb4;

-- Group A: Auth & Authorization
SOURCE 001_create_users_table.sql;
SOURCE 002_create_roles_table.sql;
SOURCE 003_create_permissions_table.sql;
SOURCE 004_create_role_permissions_table.sql;
SOURCE 005_create_user_sessions_table.sql;
SOURCE 006_create_password_resets_table.sql;
SOURCE 007_create_login_attempts_table.sql;

-- Group B: Traveler Management
SOURCE 008_create_travelers_table.sql;
SOURCE 009_create_traveler_documents_table.sql;
SOURCE 010_create_traveler_frequent_flyers_table.sql;

-- Group C: Multi-Supplier Providers
SOURCE 011_create_providers_table.sql;
SOURCE 012_create_provider_credentials_table.sql;

-- Group D: Geography Cache
SOURCE 013_create_countries_table.sql;
SOURCE 014_create_cities_table.sql;
SOURCE 015_create_airports_table.sql;
SOURCE 016_create_airlines_table.sql;

-- Group E: Flight Bookings
SOURCE 017_create_flight_bookings_table.sql;
SOURCE 018_create_flight_booking_passengers_table.sql;
SOURCE 019_create_flight_booking_segments_table.sql;
SOURCE 020_create_flight_booking_services_table.sql;
SOURCE 021_create_offer_cache_table.sql;
SOURCE 022_create_booking_sessions_table.sql;

-- Group F: Hotel Bookings
SOURCE 023_create_hotel_bookings_table.sql;
SOURCE 024_create_hotel_booking_rooms_table.sql;
SOURCE 025_create_hotel_booking_guests_table.sql;
SOURCE 026_create_hotels_content_table.sql;
SOURCE 027_create_hotel_images_table.sql;

-- Group G: Payments & Financial
SOURCE 028_create_payments_table.sql;
SOURCE 029_create_refunds_table.sql;
SOURCE 030_create_invoices_table.sql;
SOURCE 031_create_booking_pricing_breakdown_table.sql;

-- Group H: Pricing & Coupons
SOURCE 032_create_pricing_rules_table.sql;
SOURCE 033_create_coupons_table.sql;
SOURCE 034_create_coupon_usages_table.sql;

-- Group I: Notifications
SOURCE 035_create_notification_templates_table.sql;
SOURCE 036_create_user_notifications_table.sql;
SOURCE 037_create_notification_dispatch_log_table.sql;
SOURCE 038_create_user_notification_preferences_table.sql;

-- Group J: WhatsApp
SOURCE 039_create_whatsapp_providers_table.sql;
SOURCE 040_create_whatsapp_templates_table.sql;

-- Group K: Support Tickets
SOURCE 041_create_support_tickets_table.sql;
SOURCE 042_create_ticket_messages_table.sql;
SOURCE 043_create_ticket_attachments_table.sql;

-- Group L: Audit & Logs
SOURCE 044_create_booking_audit_log_table.sql;
SOURCE 045_create_admin_activity_log_table.sql;
SOURCE 046_create_webhook_logs_table.sql;
SOURCE 047_create_error_logs_table.sql;

-- Group M: System & Config
SOURCE 048_create_exchange_rates_table.sql;
SOURCE 049_create_settings_table.sql;
SOURCE 050_create_job_queue_table.sql;
SOURCE 051_create_attachments_table.sql;
SOURCE 052_create_cms_pages_table.sql;

-- Group N: Analytics & UX
SOURCE 053_create_search_logs_table.sql;
SOURCE 054_create_popular_routes_table.sql;
SOURCE 055_create_rate_limits_table.sql;
SOURCE 056_create_saved_searches_table.sql;

SOURCE 057_add_role_to_users.sql;
SOURCE 058_create_admin_sessions.sql;
SOURCE 059_push_subscriptions.sql;
SOURCE 060_alter_travelers_add_blueprint_fields.sql;

-- Group O: Payments, Support, Commissions, Currencies, API Settings
SOURCE 061_create_commissions_table.sql;
SOURCE 062_create_currencies_table.sql;
SOURCE 063_create_api_settings_table.sql;
SOURCE 064_create_payments_table.sql;
SOURCE 065_create_support_tables.sql;

SET FOREIGN_KEY_CHECKS = 1;
