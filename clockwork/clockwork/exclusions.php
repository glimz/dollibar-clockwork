<?php
/**
 * Clockwork per-user exclusion management.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && $j > 0) {
	$res = @include substr($tmp, 0, $i + 1)."/main.inc.php";
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

$langs->loadLangs(array('clockwork@clockwork', 'users'));

if (!$user->hasRight('clockwork', 'manage')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'save' && GETPOSTINT('fk_user') > 0) {
	$fkUser = GETPOSTINT('fk_user');
	$excludeCompliance = GETPOSTINT('exclude_compliance') ? 1 : 0;
	$excludeDeductions = GETPOSTINT('exclude_deductions') ? 1 : 0;
	$notificationTypes = trim((string) GETPOST('notification_types', 'nohtml'));
	$reason = trim((string) GETPOST('reason', 'nohtml'));
	$validUntil = trim((string) GETPOST('valid_until', 'alpha'));
	$validUntilSql = 'NULL';
	if (!empty($validUntil) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
		$validUntilSql = "'" . $db->idate(strtotime($validUntil . ' 23:59:59')) . "'";
	}

	$sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'clockwork_user_exclusion WHERE fk_user = '.((int) $fkUser);
	$resCheck = $db->query($sqlCheck);
	$exists = ($resCheck && $db->fetch_object($resCheck));

	if ($excludeCompliance === 0 && $excludeDeductions === 0 && $notificationTypes === '' && $reason === '' && $validUntilSql === 'NULL') {
		$sqlDelete = 'DELETE FROM '.MAIN_DB_PREFIX.'clockwork_user_exclusion WHERE fk_user = '.((int) $fkUser);
		$db->query($sqlDelete);
		setEventMessages($langs->trans('ClockworkExclusionDeleted'), null, 'mesgs');
	} elseif ($exists) {
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_user_exclusion SET';
		$sql .= ' exclude_compliance = '.$excludeCompliance;
		$sql .= ', exclude_deductions = '.$excludeDeductions;
		$sql .= ", notification_types = '". $db->escape($notificationTypes) ."'";
		$sql .= ", reason = '". $db->escape($reason) ."'";
		$sql .= ', valid_until = '.$validUntilSql;
		$sql .= ', fk_user_author = '.((int) $user->id);
		$sql .= ' WHERE fk_user = '.((int) $fkUser);
		if ($db->query($sql)) {
			setEventMessages($langs->trans('ClockworkExclusionSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	} else {
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'clockwork_user_exclusion';
		$sql .= ' (entity, fk_user, exclude_compliance, exclude_deductions, notification_types, reason, valid_until, datec, fk_user_author)';
		$sql .= ' VALUES ('.((int) $conf->entity);
		$sql .= ', '.((int) $fkUser);
		$sql .= ', '.$excludeCompliance;
		$sql .= ', '.$excludeDeductions;
		$sql .= ", '". $db->escape($notificationTypes) ."'";
		$sql .= ", '". $db->escape($reason) ."'";
		$sql .= ', '.$validUntilSql;
		$sql .= ", '".$db->idate(dol_now())."'";
		$sql .= ', '.((int) $user->id).')';
		if ($db->query($sql)) {
			setEventMessages($langs->trans('ClockworkExclusionSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
}

// Get all active users with Clockwork clock rights
$rightClockId = 500202;
$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname, u.email";
$sql .= " FROM " . MAIN_DB_PREFIX . "user u";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_rights ur ON (ur.fk_user = u.rowid AND ur.fk_id = " . ((int) $rightClockId) . ")";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user ugu ON (ugu.fk_user = u.rowid)";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_rights ugr ON (ugr.fk_usergroup = ugu.fk_usergroup AND ugr.fk_id = " . ((int) $rightClockId) . ")";
$sql .= " WHERE u.entity = " . ((int) $conf->entity);
$sql .= " AND u.statut = 1";
$sql .= " AND (ur.rowid IS NOT NULL OR ugr.rowid IS NOT NULL OR u.admin = 1)";
$sql .= " ORDER BY u.lastname, u.firstname, u.login";
$resql = $db->query($sql);

$users = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$users[] = array(
			'rowid' => (int) $obj->rowid,
			'login' => (string) $obj->login,
			'name' => trim(((string) $obj->firstname).' '.((string) $obj->lastname)),
			'email' => (string) $obj->email,
		);
	}
}

$exclusions = array();
$sqlEx = 'SELECT fk_user, exclude_compliance, exclude_deductions, notification_types, reason, valid_until FROM '.MAIN_DB_PREFIX.'clockwork_user_exclusion';
$sqlEx .= ' WHERE entity = '.((int) $conf->entity);
$resEx = $db->query($sqlEx);
if ($resEx) {
	while ($obj = $db->fetch_object($resEx)) {
		$exclusions[(int) $obj->fk_user] = array(
			'exclude_compliance' => (int) $obj->exclude_compliance,
			'exclude_deductions' => (int) $obj->exclude_deductions,
			'notification_types' => (string) $obj->notification_types,
			'reason' => (string) $obj->reason,
			'valid_until' => !empty($obj->valid_until) ? dol_print_date($db->jdate($obj->valid_until), '%Y-%m-%d') : '',
		);
	}
}

llxHeader('', $langs->trans('ClockworkExclusions'), '', '', 0, 0, '', '', '', 'mod-clockwork page-exclusions');

print load_fiche_titre($langs->trans('ClockworkExclusions'), '', 'title_setup');
print '<p class="opacitymedium">Use notification type codes like: clockin, break, missed_clockin, weekly_summary, overwork, logout_reminder, network_change, overtime, fatigue, auto_close, concurrent, shift_pattern, or *.</p>';

print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Employee').'</th>';
print '<th class="center">'.$langs->trans('ClockworkExcludedFromCompliance').'</th>';
print '<th class="center">'.$langs->trans('ClockworkExcludedFromDeductions').'</th>';
print '<th>'.$langs->trans('ClockworkExcludedNotifications').'</th>';
print '<th>'.$langs->trans('ClockworkExclusionReason').'</th>';
print '<th class="center">'.$langs->trans('ClockworkExclusionValidUntil').'</th>';
print '<th class="center">'.$langs->trans('Actions').'</th>';
print '</tr>';

if (empty($users)) {
	print '<tr><td colspan="7" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
} else {
	foreach ($users as $u) {
		$e = isset($exclusions[$u['rowid']]) ? $exclusions[$u['rowid']] : array(
			'exclude_compliance' => 0,
			'exclude_deductions' => 0,
			'notification_types' => '',
			'reason' => '',
			'valid_until' => '',
		);

		print '<tr class="oddeven">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="save">';
		print '<input type="hidden" name="fk_user" value="'.((int) $u['rowid']).'">';
		print '<td><strong>'.dol_escape_htmltag($u['name'] !== '' ? $u['name'] : $u['login']).'</strong><br><span class="opacitymedium">'.dol_escape_htmltag($u['login']).'</span></td>';
		print '<td class="center"><input type="checkbox" name="exclude_compliance" value="1"'.(!empty($e['exclude_compliance']) ? ' checked' : '').'></td>';
		print '<td class="center"><input type="checkbox" name="exclude_deductions" value="1"'.(!empty($e['exclude_deductions']) ? ' checked' : '').'></td>';
		print '<td><input class="minwidth200" type="text" name="notification_types" value="'.dol_escape_htmltag($e['notification_types']).'" placeholder="overwork,logout_reminder"></td>';
		print '<td><input class="minwidth200" type="text" name="reason" value="'.dol_escape_htmltag($e['reason']).'"></td>';
		print '<td class="center"><input type="date" name="valid_until" value="'.dol_escape_htmltag($e['valid_until']).'"></td>';
		print '<td class="center"><input class="butAction" type="submit" value="'.$langs->trans('Save').'"></td>';
		print '</form>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
