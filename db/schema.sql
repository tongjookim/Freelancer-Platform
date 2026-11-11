-- =============================================================================
-- 프리랜서 용역 플랫폼 (양면 시장) - MySQL 스키마
-- 엔진: InnoDB (트랜잭션/행 잠금 필수) / 문자셋: utf8mb4
-- 금액: DECIMAL(12,2)  (통화 KRW 기본, 필요 시 확장)
-- 실행:  mysql -u root -p < db/schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `freelance_market`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `freelance_market`;

-- -----------------------------------------------------------------------------
-- 1. users : 의뢰인(client) / 프리랜서(freelancer) / 관리자(admin)
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(190)    NOT NULL,
  `password_hash`   VARCHAR(255)    NOT NULL,
  `name`            VARCHAR(80)     NOT NULL,
  `phone`           VARCHAR(30)     NULL,
  `role`            ENUM('client','freelancer','admin') NOT NULL DEFAULT 'client',
  `status`          ENUM('active','suspended','withdrawn') NOT NULL DEFAULT 'active',
  -- 프리랜서 정산용 계좌(암호화 저장 권장)
  `bank_name`       VARCHAR(60)     NULL,
  `bank_account`    VARCHAR(80)     NULL,
  `bank_holder`     VARCHAR(60)     NULL,
  `email_verified_at` DATETIME      NULL,
  `last_login_at`   DATETIME        NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. freelancer_profiles : 프리랜서 프로필 (users.role = 'freelancer')
-- -----------------------------------------------------------------------------
CREATE TABLE `freelancer_profiles` (
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `headline`        VARCHAR(150)    NULL,                 -- 한 줄 소개
  `bio`             TEXT            NULL,                 -- 상세 소개
  `skills`          JSON            NULL,                 -- ["PHP","React", ...]
  `hourly_rate`     DECIMAL(12,2)   NULL,
  `min_project_amount` DECIMAL(12,2) NULL,
  `rating_avg`      DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
  `rating_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `completed_orders` INT UNSIGNED   NOT NULL DEFAULT 0,
  `is_featured`     TINYINT(1)      NOT NULL DEFAULT 0,
  `approved_at`     DATETIME        NULL,                 -- 관리자 심사 승인
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_fp_featured` (`is_featured`, `rating_avg`),
  CONSTRAINT `fk_fp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. categories : 서비스/프로젝트 카테고리 (자기참조 트리)
-- -----------------------------------------------------------------------------
CREATE TABLE `categories` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NULL,
  `name`      VARCHAR(80)  NOT NULL,
  `slug`      VARCHAR(100) NOT NULL,
  `sort_order` INT         NOT NULL DEFAULT 0,
  `is_active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. portfolios : 프리랜서 포트폴리오
-- -----------------------------------------------------------------------------
CREATE TABLE `portfolios` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `title`       VARCHAR(150)    NOT NULL,
  `description` TEXT            NULL,
  `thumbnail`   VARCHAR(255)    NULL,
  `link_url`    VARCHAR(255)    NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_portfolios_freelancer` (`freelancer_id`),
  CONSTRAINT `fk_portfolios_user` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. services : 프리랜서가 등록하는 판매 상품(용역)
-- -----------------------------------------------------------------------------
CREATE TABLE `services` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `category_id`   INT UNSIGNED    NULL,
  `title`         VARCHAR(150)    NOT NULL,
  `description`   TEXT            NULL,
  `price`         DECIMAL(12,2)   NOT NULL,
  `delivery_days` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `status`        ENUM('draft','active','paused','closed') NOT NULL DEFAULT 'draft',
  `view_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_services_freelancer` (`freelancer_id`),
  KEY `idx_services_category_status` (`category_id`, `status`),
  CONSTRAINT `fk_services_user` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_services_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. projects : 의뢰인이 작성하는 작업 요청서
-- -----------------------------------------------------------------------------
CREATE TABLE `projects` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`   BIGINT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED    NULL,
  `title`       VARCHAR(150)    NOT NULL,
  `description` TEXT            NULL,
  `budget`      DECIMAL(12,2)   NULL,
  `deadline`    DATE            NULL,
  `attachment`  VARCHAR(255)    NULL,
  `status`      ENUM('open','in_progress','closed','cancelled') NOT NULL DEFAULT 'open',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_projects_client` (`client_id`),
  KEY `idx_projects_category_status` (`category_id`, `status`),
  CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. proposals : 프리랜서의 작업 지원(입찰)
-- -----------------------------------------------------------------------------
CREATE TABLE `proposals` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`    BIGINT UNSIGNED NOT NULL,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `price`         DECIMAL(12,2)   NOT NULL,
  `delivery_days` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `message`       TEXT NULL,
  `status`        ENUM('submitted','accepted','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proposal_project_freelancer` (`project_id`, `freelancer_id`),
  KEY `idx_proposals_freelancer` (`freelancer_id`),
  CONSTRAINT `fk_proposals_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_proposals_freelancer` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. orders : 계약(에스크로의 기준 단위) *** 핵심 테이블 ***
--    amount = 의뢰인이 예치하는 총액(gross)
--    승인 시점에 fee_rate / fee_amount / net_amount 를 확정 스냅샷으로 기록
-- -----------------------------------------------------------------------------
CREATE TABLE `orders` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`      VARCHAR(30)     NOT NULL,               -- 외부 노출용 주문번호 (예: ORD20261110-XXXXXX)
  `service_id`    BIGINT UNSIGNED NULL,                   -- 서비스 직접 구매
  `project_id`    BIGINT UNSIGNED NULL,                   -- 프로젝트 지원 수락
  `proposal_id`   BIGINT UNSIGNED NULL,
  `client_id`     BIGINT UNSIGNED NOT NULL,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `title`         VARCHAR(150)    NOT NULL,
  `currency`      CHAR(3)         NOT NULL DEFAULT 'KRW',
  `amount`        DECIMAL(12,2)   NOT NULL,               -- 총 결제(예치) 금액
  `fee_rate`      DECIMAL(5,2)    NULL,                   -- 정산 시 수수료율(%) 스냅샷
  `fee_amount`    DECIMAL(12,2)   NULL,                   -- 플랫폼 수수료
  `net_amount`    DECIMAL(12,2)   NULL,                   -- 프리랜서 지급액 = amount - fee_amount
  `status`        ENUM('pending_payment','in_progress','delivered','revision_requested',
                       'completed','cancelled','disputed','refunded','partially_refunded')
                  NOT NULL DEFAULT 'pending_payment',
  `escrow_status` ENUM('none','held','released','refunded','partially_refunded')
                  NOT NULL DEFAULT 'none',
  `delivery_days` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `due_date`      DATE            NULL,
  `paid_at`       DATETIME        NULL,                   -- 예치(결제) 시각
  `delivered_at`  DATETIME        NULL,                   -- 작업물 발송 시각
  `approved_at`   DATETIME        NULL,                   -- 최종 승인(정산) 시각
  `settled_at`    DATETIME        NULL,
  `cancelled_at`  DATETIME        NULL,
  `disputed_at`   DATETIME        NULL,                   -- 분쟁 제기 시각
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_no` (`order_no`),
  KEY `idx_orders_client` (`client_id`, `status`),
  KEY `idx_orders_freelancer` (`freelancer_id`, `status`),
  KEY `idx_orders_escrow` (`escrow_status`),
  CONSTRAINT `fk_orders_service`    FOREIGN KEY (`service_id`)    REFERENCES `services` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_orders_project`    FOREIGN KEY (`project_id`)    REFERENCES `projects` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_orders_proposal`   FOREIGN KEY (`proposal_id`)   REFERENCES `proposals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_client`     FOREIGN KEY (`client_id`)     REFERENCES `users` (`id`),
  CONSTRAINT `fk_orders_freelancer` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. payments : PG 결제 원장 (에스크로 예치의 근거)
-- -----------------------------------------------------------------------------
CREATE TABLE `payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        BIGINT UNSIGNED NOT NULL,
  `client_id`       BIGINT UNSIGNED NOT NULL,
  `pg_provider`     VARCHAR(40)     NOT NULL DEFAULT 'manual', -- toss, inicis, kakao ...
  `pg_tx_id`        VARCHAR(120)    NULL,                      -- PG 거래번호
  `amount`          DECIMAL(12,2)   NOT NULL,
  `method`          VARCHAR(40)     NULL,                      -- card, transfer, vbank ...
  `status`          ENUM('ready','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'ready',
  `idempotency_key` VARCHAR(120)    NULL,                      -- 중복 결제 방지 키
  `raw_response`    JSON            NULL,
  `paid_at`         DATETIME        NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_idem` (`idempotency_key`),
  UNIQUE KEY `uq_payments_pg_tx` (`pg_provider`, `pg_tx_id`),
  KEY `idx_payments_order` (`order_id`),
  CONSTRAINT `fk_payments_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. escrow_transactions : 에스크로 자금 이동 원장 (append-only, 감사 추적)
--     type: deposit(예치) / release(지급) / fee(수수료) / refund(환불)
--     부호 규약: direction in = 플랫폼 에스크로로 유입, out = 유출
-- -----------------------------------------------------------------------------
CREATE TABLE `escrow_transactions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`        BIGINT UNSIGNED NULL,                -- 관련 당사자
  `type`           ENUM('deposit','release','fee','refund') NOT NULL,
  `direction`      ENUM('in','out') NOT NULL,
  `amount`         DECIMAL(12,2)   NOT NULL,
  `balance_after`  DECIMAL(12,2)   NULL,                -- 해당 주문의 에스크로 잔액(참고용)
  `idempotency_key` VARCHAR(140)   NOT NULL,            -- 중복 처리 방지
  `memo`           VARCHAR(255)    NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_escrow_idem` (`idempotency_key`),
  KEY `idx_escrow_order` (`order_id`, `type`),
  CONSTRAINT `fk_escrow_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_escrow_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. settlements : 프리랜서 정산 내역 (주문당 1건)
-- -----------------------------------------------------------------------------
CREATE TABLE `settlements` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED NOT NULL,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `gross_amount`  DECIMAL(12,2)   NOT NULL,             -- 예치 총액
  `fee_rate`      DECIMAL(5,2)    NOT NULL,
  `fee_amount`    DECIMAL(12,2)   NOT NULL,             -- 플랫폼 수수료 수익
  `net_amount`    DECIMAL(12,2)   NOT NULL,             -- 프리랜서 지급액
  `status`        ENUM('pending','processing','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `bank_snapshot` JSON            NULL,                 -- 정산 시점 계좌 정보 스냅샷
  `paid_at`       DATETIME        NULL,
  `memo`          VARCHAR(255)    NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_order` (`order_id`),
  KEY `idx_settlements_freelancer_status` (`freelancer_id`, `status`),
  CONSTRAINT `fk_settlements_order`      FOREIGN KEY (`order_id`)      REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settlements_freelancer` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11-1. disputes : 에스크로 분쟁 (주문당 1건 활성)
-- -----------------------------------------------------------------------------
CREATE TABLE `disputes` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED NOT NULL,
  `opened_by`     BIGINT UNSIGNED NOT NULL,             -- 제기자(의뢰인/프리랜서)
  `reason`        TEXT            NOT NULL,
  `status`        ENUM('open','resolved','cancelled') NOT NULL DEFAULT 'open',
  `resolution`    ENUM('none','refund_full','refund_partial','release_to_freelancer')
                  NOT NULL DEFAULT 'none',
  `refund_amount` DECIMAL(12,2)   NULL,                 -- 부분 환불 시 의뢰인 반환액
  `note`          VARCHAR(255)    NULL,                 -- 관리자 처리 메모
  `resolved_by`   BIGINT UNSIGNED NULL,                 -- 처리 관리자
  `resolved_at`   DATETIME        NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disputes_order` (`order_id`, `status`),
  CONSTRAINT `fk_disputes_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_disputes_opener` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_disputes_admin`  FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11-2. payment_webhooks : PG 웹훅 원본 보관 (서명검증/멱등 처리)
--   UNIQUE(provider, event_id) 로 동일 이벤트 재수신을 차단한다.
-- -----------------------------------------------------------------------------
CREATE TABLE `payment_webhooks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`    VARCHAR(40)     NOT NULL,               -- stripe 등
  `event_id`    VARCHAR(120)    NOT NULL,               -- evt_...
  `event_type`  VARCHAR(120)    NULL,                   -- payment_intent.succeeded ...
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

-- -----------------------------------------------------------------------------
-- 12. deliverables : 프리랜서 작업물 발송 이력
-- -----------------------------------------------------------------------------
CREATE TABLE `deliverables` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED NOT NULL,
  `freelancer_id` BIGINT UNSIGNED NOT NULL,
  `message`       TEXT            NULL,
  `file_path`     VARCHAR(255)    NULL,
  `version`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deliverables_order` (`order_id`),
  CONSTRAINT `fk_deliverables_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deliverables_user`  FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. messages : 주문 기반 1:1 메시지
-- -----------------------------------------------------------------------------
CREATE TABLE `messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   BIGINT UNSIGNED NOT NULL,
  `sender_id`  BIGINT UNSIGNED NOT NULL,
  `content`    TEXT            NOT NULL,
  `attachment` VARCHAR(255)    NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_messages_order` (`order_id`, `created_at`),
  CONSTRAINT `fk_messages_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. reviews : 완료 주문에 대한 상호 평가
-- -----------------------------------------------------------------------------
CREATE TABLE `reviews` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    BIGINT UNSIGNED NOT NULL,
  `reviewer_id` BIGINT UNSIGNED NOT NULL,
  `reviewee_id` BIGINT UNSIGNED NOT NULL,
  `rating`      TINYINT UNSIGNED NOT NULL,   -- 1~5
  `comment`     TEXT NULL,
  `status`      ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
  `hidden_reason` VARCHAR(255)  NULL,
  `hidden_by`   BIGINT UNSIGNED NULL,
  `hidden_at`   DATETIME        NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_order_reviewer` (`order_id`, `reviewer_id`),
  KEY `idx_reviews_reviewee` (`reviewee_id`, `status`),
  CONSTRAINT `fk_reviews_order`    FOREIGN KEY (`order_id`)    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reviews_reviewee` FOREIGN KEY (`reviewee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reviews_hiddenby` FOREIGN KEY (`hidden_by`)   REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14-2. review_reports : 리뷰 신고
-- -----------------------------------------------------------------------------
CREATE TABLE `review_reports` (
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

-- -----------------------------------------------------------------------------
-- 14-1. notifications : 인앱 알림
-- -----------------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,             -- 수신자
  `type`       VARCHAR(50)     NOT NULL,             -- order.paid, order.delivered, dispute.opened ...
  `title`      VARCHAR(150)    NOT NULL,
  `body`       VARCHAR(500)    NULL,
  `link`       VARCHAR(255)    NULL,                 -- 프론트 이동 경로 (예: /orders/12)
  `ref_type`   VARCHAR(40)     NULL,                 -- order / dispute / settlement ...
  `ref_id`     BIGINT UNSIGNED NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `read_at`    DATETIME        NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `is_read`, `id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14-3. email_outbox : 이메일 발송 큐 (크론이 순차 발송)
-- -----------------------------------------------------------------------------
CREATE TABLE `email_outbox` (
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

-- -----------------------------------------------------------------------------
-- 15. platform_settings : 수수료율 등 플랫폼 설정 (key-value)
-- -----------------------------------------------------------------------------
CREATE TABLE `platform_settings` (
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `description`   VARCHAR(255) NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. audit_logs : 관리자/민감 작업 감사 로그
-- -----------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`   BIGINT UNSIGNED NULL,
  `action`     VARCHAR(80)     NOT NULL,   -- escrow.release, settings.update ...
  `target_type` VARCHAR(40)    NULL,
  `target_id`  BIGINT UNSIGNED NULL,
  `meta`       JSON            NULL,
  `ip`         VARCHAR(45)     NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_id`),
  KEY `idx_audit_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 초기 데이터
-- =============================================================================
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('default_fee_rate', '10.00', '기본 플랫폼 수수료율(%)'),
  ('min_payout_amount', '10000', '최소 정산 지급 금액(원)'),
  ('settlement_cycle', 'weekly', '정산 주기 (daily/weekly/monthly)'),
  ('client_self_refund_allowed', '1', '작업물 발송 전 의뢰인 자체 환불 허용(1=허용,0=관리자만)'),
  ('dispute_sla_days', '7', '분쟁 접수 후 관리자 처리 기한(일)'),
  ('auto_release_days', '7', '검수 대기(delivered) 자동 승인까지 일수 (0=비활성)'),
  ('email_notify_enabled', '0', '이메일 알림 발송 여부(1=발송, 0=사용안함)'),
  ('review_auto_hide_reports', '0', '리뷰 자동 블라인드 신고 임계치(0=사용안함)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `categories` (`name`, `slug`, `sort_order`) VALUES
  ('디자인', 'design', 1),
  ('개발', 'development', 2),
  ('마케팅', 'marketing', 3),
  ('번역/통역', 'translation', 4),
  ('문서/글쓰기', 'writing', 5)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
