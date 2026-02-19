<?php

require_once __DIR__.'/_common.php';

clockworkApiAuth();

$dateFrom = GETPOST('date_from', 'alpha');
$dateTo = GETPOST('date_to', 'alpha');
if (empty($dateFrom) || empty($dateTo)) {
	clockworkApiReply(400, array('error' => 'date_from and date_to are required (YYYY-MM-DD)'));
}

$tsFrom = dol_stringtotime($dateFrom.' 00:00:00');
$tsTo = dol_stringtotime($dateTo.' 23:59:59');
if ($tsFrom <= 0 || $tsTo <= 0) {
	clockworkApiReply(400, array('error' => 'Invalid date range'));
}

$userId = GETPOSTINT('user_id');
$status = GETPOST('status', 'alpha'); // open|closed|all
$limit = GETPOSTINT('limit');
$offset = GETPOSTINT('offset');
$includeAudit = GETPOSTINT('include_audit') ? 1 : 1;
if (GETPOSTISSET('include_audit') && GETPOSTINT('include_audit') === 0) $includeAudit = 0;

if ($limit <= 0 || $limit > 500) $limit = 200;
if ($offset < 0) $offset = 0;

$sql = 'SELECT s.rowid as shift_id, s.fk_user, s.clockin, s.clockout, s.status, s.worked_seconds, s.break_seconds, s.net_seconds, s.note, s.ip, s.user_agent,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= " AND s.clockin >= '".$db->idate($tsFrom)."'";
$sql .= " AND s.clockin <= '".$db->idate($tsTo)."'";
if ($userId > 0) $sql .= ' AND s.fk_user = '.((int) $userId);
if ($status === 'open') $sql .= ' AND s.status = 0';
if ($status === 'closed') $sql .= ' AND s.status = 1';
$sql .= ' ORDER BY s.clockin DESC';
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
if (!$resql) {
	clockworkApiReply(500, array('error' => 'DB error', 'details' => $db->lasterror()));
}

$shifts = array();
while ($obj = $db->fetch_object($resql)) {
	$shiftId = (int) $obj->shift_id;

	// Breaks for this shift
	$breaks = array();
	$sqlb = 'SELECT rowid, break_start, break_end, seconds, note';
	$sqlb .= ' FROM '.MAIN_DB_PREFIX.'clockwork_break';
	$sqlb .= ' WHERE fk_shift = '.((int) $shiftId);
	$sqlb .= ' ORDER BY break_start ASC';
	$resb = $db->query($sqlb);
	if ($resb) {
		while ($b = $db->fetch_object($resb)) {
			$bs = $db->jdate($b->break_start);
			$be = $b->break_end ? $db->jdate($b->break_end) : null;
			$breaks[] = array(
				'id' => (int) $b->rowid,
				'start' => dol_print_date($bs, 'dayhourlog'),
				'end' => $be ? dol_print_date($be, 'dayhourlog') : null,
				'seconds' => (int) $b->seconds,
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
			'clockin' => dol_print_date($db->jdate($obj->clockin), 'dayhourlog'),
			'clockout' => $obj->clockout ? dol_print_date($db->jdate($obj->clockout), 'dayhourlog') : null,
			'status' => ((int) $obj->status) === 0 ? 'open' : 'closed',
			'worked_seconds' => (int) $obj->worked_seconds,
			'break_seconds' => (int) $obj->break_seconds,
			'net_seconds' => (int) $obj->net_seconds,
			'note' => $obj->note,
		),
		'breaks' => $breaks,
	);

	if ($includeAudit) {
		$item['shift']['ip'] = $obj->ip;
		$item['shift']['user_agent'] = $obj->user_agent;
	}

	$shifts[] = $item;
}

clockworkApiReply(200, array(
	'generated_at' => dol_print_date(dol_now(), 'dayhourlog'),
	'filters' => array(
		'date_from' => $dateFrom,
		'date_to' => $dateTo,
		'user_id' => $userId > 0 ? $userId : null,
		'status' => $status ? $status : 'all',
		'limit' => $limit,
		'offset' => $offset,
		'include_audit' => (bool) $includeAudit,
	),
	'shifts' => $shifts,
));

