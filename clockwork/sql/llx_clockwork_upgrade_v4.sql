--
-- Clockwork module upgrade v4.0
-- Adds: dedicated payslip PDF storage mapping
--

ALTER TABLE llx_clockwork_payslip_map
  ADD COLUMN pdf_file varchar(255) DEFAULT NULL AFTER fk_salary;
