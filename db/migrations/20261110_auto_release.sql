-- =============================================================================
-- 마이그레이션: 자동 승인(에스크로 자동 정산) 설정
-- 적용: mysql freelance_market < db/migrations/20261110_auto_release.sql
-- =============================================================================
USE `freelance_market`;

INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('auto_release_days', '7', '검수 대기(delivered) 자동 승인까지 일수 (0=비활성)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
