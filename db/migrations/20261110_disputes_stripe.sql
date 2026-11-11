-- =============================================================================
-- 마이그레이션: 분쟁/부분환불 + Stripe 웹훅 지원
-- 적용:  mysql freelance_market < db/migrations/20261110_disputes_stripe.sql
-- (schema.sql 을 새로 설치하는 경우에는 이 파일이 필요 없습니다.)
-- =============================================================================
USE `freelance_market`;

-- 1. orders.status / escrow_status 에 'partially_refunded' 추가 + disputed_at
ALTER TABLE `orders`
  MODIFY `status` ENUM('pending_payment','in_progress','delivered','revision_requested',
                       'completed','cancelled','disputed','refunded','partially_refunded')
         NOT NULL DEFAULT 'pending_payment',
  MODIFY `escrow_status` ENUM('none','held','released','refunded','partially_refunded')
         NOT NULL DEFAULT 'none',
  ADD COLUMN `disputed_at` DATETIME NULL AFTER `cancelled_at`;

-- 2. disputes
CREATE TABLE IF NOT EXISTS `disputes` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED NOT NULL,
  `opened_by`     BIGINT UNSIGNED NOT NULL,
  `reason`        TEXT            NOT NULL,
  `status`        ENUM('open','resolved','cancelled') NOT NULL DEFAULT 'open',
  `resolution`    ENUM('none','refund_full','refund_partial','release_to_freelancer')
                  NOT NULL DEFAULT 'none',
  `refund_amount` DECIMAL(12,2)   NULL,
  `note`          VARCHAR(255)    NULL,
  `resolved_by`   BIGINT UNSIGNED NULL,
  `resolved_at`   DATETIME        NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disputes_order` (`order_id`, `status`),
  CONSTRAINT `fk_disputes_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_disputes_opener` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_disputes_admin`  FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. payment_webhooks
CREATE TABLE IF NOT EXISTS `payment_webhooks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`    VARCHAR(40)     NOT NULL,
  `event_id`    VARCHAR(120)    NOT NULL,
  `event_type`  VARCHAR(120)    NULL,
  `order_id`    BIGINT UNSIGNED NULL,
  `payload`     JSON            NULL,
  `status`      ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
  `message`     VARCHAR(255)    NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`, `event_id`),
  KEY `idx_webhook_order` (`order_id`),
  CONSTRAINT `fk_webhook_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 환불 정책 설정값
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('client_self_refund_allowed', '1', '작업물 발송 전 의뢰인 자체 환불 허용(1=허용,0=관리자만)'),
  ('dispute_sla_days', '7', '분쟁 접수 후 관리자 처리 기한(일)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
