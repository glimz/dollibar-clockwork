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
$includeDaily = GETPOSTINT('include_daily') ? 1 : 0;

$sql = 'SELECT s.fk_user, SUM(s.net_seconds) as net, SUM(s.break_seconds) as brk, SUM(s.worked_seconds) as worked, COUNT(*) as shifts,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= " AND s.clockin >= '".$db->idate($tsFrom)."'";
$sql .= " AND s.clockin <= '".$db->idate($tsTo)."'";
$sql .= ' AND s.status = 1';
if ($userId > 0) $sql .= ' AND s.fk_user = '.((int) $userId);
$sql .= ' GROUP BY s.fk_user, u.login, u.firstname, u.lastname, u.email';
$sql .= ' ORDER BY u.login';

$resql = $db->query($sql);
if (!$resql) {
	clockworkApiReply(500, array('error' => 'DB error', 'details' => $db->lasterror()));
}

$totals = array();
while ($obj = $db->fetch_object($resql)) {
	$entry = array(
		'user' => array(
			'id' => (int) $obj->fk_user,
			'login' => $obj->login,
			'firstname' => $obj->firstname,
			'lastname' => $obj->lastname,
			'email' => $obj->email,
		),
		'period' => array(
			'date_from' => $dateFrom,
			'date_to' => $dateTo,
		),
		'totals' => array(
			'shift_count' => (int) $obj->shifts,
			'worked_seconds' => (int) $obj->worked,
			'break_seconds' => (int) $obj->brk,
			'net_seconds' => (int) $obj->net,
		),
	);

	if ($includeDaily) {
		$sql2 = 'SELECT DATE(clockin) as d, SUM(net_seconds) as net, SUM(break_seconds) as brk, SUM(worked_seconds) as worked, COUNT(*) as shifts';
		$sql2 .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
		$sql2 .= ' WHERE entity = '.((int) $conf->entity);
		$sql2 .= ' AND fk_user = '.((int) $obj->fk_user);
		$sql2 .= " AND clockin >= '".$db->idate($tsFrom)."'";
		$sql2 .= " AND clockin <= '".$db->idate($tsTo)."'";
		$sql2 .= ' AND status = 1';
		$sql2 .= ' GROUP BY DATE(clockin)';
		$sql2 .= ' ORDER BY d';
		$resql2 = $db->query($sql2);
		$days = array();
		if ($resql2) {
			while ($d = $db->fetch_object($resql2)) {
				$days[] = array(
					'date' => $d->d,
					'shift_count' => (int) $d->shifts,
					'worked_seconds' => (int) $d->worked,
					'break_seconds' => (int) $d->brk,
					'net_seconds' => (int) $d->net,
				);
			}
		}
		$entry['days'] = $days;
	}

	$totals[] = $entry;
}

clockworkApiReply(200, array(
	'generated_at' => dol_print_date(dol_now(), 'dayhourlog'),
	'filters' => array(
		'date_from' => $dateFrom,
		'date_to' => $dateTo,
		'user_id' => $userId > 0 ? $userId : null,
		'include_daily' => (bool) $includeDaily,
	),
	'totals' => $totals,
));

