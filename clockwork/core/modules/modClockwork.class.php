<?php
/* Copyright (C) 2026
 *
 * This file is part of a Dolibarr module.
 */

/**
 * \defgroup   clockwork     Module Clockwork
 * \brief      Clockwork module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Clockwork.
 */
class modClockwork extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		// Unique module id.
		// Note: Verify availability in Home -> System information -> Dolibarr.
		$this->numero = 500200;

		$this->rights_class = 'clockwork';
		$this->family = 'hr';
		$this->module_position = '90';

		$this->name = 'Clockwork';
		$this->description = 'Clock-in/clock-out with breaks and reporting';
		$this->version = '1.0.0';

		$this->const_name = 'MAIN_MODULE_CLOCKWORK';
		$this->picto = 'calendar';

		$this->langfiles = array('clockwork@clockwork');

		$this->module_parts = array(
			'hooks' => array('main', 'usercard', 'globalcard'),
			'triggers' => 1,
		);

		// Note: Some installs set $dolibarr_main_data_root to htdocs/custom (not recommended).
		// To avoid mixing writable temp files with the module code directory, we use a separate data folder name.
		$this->dirs = array('/clockwork_data/temp');
		$this->config_page_url = array('setup.php@custom/clockwork');

		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);

		// Constants
		$this->const = array();
		$this->const[0] = array('CLOCKWORK_API_ALLOW_CORS', 'yesno', '0', 'Allow CORS for Clockwork API', 0);
		$this->const[1] = array('CLOCKWORK_API_ALLOW_QUERY_TOKEN', 'yesno', '0', 'Allow api_key query param for Clockwork API (not recommended)', 0);
		$this->const[2] = array('CLOCKWORK_WEBHOOK_DEFAULT', 'chaine', '', 'Default Discord webhook for Clockwork notifications', 0);
		$this->const[3] = array('CLOCKWORK_WEBHOOK_CLOCKIN', 'chaine', '', 'Discord webhook for clock-in alerts (optional override)', 0);
		$this->const[4] = array('CLOCKWORK_WEBHOOK_BREAK', 'chaine', '', 'Discord webhook for break alerts (optional override)', 0);
		$this->const[5] = array('CLOCKWORK_WEBHOOK_MISSED_CLOCKIN', 'chaine', '', 'Discord webhook for missed clock-in alerts (optional override)', 0);
		$this->const[6] = array('CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY', 'chaine', '', 'Discord webhook for weekly summaries (optional override)', 0);
		$this->const[7] = array('CLOCKWORK_NOTIFY_CLOCKIN', 'yesno', '1', 'Enable clock-in alerts', 0);
		$this->const[8] = array('CLOCKWORK_NOTIFY_BREAK', 'yesno', '1', 'Enable break alerts', 0);
		$this->const[9] = array('CLOCKWORK_NOTIFY_MISSED_CLOCKIN', 'yesno', '1', 'Enable missed clock-in alerts', 0);
		$this->const[10] = array('CLOCKWORK_NOTIFY_WEEKLY_SUMMARY', 'yesno', '1', 'Enable weekly summaries', 0);
		$this->const[11] = array('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', 'chaine', 'admin,user.api', 'Exclude these logins from notifications (comma/space separated)', 0);
		$this->const[12] = array('CLOCKWORK_MISSED_CLOCKIN_TZ', 'chaine', 'Africa/Lagos', 'Timezone for missed clock-in checks', 0);
		$this->const[13] = array('CLOCKWORK_MISSED_CLOCKIN_CUTOFF', 'chaine', '09:30', 'Daily cutoff time (HH:MM) for missed clock-in alerts', 0);
		$this->const[14] = array('CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES', 'integer', '0', 'Grace period (minutes) after cutoff', 0);
		$this->const[15] = array('CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS', 'chaine', '1,2,3,4,5', 'Weekdays to check (1=Mon..7=Sun)', 0);
		$this->const[16] = array('CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE', 'yesno', '1', 'Skip missed clock-in if user is on approved leave', 0);
		$this->const[17] = array('CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS', 'yesno', '1', 'Skip missed clock-in on public holidays', 0);
		$this->const[18] = array('CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE', 'chaine', '', 'Override country code for public holidays (leave empty to use company)', 0);
		$this->const[19] = array('CLOCKWORK_WEEKLY_SUMMARY_TZ', 'chaine', 'Africa/Lagos', 'Timezone for weekly summary scheduling', 0);
		$this->const[20] = array('CLOCKWORK_WEEKLY_SUMMARY_DOW', 'integer', '1', 'Day of week to send weekly summary (1=Mon..7=Sun)', 0);
		$this->const[21] = array('CLOCKWORK_WEEKLY_SUMMARY_TIME', 'chaine', '09:35', 'Local time (HH:MM) to send weekly summary', 0);
		$this->const[22] = array('CLOCKWORK_MISSED_CLOCKIN_LAST_SENT_DATE', 'chaine', '', 'Internal: last local date (YYYYMMDD) we sent missed clock-in alert', 0);
		$this->const[23] = array('CLOCKWORK_WEEKLY_SUMMARY_LAST_SENT_ISOWEEK', 'chaine', '', 'Internal: last ISO week (YYYY-Www) we sent weekly summary', 0);
		$this->const[24] = array('CLOCKWORK_ALLOWED_IPS', 'chaine', '', 'Allowed IP ranges (CIDR notation, comma separated, e.g. 10.0.0.0/8,192.168.1.0/24)', 0);
		$this->const[25] = array('CLOCKWORK_MONITOR_NETWORK_CHANGES', 'yesno', '1', 'Monitor and alert on network changes during shifts', 0);
		$this->const[26] = array('CLOCKWORK_WEBHOOK_OVERWORK', 'chaine', '', 'Discord webhook for overwork alerts (optional override)', 0);
		$this->const[27] = array('CLOCKWORK_WEBHOOK_LOGOUT_REMINDER', 'chaine', '', 'Discord webhook for logout reminders (optional override)', 0);
		$this->const[28] = array('CLOCKWORK_WEBHOOK_NETWORK_CHANGE', 'chaine', '', 'Discord webhook for network change alerts (optional override)', 0);
		$this->const[29] = array('CLOCKWORK_NOTIFY_OVERWORK', 'yesno', '1', 'Enable overwork alerts', 0);
		$this->const[30] = array('CLOCKWORK_NOTIFY_LOGOUT_REMINDER', 'yesno', '1', 'Enable logout reminders', 0);
		$this->const[31] = array('CLOCKWORK_NOTIFY_NETWORK_CHANGE', 'yesno', '1', 'Enable network change alerts', 0);
		$this->const[32] = array('CLOCKWORK_OVERWORK_THRESHOLD_HOURS', 'integer', '4', 'Hours of continuous work before overwork alert', 0);
		$this->const[33] = array('CLOCKWORK_LOGOUT_REMINDER_CUTOFF', 'chaine', '23:00', 'Time (HH:MM) to send logout reminders for open shifts', 0);
		$this->const[34] = array('CLOCKWORK_LOGOUT_REMINDER_TZ', 'chaine', 'Africa/Lagos', 'Timezone for logout reminder checks', 0);
		$this->const[35] = array('CLOCKWORK_LOGOUT_REMINDER_LAST_SENT_DATE', 'chaine', '', 'Internal: last local date (YYYYMMDD) we sent logout reminders', 0);
		$this->const[36] = array('CLOCKWORK_WEBHOOK_SLACK', 'chaine', '', 'Slack webhook URL for notifications', 0);
		$this->const[37] = array('CLOCKWORK_WEBHOOK_TEAMS', 'chaine', '', 'Microsoft Teams webhook URL for notifications', 0);
		$this->const[38] = array('CLOCKWORK_WEBHOOK_OVERTIME', 'chaine', '', 'Discord webhook for weekly overtime alerts (optional override)', 0);
		$this->const[39] = array('CLOCKWORK_NOTIFY_OVERTIME', 'yesno', '1', 'Enable weekly overtime alerts', 0);
		$this->const[40] = array('CLOCKWORK_WEEKLY_OVERTIME_HOURS', 'integer', '48', 'Weekly overtime threshold in hours', 0);
		$this->const[41] = array('CLOCKWORK_NOTIFY_MAX_SHIFT', 'yesno', '1', 'Enable maximum shift length alerts', 0);
		$this->const[42] = array('CLOCKWORK_MAX_SHIFT_HOURS', 'integer', '12', 'Maximum shift duration in hours', 0);
		$this->const[43] = array('CLOCKWORK_WEBHOOK_MAX_SHIFT', 'chaine', '', 'Webhook URL for max shift alerts (optional override)', 0);
		$this->const[44] = array('CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS', 'yesno', '1', 'Enable escalating break reminders', 0);
		$this->const[45] = array('CLOCKWORK_BREAK_REMINDER_HOURS', 'chaine', '2,3,3.5,4', 'Break reminder intervals (hours, comma separated)', 0);
		$this->const[46] = array('CLOCKWORK_ENABLE_BROWSER_NOTIFICATIONS', 'yesno', '1', 'Enable browser notifications', 0);
		$this->const[47] = array('CLOCKWORK_NOTIFY_FATIGUE', 'yesno', '1', 'Enable fatigue management alerts', 0);
		$this->const[48] = array('CLOCKWORK_MIN_REST_HOURS', 'chaine', '8', 'Minimum rest between shifts (hours)', 0);
		$this->const[49] = array('CLOCKWORK_AUTO_CLOSE_SHIFTS', 'yesno', '1', 'Enable automatic shift closure', 0);
		$this->const[50] = array('CLOCKWORK_AUTO_CLOSE_HOURS', 'integer', '16', 'Auto-close shifts after this many hours', 0);
		$this->const[51] = array('CLOCKWORK_DETECT_CONCURRENT', 'yesno', '1', 'Enable concurrent session detection', 0);
		$this->const[52] = array('CLOCKWORK_DETECT_SHIFT_PATTERN', 'yesno', '0', 'Enable shift pattern violation detection', 0);
		$this->const[53] = array('CLOCKWORK_SHIFT_PATTERN_GRACE', 'integer', '15', 'Grace period for shift pattern violations (minutes)', 0);
		$this->const[54] = array('CLOCKWORK_HOURS_PER_DAY', 'chaine', '8', 'Default working hours per day for compliance calculations', 0);
		$this->const[55] = array('CLOCKWORK_DEDUCTION_PERCENT_PER_MISSED_DAY', 'chaine', '10', 'Default deduction percentage per missed day', 0);
		$this->const[56] = array('CLOCKWORK_DEDUCTION_MIN_COMPLIANCE', 'chaine', '90', 'Compliance percentage threshold below which deductions apply', 0);
		$this->const[57] = array('CLOCKWORK_DEDUCTION_MAX_PERCENT', 'chaine', '100', 'Maximum deduction percentage cap', 0);
		$this->const[58] = array('CLOCKWORK_PAYSLIP_EMAIL_ON_GENERATE', 'yesno', '0', 'Send payslip email automatically after generation', 0);
		$this->const[59] = array('CLOCKWORK_PAYSLIP_MIN_AMOUNT', 'chaine', '0.01', 'Minimum salary amount used when calculated net is zero', 0);
		$this->const[60] = array('CLOCKWORK_PAYSLIP_EMAIL_TEMPLATE_FILE', 'chaine', '', 'Absolute path to payslip email HTML template (optional)', 0);
		$this->const[61] = array('CLOCKWORK_PAYSLIP_PDF_TEMPLATE_FILE', 'chaine', '', 'Absolute path to payslip PDF HTML template (optional)', 0);
		$this->const[62] = array('CLOCKWORK_NOTIFY_IDLE', 'yesno', '1', 'Enable idle shift alerts', 0);
		$this->const[63] = array('CLOCKWORK_WEBHOOK_IDLE', 'chaine', '', 'Discord webhook for idle shift alerts (optional override)', 0);
		$this->const[64] = array('CLOCKWORK_IDLE_THRESHOLD_MINUTES', 'integer', '20', 'Minutes without activity before idle alert', 0);
		$this->const[65] = array('CLOCKWORK_IDLE_REMINDER_MINUTES', 'integer', '30', 'Minutes between repeated idle alerts', 0);
		$this->const[66] = array('CLOCKWORK_AI_ENABLE_IDLE_INSIGHTS', 'yesno', '0', 'Use Dolibarr AI module to enrich idle alerts', 0);
		$this->const[67] = array('CLOCKWORK_AI_IDLE_PROMPT', 'chaine', 'Write one short actionable sentence for an HR idle shift alert. Mention whether user should clock out or resume activity.', 'Prompt used for AI idle insight generation', 0);
		$this->const[68] = array('CLOCKWORK_AI_IDLE_MAX_CHARS', 'integer', '280', 'Maximum characters for AI-generated idle insight', 0);

		// Cronjobs
		$datestart = dol_now() + 120;
		$this->cronjobs = array(
			0 => array(
				'label' => 'ClockworkNotifyMissingClockin:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyMissingClockin',
				'parameters' => '',
				'comment' => 'Send missed clock-in alerts after cutoff time (timezone-aware)',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 50,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			1 => array(
				'label' => 'ClockworkWeeklySummary:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyWeeklySummary',
				'parameters' => '',
				'comment' => 'Send weekly worked/break/net summary',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 60,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			2 => array(
				'label' => 'ClockworkNotifyOverwork:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyOverwork',
				'parameters' => '',
				'comment' => 'Alert when users work continuously without breaks',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 55,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			3 => array(
				'label' => 'ClockworkNotifyLogoutReminder:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyForgotLogout',
				'parameters' => '',
				'comment' => 'Remind users to clock out at end of day',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 55,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			4 => array(
				'label' => 'ClockworkNotifyMaxShiftLength:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyMaxShiftLength',
				'parameters' => '',
				'comment' => 'Alert when a shift exceeds maximum duration',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 55,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			5 => array(
				'label' => 'ClockworkEscalatingBreakReminders:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyEscalatingBreakReminders',
				'parameters' => '',
				'comment' => 'Send escalating break reminders during long shifts',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 55,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			6 => array(
				'label' => 'ClockworkWeeklyOvertime:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyWeeklyOvertime',
				'parameters' => '',
				'comment' => 'Alert when weekly hours exceed overtime threshold',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 60,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			7 => array(
				'label' => 'ClockworkFatigueManagement:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyFatigueManagement',
				'parameters' => '',
				'comment' => 'Alert when users have insufficient rest between shifts',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 50,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			8 => array(
				'label' => 'ClockworkAutoCloseShifts:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'autoCloseShifts',
				'parameters' => '',
				'comment' => 'Automatically close shifts that exceed maximum duration',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 70,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			9 => array(
				'label' => 'ClockworkConcurrentSessions:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'detectConcurrentSessions',
				'parameters' => '',
				'comment' => 'Detect users with multiple active shifts',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 50,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			10 => array(
				'label' => 'ClockworkShiftPatternViolations:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'detectShiftPatternViolations',
				'parameters' => '',
				'comment' => 'Detect clock-ins outside expected shift patterns',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 50,
				'status' => 0,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			),
			11 => array(
				'label' => 'ClockworkIdleDetection:clockwork',
				'jobtype' => 'method',
				'class' => 'custom/clockwork/class/clockworkcron.class.php',
				'objectname' => 'ClockworkCron',
				'method' => 'notifyIdleUsers',
				'parameters' => '',
				'comment' => 'Detect idle open shifts and push in-app/Discord alerts',
				'frequency' => 1,
				'unitfrequency' => 300,
				'priority' => 55,
				'status' => 1,
				'test' => '$conf->clockwork->enabled',
				'datestart' => $datestart
			)
		);

		// Permissions
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = 500201;
		$this->rights[$r][1] = 'Read own clockwork data';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = 500202;
		$this->rights[$r][1] = 'Clock-in/out and breaks (self)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'clock';

		$r++;
		$this->rights[$r][0] = 500203;
		$this->rights[$r][1] = 'Read all clockwork data (HR)';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'readall';

		$r++;
		$this->rights[$r][0] = 500204;
		$this->rights[$r][1] = 'Manage clockwork data (HR)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'manage';

		$r++;
		$this->rights[$r][0] = 500205;
		$this->rights[$r][1] = 'API access (MCP)';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'api';

		$r++;
		$this->rights[$r][0] = 500206;
		$this->rights[$r][1] = 'Manage payslip generation from Clockwork';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'payslipmanage';

		// Menus
		$this->menu = array();
		$r = 0;

		// Employee clock page
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm',
			'type' => 'left',
			'titre' => 'ClockworkMyTime',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork',
			'url' => '/custom/clockwork/clockwork/clock.php',
			'langs' => 'clockwork@clockwork',
			'position' => 100,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","clock")',
			'target' => '',
			'user' => 0,
		);

		// HR shifts list
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=clockwork',
			'type' => 'left',
			'titre' => 'ClockworkHRShifts',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork_hr_shifts',
			'url' => '/custom/clockwork/clockwork/hr_shifts.php',
			'langs' => 'clockwork@clockwork',
			'position' => 110,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","readall")',
			'target' => '',
			'user' => 0,
		);

		// Employee payslips
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=clockwork',
			'type' => 'left',
			'titre' => 'ClockworkMyPayslips',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork_my_payslips',
			'url' => '/custom/clockwork/clockwork/my_payslips.php',
			'langs' => 'clockwork@clockwork',
			'position' => 115,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","clock")',
			'target' => '',
			'user' => 0,
		);

		// HR totals
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=clockwork',
			'type' => 'left',
			'titre' => 'ClockworkHRTotals',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork_hr_totals',
			'url' => '/custom/clockwork/clockwork/hr_totals.php',
			'langs' => 'clockwork@clockwork',
			'position' => 120,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","readall")',
			'target' => '',
			'user' => 0,
		);

		// Monthly compliance
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=clockwork',
			'type' => 'left',
			'titre' => 'ClockworkMonthlyCompliance',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork_monthly_compliance',
			'url' => '/custom/clockwork/clockwork/monthly_compliance.php',
			'langs' => 'clockwork@clockwork',
			'position' => 130,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","readall")',
			'target' => '',
			'user' => 0,
		);

		// Exclusions management
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=clockwork',
			'type' => 'left',
			'titre' => 'ClockworkExclusions',
			'mainmenu' => 'hrm',
			'leftmenu' => 'clockwork_exclusions',
			'url' => '/custom/clockwork/clockwork/exclusions.php',
			'langs' => 'clockwork@clockwork',
			'position' => 140,
			'enabled' => 'isModEnabled("clockwork")',
			'perms' => '$user->hasRight("clockwork","manage")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Called when module is enabled.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1> 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/clockwork/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);
		$sql = array();
		return $this->_init($sql, $options);
	}
}
