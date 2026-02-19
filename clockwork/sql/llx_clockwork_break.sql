--
-- Clockwork breaks (multiple intervals per shift)
--

CREATE TABLE llx_clockwork_break (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_shift integer NOT NULL,

  break_start datetime NOT NULL,
  break_end datetime DEFAULT NULL,
  seconds integer NOT NULL DEFAULT 0,
  note text,

  datec datetime NOT NULL,
  tms timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_clockwork_break_shift (fk_shift),
  INDEX idx_clockwork_break_open (fk_shift, break_end)
) ENGINE=innodb;

