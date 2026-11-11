-- =============================================================================
-- 마이그레이션: 리뷰 자동 블라인드 임계치 설정
-- 적용: mysql freelance_market < db/migrations/20261111_review_autohide.sql
-- =============================================================================
USE `freelance_market`;

INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('review_auto_hide_reports', '0', '리뷰 자동 블라인드 신고 임계치(0=사용안함)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
