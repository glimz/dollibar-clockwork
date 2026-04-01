--
-- Clockwork module upgrade v2.0
-- Adds: Monthly compliance, user tags, Discord integration, email notifications
--

-- Add user_param entries for Clockwork settings (stored in user_param table)
-- No schema changes needed for user params, but documenting expected keys:
-- CLOCKWORK_EXPECTED_MONTHLY_HOURS - integer, default 160
-- CLOCKWORK_CONTRACT_TYPE - string: 'full_time', 'part_time', 'contract'
-- CLOCKWORK_DISCORD_USERNAME - string: Discord username
-- CLOCKWORK_SLACK_ID - string: Slack user ID
-- CLOCKWORK_PHONE_NUMBER - string: Phone for SMS
-- CLOCKWORK_DEPARTMENT - string: Department name
-- CLOCKWORK_MONTHLY_SALARY - decimal: Base monthly salary

-- Create user tags table
CREATE TABLE IF NOT EXISTS llx_clockwork_user_tag (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  label varchar(100) NOT NULL,
  color varchar(7) DEFAULT '#3498db', -- Hex color
  description text,
  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_label (label)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- Create user-tag association table
CREATE TABLE IF NOT EXISTS llx_clockwork_user_tag_assoc (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_user integer NOT NULL,
  fk_tag integer NOT NULL,
  UNIQUE KEY uk_user_tag (fk_user, fk_tag),
  INDEX idx_clockwork_user_tag_user (fk_user),
  INDEX idx_clockwork_user_tag_tag (fk_tag)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- Create monthly compliance records table
CREATE TABLE IF NOT EXISTS llx_clockwork_monthly_compliance (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_user integer NOT NULL,
  year_month varchar(7) NOT NULL, -- Format: YYYY-MM
  expected_hours decimal(10,2) NOT NULL DEFAULT 0,
  actual_hours decimal(10,2) NOT NULL DEFAULT 0,
  expected_days integer NOT NULL DEFAULT 0,
  actual_days integer NOT NULL DEFAULT 0,
  missed_days integer NOT NULL DEFAULT 0,
  compliance_pct decimal(5,2) NOT NULL DEFAULT 0, -- (actual/expected)*100
  status varchar(20) NOT NULL DEFAULT 'red', -- green, yellow, red
  deduction_pct decimal(5,2) NOT NULL DEFAULT 0, -- Percentage to deduct
  deduction_amount decimal(10,2) NOT NULL DEFAULT 0, -- Amount to deduct
  monthly_salary decimal(10,2) NOT NULL DEFAULT 0,
  is_approved smallint NOT NULL DEFAULT 0, -- 0=pending, 1=approved
  approved_by integer DEFAULT NULL,
  approved_date datetime DEFAULT NULL,
  note text,
  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month (fk_user, year_month),
  INDEX idx_clockwork_compliance_month (year_month),
  INDEX idx_clockwork_compliance_status (status)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- Create email notification log table
CREATE TABLE IF NOT EXISTS llx_clockwork_email_log (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_user integer NOT NULL,
  email_type varchar(50) NOT NULL, -- missed_clockin, monthly_summary, deduction_warning, etc.
  subject varchar(255) NOT NULL,
  body text,
  status varchar(20) NOT NULL DEFAULT 'pending', -- pending, sent, failed
  error_message text,
  datec datetime NOT NULL,
  sent_date datetime DEFAULT NULL,
  INDEX idx_clockwork_email_user (fk_user),
  INDEX idx_clockwork_email_type (email_type),
  INDEX idx_clockwork_email_status (status)
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- Add index for user_param lookups (if not exists)
-- ALTER TABLE llx_user_param ADD INDEX idx_user_param_param (fk_user, param);