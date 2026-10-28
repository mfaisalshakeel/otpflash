-- ===========================================================================
-- OTP Flash - database schema and seed data
-- Run by the installer. Safe to re-run on an empty database only.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --- key/value settings ----------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- admin users -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `last_login_at` DATETIME NULL,
  `two_factor` VARCHAR(10) NOT NULL DEFAULT 'off',
  `totp_secret` VARCHAR(64) NOT NULL DEFAULT '',
  `recovery_codes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`),
  UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- currencies ------------------------------------------------------------
-- rate_to_base is how much of the base currency one unit of this one buys,
-- because that is the direction every conversion goes: OTPGet bills in its
-- own currency and the shop prices in ours.
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL DEFAULT '',
  `rate_to_base` DECIMAL(18,8) NOT NULL DEFAULT 1,
  `is_base` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currencies_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- countries -------------------------------------------------------------
-- One row per country, whichever server or product it came from. OTPGet
-- numbers its countries per server, so "Pakistan" is a different id on
-- server 1 and on Premium; those ids live on the offer, and this row is what
-- the customer sees and searches. iso2 is what picks the flag.
CREATE TABLE IF NOT EXISTS `countries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `iso2` VARCHAR(2) NOT NULL DEFAULT '',
  `dial_code` VARCHAR(8) NOT NULL DEFAULT '',
  `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_countries_slug` (`slug`),
  KEY `ix_countries_active` (`is_active`, `is_popular`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- services: the apps a number is bought for -----------------------------
-- WhatsApp, Telegram, Google. `icon` names a brand in the bundled sprite and
-- an empty one draws the first letter on `color`.
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `match_key` VARCHAR(80) NOT NULL DEFAULT '',
  `name` VARCHAR(120) NOT NULL,
  `icon` VARCHAR(60) NOT NULL DEFAULT '',
  `color` VARCHAR(9) NOT NULL DEFAULT '',
  `site_domain` VARCHAR(120) NOT NULL DEFAULT '',
  `intro` MEDIUMTEXT NULL,
  `meta_title` VARCHAR(190) NOT NULL DEFAULT '',
  `meta_description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_slug` (`slug`),
  KEY `ix_services_match` (`match_key`),
  KEY `ix_services_active` (`is_active`, `is_popular`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- offers: one thing OTPGet sells, at our price ---------------------------
-- product is which OTPGet API it is bought from, and the three provider
-- columns are whatever that API needs to buy it - all a purchase reads:
--   sms      server = type 1-6, provider_country = country id, provider_service = service id
--   premium  provider_country = premium "server" (a country), provider_service = service id
--   cheap    server = group, provider_country = server id
--   email    server = 1 or 2, provider_country = mailbox domain,
--            provider_service = service code (1) or website (2)
--   rental   server = provider 1 or 2, provider_country = country code,
--            provider_service = duration in hours cost is in the provider's
-- currency, exactly as it was quoted; price is ours, in the base currency,
-- rebuilt from cost whenever the markup or the exchange rate moves - unless
-- an admin pinned price_override.
CREATE TABLE IF NOT EXISTS `offers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product` VARCHAR(12) NOT NULL,
  `server` VARCHAR(10) NOT NULL DEFAULT '',
  `provider_country` VARCHAR(40) NOT NULL DEFAULT '',
  `provider_service` VARCHAR(60) NOT NULL DEFAULT '',
  `provider_name` VARCHAR(190) NOT NULL DEFAULT '',
  `country_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NULL,
  `cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `cost_currency` VARCHAR(10) NOT NULL DEFAULT '',
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `price_override` DECIMAL(12,2) NULL,
  `stock` INT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `missing` TINYINT(1) NOT NULL DEFAULT 0,
  `synced_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offers_provider` (`product`, `server`, `provider_country`, `provider_service`),
  KEY `ix_offers_service` (`service_id`, `country_id`),
  KEY `ix_offers_country` (`country_id`),
  CONSTRAINT `fk_offers_country` FOREIGN KEY (`country_id`)
    REFERENCES `countries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offers_service` FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- payment methods -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `driver` VARCHAR(40) NOT NULL DEFAULT 'manual',
  `currency` VARCHAR(10) NOT NULL DEFAULT '',
  `config` TEXT NULL,
  `short_name` VARCHAR(20) NOT NULL DEFAULT '',
  `account_title` VARCHAR(120) NOT NULL DEFAULT '',
  `account_number` VARCHAR(120) NOT NULL DEFAULT '',
  `extra_label` VARCHAR(60) NOT NULL DEFAULT '',
  `extra_value` VARCHAR(190) NOT NULL DEFAULT '',
  `instructions` TEXT NULL,
  `logo_path` VARCHAR(190) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_payment_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- customers -------------------------------------------------------------
-- An account is optional for a number - a guest pays per order and keeps the
-- code - and required for a rental, which lives for weeks and has an inbox
-- to come back to. balance is the wallet, and wallet_transactions is the
-- ledger that explains every rupee of it.
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(10) NOT NULL DEFAULT 'active',
  `reset_hash` VARCHAR(64) NOT NULL DEFAULT '',
  `reset_expires_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `two_factor` TINYINT(1) NOT NULL DEFAULT 0,
  `google_id` VARCHAR(64) NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_google` (`google_id`),
  KEY `ix_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(12) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `balance_after` DECIMAL(12,2) NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `admin_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wallet_user` (`user_id`, `created_at`),
  KEY `ix_wallet_order` (`order_id`),
  CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wallet_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- orders ----------------------------------------------------------------
-- What the customer bought and paid for: a number, an email, a rental, a
-- rental's renewal, or money into the wallet (product = deposit). The names
-- are copied on when the order is placed, so an order still reads the same
-- after the offer, the country or the service is renamed or deleted. number
-- and otp_code are the current activation's - a phone number, or the address
-- for email OTP - kept here so the tracker and the admin list read one row.
-- pay_source says whether a gateway or the customer's wallet paid for it.
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `offer_id` INT UNSIGNED NULL,
  `product` VARCHAR(12) NOT NULL DEFAULT 'sms',
  `server` VARCHAR(10) NOT NULL DEFAULT '',
  `country_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NULL,
  `service_name` VARCHAR(120) NOT NULL DEFAULT '',
  `country_name` VARCHAR(120) NOT NULL DEFAULT '',
  `country_iso` VARCHAR(2) NOT NULL DEFAULT '',
  `label` VARCHAR(190) NOT NULL DEFAULT '',
  `whatsapp` VARCHAR(40) NOT NULL DEFAULT '',
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','paid','processing','completed','expired','cancelled','refunded','api_error')
      NOT NULL DEFAULT 'pending',
  `pay_source` VARCHAR(10) NOT NULL DEFAULT 'gateway',
  `meta` TEXT NULL,
  `activation_id` INT UNSIGNED NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `number` VARCHAR(190) NOT NULL DEFAULT '',
  `otp_code` VARCHAR(190) NOT NULL DEFAULT '',
  `api_error` VARCHAR(500) NOT NULL DEFAULT '',
  `sending_at` DATETIME NULL,
  `hold_until` DATETIME NULL,
  `quote_price` DECIMAL(12,2) NULL,
  `payment_method_id` INT UNSIGNED NULL,
  `trx_id` VARCHAR(120) NOT NULL DEFAULT '',
  `paid_amount` DECIMAL(12,2) NULL,
  `paid_at` DATETIME NULL,
  `payment_details` TEXT NULL,
  `gateway_details` TEXT NULL,
  `completed_at` DATETIME NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_code` (`code`),
  KEY `ix_orders_status` (`status`, `created_at`),
  KEY `ix_orders_trx` (`trx_id`),
  KEY `ix_orders_user` (`user_id`, `created_at`),
  KEY `ix_orders_offer` (`offer_id`),
  KEY `ix_orders_service` (`service_id`),
  KEY `ix_orders_country` (`country_id`),
  KEY `ix_orders_method` (`payment_method_id`),
  KEY `ix_orders_created` (`created_at`),
  KEY `ix_orders_hold` (`hold_until`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_offer` FOREIGN KEY (`offer_id`)
    REFERENCES `offers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_country` FOREIGN KEY (`country_id`)
    REFERENCES `countries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_service` FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_payment` FOREIGN KEY (`payment_method_id`)
    REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- activations: every number bought for an order --------------------------
-- An order can go through more than one: a number that never receives its
-- SMS is cancelled at OTPGet, refunded to our wallet, and replaced. Each one
-- keeps what it cost and whether that cost came back, which is what makes
-- the profit on an order honest.
CREATE TABLE IF NOT EXISTS `activations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `product` VARCHAR(12) NOT NULL DEFAULT 'sms',
  `server` VARCHAR(10) NOT NULL DEFAULT '',
  `provider_order_id` VARCHAR(80) NOT NULL DEFAULT '',
  `number` VARCHAR(190) NOT NULL DEFAULT '',
  `status` VARCHAR(12) NOT NULL DEFAULT 'waiting',
  `code` VARCHAR(190) NOT NULL DEFAULT '',
  `messages` TEXT NULL,
  `cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `cost_currency` VARCHAR(10) NOT NULL DEFAULT '',
  `cost_base` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `refunded` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `checked_at` DATETIME NULL,
  `received_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_activations_order` (`order_id`),
  KEY `ix_activations_open` (`status`, `expires_at`),
  KEY `ix_activations_provider` (`product`, `provider_order_id`),
  CONSTRAINT `fk_activations_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- rentals: numbers kept for days or months -------------------------------
-- Bought through an order like anything else; this row is what lives on
-- after it. provider_rental_id is OTPGet's numeric id, which renewing needs
-- and renting does not return - it is looked up afterwards.
CREATE TABLE IF NOT EXISTS `rentals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `provider` VARCHAR(4) NOT NULL DEFAULT '1',
  `provider_order_id` VARCHAR(80) NOT NULL DEFAULT '',
  `provider_rental_id` VARCHAR(40) NOT NULL DEFAULT '',
  `number` VARCHAR(40) NOT NULL DEFAULT '',
  `country_code` VARCHAR(8) NOT NULL DEFAULT '',
  `country_name` VARCHAR(120) NOT NULL DEFAULT '',
  `duration_hours` INT UNSIGNED NOT NULL DEFAULT 0,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(12) NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NULL,
  `checked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rentals_user` (`user_id`, `status`),
  KEY `ix_rentals_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_rentals_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rentals_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rental_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rental_id` INT UNSIGNED NOT NULL,
  `fingerprint` VARCHAR(64) NOT NULL,
  `sender` VARCHAR(120) NOT NULL DEFAULT '',
  `body` TEXT NULL,
  `code` VARCHAR(60) NOT NULL DEFAULT '',
  `received_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rental_messages` (`rental_id`, `fingerprint`),
  CONSTRAINT `fk_rental_messages_rental` FOREIGN KEY (`rental_id`)
    REFERENCES `rentals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- order activity log ----------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_order_logs_order` (`order_id`, `created_at`),
  CONSTRAINT `fk_order_logs_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- order_calls: every request made to OTPGet or a payment gateway for an
-- order, with what came back - the admin's answer to "what did they say?".
-- A poll that gets the same answer again bumps `repeats` and `last_at`
-- instead of writing a row every four seconds. Secrets are masked before
-- anything is written.
CREATE TABLE IF NOT EXISTS `order_calls` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT '',
  `party` VARCHAR(120) NOT NULL DEFAULT '',
  `action` VARCHAR(60) NOT NULL DEFAULT '',
  `ok` TINYINT(1) NOT NULL DEFAULT 0,
  `summary` VARCHAR(500) NOT NULL DEFAULT '',
  `request` TEXT NULL,
  `response` TEXT NULL,
  `http_status` INT NOT NULL DEFAULT 0,
  `ms` INT NOT NULL DEFAULT 0,
  `repeats` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `last_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_order_calls_order` (`order_id`, `id`),
  KEY `ix_order_calls_source` (`source`, `ok`, `id`),
  CONSTRAINT `fk_order_calls_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- content pages ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(100) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `content` MEDIUMTEXT NULL,
  `meta_title` VARCHAR(190) NOT NULL DEFAULT '',
  `meta_description` VARCHAR(255) NOT NULL DEFAULT '',
  `og_image` VARCHAR(190) NOT NULL DEFAULT '',
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `show_in_footer` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pages_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- blog --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(100) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `meta_title` VARCHAR(190) NOT NULL DEFAULT '',
  `meta_description` VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- content is HTML from the editor, written by an admin and printed as it is,
-- the same trust as a page. focus_keyword only feeds the SEO checklist on the
-- edit screen; nothing on the site prints it.
CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NULL,
  `slug` VARCHAR(160) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `excerpt` TEXT NULL,
  `content` MEDIUMTEXT NULL,
  `cover_path` VARCHAR(190) NOT NULL DEFAULT '',
  `meta_title` VARCHAR(190) NOT NULL DEFAULT '',
  `meta_description` VARCHAR(255) NOT NULL DEFAULT '',
  `focus_keyword` VARCHAR(120) NOT NULL DEFAULT '',
  `image_prompt` TEXT NULL,
  `image_alt` VARCHAR(190) NOT NULL DEFAULT '',
  `tags` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(10) NOT NULL DEFAULT 'draft',
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `author_name` VARCHAR(120) NOT NULL DEFAULT '',
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posts_slug` (`slug`),
  KEY `ix_posts_published` (`status`, `published_at`),
  KEY `ix_posts_category` (`category_id`),
  CONSTRAINT `fk_posts_category` FOREIGN KEY (`category_id`)
    REFERENCES `post_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- FAQs ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faqs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question` VARCHAR(255) NOT NULL,
  `answer` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_faqs_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- contact messages ------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `whatsapp` VARCHAR(40) NOT NULL DEFAULT '',
  `body` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_messages_read` (`is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- throttling ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(40) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rate_action_ip` (`action`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- notifications and mail ------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event` VARCHAR(60) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` VARCHAR(500) NOT NULL DEFAULT '',
  `link` VARCHAR(190) NOT NULL DEFAULT '',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notifications_read` (`is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event` VARCHAR(60) NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `body` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_templates_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event` VARCHAR(60) NOT NULL DEFAULT '',
  `recipient` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(190) NOT NULL DEFAULT '',
  `status` VARCHAR(12) NOT NULL DEFAULT 'sent',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_email_log_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- scheduled work, locks and the audit log --------------------------------
CREATE TABLE IF NOT EXISTS `cron_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task` VARCHAR(40) NOT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'cron',
  `ok` TINYINT(1) NOT NULL DEFAULT 1,
  `summary` VARCHAR(500) NOT NULL DEFAULT '',
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cron_runs_task` (`task`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `locks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `owner` VARCHAR(80) NOT NULL DEFAULT '',
  `acquired_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locks_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(60) NOT NULL,
  `actor_type` VARCHAR(12) NOT NULL DEFAULT 'system',
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_name` VARCHAR(80) NOT NULL DEFAULT '',
  `entity` VARCHAR(40) NOT NULL DEFAULT '',
  `entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `summary` VARCHAR(300) NOT NULL DEFAULT '',
  `severity` VARCHAR(8) NOT NULL DEFAULT 'info',
  `before_json` TEXT NULL,
  `after_json` TEXT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_time` (`created_at`),
  KEY `ix_audit_entity` (`entity`, `entity_id`),
  KEY `ix_audit_severity` (`severity`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
-- Seed data
-- ===========================================================================

INSERT INTO `settings` (`k`, `v`) VALUES
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
  ('geo_provider',          'auto'),
  ('geo_behind_cdn',        '0'),
  ('currency_auto_add',     '1'),
  ('currency_switcher',     '1'),
  ('app_version',           '')
ON DUPLICATE KEY UPDATE `k` = `k`;

INSERT INTO `currencies` (`code`, `name`, `symbol`, `rate_to_base`, `is_base`, `is_active`, `sort_order`, `updated_at`) VALUES
  ('PKR', 'Pakistani Rupee', 'Rs ', 1,   1, 1, 1, '2026-01-01 00:00:00'),
  ('USD', 'US Dollar',       '$',   278, 0, 1, 2, '2026-01-01 00:00:00')
ON DUPLICATE KEY UPDATE `code` = `code`;

INSERT INTO `payment_methods`
  (`name`, `short_name`, `account_title`, `account_number`, `sort_order`, `is_active`) VALUES
  ('JazzCash',      'JC', 'Your business name', '', 1, 0),
  ('EasyPaisa',     'EP', 'Your business name', '', 2, 0),
  ('Bank Transfer', 'BK', 'Your business name', '', 3, 0);

INSERT INTO `pages` (`slug`, `title`, `content`, `show_in_footer`, `sort_order`, `is_active`) VALUES
  ('about-us',        'About Us',        '<p>Tell your customers who you are.</p>', 1, 1, 1),
  ('privacy-policy',  'Privacy Policy',  '<p>Replace with your privacy policy.</p>',1, 2, 1),
  ('refund-policy',   'Refund Policy',   '<p>A number that never receives its SMS is replaced free of charge. If no number works, message us with your order code for a refund.</p>', 1, 3, 1),
  ('terms-of-service','Terms of Service','<p>Replace with your terms.</p>',         1, 4, 1)
ON DUPLICATE KEY UPDATE `slug` = `slug`;

INSERT INTO `post_categories` (`slug`, `name`, `description`, `sort_order`, `is_active`) VALUES
  ('guides', 'Guides', 'Step-by-step help with virtual numbers and OTP verification.', 1, 1),
  ('news',   'News',   'What is new on the shop.', 2, 1)
ON DUPLICATE KEY UPDATE `slug` = `slug`;

INSERT INTO `faqs` (`question`, `answer`, `sort_order`, `is_active`) VALUES
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
   'The ones shown when you buy. Automatic methods confirm by themselves; for a manual transfer you submit the transaction id and we confirm it.', 6, 1)
ON DUPLICATE KEY UPDATE `question` = `question`;
