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

		$notifyType = CLOCKWORK_NOTIFY_TYPE_CLOCKIN;
		if ($action === 'CLOCKWORK_BREAK_START' || $action === 'CLOCKWORK_BREAK_END') {
			$notifyType = CLOCKWORK_NOTIFY_TYPE_BREAK;
		}
		if (clockworkShouldSkipNotificationUser($this->db, (int) $user->id, (string) $user->login, $notifyType)) {
			return 0;
		}

		$objectId = (isset($object->id) ? (int) $object->id : 0);
		$login = !empty($user->login) ? $user->login : ('user#'.((int) $user->id));
		$when = dol_print_date(dol_now(), 'dayhour');

		if ($action === 'CLOCKWORK_CLOCKIN') {
			$clockinTs = (is_object($object) && !empty($object->clockin)) ? (int) $object->clockin : dol_now();
			$ip = (is_object($object) && !empty($object->ip)) ? $object->ip : '';
			$res = clockworkNotifyClockin($login, $objectId, $clockinTs, $ip);
			if (empty($res['ok'])) dol_syslog('Clockwork notify clockin failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		if ($action === 'CLOCKWORK_CLOCKOUT') {
			$clockoutTs = dol_now();
			$netSeconds = (is_object($object) && isset($object->net_seconds)) ? (int) $object->net_seconds : 0;
			$res = clockworkNotifyClockout($login, $objectId, $clockoutTs, $netSeconds);
			if (empty($res['ok'])) dol_syslog('Clockwork notify clockout failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		if ($action === 'CLOCKWORK_BREAK_START') {
			$shiftId = (is_object($object) && isset($object->fk_shift)) ? (int) $object->fk_shift : 0;
			$breakStartTs = (is_object($object) && !empty($object->break_start)) ? (int) $object->break_start : dol_now();
			$res = clockworkNotifyBreakStart($login, $shiftId, $breakStartTs);
			if (empty($res['ok'])) dol_syslog('Clockwork notify break start failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		if ($action === 'CLOCKWORK_BREAK_END') {
			$shiftId = (is_object($object) && isset($object->fk_shift)) ? (int) $object->fk_shift : 0;
			$breakEndTs = (is_object($object) && !empty($object->break_end)) ? (int) $object->break_end : dol_now();
			$breakSeconds = (is_object($object) && isset($object->seconds)) ? (int) $object->seconds : 0;
			$res = clockworkNotifyBreakEnd($login, $shiftId, $breakEndTs, $breakSeconds);
			if (empty($res['ok'])) dol_syslog('Clockwork notify break end failed: '.json_encode($res), LOG_ERR);
			return 0;
		}

		dol_syslog('Clockwork trigger fired (no notify): '.$action.' at '.$when.' user='.$user->id.' objectId='.$objectId, LOG_INFO);
		return 0;
	}
}
