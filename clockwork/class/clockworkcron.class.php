<?php

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_webhook.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

/**
 * Cron jobs for Clockwork module (Discord webhook alerts).
 */
class ClockworkCron
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
	 * Send a "missed clock-in" alert after the configured cutoff time.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyMissingClockin()
	{
		global $conf, $mysoc;

		if (!clockworkIsNotificationEnabled(CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN)) {
			return 0;
		}

		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN);
		if (empty($webhook)) {
			return 0;
		}

		$tzName = getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_TZ', 'Africa/Lagos');
		try {
			$tz = new DateTimeZone($tzName);
		} catch (Exception $e) {
			dol_syslog('Clockwork notifyMissingClockin: invalid timezone '.$tzName, LOG_ERR);
			return 0;
		}

		$nowLocal = new DateTimeImmutable('now', $tz);

		$weekdaysCsv = getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS', '1,2,3,4,5');
		$weekdays = preg_split('/[\\s,;]+/', (string) $weekdaysCsv);
		$weekdays = array_filter(array_map('trim', is_array($weekdays) ? $weekdays : array()));
		$dow = (int) $nowLocal->format('N'); // 1=Mon .. 7=Sun
		if (!in_array((string) $dow, $weekdays, true)) {
			return 0;
		}

		$cutoff = getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_CUTOFF', '09:30');
		if (!preg_match('/^(\\d{1,2}):(\\d{2})$/', $cutoff, $m)) {
			dol_syslog('Clockwork notifyMissingClockin: invalid cutoff '.$cutoff, LOG_ERR);
			return 0;
		}
		$cutoffHour = (int) $m[1];
		$cutoffMin = (int) $m[2];
		$graceMin = (int) getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES', 0);

		$cutoffLocal = $nowLocal->setTime($cutoffHour, $cutoffMin, 0)->modify('+'.max(0, $graceMin).' minutes');
		if ($nowLocal < $cutoffLocal) {
			return 0;
		}

		$localDateKey = $nowLocal->format('Ymd');
		if (getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_LAST_SENT_DATE', '') === $localDateKey) {
			return 0;
		}

		// Skip public holidays (for the local date) if enabled.
		if (getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS', 1)) {
			$countryCode = trim((string) getDolGlobalString('CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE', ''));
			if (empty($countryCode) && !empty($mysoc->country_code)) $countryCode = $mysoc->country_code;

			$tsUtcMidnight = dol_mktime(0, 0, 0, (int) $nowLocal->format('m'), (int) $nowLocal->format('d'), (int) $nowLocal->format('Y'), 'tz,UTC');
			$nb = num_public_holiday($tsUtcMidnight, $tsUtcMidnight + 86400, $countryCode, 0, 0, 0, 0, 0);
			if (is_numeric($nb) && (int) $nb > 0) {
				dolibarr_set_const($this->db, 'CLOCKWORK_MISSED_CLOCKIN_LAST_SENT_DATE', $localDateKey, 'chaine', 0, '', $conf->entity);
				return 0;
			}
		}

		$dayStartLocal = $nowLocal->setTime(0, 0, 0);
		$dayEndLocal = $dayStartLocal->modify('+1 day');
		$startUtc = $dayStartLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
		$endUtc = $dayEndLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();

		// Get all users that should be checked (users with clockwork "clock" right, direct or via group).
		$rightClockId = 500202;
		$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname, u.email";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user_rights as ur ON (ur.fk_user = u.rowid AND ur.fk_id = ".((int) $rightClockId)." AND ur.entity IN (".getEntity('user')."))";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."usergroup_user as ugu ON (ugu.fk_user = u.rowid AND ugu.entity IN (".getEntity('user')."))";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."usergroup_rights as ugr ON (ugr.fk_usergroup = ugu.fk_usergroup AND ugr.fk_id = ".((int) $rightClockId)." AND ugr.entity IN (".getEntity('user')."))";
		$sql .= " WHERE u.entity IN (".getEntity('user').")";
		$sql .= " AND u.statut = 1";
		$sql .= " AND (ur.rowid IS NOT NULL OR ugr.rowid IS NOT NULL OR u.admin = 1)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$denylist = getDolGlobalString('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', '');
		$users = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if ($login !== '' && clockworkIsLoginExcluded($login, $denylist)) {
				continue;
			}
			$users[] = array(
				'id' => (int) $obj->rowid,
				'login' => $login,
				'firstname' => (string) $obj->firstname,
				'lastname' => (string) $obj->lastname,
				'email' => (string) $obj->email,
			);
		}

		if (empty($users)) {
			dolibarr_set_const($this->db, 'CLOCKWORK_MISSED_CLOCKIN_LAST_SENT_DATE', $localDateKey, 'chaine', 0, '', $conf->entity);
			return 0;
		}

		// Fetch all users who clocked in today.
		$sql = "SELECT DISTINCT fk_user FROM ".MAIN_DB_PREFIX."clockwork_shift";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND clockin >= '".$this->db->idate($startUtc)."'";
		$sql .= " AND clockin < '".$this->db->idate($endUtc)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$clockedIn = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$clockedIn[(int) $obj->fk_user] = true;
		}

		$tsUtcMidnight = dol_mktime(0, 0, 0, (int) $nowLocal->format('m'), (int) $nowLocal->format('d'), (int) $nowLocal->format('Y'), 'tz,UTC');

		$holiday = null;
		$checkLeave = (bool) getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE', 1);
		if ($checkLeave && !empty($conf->holiday->enabled)) {
			require_once DOL_DOCUMENT_ROOT.'/holiday/class/holiday.class.php';
			$holiday = new Holiday($this->db);
		}

		$missing = array();
		foreach ($users as $u) {
			if (!empty($clockedIn[$u['id']])) continue;

			// Skip if on approved leave.
			if ($holiday) {
				$avail = $holiday->verifDateHolidayForTimestamp($u['id'], $tsUtcMidnight, '2,3');
				if (is_array($avail) && isset($avail['morning']) && (int) $avail['morning'] === 0) {
					continue;
				}
			}

			$name = trim($u['firstname'].' '.$u['lastname']);
			$label = $u['login'];
			if ($name !== '') $label .= ' ('.$name.')';
			$missing[] = $label;
		}

		if (!empty($missing)) {
			$cutoffText = sprintf('%02d:%02d', $cutoffHour, $cutoffMin).($graceMin > 0 ? ' +'.$graceMin.'m' : '');
			$msg = '[Clockwork] Missing clock-in after '.$cutoffText.' '.$tzName.' on '.$nowLocal->format('Y-m-d').":\n";
			$msg .= "- ".implode("\n- ", $missing);
			$res = clockworkSendDiscordWebhook(CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN, array('content' => $msg));
			if (empty($res['ok'])) {
				dol_syslog('Clockwork missed clock-in webhook failed: '.json_encode($res), LOG_ERR);
			}
		}

		dolibarr_set_const($this->db, 'CLOCKWORK_MISSED_CLOCKIN_LAST_SENT_DATE', $localDateKey, 'chaine', 0, '', $conf->entity);
		return 0;
	}

	/**
	 * Send weekly summary (previous ISO week by default).
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyWeeklySummary()
	{
		global $conf;

		if (!clockworkIsNotificationEnabled(CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY);
		if (empty($webhook)) {
			return 0;
		}

		$tzName = getDolGlobalString('CLOCKWORK_WEEKLY_SUMMARY_TZ', 'Africa/Lagos');
		try {
			$tz = new DateTimeZone($tzName);
		} catch (Exception $e) {
			dol_syslog('Clockwork notifyWeeklySummary: invalid timezone '.$tzName, LOG_ERR);
			return 0;
		}

		$nowLocal = new DateTimeImmutable('now', $tz);
		$wantDow = (int) getDolGlobalInt('CLOCKWORK_WEEKLY_SUMMARY_DOW', 1);
		$nowDow = (int) $nowLocal->format('N');
		if ($wantDow < 1 || $wantDow > 7) $wantDow = 1;
		if ($nowDow !== $wantDow) {
			return 0;
		}

		$time = getDolGlobalString('CLOCKWORK_WEEKLY_SUMMARY_TIME', '09:35');
		if (!preg_match('/^(\\d{1,2}):(\\d{2})$/', $time, $m)) {
			dol_syslog('Clockwork notifyWeeklySummary: invalid time '.$time, LOG_ERR);
			return 0;
		}
		$sendAfter = $nowLocal->setTime((int) $m[1], (int) $m[2], 0);
		if ($nowLocal < $sendAfter) {
			return 0;
		}

		// Summarize previous ISO week.
		$target = $nowLocal->modify('-7 days');
		$isoWeek = $target->format('o-\\WW');
		if (getDolGlobalString('CLOCKWORK_WEEKLY_SUMMARY_LAST_SENT_ISOWEEK', '') === $isoWeek) {
			return 0;
		}

		$weekStartLocal = $target->modify('monday this week')->setTime(0, 0, 0);
		$weekEndLocal = $weekStartLocal->modify('+7 days');
		$startUtc = $weekStartLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
		$endUtc = $weekEndLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();

		$sql = "SELECT s.fk_user, u.login, u.firstname, u.lastname,";
		$sql .= " SUM(s.worked_seconds) as worked, SUM(s.break_seconds) as breaksec, SUM(s.net_seconds) as netsec, COUNT(*) as nbshifts";
		$sql .= " FROM ".MAIN_DB_PREFIX."clockwork_shift as s";
		$sql .= " JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = s.fk_user";
		$sql .= " WHERE s.entity = ".((int) $conf->entity);
		$sql .= " AND s.status = 1";
		$sql .= " AND s.clockin >= '".$this->db->idate($startUtc)."'";
		$sql .= " AND s.clockin < '".$this->db->idate($endUtc)."'";
		$sql .= " GROUP BY s.fk_user, u.login, u.firstname, u.lastname";
		$sql .= " ORDER BY netsec DESC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$denylist = getDolGlobalString('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', '');
		$lines = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if ($login !== '' && clockworkIsLoginExcluded($login, $denylist)) continue;

			$name = trim(((string) $obj->firstname).' '.((string) $obj->lastname));
			$label = $login;
			if ($name !== '') $label .= ' ('.$name.')';

			$lines[] = $label.': net '.clockworkFormatDuration((int) $obj->netsec)
				.' (worked '.clockworkFormatDuration((int) $obj->worked).', breaks '.clockworkFormatDuration((int) $obj->breaksec).', shifts '.((int) $obj->nbshifts).')';
		}

		$title = '[Clockwork] Weekly summary '.$isoWeek.' ('.$weekStartLocal->format('Y-m-d').' → '.$weekEndLocal->modify('-1 second')->format('Y-m-d').')';
		$msg = $title."\n";
		if (!empty($lines)) {
			$msg .= "- ".implode("\n- ", $lines);
		} else {
			$msg .= '(no closed shifts)';
		}

		$res = clockworkSendDiscordWebhook(CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY, array('content' => $msg));
		if (empty($res['ok'])) {
			dol_syslog('Clockwork weekly summary webhook failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		dolibarr_set_const($this->db, 'CLOCKWORK_WEEKLY_SUMMARY_LAST_SENT_ISOWEEK', $isoWeek, 'chaine', 0, '', $conf->entity);
		return 0;
	}
}

