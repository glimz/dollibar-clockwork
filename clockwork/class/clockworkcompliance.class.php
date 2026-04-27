<?php
/**
 * Monthly compliance calculation class.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_email.lib.php';
require_once DOL_DOCUMENT_ROOT.'/salaries/class/salary.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

class ClockworkCompliance
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Calculate monthly compliance for a user.
	 *
	 * @param int    $userId    User ID
	 * @param string $yearMonth Year-month (YYYY-MM)
	 * @return array|false      Compliance data or false on error
	 */
	public function calculateMonthlyCompliance($userId, $yearMonth)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			$this->error = 'Invalid user id';
			return false;
		}

		if (clockworkIsUserExcludedFromCompliance($this->db, $userId)) {
			return false;
		}

		// Get user's expected monthly hours
		$expectedHours = (float) $this->getUserParam($userId, 'CLOCKWORK_EXPECTED_MONTHLY_HOURS', 160);
		$monthlySalary = (float) $this->getUserParam($userId, 'CLOCKWORK_MONTHLY_SALARY', 0);
		$contractType = $this->getUserParam($userId, 'CLOCKWORK_CONTRACT_TYPE', 'full_time');
		$hoursPerDay = (float) $this->getUserParam($userId, 'CLOCKWORK_HOURS_PER_DAY', getDolGlobalString('CLOCKWORK_HOURS_PER_DAY', '8'));
		if ($hoursPerDay <= 0) $hoursPerDay = 8.0;

		// Calculate expected working days based on expected hours and hours per day
		$expectedDays = $hoursPerDay > 0 ? $expectedHours / $hoursPerDay : 0;
		$expectedDays = round($expectedDays, 2);
		
		// Also get calendar weekdays for reference
		$calendarWeekdays = $this->getCalendarWeekdays($yearMonth, $contractType);

		// Get month start and end timestamps
		$monthStart = strtotime($yearMonth . '-01 00:00:00');
		$monthEnd = strtotime('+1 month', $monthStart);

		// Get all closed shifts for this user in the month
		$sql = 'SELECT s.rowid, s.clockin, s.clockout, s.net_seconds, s.worked_seconds, s.break_seconds';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
		$sql .= ' WHERE s.fk_user = ' . ((int) $userId);
		$sql .= ' AND s.status = 1'; // Closed shifts only
		$sql .= " AND s.clockin >= '" . $this->db->idate($monthStart) . "'";
		$sql .= " AND s.clockin < '" . $this->db->idate($monthEnd) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$totalNetSeconds = 0;
		$daysWorked = array();

		while ($obj = $this->db->fetch_object($resql)) {
			$totalNetSeconds += (int) $obj->net_seconds;
			$clockinDate = date('Y-m-d', $this->db->jdate($obj->clockin));
			$daysWorked[$clockinDate] = true;
		}

		$actualHours = $totalNetSeconds / 3600;
		
		// Calculate actual days based on hours worked (8 hours = 1 day)
		$actualDays = $hoursPerDay > 0 ? $actualHours / $hoursPerDay : 0;
		$actualDays = round($actualDays, 2); // Allow partial days
		
		// Also keep track of calendar days worked for reporting
		$calendarDaysWorked = count($daysWorked);
		
		$missedDays = max(0, $expectedDays - $actualDays);

		// Calculate compliance percentage
		$compliancePct = $expectedHours > 0 ? ($actualHours / $expectedHours) * 100 : 0;

		// Determine status
		$minComplianceForDeduction = (float) getDolGlobalString('CLOCKWORK_DEDUCTION_MIN_COMPLIANCE', '90');
		$deductionPercentPerDay = (float) $this->getUserParam($userId, 'CLOCKWORK_DEDUCTION_PERCENT_PER_DAY', getDolGlobalString('CLOCKWORK_DEDUCTION_PERCENT_PER_MISSED_DAY', '10'));
		if ($deductionPercentPerDay < 0) $deductionPercentPerDay = 0;
		$maxDeductionPercent = (float) getDolGlobalString('CLOCKWORK_DEDUCTION_MAX_PERCENT', '100');
		if ($maxDeductionPercent <= 0) $maxDeductionPercent = 100;

		if ($compliancePct >= 100) {
			$status = 'green'; // Met or exceeded target
			$deductionPct = 0;
		} elseif ($compliancePct >= $minComplianceForDeduction) {
			$status = 'yellow'; // Within 10% of target
			$deductionPct = 0; // No deduction yet, but warning
		} else {
			$status = 'red'; // Below target
			$deductionPct = min($maxDeductionPercent, $missedDays * $deductionPercentPerDay);
		}

		if (clockworkIsUserExcludedFromDeductions($this->db, $userId)) {
			$deductionPct = 0;
		}

		// Calculate deduction amount
		$deductionAmount = $monthlySalary > 0 ? ($monthlySalary * $deductionPct / 100) : 0;

		$result = array(
			'user_id' => $userId,
			'year_month' => $yearMonth,
			'expected_hours' => $expectedHours,
			'actual_hours' => round($actualHours, 2),
			'expected_days' => $expectedDays,
			'actual_days' => $actualDays,
			'missed_days' => $missedDays,
			'compliance_pct' => round($compliancePct, 2),
			'status' => $status,
			'deduction_pct' => $deductionPct,
			'deduction_amount' => round($deductionAmount, 2),
			'monthly_salary' => $monthlySalary,
			'contract_type' => $contractType,
			// Additional details for transparency
			'hours_per_day' => $hoursPerDay,
			'calendar_days_worked' => $calendarDaysWorked,
			'deduction_percent_per_day' => $deductionPercentPerDay,
			'deduction_min_compliance' => $minComplianceForDeduction,
			'calendar_weekdays' => $calendarWeekdays,
			'calculation_method' => 'hour_based', // hour_based vs calendar_based
		);

		// Save to database
		$this->saveCompliance($result);

		return $result;
	}

	/**
	 * Calculate compliance for all users.
	 *
	 * @param string $yearMonth Year-month (YYYY-MM)
	 * @return array            Array of compliance results
	 */
	public function calculateAllUsersCompliance($yearMonth)
	{
		global $conf;

		// Get all active users with clockwork rights
		$rightClockId = 500202;
		$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname, u.email";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user u";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_rights ur ON (ur.fk_user = u.rowid AND ur.fk_id = " . ((int) $rightClockId) . ")";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user ugu ON (ugu.fk_user = u.rowid)";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_rights ugr ON (ugr.fk_usergroup = ugu.fk_usergroup AND ugr.fk_id = " . ((int) $rightClockId) . ")";
		$sql .= " WHERE u.entity = " . ((int) $conf->entity);
		$sql .= " AND u.statut = 1"; // Active users only
		$sql .= " AND (ur.rowid IS NOT NULL OR ugr.rowid IS NOT NULL OR u.admin = 1)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$results = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$userId = (int) $obj->rowid;
			if (clockworkIsUserExcludedFromCompliance($this->db, $userId)) {
				continue;
			}
			$compliance = $this->calculateMonthlyCompliance($userId, $yearMonth);
			if ($compliance !== false) {
				$compliance['name'] = trim($obj->firstname . ' ' . $obj->lastname);
				$compliance['login'] = $obj->login;
				$compliance['email'] = $obj->email;
				$results[] = $compliance;
			}
		}

		return $results;
	}

	/**
	 * Get compliance status for a user.
	 *
	 * @param int    $userId    User ID
	 * @param string $yearMonth Year-month (YYYY-MM)
	 * @return array|false      Compliance data or false if not found
	 */
	public function getComplianceStatus($userId, $yearMonth)
	{
		$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance';
		$sql .= ' WHERE fk_user = ' . ((int) $userId);
		$sql .= " AND `year_month` = '" . $this->db->escape($yearMonth) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) return false;

		$obj = $this->db->fetch_object($resql);
		if (!$obj) return false;

		return array(
			'rowid' => (int) $obj->rowid,
			'user_id' => (int) $obj->fk_user,
			'year_month' => $obj->year_month,
			'expected_hours' => (float) $obj->expected_hours,
			'actual_hours' => (float) $obj->actual_hours,
			'expected_days' => (float) $obj->expected_days,
			'actual_days' => (float) $obj->actual_days,
			'missed_days' => (float) $obj->missed_days,
			'compliance_pct' => (float) $obj->compliance_pct,
			'status' => $obj->status,
			'deduction_pct' => (float) $obj->deduction_pct,
			'deduction_amount' => (float) $obj->deduction_amount,
			'monthly_salary' => (float) $obj->monthly_salary,
			'is_approved' => (int) $obj->is_approved,
		);
	}

	/**
	 * Get all compliance records for a month.
	 *
	 * @param string $yearMonth Year-month (YYYY-MM)
	 * @param string $status    Filter by status (green, yellow, red) or empty for all
	 * @return array            Array of compliance records
	 */
	public function getMonthlyComplianceReport($yearMonth, $status = '')
	{
		global $conf;

		$sql = 'SELECT c.rowid, c.fk_user, c.`year_month`, c.expected_hours, c.actual_hours, c.expected_days, c.actual_days, c.missed_days, c.compliance_pct, c.status, c.deduction_pct, c.deduction_amount, c.monthly_salary, c.is_approved, u.login, u.firstname, u.lastname, u.email';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance c';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user u ON u.rowid = c.fk_user';
		$sql .= ' WHERE c.entity = ' . ((int) $conf->entity);
		$sql .= " AND c.`year_month` = '" . $this->db->escape($yearMonth) . "'";

		if (!empty($status)) {
			$sql .= " AND c.status = '" . $this->db->escape($status) . "'";
		}

		$sql .= ' ORDER BY c.compliance_pct ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$results = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$results[] = array(
				'rowid' => (int) $obj->rowid,
				'user_id' => (int) $obj->fk_user,
				'login' => $obj->login,
				'name' => trim($obj->firstname . ' ' . $obj->lastname),
				'email' => $obj->email,
				'year_month' => $obj->year_month,
				'expected_hours' => (float) $obj->expected_hours,
				'actual_hours' => (float) $obj->actual_hours,
				'expected_days' => (float) $obj->expected_days,
				'actual_days' => (float) $obj->actual_days,
				'missed_days' => (float) $obj->missed_days,
				'compliance_pct' => (float) $obj->compliance_pct,
				'status' => $obj->status,
				'deduction_pct' => (float) $obj->deduction_pct,
				'deduction_amount' => (float) $obj->deduction_amount,
				'monthly_salary' => (float) $obj->monthly_salary,
				'is_approved' => (int) $obj->is_approved,
			);
		}

		return $results;
	}

	/**
	 * Approve a compliance record.
	 *
	 * @param int $complianceId Compliance record ID
	 * @param int $approvedBy   User ID who approved
	 * @return bool             True on success, false on failure
	 */
	public function approveCompliance($complianceId, $approvedBy)
	{
		$now = dol_now();

		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance';
		$sql .= ' SET is_approved = 1, approved_by = ' . ((int) $approvedBy);
		$sql .= ', approved_date = "' . $this->db->idate($now) . '"';
		$sql .= ' WHERE rowid = ' . ((int) $complianceId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 * Save compliance record to database.
	 *
	 * @param array $data Compliance data
	 * @return bool       True on success, false on failure
	 */
	private function saveCompliance($data)
	{
		global $conf;

		$now = dol_now();

		// Check if record exists
		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance';
		$sql .= ' WHERE fk_user = ' . ((int) $data['user_id']);
		$sql .= " AND `year_month` = '" . $this->db->escape($data['year_month']) . "'";

		$resql = $this->db->query($sql);
		$exists = $resql && $this->db->fetch_object($resql);

		if ($exists) {
			// Update existing record
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance SET';
			$sql .= ' expected_hours = ' . ((float) $data['expected_hours']);
			$sql .= ', actual_hours = ' . ((float) $data['actual_hours']);
			$sql .= ', expected_days = ' . ((float) $data['expected_days']);
			$sql .= ', actual_days = ' . ((float) $data['actual_days']);
			$sql .= ', missed_days = ' . ((float) $data['missed_days']);
			$sql .= ', compliance_pct = ' . ((float) $data['compliance_pct']);
			$sql .= ", status = '" . $this->db->escape($data['status']) . "'";
			$sql .= ', deduction_pct = ' . ((float) $data['deduction_pct']);
			$sql .= ', deduction_amount = ' . ((float) $data['deduction_amount']);
			$sql .= ', monthly_salary = ' . ((float) $data['monthly_salary']);
			$sql .= ' WHERE fk_user = ' . ((int) $data['user_id']);
			$sql .= " AND `year_month` = '" . $this->db->escape($data['year_month']) . "'";
		} else {
			// Insert new record
			$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance';
			$sql .= ' (entity, fk_user, `year_month`, expected_hours, actual_hours, expected_days, actual_days, missed_days, compliance_pct, status, deduction_pct, deduction_amount, monthly_salary, datec)';
			$sql .= ' VALUES (' . ((int) $conf->entity);
			$sql .= ', ' . ((int) $data['user_id']);
			$sql .= ", '" . $this->db->escape($data['year_month']) . "'";
			$sql .= ', ' . ((float) $data['expected_hours']);
			$sql .= ', ' . ((float) $data['actual_hours']);
			$sql .= ', ' . ((float) $data['expected_days']);
			$sql .= ', ' . ((float) $data['actual_days']);
			$sql .= ', ' . ((float) $data['missed_days']);
			$sql .= ', ' . ((float) $data['compliance_pct']);
			$sql .= ", '" . $this->db->escape($data['status']) . "'";
			$sql .= ', ' . ((float) $data['deduction_pct']);
			$sql .= ', ' . ((float) $data['deduction_amount']);
			$sql .= ', ' . ((float) $data['monthly_salary']);
			$sql .= ", '" . $this->db->idate($now) . "')";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 * Generate a salary record (payslip source) from an approved compliance row.
	 *
	 * @param int  $complianceId
	 * @param int  $authorId
	 * @param bool $forceRegenerate
	 * @return array|false
	 */
	public function generatePayslipFromCompliance($complianceId, $authorId, $forceRegenerate = false)
	{
		global $conf;

		$complianceId = (int) $complianceId;
		$authorId = (int) $authorId;

		$sql = 'SELECT c.rowid, c.fk_user, c.`year_month`, c.monthly_salary, c.deduction_amount, c.deduction_pct, c.is_approved';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance c';
		$sql .= ' WHERE c.entity = ' . ((int) $conf->entity);
		$sql .= ' AND c.rowid = ' . $complianceId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$row = $this->db->fetch_object($resql);
		if (!$row) {
			$this->error = 'Compliance record not found';
			return false;
		}
		if ((int) $row->is_approved !== 1) {
			$this->error = 'Compliance must be approved before payslip generation';
			return false;
		}

		$existingSalaryId = $this->getSalaryIdByComplianceId($complianceId);
		if ($existingSalaryId > 0 && !$forceRegenerate) {
			return array('salary_id' => $existingSalaryId, 'already_exists' => 1);
		}

		$gross = max(0, (float) $row->monthly_salary);
		if ($gross <= 0) {
			$sqlUserSalary = 'SELECT salary FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $row->fk_user);
			$resUserSalary = $this->db->query($sqlUserSalary);
			if ($resUserSalary && ($objUserSalary = $this->db->fetch_object($resUserSalary))) {
				$gross = max(0, (float) $objUserSalary->salary);
			}
		}
		$deduction = max(0, (float) $row->deduction_amount);
		$net = max(0, $gross - $deduction);

		$datesp = strtotime($row->year_month . '-01 00:00:00');
		$dateep = strtotime(date('Y-m-t', $datesp) . ' 23:59:59');
		$label = 'Clockwork Payslip ' . $row->year_month;
		$note = "Generated from Clockwork monthly compliance #".$complianceId."\n";
		$note .= "Gross: ".$gross."\n";
		$note .= "Deduction: ".$deduction.' ('.$row->deduction_pct."%)\n";
		$note .= "Net: ".$net;

		$author = new User($this->db);
		$author->fetch($authorId);

		$salary = new Salary($this->db);
		$salary->fk_user = (int) $row->fk_user;
		$salary->label = $label;
		$minAmount = (float) getDolGlobalString('CLOCKWORK_PAYSLIP_MIN_AMOUNT', '0.01');
		if ($minAmount <= 0) $minAmount = 0.01;
		$postedAmount = $net;
		if ($postedAmount <= 0) {
			$postedAmount = $minAmount;
			$note .= "\n\nNote: Net salary was 0.00. Stored amount adjusted to ".$postedAmount." because Dolibarr salary object requires a non-empty amount.";
		}
		$salary->amount = $postedAmount;
		$salary->salary = $gross;
		$salary->datesp = $datesp;
		$salary->dateep = $dateep;
		$salary->note = $note;
		$salary->type_payment = 0;
		$salary->accountid = 0;

		if ($existingSalaryId > 0 && $forceRegenerate) {
			$sqlDeleteMap = 'DELETE FROM '.MAIN_DB_PREFIX.'clockwork_payslip_map WHERE fk_compliance = '.$complianceId;
			if (!$this->db->query($sqlDeleteMap)) {
				$this->error = $this->db->lasterror();
				return false;
			}
		}

		$salaryId = $salary->create($author);
		if ($salaryId <= 0) {
			$this->error = !empty($salary->error) ? $salary->error : 'Unable to create salary';
			if (!empty($salary->errors) && is_array($salary->errors)) {
				$this->error .= ' | '.implode('; ', $salary->errors);
			}
			$this->error .= ' | context: gross='.price2num($gross).' net='.price2num($net).' postedAmount='.price2num($postedAmount);
			return false;
		}

		$sqlMap = 'INSERT INTO '.MAIN_DB_PREFIX.'clockwork_payslip_map (entity, fk_compliance, fk_salary, datec, fk_user_author)';
		$sqlMap .= ' VALUES ('.((int) $conf->entity).', '.$complianceId.', '.((int) $salaryId).", '".$this->db->idate(dol_now())."', ".$authorId.')';
		if (!$this->db->query($sqlMap)) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$pdfResult = $this->generatePayslipPdf($complianceId, (int) $salaryId, true);
		if (empty($pdfResult['ok'])) {
			dol_syslog('Clockwork PDF render failed for compliance '.$complianceId.': '.(!empty($pdfResult['error']) ? $pdfResult['error'] : 'unknown'), LOG_WARNING);
		}

		if (getDolGlobalInt('CLOCKWORK_PAYSLIP_EMAIL_ON_GENERATE', 0)) {
			$emailRes = $this->sendPayslipEmail((int) $row->fk_user, (int) $salaryId, (int) $complianceId, (string) $row->year_month, $gross, $deduction, $net);
			if (empty($emailRes['ok'])) {
				dol_syslog('Clockwork payslip email failed for compliance '.$complianceId.': '.(!empty($emailRes['error']) ? $emailRes['error'] : 'unknown'), LOG_WARNING);
			}
		}

		return array(
			'salary_id' => (int) $salaryId,
			'already_exists' => 0,
			'pdf_url' => !empty($pdfResult['url']) ? $pdfResult['url'] : '',
		);
	}

	/**
	 * Send a payslip generated email to employee.
	 *
	 * @param int    $userId
	 * @param int    $salaryId
	 * @param int    $complianceId
	 * @param string $yearMonth
	 * @param float  $gross
	 * @param float  $deduction
	 * @param float  $net
	 * @return array
	 */
	public function sendPayslipEmail($userId, $salaryId, $complianceId, $yearMonth, $gross, $deduction, $net)
	{
		$pdfInfo = $this->getPayslipPdfInfo((int) $complianceId);
		return clockworkEmailPayslipGenerated((int) $userId, array(
			'salary_id' => (int) $salaryId,
			'compliance_id' => (int) $complianceId,
			'year_month' => (string) $yearMonth,
			'gross' => (float) $gross,
			'deduction' => (float) $deduction,
			'net' => (float) $net,
			'pdf_url' => !empty($pdfInfo['url']) ? $pdfInfo['url'] : '',
			'pdf_file' => !empty($pdfInfo['absolute']) ? $pdfInfo['absolute'] : '',
		));
	}

	/**
	 * @param int $complianceId
	 * @return int
	 */
	public function getSalaryIdByComplianceId($complianceId)
	{
		$sql = 'SELECT fk_salary FROM '.MAIN_DB_PREFIX.'clockwork_payslip_map WHERE fk_compliance = '.((int) $complianceId);
		$resql = $this->db->query($sql);
		if (!$resql) return 0;
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->fk_salary : 0;
	}

	/**
	 * Render dedicated payslip PDF and store path on payslip map.
	 *
	 * @param int  $complianceId
	 * @param int  $salaryId
	 * @param bool $force
	 * @return array
	 */
	public function generatePayslipPdf($complianceId, $salaryId = 0, $force = false)
	{
		global $conf, $mysoc;

		$complianceId = (int) $complianceId;
		$salaryId = (int) $salaryId;
		if ($complianceId <= 0) return array('ok' => false, 'error' => 'Invalid compliance id');

		$sql = 'SELECT c.rowid as compliance_id, c.fk_user, c.year_month, c.monthly_salary, c.deduction_amount, c.deduction_pct, c.is_approved,';
		$sql .= ' u.firstname, u.lastname, u.login, m.fk_salary, m.pdf_file';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_monthly_compliance c';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'clockwork_payslip_map m ON m.fk_compliance = c.rowid';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = c.fk_user';
		$sql .= ' WHERE c.entity = '.((int) $conf->entity);
		$sql .= ' AND c.rowid = '.$complianceId;
		$resql = $this->db->query($sql);
		if (!$resql) return array('ok' => false, 'error' => $this->db->lasterror());
		$row = $this->db->fetch_object($resql);
		if (!$row) return array('ok' => false, 'error' => 'Compliance row not found');
		if ((int) $row->is_approved !== 1) return array('ok' => false, 'error' => 'Compliance not approved');

		$salaryId = $salaryId > 0 ? $salaryId : (int) $row->fk_salary;
		if ($salaryId <= 0) return array('ok' => false, 'error' => 'No salary linked');

		$yearMonth = (string) $row->year_month;
		$fileName = 'clockwork_payslip_'.$yearMonth.'_c'.$complianceId.'_s'.$salaryId.'.pdf';
		$relDir = 'clockwork_data/payslips/'.$yearMonth;
		$relative = $relDir.'/'.$fileName;
		$absoluteDir = DOL_DATA_ROOT.'/'.$relDir;
		$absolute = DOL_DATA_ROOT.'/'.$relative;

		if (!$force && !empty($row->pdf_file) && is_readable(DOL_DATA_ROOT.'/'.ltrim((string) $row->pdf_file, '/'))) {
			return array(
				'ok' => true,
				'relative' => ltrim((string) $row->pdf_file, '/'),
				'absolute' => DOL_DATA_ROOT.'/'.ltrim((string) $row->pdf_file, '/'),
				'url' => DOL_MAIN_URL_ROOT.'/custom/clockwork/clockwork/payslip_download.php?compliance_id='.$complianceId,
			);
		}

		if (!dol_mkdir($absoluteDir)) {
			return array('ok' => false, 'error' => 'Cannot create PDF directory');
		}

		$employeeName = trim(((string) $row->firstname).' '.((string) $row->lastname));
		if ($employeeName === '') $employeeName = (string) $row->login;

		$gross = (float) $row->monthly_salary;
		$deduction = (float) $row->deduction_amount;
		$net = max(0, $gross - $deduction);
		$monthLabel = date('F Y', strtotime($yearMonth.'-01'));

		$templatePath = getDolGlobalString('CLOCKWORK_PAYSLIP_PDF_TEMPLATE_FILE', '');
		if ($templatePath === '') $templatePath = DOL_DOCUMENT_ROOT.'/custom/clockwork/templates/payslip_pdf_template.html';
		$template = is_readable($templatePath) ? file_get_contents($templatePath) : '';
		if ($template === false || $template === '') {
			$template = '<h1>Payslip - {{month_label}}</h1><p><strong>Employee:</strong> {{employee_name}}</p><p><strong>Company:</strong> {{company_name}}</p><hr><table cellpadding="6"><tr><td>Gross Salary</td><td align="right">{{gross}}</td></tr><tr><td>Deductions ({{deduction_pct}}%)</td><td align="right">{{deduction}}</td></tr><tr><td><strong>Net Salary</strong></td><td align="right"><strong>{{net}}</strong></td></tr></table><p>Generated on {{generated_at}}</p>';
		}

		$html = strtr($template, array(
			'{{company_name}}' => dol_escape_htmltag((string) $mysoc->name),
			'{{employee_name}}' => dol_escape_htmltag($employeeName),
			'{{employee_login}}' => dol_escape_htmltag((string) $row->login),
			'{{month_label}}' => dol_escape_htmltag($monthLabel),
			'{{year_month}}' => dol_escape_htmltag($yearMonth),
			'{{gross}}' => price($gross, 0, null, 1, -1, -1, $conf->currency),
			'{{deduction}}' => price($deduction, 0, null, 1, -1, -1, $conf->currency),
			'{{deduction_pct}}' => number_format((float) $row->deduction_pct, 2),
			'{{net}}' => price($net, 0, null, 1, -1, -1, $conf->currency),
			'{{salary_id}}' => (string) $salaryId,
			'{{compliance_id}}' => (string) $complianceId,
			'{{generated_at}}' => dol_print_date(dol_now(), 'dayhour'),
		));

		$pdf = pdf_getInstance('A4');
		$pdf->SetCreator('Clockwork');
		$pdf->SetAuthor('Clockwork');
		$pdf->SetTitle('Payslip '.$monthLabel.' - '.$employeeName);
		$pdf->SetMargins(12, 12, 12);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetAutoPageBreak(true, 12);
		$pdf->AddPage();
		$pdf->SetFont('helvetica', '', 10);
		$pdf->writeHTML($html, true, false, true, false, '');
		$pdf->Output($absolute, 'F');

		if (!is_readable($absolute)) {
			return array('ok' => false, 'error' => 'PDF file was not generated');
		}

		$sqlUpdate = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_payslip_map SET pdf_file = \''.$this->db->escape($relative).'\' WHERE fk_compliance = '.$complianceId;
		$this->db->query($sqlUpdate);

		return array(
			'ok' => true,
			'relative' => $relative,
			'absolute' => $absolute,
			'url' => DOL_MAIN_URL_ROOT.'/custom/clockwork/clockwork/payslip_download.php?compliance_id='.$complianceId,
		);
	}

	/**
	 * @param int $complianceId
	 * @return array
	 */
	public function getPayslipPdfInfo($complianceId)
	{
		$sql = 'SELECT m.pdf_file FROM '.MAIN_DB_PREFIX.'clockwork_payslip_map m WHERE m.fk_compliance = '.((int) $complianceId);
		$resql = $this->db->query($sql);
		if (!$resql) return array();
		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->pdf_file)) return array();

		$relative = ltrim((string) $obj->pdf_file, '/');
		return array(
			'relative' => $relative,
			'absolute' => DOL_DATA_ROOT.'/'.$relative,
			'url' => DOL_MAIN_URL_ROOT.'/custom/clockwork/clockwork/payslip_download.php?compliance_id='.(int) $complianceId,
		);
	}

	/**
	 * @param string $yearMonth
	 * @return array<int,int> map compliance_id => salary_id
	 */
	public function getPayslipMapForMonth($yearMonth)
	{
		global $conf;

		$sql = 'SELECT m.fk_compliance, m.fk_salary, m.pdf_file';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_payslip_map m';
		$sql .= ' JOIN '.MAIN_DB_PREFIX.'clockwork_monthly_compliance c ON c.rowid = m.fk_compliance';
		$sql .= ' WHERE c.entity = '.((int) $conf->entity);
		$sql .= " AND c.`year_month` = '".$this->db->escape($yearMonth)."'";
		$resql = $this->db->query($sql);
		if (!$resql) return array();

		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[(int) $obj->fk_compliance] = array(
				'salary_id' => (int) $obj->fk_salary,
				'pdf_file' => (string) $obj->pdf_file,
			);
		}
		return $out;
	}

	/**
	 * @param int $complianceId
	 * @return array|false
	 */
	public function getComplianceById($complianceId)
	{
		global $conf;

		$sql = 'SELECT c.rowid, c.fk_user, c.`year_month`, c.monthly_salary, c.deduction_amount, c.deduction_pct, c.is_approved';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_monthly_compliance c';
		$sql .= ' WHERE c.entity = '.((int) $conf->entity);
		$sql .= ' AND c.rowid = '.((int) $complianceId);
		$resql = $this->db->query($sql);
		if (!$resql) return false;
		$obj = $this->db->fetch_object($resql);
		if (!$obj) return false;

		return array(
			'rowid' => (int) $obj->rowid,
			'user_id' => (int) $obj->fk_user,
			'year_month' => (string) $obj->year_month,
			'monthly_salary' => (float) $obj->monthly_salary,
			'deduction_amount' => (float) $obj->deduction_amount,
			'deduction_pct' => (float) $obj->deduction_pct,
			'is_approved' => (int) $obj->is_approved,
		);
	}

	/**
	 * Get user parameter value.
	 *
	 * @param int    $userId User ID
	 * @param string $param  Parameter name
	 * @param mixed  $default Default value
	 * @return mixed         Parameter value or default
	 */
	private function getUserParam($userId, $param, $default)
	{
		$sql = 'SELECT value FROM ' . MAIN_DB_PREFIX . 'user_param';
		$sql .= ' WHERE fk_user = ' . ((int) $userId);
		$sql .= " AND param = '" . $this->db->escape($param) . "'";

		$resql = $this->db->query($sql);
		if ($resql && $obj = $this->db->fetch_object($resql)) {
			return $obj->value;
		}

		return $default;
	}

	/**
	 * Get expected working days for a month based on contract type.
	 *
	 * @param string $yearMonth    Year-month (YYYY-MM)
	 * @param string $contractType Contract type
	 * @return int                 Expected working days
	 */
	private function getExpectedWorkingDays($yearMonth, $contractType)
	{
		// Get first and last day of month
		$firstDay = new DateTime($yearMonth . '-01');
		$lastDay = new DateTime($yearMonth . '-' . $firstDay->format('t'));

		$workingDays = 0;
		$current = clone $firstDay;

		while ($current <= $lastDay) {
			$dayOfWeek = (int) $current->format('N'); // 1=Mon, 7=Sun
			if ($dayOfWeek <= 5) { // Monday to Friday
				$workingDays++;
			}
			$current->modify('+1 day');
		}

		// Adjust for part-time (assume half days)
		if ($contractType === 'part_time') {
			$workingDays = ceil($workingDays / 2);
		}

		return $workingDays;
	}
	
	/**
	 * Get calendar weekdays for a month (for reference).
	 *
	 * @param string $yearMonth    Year-month (YYYY-MM)
	 * @param string $contractType Contract type
	 * @return int                 Calendar weekdays
	 */
	private function getCalendarWeekdays($yearMonth, $contractType)
	{
		// Get first and last day of month
		$firstDay = new DateTime($yearMonth . '-01');
		$lastDay = new DateTime($yearMonth . '-' . $firstDay->format('t'));

		$workingDays = 0;
		$current = clone $firstDay;

		while ($current <= $lastDay) {
			$dayOfWeek = (int) $current->format('N'); // 1=Mon, 7=Sun
			if ($dayOfWeek <= 5) { // Monday to Friday
				$workingDays++;
			}
			$current->modify('+1 day');
		}

		// Adjust for part-time (assume half days)
		if ($contractType === 'part_time') {
			$workingDays = ceil($workingDays / 2);
		}

		return $workingDays;
	}
}
