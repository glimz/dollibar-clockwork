<?php

require_once __DIR__.'/_common.php';

clockworkApiAuth();

$includeAudit = GETPOSTINT('include_audit') ? 1 : 1; // default include audit fields
if (GETPOSTISSET('include_audit') && GETPOSTINT('include_audit') === 0) $includeAudit = 0;

$asOf = GETPOST('as_of', 'alpha');
$now = dol_now();
if (!empty($asOf)) {
	$tmp = dol_stringtotime($asOf);
	if ($tmp > 0) $now = $tmp;
}

$sql = 'SELECT s.rowid as shift_id, s.fk_user, s.clockin, s.note, s.ip, s.user_agent,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= ' AND s.status = 0';
$sql .= ' ORDER BY s.clockin ASC';
$sql .= $db->plimit(500, 0);

$resql = $db->query($sql);
if (!$resql) {
	clockworkApiReply(500, array('error' => 'DB error', 'details' => $db->lasterror()));
}

$active = array();
while ($obj = $db->fetch_object($resql)) {
	$shiftId = (int) $obj->shift_id;
	$clockin = $db->jdate($obj->clockin);
	$workedSoFar = max(0, (int) ($now - $clockin));

	// Breaks (all) + compute break seconds so far
	$breaks = array();
	$breakSoFar = 0;
	$sqlb = 'SELECT rowid, break_start, break_end, seconds, note';
	$sqlb .= ' FROM '.MAIN_DB_PREFIX.'clockwork_break';
	$sqlb .= ' WHERE fk_shift = '.((int) $shiftId);
	$sqlb .= ' ORDER BY break_start ASC';
	$resb = $db->query($sqlb);
	if ($resb) {
		while ($b = $db->fetch_object($resb)) {
			$bs = $db->jdate($b->break_start);
			$be = $b->break_end ? $db->jdate($b->break_end) : null;
			$sec = (int) $b->seconds;
			if (!$be) {
				$sec = max(0, (int) ($now - $bs));
			}
			$breakSoFar += $sec;
			$breaks[] = array(
				'id' => (int) $b->rowid,
				'start' => dol_print_date($bs, 'dayhourlog'),
				'end' => $be ? dol_print_date($be, 'dayhourlog') : null,
				'seconds' => $sec,
				'note' => $b->note,
			);
		}
	}

	$item = array(
		'user' => array(
			'id' => (int) $obj->fk_user,
			'login' => $obj->login,
			'firstname' => $obj->firstname,
			'lastname' => $obj->lastname,
			'email' => $obj->email,
		),
		'shift' => array(
			'id' => $shiftId,
			'clockin' => dol_print_date($clockin, 'dayhourlog'),
			'clockout' => null,
			'note' => $obj->note,
		),
		'breaks' => $breaks,
		'computed' => array(
			'worked_seconds_so_far' => $workedSoFar,
			'break_seconds_so_far' => $breakSoFar,
			'net_seconds_so_far' => max(0, $workedSoFar - $breakSoFar),
		),
	);

	if ($includeAudit) {
		$item['shift']['ip'] = $obj->ip;
		$item['shift']['user_agent'] = $obj->user_agent;
	}

	$active[] = $item;
}

clockworkApiReply(200, array(
	'generated_at' => dol_print_date(dol_now(), 'dayhourlog'),
	'as_of' => dol_print_date($now, 'dayhourlog'),
	'active' => $active,
));

