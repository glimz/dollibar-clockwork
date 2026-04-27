--
-- Clockwork module upgrade v5.0
-- Adds: idle tracking and in-app notifications
--

ALTER TABLE llx_clockwork_shift
  ADD COLUMN last_activity_at datetime DEFAULT NULL AFTER clockin,
  ADD COLUMN idle_notified_at datetime DEFAULT NULL AFTER last_activity_at,
  ADD COLUMN idle_notif_count integer NOT NULL DEFAULT 0 AFTER idle_notified_at;

CREATE TABLE IF NOT EXISTS llx_clockwork_notification (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_user integer NOT NULL,
  fk_shift integer DEFAULT NULL,
  notif_type varchar(64) NOT NULL,
  severity varchar(16) NOT NULL DEFAULT 'info',
  title varchar(255) NOT NULL,
  message text,
  meta_json text,
  is_read smallint NOT NULL DEFAULT 0,
  date_read datetime DEFAULT NULL,
  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clockwork_notif_user_read (fk_user, is_read),
  INDEX idx_clockwork_notif_user_date (fk_user, datec)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
