-- =====================================================================
--  Haryanvi AI Translator — MySQL schema
-- ---------------------------------------------------------------------
--  Target      : MySQL 8.0+ (also runs on MariaDB 10.6+)
--  Charset     : utf8mb4 / utf8mb4_unicode_ci
--                (utf8mb4_unicode_ci is used instead of utf8mb4_0900_ai_ci
--                 so the same file loads on MariaDB-based shared hosting)
--  Engine      : InnoDB (foreign keys + transactions required)
--
--  Load order  : schema.sql  ->  seed.sql
--
--  SECURITY NOTES
--   * No plain-text passwords are stored. Passwords are Argon2id/bcrypt
--     hashes produced by PHP password_hash().
--   * No plain-text AI provider keys are stored. `ai_providers.api_key_cipher`
--     holds an AES-256-GCM envelope; the master key lives only in
--     backend/config/config.php on the server filesystem.
--   * API tokens are stored as SHA-256 hashes; the plaintext token is shown
--     to the client exactly once at login.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. users
-- =====================================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `email`             VARCHAR(190)    NOT NULL,
  `password_hash`     VARCHAR(255)    NOT NULL,
  `display_name`      VARCHAR(100)    NOT NULL,
  `status`            ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
  `blocked_reason`    VARCHAR(255)        NULL DEFAULT NULL,
  -- NULL => inherit the global limit from `app_settings`
  `daily_limit`       INT UNSIGNED        NULL DEFAULT NULL,
  `monthly_limit`     INT UNSIGNED        NULL DEFAULT NULL,
  `registration_ip`   VARCHAR(45)         NULL DEFAULT NULL,
  `last_login_at`     TIMESTAMP           NULL DEFAULT NULL,
  `last_seen_at`      TIMESTAMP           NULL DEFAULT NULL,
  `deleted_at`        TIMESTAMP           NULL DEFAULT NULL,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_uuid`  (`uuid`),
  KEY `idx_users_status`      (`status`),
  KEY `idx_users_created_at`  (`created_at`),
  KEY `idx_users_last_seen`   (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. dialects  (regional Haryanvi profiles)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `dialects` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(40)  NOT NULL,
  `name_en`        VARCHAR(100) NOT NULL,
  `name_hi`        VARCHAR(100) NOT NULL,
  `region_label`   VARCHAR(150) NOT NULL,
  `description`    TEXT             NULL,
  -- Linguistic guidance injected verbatim into the AI system prompt.
  `prompt_notes`   TEXT             NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `is_default`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dialects_code` (`code`),
  KEY `idx_dialects_active`     (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3. tone_presets  (conversational registers)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `tone_presets` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                VARCHAR(40)  NOT NULL,
  `name_en`             VARCHAR(80)  NOT NULL,
  `name_hi`             VARCHAR(80)  NOT NULL,
  `description`         VARCHAR(255)     NULL,
  `prompt_instruction`  TEXT         NOT NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `is_default`          TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`          SMALLINT     NOT NULL DEFAULT 0,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tone_code` (`code`),
  KEY `idx_tone_active`     (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4. user_settings  (1:1 with users)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `dialect_id`          INT UNSIGNED        NULL DEFAULT NULL,
  `tone_code`           VARCHAR(40)     NOT NULL DEFAULT 'natural',
  `output_script`       ENUM('devanagari','roman') NOT NULL DEFAULT 'devanagari',
  `include_roman`       TINYINT(1)      NOT NULL DEFAULT 0,
  `default_source_lang` ENUM('auto','hi','en') NOT NULL DEFAULT 'auto',
  -- Privacy switches. `save_history = 0` disables server-side history fully.
  `save_history`        TINYINT(1)      NOT NULL DEFAULT 1,
  `cloud_sync`          TINYINT(1)      NOT NULL DEFAULT 0,
  `floating_enabled`    TINYINT(1)      NOT NULL DEFAULT 0,
  `keyboard_haptics`    TINYINT(1)      NOT NULL DEFAULT 1,
  `theme`               ENUM('system','dark','light') NOT NULL DEFAULT 'dark',
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_settings_dialect` (`dialect_id`),
  CONSTRAINT `fk_settings_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_settings_dialect`
    FOREIGN KEY (`dialect_id`) REFERENCES `dialects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5. dialect_vocabulary  (curated regional lexicon + idioms)
-- ---------------------------------------------------------------------
--  Used as *reference* material in the prompt. The engine never does
--  blind find-and-replace with these rows.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `dialect_vocabulary` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- NULL dialect_id => applies to every dialect (pan-Haryanvi).
  `dialect_id`       INT UNSIGNED        NULL DEFAULT NULL,
  `entry_type`       ENUM('word','phrase','idiom','slang','grammar') NOT NULL DEFAULT 'word',
  `source_lang`      ENUM('hi','en','hinglish') NOT NULL DEFAULT 'hi',
  `source_term`      VARCHAR(255)    NOT NULL,
  `haryanvi_term`    VARCHAR(255)    NOT NULL,
  `roman_term`       VARCHAR(255)        NULL DEFAULT NULL,
  `register`         ENUM('neutral','respectful','informal','funny') NOT NULL DEFAULT 'neutral',
  `usage_note`       VARCHAR(500)        NULL DEFAULT NULL,
  `example_source`   VARCHAR(500)        NULL DEFAULT NULL,
  `example_haryanvi` VARCHAR(500)        NULL DEFAULT NULL,
  -- Higher weight = stronger preference when assembling prompt context.
  `weight`           SMALLINT        NOT NULL DEFAULT 10,
  `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by_admin` INT UNSIGNED        NULL DEFAULT NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vocab_lookup`  (`dialect_id`, `is_active`, `source_term`),
  KEY `idx_vocab_source`  (`source_term`),
  KEY `idx_vocab_type`    (`entry_type`),
  CONSTRAINT `fk_vocab_dialect`
    FOREIGN KEY (`dialect_id`) REFERENCES `dialects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6. ai_providers
-- ---------------------------------------------------------------------
--  `api_key_cipher`  : base64(AES-256-GCM envelope). Never plaintext.
--  `priority`        : lower value tried first; enables fallback chains.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(100) NOT NULL,
  `slug`             VARCHAR(60)  NOT NULL,
  `driver`           ENUM('openai_compatible','anthropic','gemini') NOT NULL DEFAULT 'openai_compatible',
  `base_url`         VARCHAR(255) NOT NULL,
  `model`            VARCHAR(120) NOT NULL,
  `api_key_cipher`   TEXT             NULL DEFAULT NULL,
  `api_key_last4`    VARCHAR(8)       NULL DEFAULT NULL,
  `temperature`      DECIMAL(3,2) NOT NULL DEFAULT 0.30,
  `max_tokens`       INT UNSIGNED NOT NULL DEFAULT 1024,
  `timeout_seconds`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `max_retries`      TINYINT UNSIGNED  NOT NULL DEFAULT 2,
  `extra_headers`    TEXT             NULL DEFAULT NULL COMMENT 'JSON object of additional request headers',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 0,
  `is_default`       TINYINT(1)   NOT NULL DEFAULT 0,
  `priority`         SMALLINT     NOT NULL DEFAULT 100,
  `last_error`       TEXT             NULL DEFAULT NULL,
  `last_used_at`     TIMESTAMP        NULL DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_providers_slug` (`slug`),
  KEY `idx_providers_active`     (`is_active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7. api_tokens  (bearer tokens for the Android client)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)        NOT NULL COMMENT 'SHA-256 of the bearer token',
  `name`         VARCHAR(100)    NOT NULL DEFAULT 'android',
  `device_info`  VARCHAR(255)        NULL DEFAULT NULL,
  `last_used_at` TIMESTAMP           NULL DEFAULT NULL,
  `expires_at`   TIMESTAMP           NULL DEFAULT NULL,
  `revoked_at`   TIMESTAMP           NULL DEFAULT NULL,
  `created_ip`   VARCHAR(45)         NULL DEFAULT NULL,
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash`  (`token_hash`),
  KEY `idx_tokens_user`        (`user_id`, `revoked_at`),
  KEY `idx_tokens_expiry`      (`expires_at`),
  CONSTRAINT `fk_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 8. translation_history
-- ---------------------------------------------------------------------
--  Written ONLY when the user's `save_history` setting is enabled.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `translation_history` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `source_text`       TEXT            NOT NULL,
  `source_lang`       ENUM('auto','hi','en','hinglish') NOT NULL DEFAULT 'auto',
  `detected_lang`     VARCHAR(16)         NULL DEFAULT NULL,
  `dialect_id`        INT UNSIGNED        NULL DEFAULT NULL,
  `tone_code`         VARCHAR(40)     NOT NULL DEFAULT 'natural',
  `output_script`     ENUM('devanagari','roman') NOT NULL DEFAULT 'devanagari',
  `translated_text`   TEXT            NOT NULL,
  `roman_text`        TEXT                NULL DEFAULT NULL,
  `provider_id`       INT UNSIGNED        NULL DEFAULT NULL,
  `model`             VARCHAR(120)        NULL DEFAULT NULL,
  `source_channel`    ENUM('app','keyboard','floating','share','api') NOT NULL DEFAULT 'app',
  `is_favourite`      TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_history_uuid`      (`uuid`),
  KEY `idx_history_user_time`       (`user_id`, `created_at`),
  KEY `idx_history_user_fav`        (`user_id`, `is_favourite`, `created_at`),
  KEY `idx_history_dialect`         (`dialect_id`),
  CONSTRAINT `fk_history_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_history_dialect`
    FOREIGN KEY (`dialect_id`) REFERENCES `dialects` (`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_history_provider`
    FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 9. admin_users
-- =====================================================================
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(60)  NOT NULL,
  `email`           VARCHAR(190) NOT NULL,
  `password_hash`   VARCHAR(255) NOT NULL,
  `full_name`       VARCHAR(120) NOT NULL,
  `role`            ENUM('super_admin','admin','moderator') NOT NULL DEFAULT 'moderator',
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`    TIMESTAMP        NULL DEFAULT NULL,
  `last_login_at`   TIMESTAMP        NULL DEFAULT NULL,
  `last_login_ip`   VARCHAR(45)      NULL DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  UNIQUE KEY `uq_admin_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. translation_feedback  (quality reports from users)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `translation_feedback` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED     NULL DEFAULT NULL,
  `history_id`      BIGINT UNSIGNED     NULL DEFAULT NULL,
  `dialect_id`      INT UNSIGNED        NULL DEFAULT NULL,
  `source_text`     TEXT            NOT NULL,
  `translated_text` TEXT            NOT NULL,
  `suggested_text`  TEXT                NULL DEFAULT NULL,
  `reason`          ENUM('wrong_meaning','wrong_dialect','unnatural','script_issue','offensive','other')
                    NOT NULL DEFAULT 'other',
  `comment`         VARCHAR(1000)       NULL DEFAULT NULL,
  `status`          ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',
  `reviewed_by`     INT UNSIGNED        NULL DEFAULT NULL,
  `reviewed_at`     TIMESTAMP           NULL DEFAULT NULL,
  `review_note`     VARCHAR(500)        NULL DEFAULT NULL,
  `applied_vocab_id` BIGINT UNSIGNED    NULL DEFAULT NULL,
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_status`  (`status`, `created_at`),
  KEY `idx_feedback_user`    (`user_id`),
  KEY `idx_feedback_dialect` (`dialect_id`),
  CONSTRAINT `fk_feedback_user`
    FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)                ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_history`
    FOREIGN KEY (`history_id`)  REFERENCES `translation_history` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_dialect`
    FOREIGN KEY (`dialect_id`)  REFERENCES `dialects` (`id`)             ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_reviewer`
    FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_vocab`
    FOREIGN KEY (`applied_vocab_id`) REFERENCES `dialect_vocabulary` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 11. ai_usage_logs
-- ---------------------------------------------------------------------
--  Observability + quota source of truth. Deliberately stores NO
--  translated text and NO source text — only sizes and metadata.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED     NULL DEFAULT NULL,
  `provider_id`    INT UNSIGNED        NULL DEFAULT NULL,
  `endpoint`       VARCHAR(120)    NOT NULL DEFAULT '/api/v1/translate',
  `status`         ENUM('success','error','timeout','rate_limited','not_configured') NOT NULL,
  `http_status`    SMALLINT UNSIGNED   NULL DEFAULT NULL,
  `model`          VARCHAR(120)        NULL DEFAULT NULL,
  `request_chars`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `tokens_in`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `tokens_out`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `latency_ms`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `attempt_count`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `error_code`     VARCHAR(60)         NULL DEFAULT NULL,
  `error_message`  VARCHAR(500)        NULL DEFAULT NULL,
  `source_channel` VARCHAR(20)         NULL DEFAULT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usage_created`        (`created_at`),
  KEY `idx_usage_user_created`   (`user_id`, `created_at`),
  KEY `idx_usage_provider`       (`provider_id`, `created_at`),
  KEY `idx_usage_status`         (`status`, `created_at`),
  CONSTRAINT `fk_usage_user`
    FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_usage_provider`
    FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 12. usage_daily  (pre-aggregated counters for O(1) quota checks)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `usage_daily` (
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `usage_date`    DATE            NOT NULL,
  `translations`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `failed`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `chars`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_in`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `usage_date`),
  KEY `idx_usage_daily_date` (`usage_date`),
  CONSTRAINT `fk_usage_daily_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. rate_limits  (fixed-window counters; server-side only)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket_key`   VARCHAR(190) NOT NULL,
  `window_start` DATETIME     NOT NULL,
  `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket_key`, `window_start`),
  KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 14. app_settings  (key/value application configuration)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key`  VARCHAR(100) NOT NULL,
  `setting_value` TEXT            NULL,
  `value_type`   ENUM('string','text','int','bool','json') NOT NULL DEFAULT 'string',
  `group_name`   VARCHAR(60)  NOT NULL DEFAULT 'general',
  `label`        VARCHAR(150) NOT NULL,
  `description`  VARCHAR(500)     NULL,
  -- is_public = 1 => exposed through GET /api/v1/settings
  `is_public`    TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  KEY `idx_settings_group`  (`group_name`),
  KEY `idx_settings_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 15. admin_activity_logs  (audit trail)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `admin_activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED        NULL DEFAULT NULL,
  `action`      VARCHAR(100)    NOT NULL,
  `entity_type` VARCHAR(60)         NULL DEFAULT NULL,
  `entity_id`   VARCHAR(60)         NULL DEFAULT NULL,
  `details`     TEXT                NULL DEFAULT NULL COMMENT 'JSON payload',
  `ip_address`  VARCHAR(45)         NULL DEFAULT NULL,
  `user_agent`  VARCHAR(255)        NULL DEFAULT NULL,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_admin`  (`admin_id`, `created_at`),
  KEY `idx_audit_action` (`action`, `created_at`),
  CONSTRAINT `fk_audit_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 16. schema_migrations  (applied by bin/migrate.php)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`    VARCHAR(60) NOT NULL,
  `applied_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
