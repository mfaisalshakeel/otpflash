-- ===========================================================================
-- OTP Flash - SQLite schema and seed data
--
-- The MySQL file (schema.sql) is the reference; this one says the same thing
-- in SQLite's dialect:
--   INTEGER PRIMARY KEY AUTOINCREMENT instead of INT AUTO_INCREMENT
--   TEXT with a CHECK instead of ENUM
--   no ENGINE / CHARSET / COLLATE
--   INSERT OR IGNORE instead of ON DUPLICATE KEY UPDATE
-- Keep the two in step when either changes.
-- ===========================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS "settings" (
  "k" TEXT NOT NULL PRIMARY KEY,
  "v" TEXT
);

CREATE TABLE IF NOT EXISTS "admins" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" TEXT NOT NULL UNIQUE,
  "email" TEXT NOT NULL UNIQUE,
  "password_hash" TEXT NOT NULL,
  "last_login_at" TEXT,
  "two_factor" TEXT NOT NULL DEFAULT 'off',
  "totp_secret" TEXT NOT NULL DEFAULT '',
  "recovery_codes" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "currencies" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL UNIQUE,
  "name" TEXT NOT NULL,
  "symbol" TEXT NOT NULL DEFAULT '',
  "rate_to_base" REAL NOT NULL DEFAULT 1,
  "is_base" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "updated_at" TEXT
);

CREATE TABLE IF NOT EXISTS "countries" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "slug" TEXT NOT NULL UNIQUE,
  "name" TEXT NOT NULL,
  "iso2" TEXT NOT NULL DEFAULT '',
  "dial_code" TEXT NOT NULL DEFAULT '',
  "is_popular" INTEGER NOT NULL DEFAULT 0,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_countries_active" ON "countries" ("is_active", "is_popular", "sort_order");

CREATE TABLE IF NOT EXISTS "services" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "slug" TEXT NOT NULL UNIQUE,
  "match_key" TEXT NOT NULL DEFAULT '',
  "name" TEXT NOT NULL,
  "icon" TEXT NOT NULL DEFAULT '',
  "color" TEXT NOT NULL DEFAULT '',
  "site_domain" TEXT NOT NULL DEFAULT '',
  "intro" TEXT,
  "meta_title" TEXT NOT NULL DEFAULT '',
  "meta_description" TEXT NOT NULL DEFAULT '',
  "is_popular" INTEGER NOT NULL DEFAULT 0,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_services_active" ON "services" ("is_active", "is_popular", "sort_order");
CREATE INDEX IF NOT EXISTS "ix_services_match" ON "services" ("match_key");

CREATE TABLE IF NOT EXISTS "offers" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "product" TEXT NOT NULL,
  "server" TEXT NOT NULL DEFAULT '',
  "provider_country" TEXT NOT NULL DEFAULT '',
  "provider_service" TEXT NOT NULL DEFAULT '',
  "provider_name" TEXT NOT NULL DEFAULT '',
  "country_id" INTEGER,
  "service_id" INTEGER,
  "cost" REAL NOT NULL DEFAULT 0,
  "cost_currency" TEXT NOT NULL DEFAULT '',
  "price" REAL NOT NULL DEFAULT 0,
  "price_override" REAL,
  "stock" INTEGER,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "missing" INTEGER NOT NULL DEFAULT 0,
  "synced_at" TEXT,
  "created_at" TEXT NOT NULL,
  UNIQUE ("product", "server", "provider_country", "provider_service"),
  FOREIGN KEY ("country_id") REFERENCES "countries" ("id") ON DELETE SET NULL,
  FOREIGN KEY ("service_id") REFERENCES "services" ("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "ix_offers_service" ON "offers" ("service_id", "country_id");
CREATE INDEX IF NOT EXISTS "ix_offers_country" ON "offers" ("country_id");

CREATE TABLE IF NOT EXISTS "payment_methods" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "driver" TEXT NOT NULL DEFAULT 'manual',
  "currency" TEXT NOT NULL DEFAULT '',
  "config" TEXT,
  "short_name" TEXT NOT NULL DEFAULT '',
  "account_title" TEXT NOT NULL DEFAULT '',
  "account_number" TEXT NOT NULL DEFAULT '',
  "extra_label" TEXT NOT NULL DEFAULT '',
  "extra_value" TEXT NOT NULL DEFAULT '',
  "instructions" TEXT,
  "logo_path" TEXT NOT NULL DEFAULT '',
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS "ix_payment_active" ON "payment_methods" ("is_active", "sort_order");

CREATE TABLE IF NOT EXISTS "users" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "email" TEXT NOT NULL UNIQUE,
  "phone" TEXT NOT NULL DEFAULT '',
  "password_hash" TEXT NOT NULL,
  "balance" REAL NOT NULL DEFAULT 0,
  "status" TEXT NOT NULL DEFAULT 'active',
  "reset_hash" TEXT NOT NULL DEFAULT '',
  "reset_expires_at" TEXT,
  "last_login_at" TEXT,
  "last_login_ip" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL,
  "updated_at" TEXT
);
CREATE INDEX IF NOT EXISTS "ix_users_created" ON "users" ("created_at");

CREATE TABLE IF NOT EXISTS "wallet_transactions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NOT NULL,
  "type" TEXT NOT NULL,
  "amount" REAL NOT NULL,
  "balance_after" REAL NOT NULL,
  "order_id" INTEGER,
  "note" TEXT NOT NULL DEFAULT '',
  "admin_id" INTEGER,
  "created_at" TEXT NOT NULL,
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
  FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "ix_wallet_user" ON "wallet_transactions" ("user_id", "created_at");
CREATE INDEX IF NOT EXISTS "ix_wallet_order" ON "wallet_transactions" ("order_id");

CREATE TABLE IF NOT EXISTS "orders" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL UNIQUE,
  "user_id" INTEGER,
  "offer_id" INTEGER,
  "product" TEXT NOT NULL DEFAULT 'sms',
  "server" TEXT NOT NULL DEFAULT '',
  "country_id" INTEGER,
  "service_id" INTEGER,
  "service_name" TEXT NOT NULL DEFAULT '',
  "country_name" TEXT NOT NULL DEFAULT '',
  "country_iso" TEXT NOT NULL DEFAULT '',
  "label" TEXT NOT NULL DEFAULT '',
  "whatsapp" TEXT NOT NULL DEFAULT '',
  "email" TEXT NOT NULL DEFAULT '',
  "price" REAL NOT NULL DEFAULT 0,
  "cost" REAL NOT NULL DEFAULT 0,
  "status" TEXT NOT NULL DEFAULT 'pending'
     CHECK ("status" IN ('pending','paid','processing','completed','expired',
                         'cancelled','refunded','api_error')),
  "pay_source" TEXT NOT NULL DEFAULT 'gateway',
  "meta" TEXT,
  "activation_id" INTEGER,
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "number" TEXT NOT NULL DEFAULT '',
  "otp_code" TEXT NOT NULL DEFAULT '',
  "api_error" TEXT NOT NULL DEFAULT '',
  "sending_at" TEXT,
  "hold_until" TEXT,
  "quote_price" REAL,
  "payment_method_id" INTEGER,
  "trx_id" TEXT NOT NULL DEFAULT '',
  "paid_amount" REAL,
  "paid_at" TEXT,
  "payment_details" TEXT,
  "gateway_details" TEXT,
  "completed_at" TEXT,
  "ip" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL,
  "updated_at" TEXT,
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
  FOREIGN KEY ("offer_id") REFERENCES "offers" ("id") ON DELETE SET NULL,
  FOREIGN KEY ("country_id") REFERENCES "countries" ("id") ON DELETE SET NULL,
  FOREIGN KEY ("service_id") REFERENCES "services" ("id") ON DELETE SET NULL,
  FOREIGN KEY ("payment_method_id") REFERENCES "payment_methods" ("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "ix_orders_status" ON "orders" ("status", "created_at");
CREATE INDEX IF NOT EXISTS "ix_orders_trx" ON "orders" ("trx_id");
CREATE INDEX IF NOT EXISTS "ix_orders_user" ON "orders" ("user_id", "created_at");
CREATE INDEX IF NOT EXISTS "ix_orders_offer" ON "orders" ("offer_id");
CREATE INDEX IF NOT EXISTS "ix_orders_service" ON "orders" ("service_id");
CREATE INDEX IF NOT EXISTS "ix_orders_country" ON "orders" ("country_id");
CREATE INDEX IF NOT EXISTS "ix_orders_method" ON "orders" ("payment_method_id");
CREATE INDEX IF NOT EXISTS "ix_orders_created" ON "orders" ("created_at");
CREATE INDEX IF NOT EXISTS "ix_orders_hold" ON "orders" ("hold_until");

CREATE TABLE IF NOT EXISTS "activations" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "order_id" INTEGER NOT NULL,
  "product" TEXT NOT NULL DEFAULT 'sms',
  "server" TEXT NOT NULL DEFAULT '',
  "provider_order_id" TEXT NOT NULL DEFAULT '',
  "number" TEXT NOT NULL DEFAULT '',
  "status" TEXT NOT NULL DEFAULT 'waiting',
  "code" TEXT NOT NULL DEFAULT '',
  "messages" TEXT,
  "cost" REAL NOT NULL DEFAULT 0,
  "cost_currency" TEXT NOT NULL DEFAULT '',
  "cost_base" REAL NOT NULL DEFAULT 0,
  "refunded" INTEGER NOT NULL DEFAULT 0,
  "expires_at" TEXT,
  "checked_at" TEXT,
  "received_at" TEXT,
  "closed_at" TEXT,
  "created_at" TEXT NOT NULL,
  FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "ix_activations_order" ON "activations" ("order_id");
CREATE INDEX IF NOT EXISTS "ix_activations_open" ON "activations" ("status", "expires_at");
CREATE INDEX IF NOT EXISTS "ix_activations_provider" ON "activations" ("product", "provider_order_id");

CREATE TABLE IF NOT EXISTS "rentals" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "order_id" INTEGER,
  "user_id" INTEGER NOT NULL,
  "provider" TEXT NOT NULL DEFAULT '1',
  "provider_order_id" TEXT NOT NULL DEFAULT '',
  "provider_rental_id" TEXT NOT NULL DEFAULT '',
  "number" TEXT NOT NULL DEFAULT '',
  "country_code" TEXT NOT NULL DEFAULT '',
  "country_name" TEXT NOT NULL DEFAULT '',
  "duration_hours" INTEGER NOT NULL DEFAULT 0,
  "price" REAL NOT NULL DEFAULT 0,
  "cost" REAL NOT NULL DEFAULT 0,
  "status" TEXT NOT NULL DEFAULT 'active',
  "expires_at" TEXT,
  "checked_at" TEXT,
  "created_at" TEXT NOT NULL,
  "updated_at" TEXT,
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
  FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "ix_rentals_user" ON "rentals" ("user_id", "status");
CREATE INDEX IF NOT EXISTS "ix_rentals_expiry" ON "rentals" ("status", "expires_at");

CREATE TABLE IF NOT EXISTS "rental_messages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "rental_id" INTEGER NOT NULL,
  "fingerprint" TEXT NOT NULL,
  "sender" TEXT NOT NULL DEFAULT '',
  "body" TEXT,
  "code" TEXT NOT NULL DEFAULT '',
  "received_at" TEXT,
  "created_at" TEXT NOT NULL,
  UNIQUE ("rental_id", "fingerprint"),
  FOREIGN KEY ("rental_id") REFERENCES "rentals" ("id") ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS "order_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "order_id" INTEGER NOT NULL,
  "message" TEXT NOT NULL,
  "is_internal" INTEGER NOT NULL DEFAULT 1,
  "created_at" TEXT NOT NULL,
  FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "ix_order_logs_order" ON "order_logs" ("order_id", "created_at");

CREATE TABLE IF NOT EXISTS "order_calls" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "order_id" INTEGER NOT NULL,
  "source" TEXT NOT NULL DEFAULT '',
  "party" TEXT NOT NULL DEFAULT '',
  "action" TEXT NOT NULL DEFAULT '',
  "ok" INTEGER NOT NULL DEFAULT 0,
  "summary" TEXT NOT NULL DEFAULT '',
  "request" TEXT NULL,
  "response" TEXT NULL,
  "http_status" INTEGER NOT NULL DEFAULT 0,
  "ms" INTEGER NOT NULL DEFAULT 0,
  "repeats" INTEGER NOT NULL DEFAULT 1,
  "created_at" TEXT NOT NULL,
  "last_at" TEXT NOT NULL,
  FOREIGN KEY ("order_id") REFERENCES "orders" ("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "ix_order_calls_order" ON "order_calls" ("order_id", "id");
CREATE INDEX IF NOT EXISTS "ix_order_calls_source" ON "order_calls" ("source", "ok", "id");

CREATE TABLE IF NOT EXISTS "pages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "slug" TEXT NOT NULL UNIQUE,
  "title" TEXT NOT NULL,
  "content" TEXT,
  "meta_title" TEXT NOT NULL DEFAULT '',
  "meta_description" TEXT NOT NULL DEFAULT '',
  "og_image" TEXT NOT NULL DEFAULT '',
  "noindex" INTEGER NOT NULL DEFAULT 0,
  "show_in_footer" INTEGER NOT NULL DEFAULT 1,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "post_categories" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "slug" TEXT NOT NULL UNIQUE,
  "name" TEXT NOT NULL,
  "description" TEXT,
  "meta_title" TEXT NOT NULL DEFAULT '',
  "meta_description" TEXT NOT NULL DEFAULT '',
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "posts" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "category_id" INTEGER,
  "slug" TEXT NOT NULL UNIQUE,
  "title" TEXT NOT NULL,
  "excerpt" TEXT,
  "content" TEXT,
  "cover_path" TEXT NOT NULL DEFAULT '',
  "meta_title" TEXT NOT NULL DEFAULT '',
  "meta_description" TEXT NOT NULL DEFAULT '',
  "focus_keyword" TEXT NOT NULL DEFAULT '',
  "image_prompt" TEXT NULL,
  "image_alt" TEXT NOT NULL DEFAULT '',
  "tags" TEXT NOT NULL DEFAULT '',
  "status" TEXT NOT NULL DEFAULT 'draft',
  "is_featured" INTEGER NOT NULL DEFAULT 0,
  "noindex" INTEGER NOT NULL DEFAULT 0,
  "author_name" TEXT NOT NULL DEFAULT '',
  "views" INTEGER NOT NULL DEFAULT 0,
  "published_at" TEXT,
  "created_at" TEXT NOT NULL,
  "updated_at" TEXT,
  FOREIGN KEY ("category_id") REFERENCES "post_categories" ("id") ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "ix_posts_published" ON "posts" ("status", "published_at");
CREATE INDEX IF NOT EXISTS "ix_posts_category" ON "posts" ("category_id");

CREATE TABLE IF NOT EXISTS "faqs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "question" TEXT NOT NULL,
  "answer" TEXT NOT NULL,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "is_active" INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS "ix_faqs_active" ON "faqs" ("is_active", "sort_order");

CREATE TABLE IF NOT EXISTS "messages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "email" TEXT NOT NULL DEFAULT '',
  "whatsapp" TEXT NOT NULL DEFAULT '',
  "body" TEXT NOT NULL,
  "is_read" INTEGER NOT NULL DEFAULT 0,
  "ip" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_messages_read" ON "messages" ("is_read", "created_at");

CREATE TABLE IF NOT EXISTS "rate_limits" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "action" TEXT NOT NULL,
  "ip" TEXT NOT NULL,
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_rate_action_ip" ON "rate_limits" ("action", "ip", "created_at");

CREATE TABLE IF NOT EXISTS "notifications" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "event" TEXT NOT NULL,
  "title" TEXT NOT NULL,
  "body" TEXT NOT NULL DEFAULT '',
  "link" TEXT NOT NULL DEFAULT '',
  "is_read" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_notifications_read" ON "notifications" ("is_read", "created_at");

CREATE TABLE IF NOT EXISTS "email_templates" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "event" TEXT NOT NULL UNIQUE,
  "subject" TEXT NOT NULL,
  "body" TEXT NOT NULL,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "updated_at" TEXT
);

CREATE TABLE IF NOT EXISTS "email_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "event" TEXT NOT NULL DEFAULT '',
  "recipient" TEXT NOT NULL,
  "subject" TEXT NOT NULL DEFAULT '',
  "status" TEXT NOT NULL DEFAULT 'sent',
  "error" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_email_log_time" ON "email_log" ("created_at");

CREATE TABLE IF NOT EXISTS "cron_runs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "task" TEXT NOT NULL,
  "source" TEXT NOT NULL DEFAULT 'cron',
  "ok" INTEGER NOT NULL DEFAULT 1,
  "summary" TEXT NOT NULL DEFAULT '',
  "duration_ms" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_cron_runs_task" ON "cron_runs" ("task", "created_at");

CREATE TABLE IF NOT EXISTS "locks" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL UNIQUE,
  "owner" TEXT NOT NULL DEFAULT '',
  "acquired_at" TEXT NOT NULL,
  "expires_at" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "audit_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "action" TEXT NOT NULL,
  "actor_type" TEXT NOT NULL DEFAULT 'system',
  "actor_id" INTEGER NOT NULL DEFAULT 0,
  "actor_name" TEXT NOT NULL DEFAULT '',
  "entity" TEXT NOT NULL DEFAULT '',
  "entity_id" INTEGER NOT NULL DEFAULT 0,
  "summary" TEXT NOT NULL DEFAULT '',
  "severity" TEXT NOT NULL DEFAULT 'info',
  "before_json" TEXT NOT NULL DEFAULT '',
  "after_json" TEXT NOT NULL DEFAULT '',
  "ip" TEXT NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ix_audit_time" ON "audit_log" ("created_at");
CREATE INDEX IF NOT EXISTS "ix_audit_entity" ON "audit_log" ("entity", "entity_id");
CREATE INDEX IF NOT EXISTS "ix_audit_severity" ON "audit_log" ("severity", "created_at");

-- ===========================================================================
-- Seed data
-- ===========================================================================

INSERT OR IGNORE INTO "settings" ("k", "v") VALUES
  ('site_name',             'OTP Flash'),
  ('site_tagline',          'Virtual numbers for OTP verification, in seconds'),
  ('currency_symbol',       'Rs '),
  ('theme_color',           '#6c4df6'),
  ('whatsapp_number',       ''),
  ('telegram_username',     ''),
  ('support_email',         ''),
  ('logo_path',             ''),
  ('favicon_path',          ''),
  ('head_code',             ''),
  ('require_trx_id',        '1'),
  ('ask_email',             '1'),
  ('ask_whatsapp',          '1'),
  ('order_prefix',          'OF'),
  ('admin_per_page',        '30'),
  ('active_theme',          'default'),
  ('base_currency',         'PKR'),
  ('currency_rates_updated_at', ''),
  ('currency_auto_update',  '1'),
  ('cronjob_org_key',       ''),
  ('cronjob_org_job_id',    ''),
  ('mail_driver',           'off'),
  ('smtp_port',             '587'),
  ('smtp_secure',           'tls'),
  ('audit_keep_days',       '180'),
  ('log_keep_days',         '90'),
  ('chat_float',            'whatsapp'),
  ('chat_header',           'whatsapp'),
  ('chat_home',             'whatsapp'),
  ('chat_contact',          'whatsapp,email'),
  ('chat_order',            'whatsapp'),
  ('otpget_base_url',       'https://otpget.com/stubs'),
  ('otpget_api_key',        ''),
  ('otpget_currency',       'PKR'),
  ('low_balance_threshold', '0'),
  ('products',              'sms,premium,cheap,email,rental'),
  ('email_servers',         '1,2'),
  ('rental_providers',      '1,2'),
  ('email_sites',           'instagram.com,facebook.com,telegram.com,tiktok.com,twitter.com,discord.com,google.com,microsoft.com,apple.com,amazon.com,openai.com,netflix.com'),
  ('markup_sms',            ''),
  ('markup_premium',        ''),
  ('markup_cheap',          ''),
  ('markup_email',          ''),
  ('markup_rental',         ''),
  ('guest_checkout',        '1'),
  ('registration_open',     '1'),
  ('min_deposit',           '100'),
  ('seo_title_format',      '{title} | {site}'),
  ('seo_description',       'Buy virtual phone numbers for OTP and SMS verification - WhatsApp, Telegram, Google and 1000+ apps in 150+ countries. Instant delivery, automatic refunds.'),
  ('seo_same_as',           ''),
  ('seo_robots_extra',      ''),
  ('blog_title',            'Guides & news'),
  ('blog_description',      'How-to guides for getting verification codes on every app, privacy tips and news from the shop.'),
  ('seo_og_image',          ''),
  ('seo_twitter',           ''),
  ('seo_google_verify',     ''),
  ('seo_bing_verify',       ''),
  ('seo_org_name',          ''),
  ('seo_landing_pages',     '1'),
  ('blog_per_page',         '9'),
  ('sms_servers',           '1,2,3,4,5,6'),
  ('default_markup',        '30'),
  ('min_profit',            '0'),
  ('price_check_minutes',   '10'),
  ('min_price',             '0'),
  ('price_rounding',        '1'),
  ('auto_buy_number',       '1'),
  ('auto_refund',           '1'),
  ('loss_guard',            '1'),
  ('hold_before_pay',       '1'),
  ('hold_minutes',          '10'),
  ('rate_sanity_factor',    '5'),
  ('max_numbers_per_order', '3'),
  ('number_lifetime',       '20'),
  ('sync_on_cron',          '1'),
  ('sync_batch',            '144'),
  ('app_version',           '');

INSERT OR IGNORE INTO "currencies" ("code", "name", "symbol", "rate_to_base", "is_base", "is_active", "sort_order", "updated_at") VALUES
  ('PKR', 'Pakistani Rupee', 'Rs ', 1,   1, 1, 1, '2026-01-01 00:00:00'),
  ('USD', 'US Dollar',       '$',   278, 0, 1, 2, '2026-01-01 00:00:00');

INSERT INTO "payment_methods"
  ("name", "short_name", "account_title", "account_number", "sort_order", "is_active") VALUES
  ('JazzCash',      'JC', 'Your business name', '', 1, 0),
  ('EasyPaisa',     'EP', 'Your business name', '', 2, 0),
  ('Bank Transfer', 'BK', 'Your business name', '', 3, 0);

INSERT OR IGNORE INTO "pages" ("slug", "title", "content", "show_in_footer", "sort_order", "is_active") VALUES
  ('about-us',        'About Us',        '<p>Tell your customers who you are.</p>', 1, 1, 1),
  ('privacy-policy',  'Privacy Policy',  '<p>Replace with your privacy policy.</p>',1, 2, 1),
  ('refund-policy',   'Refund Policy',   '<p>A number that never receives its SMS is replaced free of charge. If no number works, message us with your order code for a refund.</p>', 1, 3, 1),
  ('terms-of-service','Terms of Service','<p>Replace with your terms.</p>',         1, 4, 1);

INSERT OR IGNORE INTO "post_categories" ("slug", "name", "description", "sort_order", "is_active") VALUES
  ('guides', 'Guides', 'Step-by-step help with virtual numbers and OTP verification.', 1, 1),
  ('news',   'News',   'What is new on the shop.', 2, 1);

INSERT INTO "faqs" ("question", "answer", "sort_order", "is_active") VALUES
  ('What is a virtual number?',
   'A real mobile number you use once to receive a verification code (OTP) - for WhatsApp, Telegram, Google and thousands of other apps - without giving out your own SIM.', 1, 1),
  ('How fast do I get the number?',
   'The moment your payment is confirmed. The number appears on your order page with a copy button, and the code shows up there by itself as soon as the SMS arrives.', 2, 1),
  ('What if the SMS never comes?',
   'Then you do not lose anything. Press Change number to get a different one free of charge, or let it expire - an order whose number never received a code is replaced or refunded.', 3, 1),
  ('How long can I use the number?',
   'A number is held for you for about 20 minutes, long enough to receive the code. It is for one verification, not for keeping.', 4, 1),
  ('Do I need an account?',
   'No. Pick a service and a country, pay, and keep your order code - that code is how you come back to your number and your SMS.', 5, 1),
  ('Which payment methods do you accept?',
   'The ones shown when you buy. Automatic methods confirm by themselves; for a manual transfer you submit the transaction id and we confirm it.', 6, 1);
