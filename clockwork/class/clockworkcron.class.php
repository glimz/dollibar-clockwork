<?php

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_webhook.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworknotification.class.php';

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

		$users = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->rowid, $login, CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN)) {
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
			
			// Build rich embed notification
			$fields = array(
				clockworkEmbedField('Date', $nowLocal->format('Y-m-d'), true),
				clockworkEmbedField('Cutoff', $cutoffText.' '.$tzName, true),
				clockworkEmbedField('Missing Users', implode("\n", $missing), false),
			);

			$res = clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN, array(
				'title' => '⏰ Missing Clock-In Alert',
				'description' => 'The following users have not clocked in after the cutoff time.',
				'color' => 16744448, // Orange
				'fields' => $fields,
				'footer' => 'Clockwork • Missed Clock-In',
			));
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

		$lines = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY)) continue;

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

	/**
	 * Send overwork alerts for users working continuously without breaks.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyOverwork()
	{
		global $conf;

		if (!clockworkIsNotificationEnabled(CLOCKWORK_NOTIFY_TYPE_OVERWORK)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_OVERWORK);
		if (empty($webhook)) {
			return 0;
		}

		$thresholdHours = (int) getDolGlobalInt('CLOCKWORK_OVERWORK_THRESHOLD_HOURS', 4);
		$thresholdSeconds = $thresholdHours * 3600;

		$now = dol_now();

		// Get all open shifts (only for active users)
		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.ip, u.login, u.firstname, u.lastname, u.statut';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift as s';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1'; // Only active users

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_OVERWORK)) {
				continue;
			}

			$shiftId = (int) $obj->rowid;
			$clockinTs = $this->db->jdate($obj->clockin);

			// Get the last break end time for this shift
			$sqlBreak = 'SELECT MAX(break_end) as last_break_end';
			$sqlBreak .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_break';
			$sqlBreak .= ' WHERE fk_shift = ' . $shiftId;
			$sqlBreak .= ' AND break_end IS NOT NULL';

			$resqlBreak = $this->db->query($sqlBreak);
			$lastBreakEnd = $clockinTs; // Default to clock-in time
			if ($resqlBreak) {
				$objBreak = $this->db->fetch_object($resqlBreak);
				if ($objBreak && $objBreak->last_break_end) {
					$lastBreakEnd = $this->db->jdate($objBreak->last_break_end);
				}
			}

			// Check if there's an open break
			$sqlOpenBreak = 'SELECT COUNT(*) as c';
			$sqlOpenBreak .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_break';
			$sqlOpenBreak .= ' WHERE fk_shift = ' . $shiftId;
			$sqlOpenBreak .= ' AND break_end IS NULL';

			$resqlOpenBreak = $this->db->query($sqlOpenBreak);
			if ($resqlOpenBreak) {
				$objOpenBreak = $this->db->fetch_object($resqlOpenBreak);
				if ($objOpenBreak && (int) $objOpenBreak->c > 0) {
					continue; // User is on break, skip
				}
			}

			// Calculate continuous work time
			$continuousSeconds = max(0, $now - $lastBreakEnd);

			if ($continuousSeconds >= $thresholdSeconds) {
				// Check if we already sent an alert for this shift recently (avoid spam)
				$alertKey = 'CLOCKWORK_OVERWORK_ALERT_' . $shiftId;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') {
					$lastAlertTs = dol_stringtotime($lastAlert);
					// Don't alert again within 1 hour
					if ($lastAlertTs > 0 && ($now - $lastAlertTs) < 3600) {
						continue;
					}
				}

				$name = trim($obj->firstname . ' ' . $obj->lastname);
				$label = $login;
				if ($name !== '') $label .= ' (' . $name . ')';

				// Send rich embed notification
				require_once DOL_DOCUMENT_ROOT . '/custom/clockwork/lib/clockwork_webhook.lib.php';
				clockworkNotifyOverwork($login, $shiftId, $continuousSeconds, $obj->ip);

				// Mark alert sent
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Send logout reminders for users who haven't clocked out.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyForgotLogout()
	{
		global $conf;

		if (!clockworkIsNotificationEnabled(CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER);
		if (empty($webhook)) {
			return 0;
		}

		$tzName = getDolGlobalString('CLOCKWORK_LOGOUT_REMINDER_TZ', 'Africa/Lagos');
		try {
			$tz = new DateTimeZone($tzName);
		} catch (Exception $e) {
			dol_syslog('Clockwork notifyForgotLogout: invalid timezone ' . $tzName, LOG_ERR);
			return 0;
		}

		$nowLocal = new DateTimeImmutable('now', $tz);

		$cutoff = getDolGlobalString('CLOCKWORK_LOGOUT_REMINDER_CUTOFF', '23:00');
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', $cutoff, $m)) {
			dol_syslog('Clockwork notifyForgotLogout: invalid cutoff ' . $cutoff, LOG_ERR);
			return 0;
		}
		$cutoffHour = (int) $m[1];
		$cutoffMin = (int) $m[2];

		$cutoffLocal = $nowLocal->setTime($cutoffHour, $cutoffMin, 0);
		if ($nowLocal < $cutoffLocal) {
			return 0; // Not yet past cutoff
		}

		$localDateKey = $nowLocal->format('Ymd');
		if (getDolGlobalString('CLOCKWORK_LOGOUT_REMINDER_LAST_SENT_DATE', '') === $localDateKey) {
			return 0; // Already sent today
		}


		// Get all open shifts (only for active users)
		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, u.login, u.firstname, u.lastname';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift as s';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1'; // Only active users

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$reminders = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER)) {
				continue;
			}

			$shiftId = (int) $obj->rowid;
			$clockinTs = $this->db->jdate($obj->clockin);

			$name = trim($obj->firstname . ' ' . $obj->lastname);
			$label = $login;
			if ($name !== '') $label .= ' (' . $name . ')';

			// Send rich embed notification
			require_once DOL_DOCUMENT_ROOT . '/custom/clockwork/lib/clockwork_webhook.lib.php';
			clockworkNotifyLogoutReminder($login, $shiftId, $clockinTs);

			$reminders[] = $label;
		}

		if (!empty($reminders)) {
			dolibarr_set_const($this->db, 'CLOCKWORK_LOGOUT_REMINDER_LAST_SENT_DATE', $localDateKey, 'chaine', 0, '', $conf->entity);
		}

		return 0;
	}

	/**
	 * Send maximum shift length alerts for users who have exceeded the max shift duration.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyMaxShiftLength()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_NOTIFY_MAX_SHIFT', 1)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_OVERWORK);
		if (empty($webhook)) {
			return 0;
		}

		$maxShiftHours = (int) getDolGlobalInt('CLOCKWORK_MAX_SHIFT_HOURS', 12);
		$maxShiftSeconds = $maxShiftHours * 3600;
		$now = dol_now();

		// Get all open shifts (only for active users)
		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.ip, u.login, u.firstname, u.lastname';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift as s';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1'; // Only active users

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_OVERWORK)) {
				continue;
			}

			$shiftId = (int) $obj->rowid;
			$clockinTs = $this->db->jdate($obj->clockin);
			$workedSeconds = max(0, $now - $clockinTs);

			if ($workedSeconds >= $maxShiftSeconds) {
				// Check if we already sent an alert for this shift recently
				$alertKey = 'CLOCKWORK_MAX_SHIFT_ALERT_' . $shiftId;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') {
					$lastAlertTs = dol_stringtotime($lastAlert);
					// Don't alert again within 2 hours
					if ($lastAlertTs > 0 && ($now - $lastAlertTs) < 7200) {
						continue;
					}
				}

				$name = trim($obj->firstname . ' ' . $obj->lastname);
				$label = $login;
				if ($name !== '') $label .= ' (' . $name . ')';

				$workedHours = round($workedSeconds / 3600, 1);

				// Build rich embed notification
				$fields = array(
					clockworkEmbedField('User', $label, true),
					clockworkEmbedField('Shift', '#' . $shiftId, true),
					clockworkEmbedField('Worked', clockworkFormatDuration($workedSeconds), true),
					clockworkEmbedField('Max Allowed', $maxShiftHours . ' hours', true),
					clockworkEmbedField('IP', $obj->ip, true),
				);

				clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_OVERWORK, array(
					'title' => '⚠️ Maximum Shift Length Exceeded',
					'description' => 'User has exceeded the maximum allowed shift duration of ' . $maxShiftHours . ' hours.',
					'color' => 16711680, // Red
					'fields' => $fields,
					'footer' => 'Clockwork • Maximum Shift Alert',
				));

				// Mark alert sent
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Send escalating break reminders for users working without breaks.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyEscalatingBreakReminders()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS', 1)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_OVERWORK);
		if (empty($webhook)) {
			return 0;
		}

		$breakReminderHoursStr = getDolGlobalString('CLOCKWORK_BREAK_REMINDER_HOURS', '2,3,3.5,4');
		$breakReminderHours = array_map('floatval', array_filter(array_map('trim', explode(',', $breakReminderHoursStr))));
		$now = dol_now();

		// Get all open shifts (only for active users)
		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, u.login, u.firstname, u.lastname';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift as s';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1'; // Only active users

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_OVERWORK)) {
				continue;
			}

			$shiftId = (int) $obj->rowid;
			$clockinTs = $this->db->jdate($obj->clockin);

			// Get the last break end time for this shift
			$sqlBreak = 'SELECT MAX(break_end) as last_break_end';
			$sqlBreak .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_break';
			$sqlBreak .= ' WHERE fk_shift = ' . $shiftId;
			$sqlBreak .= ' AND break_end IS NOT NULL';

			$resqlBreak = $this->db->query($sqlBreak);
			$lastBreakEnd = $clockinTs;
			if ($resqlBreak) {
				$objBreak = $this->db->fetch_object($resqlBreak);
				if ($objBreak && $objBreak->last_break_end) {
					$lastBreakEnd = $this->db->jdate($objBreak->last_break_end);
				}
			}

			// Check if there's an open break
			$sqlOpenBreak = 'SELECT COUNT(*) as c';
			$sqlOpenBreak .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_break';
			$sqlOpenBreak .= ' WHERE fk_shift = ' . $shiftId;
			$sqlOpenBreak .= ' AND break_end IS NULL';

			$resqlOpenBreak = $this->db->query($sqlOpenBreak);
			if ($resqlOpenBreak) {
				$objOpenBreak = $this->db->fetch_object($resqlOpenBreak);
				if ($objOpenBreak && (int) $objOpenBreak->c > 0) {
					continue; // User is on break, skip
				}
			}

			$continuousSeconds = max(0, $now - $lastBreakEnd);

			// Check each reminder threshold
			foreach ($breakReminderHours as $index => $hours) {
				$thresholdSeconds = $hours * 3600;
				if ($continuousSeconds < $thresholdSeconds) {
					continue;
				}

				// Check if we already sent this specific reminder
				$alertKey = 'CLOCKWORK_BREAK_REMINDER_' . $shiftId . '_' . $index;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') {
					continue; // Already sent this reminder
				}

				$name = trim($obj->firstname . ' ' . $obj->lastname);
				$label = $login;
				if ($name !== '') $label .= ' (' . $name . ')';

				$continuousHours = round($continuousSeconds / 3600, 1);

				// Build rich embed notification
				$fields = array(
					clockworkEmbedField('User', $label, true),
					clockworkEmbedField('Shift', '#' . $shiftId, true),
					clockworkEmbedField('Continuous Work', clockworkFormatDuration($continuousSeconds), true),
					clockworkEmbedField('Reminder', ($index + 1) . '/' . count($breakReminderHours), true),
				);

				clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_OVERWORK, array(
					'title' => '💡 Break Reminder (' . ($index + 1) . '/' . count($breakReminderHours) . ')',
					'description' => 'User has been working continuously for ' . $continuousHours . ' hours without a break.',
					'color' => 16776960, // Yellow
					'fields' => $fields,
					'footer' => 'Clockwork • Break Reminder',
				));

				// Mark reminder sent
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Send fatigue management alerts for users with insufficient rest between shifts.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyFatigueManagement()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_NOTIFY_FATIGUE', 1)) {
			return 0;
		}

		$minRestHours = (float) getDolGlobalString('CLOCKWORK_MIN_REST_HOURS', '8');
		$minRestSeconds = $minRestHours * 3600;
		$now = dol_now();

		// Get all users with clockwork rights
		$rightClockId = 500202;
		$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user as u";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_rights as ur ON (ur.fk_user = u.rowid AND ur.fk_id = " . ((int) $rightClockId) . " AND ur.entity IN (" . getEntity('user') . "))";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user as ugu ON (ugu.fk_user = u.rowid AND ugu.entity IN (" . getEntity('user') . "))";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_rights as ugr ON (ugr.fk_usergroup = ugu.fk_usergroup AND ugr.fk_id = " . ((int) $rightClockId) . " AND ugr.entity IN (" . getEntity('user') . "))";
		$sql .= " WHERE u.entity IN (" . getEntity('user') . ")";
		$sql .= " AND u.statut = 1";
		$sql .= " AND (ur.rowid IS NOT NULL OR ugr.rowid IS NOT NULL OR u.admin = 1)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$users = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->rowid, $login, CLOCKWORK_NOTIFY_TYPE_FATIGUE)) {
				continue;
			}
			$users[] = array(
				'id' => (int) $obj->rowid,
				'login' => $login,
				'firstname' => (string) $obj->firstname,
				'lastname' => (string) $obj->lastname,
			);
		}

		// Check each user's last shift for insufficient rest
		foreach ($users as $u) {
			// Get the last closed shift for this user
			$sqlShift = 'SELECT s.rowid, s.clockout, s.clockin';
			$sqlShift .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
			$sqlShift .= ' WHERE s.fk_user = ' . ((int) $u['id']);
			$sqlShift .= ' AND s.status = 1'; // Closed shift
			$sqlShift .= ' AND s.clockout IS NOT NULL';
			$sqlShift .= ' ORDER BY s.clockout DESC';
			$sqlShift .= ' LIMIT 1';

			$resqlShift = $this->db->query($sqlShift);
			if (!$resqlShift) continue;

			$objShift = $this->db->fetch_object($resqlShift);
			if (!$objShift) continue;

			$lastClockout = $this->db->jdate($objShift->clockout);
			$lastShiftId = (int) $objShift->rowid;

			// Check if there's a new shift started after the last one ended
			$sqlNewShift = 'SELECT s.rowid, s.clockin';
			$sqlNewShift .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
			$sqlNewShift .= ' WHERE s.fk_user = ' . ((int) $u['id']);
			$sqlNewShift .= ' AND s.clockin > ' . $this->db->idate($lastClockout);
			$sqlNewShift .= ' ORDER BY s.clockin ASC';
			$sqlNewShift .= ' LIMIT 1';

			$resqlNewShift = $this->db->query($sqlNewShift);
			if (!$resqlNewShift) continue;

			$objNewShift = $this->db->fetch_object($resqlNewShift);
			if (!$objNewShift) continue;

			$newShiftClockin = $this->db->jdate($objNewShift->clockin);
			$restSeconds = $newShiftClockin - $lastClockout;

			if ($restSeconds < $minRestSeconds && $restSeconds > 0) {
				// Check if we already alerted for this shift pair
				$alertKey = 'CLOCKWORK_FATIGUE_ALERT_' . $lastShiftId . '_' . $objNewShift->rowid;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') continue;

				$name = trim($u['firstname'] . ' ' . $u['lastname']);
				$label = $u['login'];
				if ($name !== '') $label .= ' (' . $name . ')';

				clockworkNotifyFatigue($label, $u['id'], $restSeconds, $minRestSeconds, $lastShiftId);

				// Mark alert sent
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Automatically close shifts that exceed maximum duration.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function autoCloseShifts()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_AUTO_CLOSE_SHIFTS', 1)) {
			return 0;
		}

		$maxShiftHours = (int) getDolGlobalInt('CLOCKWORK_AUTO_CLOSE_HOURS', 16);
		$maxShiftSeconds = $maxShiftHours * 3600;
		$now = dol_now();

		// Get all open shifts (only for active users)
		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.ip, u.login, u.firstname, u.lastname';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift as s';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1'; // Only active users

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->fk_user, $login, CLOCKWORK_NOTIFY_TYPE_AUTO_CLOSE)) {
				continue;
			}

			$shiftId = (int) $obj->rowid;
			$clockinTs = $this->db->jdate($obj->clockin);
			$workedSeconds = max(0, $now - $clockinTs);

			if ($workedSeconds >= $maxShiftSeconds) {
				// Check if we already sent notification
				$alertKey = 'CLOCKWORK_AUTO_CLOSE_' . $shiftId;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') continue;

				$name = trim($obj->firstname . ' ' . $obj->lastname);
				$label = $login;
				if ($name !== '') $label .= ' (' . $name . ')';

				// Auto-close the shift
				$sqlUpdate = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_shift';
				$sqlUpdate .= ' SET status = 1, clockout = ' . $this->db->idate($now);
				$sqlUpdate .= ' WHERE rowid = ' . $shiftId;

				$this->db->query($sqlUpdate);

				// Calculate worked time
				$netSeconds = $workedSeconds;
				$workedHours = round($netSeconds / 3600, 1);

				// Send notification
				clockworkNotifyAutoClose($label, $shiftId, $netSeconds, $maxShiftSeconds);

				// Mark as closed
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Detect and alert on concurrent active sessions for same user.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function detectConcurrentSessions()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_DETECT_CONCURRENT', 1)) {
			return 0;
		}

		$now = dol_now();

		// Find users with multiple open shifts
		$sql = 'SELECT s.fk_user, COUNT(*) as cnt, GROUP_CONCAT(s.rowid) as shift_ids';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
		$sql .= ' WHERE s.entity = ' . ((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' GROUP BY s.fk_user';
		$sql .= ' HAVING cnt > 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$userId = (int) $obj->fk_user;
			$shiftIds = explode(',', (string) $obj->shift_ids);

			// Get user details
			$sqlUser = 'SELECT login, firstname, lastname FROM ' . MAIN_DB_PREFIX . 'user WHERE rowid = ' . $userId;
			$resqlUser = $this->db->query($sqlUser);
			if (!$resqlUser) continue;

			$objUser = $this->db->fetch_object($resqlUser);
			if (!$objUser) continue;

			$login = (string) $objUser->login;
			if (clockworkShouldSkipNotificationUser($this->db, $userId, $login, CLOCKWORK_NOTIFY_TYPE_CONCURRENT)) {
				continue;
			}

			// Check if we already alerted
			$alertKey = 'CLOCKWORK_CONCURRENT_' . $userId . '_' . date('Y-m-d');
			$lastAlert = getDolGlobalString($alertKey, '');
			if ($lastAlert !== '') continue;

			$name = trim($objUser->firstname . ' ' . $objUser->lastname);
			$label = $login;
			if ($name !== '') $label .= ' (' . $name . ')';

			clockworkNotifyConcurrent($label, $userId, $shiftIds);

			// Mark alert sent
			dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
		}

		return 0;
	}

	/**
	 * Detect shift pattern violations.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function detectShiftPatternViolations()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_DETECT_SHIFT_PATTERN', 0)) {
			return 0;
		}

		$now = dol_now();
		$today = dol_print_date($now, '%Y-%m-%d');

		// Get users with shift patterns defined
		$sql = 'SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname, uv.value as pattern';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'user u';
		$sql .= ' JOIN ' . MAIN_DB_PREFIX . 'user_param uv ON uv.fk_user = u.rowid';
		$sql .= ' WHERE uv.param = \'CLOCKWORK_SHIFT_PATTERN\'';
		$sql .= ' AND u.entity = ' . ((int) $conf->entity);
		$sql .= ' AND u.statut = 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->rowid, $login, CLOCKWORK_NOTIFY_TYPE_SHIFT_PATTERN)) {
				continue;
			}

			$pattern = (string) $obj->pattern;
			if (empty($pattern)) continue;

			// Parse pattern (format: "HH:MM-HH:MM" e.g., "09:00-17:00")
			if (!preg_match('/^(\\d{1,2}):(\\d{2})-(\\d{1,2}):(\\d{2})$/', $pattern, $m)) {
				continue;
			}

			$expectedStartHour = (int) $m[1];
			$expectedStartMin = (int) $m[2];
			$expectedEndHour = (int) $m[3];
			$expectedEndMin = (int) $m[4];

			// Get today's clock-in for this user
			$dayStart = dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), (int) dol_print_date($now, '%d'), (int) dol_print_date($now, '%Y'));
			$dayEnd = $dayStart + 86400;

			$sqlShift = 'SELECT s.rowid, s.clockin';
			$sqlShift .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift s';
			$sqlShift .= ' WHERE s.fk_user = ' . ((int) $obj->rowid);
			$sqlShift .= ' AND s.clockin >= ' . $this->db->idate($dayStart);
			$sqlShift .= ' AND s.clockin < ' . $this->db->idate($dayEnd);
			$sqlShift .= ' ORDER BY s.clockin ASC';
			$sqlShift .= ' LIMIT 1';

			$resqlShift = $this->db->query($sqlShift);
			if (!$resqlShift) continue;

			$objShift = $this->db->fetch_object($resqlShift);
			if (!$objShift) continue;

			$clockinTs = $this->db->jdate($objShift->clockin);
			$clockinHour = (int) dol_print_date($clockinTs, '%H');
			$clockinMin = (int) dol_print_date($clockinTs, '%M');

			// Check if clock-in is outside expected pattern (with 15 min grace)
			$graceMinutes = (int) getDolGlobalInt('CLOCKWORK_SHIFT_PATTERN_GRACE', 15);
			$expectedStartMinutes = $expectedStartHour * 60 + $expectedStartMin;
			$actualMinutes = $clockinHour * 60 + $clockinMin;
			$diff = $actualMinutes - $expectedStartMinutes;

			// Violation if clock-in is more than grace period before or after expected start
			if (abs($diff) > $graceMinutes) {
				// Check if we already alerted today
				$alertKey = 'CLOCKWORK_PATTERN_' . $obj->rowid . '_' . $today;
				$lastAlert = getDolGlobalString($alertKey, '');
				if ($lastAlert !== '') continue;

				$name = trim($obj->firstname . ' ' . $obj->lastname);
				$label = $login;
				if ($name !== '') $label .= ' (' . $name . ')';

				$actualClockinStr = sprintf('%02d:%02d', $clockinHour, $clockinMin);

				clockworkNotifyShiftPattern($label, (int) $obj->rowid, $pattern, $actualClockinStr);

				// Mark alert sent
				dolibarr_set_const($this->db, $alertKey, dol_print_date($now, 'dayhourlog'), 'chaine', 0, '', $conf->entity);
			}
		}

		return 0;
	}

	/**
	 * Send weekly overtime alerts for users who exceed weekly hour limits.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyWeeklyOvertime()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_NOTIFY_OVERTIME', 1)) {
			return 0;
		}
		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_OVERTIME);
		if (empty($webhook)) {
			return 0;
		}

		$weeklyOvertimeHours = (int) getDolGlobalInt('CLOCKWORK_WEEKLY_OVERTIME_HOURS', 48);
		$weeklyOvertimeSeconds = $weeklyOvertimeHours * 3600;
		$now = dol_now();

		// Calculate start of current ISO week
		$dayOfWeek = (int) dol_print_date($now, '%u'); // 1=Mon, 7=Sun
		$weekStart = $now - ($dayOfWeek - 1) * 86400;
		$weekStart = dol_mktime(0, 0, 0, (int) dol_print_date($weekStart, '%m'), (int) dol_print_date($weekStart, '%d'), (int) dol_print_date($weekStart, '%Y'));
		$weekEnd = $weekStart + 7 * 86400;

		// Get all users with clockwork rights
		$rightClockId = 500202;
		$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user as u";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_rights as ur ON (ur.fk_user = u.rowid AND ur.fk_id = " . ((int) $rightClockId) . " AND ur.entity IN (" . getEntity('user') . "))";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user as ugu ON (ugu.fk_user = u.rowid AND ugu.entity IN (" . getEntity('user') . "))";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_rights as ugr ON (ugr.fk_usergroup = ugu.fk_usergroup AND ugr.fk_id = " . ((int) $rightClockId) . " AND ugr.entity IN (" . getEntity('user') . "))";
		$sql .= " WHERE u.entity IN (" . getEntity('user') . ")";
		$sql .= " AND u.statut = 1";
		$sql .= " AND (ur.rowid IS NOT NULL OR ugr.rowid IS NOT NULL OR u.admin = 1)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$users = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$login = (string) $obj->login;
			if (clockworkShouldSkipNotificationUser($this->db, (int) $obj->rowid, $login, CLOCKWORK_NOTIFY_TYPE_OVERTIME)) {
				continue;
			}
			$users[] = array(
				'id' => (int) $obj->rowid,
				'login' => $login,
				'firstname' => (string) $obj->firstname,
				'lastname' => (string) $obj->lastname,
			);
		}

		// Calculate weekly hours for each user
		$overTimeUsers = array();
		foreach ($users as $u) {
			$sqlWeek = 'SELECT COALESCE(SUM(net_seconds), 0) as total_net';
			$sqlWeek .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift';
			$sqlWeek .= ' WHERE fk_user = ' . ((int) $u['id']);
			$sqlWeek .= ' AND clockin >= ' . $this->db->idate($weekStart);
			$sqlWeek .= ' AND clockin < ' . $this->db->idate($weekEnd);
			$sqlWeek .= ' AND status = 1';

			$resqlWeek = $this->db->query($sqlWeek);
			if ($resqlWeek) {
				$objWeek = $this->db->fetch_object($resqlWeek);
				$totalNet = (int) $objWeek->total_net;

				// Add current open shift if any
				$sqlOpen = 'SELECT rowid, clockin';
				$sqlOpen .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_shift';
				$sqlOpen .= ' WHERE fk_user = ' . ((int) $u['id']);
				$sqlOpen .= ' AND status = 0';
				$sqlOpen .= ' LIMIT 1';

				$resqlOpen = $this->db->query($sqlOpen);
				if ($resqlOpen) {
					$objOpen = $this->db->fetch_object($resqlOpen);
					if ($objOpen) {
						$clockinTs = $this->db->jdate($objOpen->clockin);
						$totalNet += max(0, $now - $clockinTs);
					}
				}

				if ($totalNet >= $weeklyOvertimeSeconds) {
					$overTimeUsers[] = array(
						'login' => $u['login'],
						'name' => trim($u['firstname'] . ' ' . $u['lastname']),
						'totalNet' => $totalNet,
					);
				}
			}
		}

		// Send notifications for users exceeding overtime
		foreach ($overTimeUsers as $u) {
			$label = $u['login'];
			if ($u['name'] !== '') $label .= ' (' . $u['name'] . ')';

			$weeklyHours = round($u['totalNet'] / 3600, 1);

			// Build rich embed notification
			$fields = array(
				clockworkEmbedField('User', $label, true),
				clockworkEmbedField('Weekly Hours', clockworkFormatDuration($u['totalNet']), true),
				clockworkEmbedField('Overtime Limit', $weeklyOvertimeHours . ' hours', true),
				clockworkEmbedField('Week', dol_print_date($weekStart, 'day') . ' - ' . dol_print_date($weekEnd - 86400, 'day'), true),
			);

			clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_OVERTIME, array(
				'title' => '⏰ Weekly Overtime Alert',
				'description' => 'User has exceeded the weekly overtime limit of ' . $weeklyOvertimeHours . ' hours.',
				'color' => 16744448, // Orange
				'fields' => $fields,
				'footer' => 'Clockwork • Weekly Overtime',
			));
		}

		return 0;
	}

	/**
	 * Detect idle open shifts using heartbeat timestamps.
	 *
	 * @return int 0 if OK, <0 if error
	 */
	public function notifyIdleUsers()
	{
		global $conf;

		if (!getDolGlobalInt('CLOCKWORK_NOTIFY_IDLE', 1)) {
			return 0;
		}

		$thresholdMinutes = (int) getDolGlobalInt('CLOCKWORK_IDLE_THRESHOLD_MINUTES', 20);
		$reminderMinutes = (int) getDolGlobalInt('CLOCKWORK_IDLE_REMINDER_MINUTES', 30);
		$thresholdSeconds = max(60, $thresholdMinutes * 60);
		$reminderSeconds = max(60, $reminderMinutes * 60);
		$now = dol_now();

		$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.last_activity_at, s.idle_notified_at, s.idle_notif_count, u.login, u.firstname, u.lastname';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift s';
		$sql .= ' JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = s.fk_user';
		$sql .= ' WHERE s.entity = '.((int) $conf->entity);
		$sql .= ' AND s.status = 0';
		$sql .= ' AND u.statut = 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$notifService = new ClockworkNotification($this->db);

		while ($obj = $this->db->fetch_object($resql)) {
			$userId = (int) $obj->fk_user;
			$shiftId = (int) $obj->rowid;
			$login = (string) $obj->login;

			if (clockworkShouldSkipNotificationUser($this->db, $userId, $login, CLOCKWORK_NOTIFY_TYPE_IDLE)) {
				continue;
			}

			$clockinTs = $this->db->jdate($obj->clockin);
			$lastActivityTs = !empty($obj->last_activity_at) ? $this->db->jdate($obj->last_activity_at) : $clockinTs;
			$idleSeconds = max(0, $now - $lastActivityTs);
			if ($idleSeconds < $thresholdSeconds) {
				continue;
			}

			$lastNotifiedTs = !empty($obj->idle_notified_at) ? $this->db->jdate($obj->idle_notified_at) : 0;
			if ($lastNotifiedTs > 0 && ($now - $lastNotifiedTs) < $reminderSeconds) {
				continue;
			}

			$name = trim(((string) $obj->firstname).' '.((string) $obj->lastname));
			$label = $login;
			if ($name !== '') $label .= ' ('.$name.')';

			$title = 'Idle Shift Detected';
			$message = 'No Clockwork activity detected for '.clockworkFormatDuration($idleSeconds).' on active shift #'.$shiftId.'. If you are done, please clock out.';
			$notifService->create($userId, 'idle', $title, $message, 'warning', $shiftId, array(
				'idle_seconds' => $idleSeconds,
				'shift_id' => $shiftId,
			));

			$lastActivityText = dol_print_date($lastActivityTs, 'dayhourlog');
			clockworkNotifyIdle($label, $shiftId, $idleSeconds, $lastActivityText);

			$sqlu = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift';
			$sqlu .= ' SET idle_notified_at = \''.$this->db->idate($now).'\'';
			$sqlu .= ', idle_notif_count = '.(((int) $obj->idle_notif_count) + 1);
			$sqlu .= ' WHERE rowid = '.$shiftId;
			$this->db->query($sqlu);
		}

		return 0;
	}
}
