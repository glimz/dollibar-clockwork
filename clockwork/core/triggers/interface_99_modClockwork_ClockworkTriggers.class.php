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
		if (!preg_match('/^CLOCKWORK_/', (string) $action)) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_webhook.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

		$denylist = getDolGlobalString('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', '');
		if (!empty($user->login) && clockworkIsLoginExcluded($user->login, $denylist)) {
			return 0;
		}

		$objectId = (isset($object->id) ? (int) $object->id : 0);
		$login = !empty($user->login) ? $user->login : ('user#'.((int) $user->id));
		$when = dol_print_date(dol_now(), 'dayhour');

		if ($action === 'CLOCKWORK_CLOCKIN') {
			$clockin = '';
			if (is_object($object) && !empty($object->clockin)) {
				$clockin = dol_print_date((int) $object->clockin, 'dayhour');
			}
			$msg = '[Clockwork] '.$login.' clocked in'.($clockin ? ' at '.$clockin : '').' (shift #'.$objectId.')';
			$res = clockworkNotify(CLOCKWORK_NOTIFY_TYPE_CLOCKIN, $msg);
			if (empty($res['ok'])) dol_syslog('Clockwork notify clockin failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		if ($action === 'CLOCKWORK_BREAK_START') {
			$start = '';
			$shiftId = (is_object($object) && isset($object->fk_shift)) ? (int) $object->fk_shift : 0;
			if (is_object($object) && !empty($object->break_start)) {
				$start = dol_print_date((int) $object->break_start, 'dayhour');
			}
			$msg = '[Clockwork] '.$login.' started a break'.($start ? ' at '.$start : '').($shiftId ? ' (shift #'.$shiftId.')' : '');
			$res = clockworkNotify(CLOCKWORK_NOTIFY_TYPE_BREAK, $msg);
			if (empty($res['ok'])) dol_syslog('Clockwork notify break start failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		if ($action === 'CLOCKWORK_BREAK_END') {
			$end = '';
			$shiftId = (is_object($object) && isset($object->fk_shift)) ? (int) $object->fk_shift : 0;
			$seconds = (is_object($object) && isset($object->seconds)) ? (int) $object->seconds : 0;
			if (is_object($object) && !empty($object->break_end)) {
				$end = dol_print_date((int) $object->break_end, 'dayhour');
			}
			$msg = '[Clockwork] '.$login.' ended a break'.($end ? ' at '.$end : '').($seconds ? ' ('.clockworkFormatDuration($seconds).')' : '').($shiftId ? ' (shift #'.$shiftId.')' : '');
			$res = clockworkNotify(CLOCKWORK_NOTIFY_TYPE_BREAK, $msg);
			if (empty($res['ok'])) dol_syslog('Clockwork notify break end failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		dol_syslog('Clockwork trigger fired (no notify): '.$action.' at '.$when.' user='.$user->id.' objectId='.$objectId, LOG_INFO);
		return 0;
	}
}
