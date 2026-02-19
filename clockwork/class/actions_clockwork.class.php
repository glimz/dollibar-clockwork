<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hooks for Clockwork module.
 */
class ActionsClockwork extends CommonHookActions
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

	/**
	 * @var mixed[]
	 */
	public $results = array();

	/**
	 * @var string
	 */
	public $resprints;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add quick access link in the top right menu.
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		$langs->load('clockwork@clockwork');

		if (!isModEnabled('clockwork')) return 0;
		if (!$user->hasRight('clockwork', 'clock')) return 0;

		$url = DOL_URL_ROOT.'/custom/clockwork/clockwork/clock.php';
		$this->resprints = '<div class="login"><a class="butAction" href="'.$url.'">'.$langs->trans('Clockwork').'</a></div>';
		return 0;
	}
}

