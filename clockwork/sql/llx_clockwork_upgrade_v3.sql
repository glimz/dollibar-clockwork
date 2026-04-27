--
-- Clockwork module upgrade v3.0
-- Adds: per-user exclusions, payslip mapping, decimal day precision
--

CREATE TABLE IF NOT EXISTS llx_clockwork_user_exclusion (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_user integer NOT NULL,
  exclude_compliance smallint NOT NULL DEFAULT 0,
  exclude_deductions smallint NOT NULL DEFAULT 0,
  notification_types varchar(255) DEFAULT NULL, -- CSV list of notification types or * for all
  reason varchar(255) DEFAULT NULL,
  valid_until datetime DEFAULT NULL,
  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_author integer DEFAULT NULL,
  UNIQUE KEY uk_clockwork_user_exclusion_user (fk_user),
  INDEX idx_clockwork_user_exclusion_entity (entity)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_clockwork_payslip_map (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_compliance integer NOT NULL,
  fk_salary integer NOT NULL,
  fk_user_author integer DEFAULT NULL,
  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_clockwork_payslip_compliance (fk_compliance),
  UNIQUE KEY uk_clockwork_payslip_salary (fk_salary),
  INDEX idx_clockwork_payslip_entity (entity)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

ALTER TABLE llx_clockwork_monthly_compliance
  MODIFY expected_days decimal(10,2) NOT NULL DEFAULT 0,
  MODIFY actual_days decimal(10,2) NOT NULL DEFAULT 0,
  MODIFY missed_days decimal(10,2) NOT NULL DEFAULT 0;
