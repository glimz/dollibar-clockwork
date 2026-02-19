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

		$this->dirs = array('/clockwork/temp');
		$this->config_page_url = array('setup.php@clockwork');

		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);

		// Constants
		$this->const = array();
		$this->const[0] = array('CLOCKWORK_API_ALLOW_CORS', 'yesno', '0', 'Allow CORS for Clockwork API', 0);

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
			'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=',
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

