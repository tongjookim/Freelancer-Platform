-- =============================================================================
-- 마이그레이션: 리뷰 신고/블라인드 + 이메일 아웃박스
-- 적용: mysql freelance_market < db/migrations/20261111_reviews_moderation.sql
-- =============================================================================
USE `freelance_market`;

-- 1. reviews 블라인드 상태
ALTER TABLE `reviews`
  ADD COLUMN `status`        ENUM('visible','hidden') NOT NULL DEFAULT 'visible' AFTER `comment`,
  ADD COLUMN `hidden_reason` VARCHAR(255)    NULL AFTER `status`,
  ADD COLUMN `hidden_by`     BIGINT UNSIGNED NULL AFTER `hidden_reason`,
  ADD COLUMN `hidden_at`     DATETIME        NULL AFTER `hidden_by`,
  ADD CONSTRAINT `fk_reviews_hiddenby` FOREIGN KEY (`hidden_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 2. review_reports
CREATE TABLE IF NOT EXISTS `review_reports` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id`   BIGINT UNSIGNED NOT NULL,
  `reporter_id` BIGINT UNSIGNED NOT NULL,
  `reason`      VARCHAR(500)    NOT NULL,
  `status`      ENUM('open','resolved','rejected') NOT NULL DEFAULT 'open',
  `note`        VARCHAR(255)    NULL,
  `handled_by`  BIGINT UNSIGNED NULL,
  `handled_at`  DATETIME        NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_review_reporter` (`review_id`, `reporter_id`),
  KEY `idx_reports_status` (`status`),
  CONSTRAINT `fk_reports_review`   FOREIGN KEY (`review_id`)   REFERENCES `reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reports_handler`  FOREIGN KEY (`handled_by`)  REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. email_outbox
CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `to_email`    VARCHAR(190)    NOT NULL,
  `subject`     VARCHAR(200)    NOT NULL,
  `body`        TEXT            NOT NULL,
  `status`      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error`       VARCHAR(255)    NULL,
  `sent_at`     DATETIME        NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_status` (`status`, `id`),
  CONSTRAINT `fk_outbox_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 이메일 알림 설정
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('email_notify_enabled', '0', '이메일 알림 발송 여부(1=발송, 0=사용안함)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
