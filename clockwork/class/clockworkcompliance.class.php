<?php
/**
 * Monthly compliance calculation class.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

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
		global $conf;

		// Get user's expected monthly hours
		$expectedHours = (float) $this->getUserParam($userId, 'CLOCKWORK_EXPECTED_MONTHLY_HOURS', 160);
		$monthlySalary = (float) $this->getUserParam($userId, 'CLOCKWORK_MONTHLY_SALARY', 0);
		$contractType = $this->getUserParam($userId, 'CLOCKWORK_CONTRACT_TYPE', 'full_time');

		// Calculate expected working days in the month
		$expectedDays = $this->getExpectedWorkingDays($yearMonth, $contractType);

		// Get month start and end timestamps
		$monthStart = strtotime($yearMonth . '-01 00:00:00');
		$monthEnd = strtotime('+1 month', $monthStart);

		// Get all closed shifts for this user in the month
		$sql = 'SELECT s.rowid, s.clockin, s.clockout, s.net_seconds, s.worked_seconds, s.break_seconds';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
		$sql .= ' WHERE s.fk_user = ' . ((int) $userId);
		$sql .= ' AND s.status = 1'; // Closed shifts only
		$sql .= ' AND s.clockin >= ' . $this->db->idate($monthStart);
		$sql .= ' AND s.clockin < ' . $this->db->idate($monthEnd);

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
		$actualDays = count($daysWorked);
		$missedDays = max(0, $expectedDays - $actualDays);

		// Calculate compliance percentage
		$compliancePct = $expectedHours > 0 ? ($actualHours / $expectedHours) * 100 : 0;

		// Determine status
		if ($compliancePct >= 100) {
			$status = 'green'; // Met or exceeded target
			$deductionPct = 0;
		} elseif ($compliancePct >= 90) {
			$status = 'yellow'; // Within 10% of target
			$deductionPct = 0; // No deduction yet, but warning
		} else {
			$status = 'red'; // Below target
			// 10% deduction per missed day
			$deductionPct = min(100, $missedDays * 10);
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
		$sql .= " AND year_month = '" . $this->db->escape($yearMonth) . "'";

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
			'expected_days' => (int) $obj->expected_days,
			'actual_days' => (int) $obj->actual_days,
			'missed_days' => (int) $obj->missed_days,
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

		$sql = 'SELECT c.*, u.login, u.firstname, u.lastname, u.email';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance c';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user u ON u.rowid = c.fk_user';
		$sql .= ' WHERE c.entity = ' . ((int) $conf->entity);
		$sql .= " AND c.year_month = '" . $this->db->escape($yearMonth) . "'";

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
				'expected_days' => (int) $obj->expected_days,
				'actual_days' => (int) $obj->actual_days,
				'missed_days' => (int) $obj->missed_days,
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
		$sql .= " AND year_month = '" . $this->db->escape($data['year_month']) . "'";

		$resql = $this->db->query($sql);
		$exists = $resql && $this->db->fetch_object($resql);

		if ($exists) {
			// Update existing record
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance SET';
			$sql .= ' expected_hours = ' . ((float) $data['expected_hours']);
			$sql .= ', actual_hours = ' . ((float) $data['actual_hours']);
			$sql .= ', expected_days = ' . ((int) $data['expected_days']);
			$sql .= ', actual_days = ' . ((int) $data['actual_days']);
			$sql .= ', missed_days = ' . ((int) $data['missed_days']);
			$sql .= ', compliance_pct = ' . ((float) $data['compliance_pct']);
			$sql .= ", status = '" . $this->db->escape($data['status']) . "'";
			$sql .= ', deduction_pct = ' . ((float) $data['deduction_pct']);
			$sql .= ', deduction_amount = ' . ((float) $data['deduction_amount']);
			$sql .= ', monthly_salary = ' . ((float) $data['monthly_salary']);
			$sql .= ' WHERE fk_user = ' . ((int) $data['user_id']);
			$sql .= " AND year_month = '" . $this->db->escape($data['year_month']) . "'";
		} else {
			// Insert new record
			$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'clockwork_monthly_compliance';
			$sql .= ' (entity, fk_user, year_month, expected_hours, actual_hours, expected_days, actual_days, missed_days, compliance_pct, status, deduction_pct, deduction_amount, monthly_salary, datec)';
			$sql .= ' VALUES (' . ((int) $conf->entity);
			$sql .= ', ' . ((int) $data['user_id']);
			$sql .= ", '" . $this->db->escape($data['year_month']) . "'";
			$sql .= ', ' . ((float) $data['expected_hours']);
			$sql .= ', ' . ((float) $data['actual_hours']);
			$sql .= ', ' . ((int) $data['expected_days']);
			$sql .= ', ' . ((int) $data['actual_days']);
			$sql .= ', ' . ((int) $data['missed_days']);
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
}