<?php

/**
 * Trigger interface for Clockwork module.
 */
class InterfaceClockworkTriggers
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
	 * @var string[]
	 */
	public $errors = array();

	public $family = 'clockwork';
	public $version = '1.0';
	public $description = 'Clockwork triggers';
	public $picto = 'clockwork@clockwork';

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	public function getName()
	{
		return 'ClockworkTriggers';
	}

	/**
	 * @return int
	 */
	public function runTrigger($action, $object, $user, $langs, $conf)
	{
		// Reserved for future automation. Keep triggers registered and log when used.
		if (!preg_match('/^CLOCKWORK_/', (string) $action)) {
			return 0;
		}

		dol_syslog('Clockwork trigger fired: '.$action.' user='.$user->id.' objectId='.(isset($object->id) ? $object->id : ''), LOG_INFO);
		return 0;
	}
}

