--
-- Clockwork shifts
--

CREATE TABLE llx_clockwork_shift (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_user integer NOT NULL,

  clockin datetime NOT NULL,
  clockout datetime DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0, -- 0=open, 1=closed

  worked_seconds integer NOT NULL DEFAULT 0,
  break_seconds integer NOT NULL DEFAULT 0,
  net_seconds integer NOT NULL DEFAULT 0,

  note text,
  ip varchar(64) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  ip_flagged smallint NOT NULL DEFAULT 0, -- 0=normal, 1=ip changed during shift

  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_clockwork_shift_user_clockin (fk_user, clockin),
  INDEX idx_clockwork_shift_status (status),
  INDEX idx_clockwork_shift_open_user (fk_user, status)
) ENGINE=innodb;

