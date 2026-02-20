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
			'hooks' => array('main'),
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
