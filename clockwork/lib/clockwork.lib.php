<?php

function clockworkPrepareHead()
{
	global $langs;
	$langs->load('clockwork@clockwork');

	$h = 0;
	$head = array();

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/clock.php';
	$head[$h][1] = $langs->trans('ClockworkMyTime');
	$head[$h][2] = 'clock';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/hr_shifts.php';
	$head[$h][1] = $langs->trans('ClockworkHRShifts');
	$head[$h][2] = 'hr_shifts';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/hr_totals.php';
	$head[$h][1] = $langs->trans('ClockworkHRTotals');
	$head[$h][2] = 'hr_totals';
	$h++;

	return $head;
}

/**
 * Format seconds as H:MM.
 *
 * @param int $seconds
 * @return string
 */
function clockworkFormatDuration($seconds)
{
	$seconds = max(0, (int) $seconds);
	$hours = (int) floor($seconds / 3600);
	$minutes = (int) floor(($seconds % 3600) / 60);
	return $hours.':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
}

/**
 * Returns user exclusion settings for Clockwork.
 *
 * @param DoliDB $db
 * @param int    $userId
 * @return array
 */
function clockworkGetUserExclusion($db, $userId)
{
	$empty = array(
		'exclude_compliance' => 0,
		'exclude_deductions' => 0,
		'notification_types' => array(),
		'reason' => '',
		'valid_until' => '',
	);

	$userId = (int) $userId;
	if ($userId <= 0 || !is_object($db)) {
		return $empty;
	}

	$sql = 'SELECT exclude_compliance, exclude_deductions, notification_types, reason, valid_until';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'clockwork_user_exclusion';
	$sql .= ' WHERE fk_user = ' . $userId;

	$resql = $db->query($sql);
	if (!$resql) {
		return $empty;
	}

	$obj = $db->fetch_object($resql);
	if (!$obj) {
		return $empty;
	}

	$validUntilTs = !empty($obj->valid_until) ? $db->jdate($obj->valid_until) : 0;
	if (!empty($validUntilTs) && $validUntilTs < dol_now()) {
		return $empty;
	}

	$list = preg_split('/[\s,;]+/', (string) $obj->notification_types);
	$list = is_array($list) ? $list : array();
	$list = array_filter(array_map('trim', $list));
	$list = array_map('strtolower', $list);

	return array(
		'exclude_compliance' => (int) $obj->exclude_compliance,
		'exclude_deductions' => (int) $obj->exclude_deductions,
		'notification_types' => $list,
		'reason' => (string) $obj->reason,
		'valid_until' => !empty($obj->valid_until) ? $obj->valid_until : '',
	);
}

/**
 * @param DoliDB $db
 * @param int    $userId
 * @return bool
 */
function clockworkIsUserExcludedFromCompliance($db, $userId)
{
	$settings = clockworkGetUserExclusion($db, $userId);
	return ((int) $settings['exclude_compliance']) === 1;
}

/**
 * @param DoliDB $db
 * @param int    $userId
 * @return bool
 */
function clockworkIsUserExcludedFromDeductions($db, $userId)
{
	$settings = clockworkGetUserExclusion($db, $userId);
	return ((int) $settings['exclude_deductions']) === 1;
}

/**
 * @param DoliDB $db
 * @param int    $userId
 * @param string $notifyType
 * @return bool
 */
function clockworkIsUserExcludedFromNotification($db, $userId, $notifyType)
{
	$settings = clockworkGetUserExclusion($db, $userId);
	$types = $settings['notification_types'];
	if (empty($types)) {
		return false;
	}

	$notifyType = strtolower(trim((string) $notifyType));
	return in_array('*', $types, true) || in_array($notifyType, $types, true);
}

/**
 * Helper to centralize denylist + per-user exclusion checks.
 *
 * @param DoliDB $db
 * @param int    $userId
 * @param string $login
 * @param string $notifyType
 * @return bool
 */
function clockworkShouldSkipNotificationUser($db, $userId, $login, $notifyType)
{
	$denylist = getDolGlobalString('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', '');
	$login = trim((string) $login);
	if ($login !== '' && function_exists('clockworkIsLoginExcluded') && clockworkIsLoginExcluded($login, $denylist)) {
		return true;
	}

	return clockworkIsUserExcludedFromNotification($db, $userId, $notifyType);
}
