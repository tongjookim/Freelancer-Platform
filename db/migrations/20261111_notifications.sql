-- =============================================================================
-- 마이그레이션: 인앱 알림
-- 적용: mysql freelance_market < db/migrations/20261111_notifications.sql
-- =============================================================================
USE `freelance_market`;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `type`       VARCHAR(50)     NOT NULL,
  `title`      VARCHAR(150)    NOT NULL,
  `body`       VARCHAR(500)    NULL,
  `link`       VARCHAR(255)    NULL,
  `ref_type`   VARCHAR(40)     NULL,
  `ref_id`     BIGINT UNSIGNED NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `read_at`    DATETIME        NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `is_read`, `id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
