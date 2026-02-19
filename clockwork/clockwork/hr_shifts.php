<?php

// Load Dolibarr environment
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

$langs->loadLangs(array('clockwork@clockwork', 'users'));

if (!isModEnabled('clockwork')) accessforbidden();
if (!$user->hasRight('clockwork', 'readall')) accessforbidden();

$form = new Form($db);

$dateFrom = GETPOST('date_from', 'alpha');
$dateTo = GETPOST('date_to', 'alpha');
$searchUser = GETPOSTINT('user_id');
$status = GETPOST('status', 'alpha'); // open|closed|all

if (empty($dateFrom) || empty($dateTo)) {
	$dateTo = dol_print_date(dol_now(), '%Y-%m-%d');
	$dateFrom = dol_print_date(dol_now() - 7 * 86400, '%Y-%m-%d');
}

$tsFrom = dol_stringtotime($dateFrom.' 00:00:00');
$tsTo = dol_stringtotime($dateTo.' 23:59:59');

llxHeader('', $langs->trans('ClockworkHRShifts'));
print load_fiche_titre($langs->trans('ClockworkShifts'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'hr_shifts', $langs->trans('Clockwork'), -1, 'calendar');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginrightonly">';
print $langs->trans('DateFrom').': <input type="date" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"> ';
print $langs->trans('DateTo').': <input type="date" name="date_to" value="'.dol_escape_htmltag($dateTo).'"> ';
print '</div>';

print '<div class="inline-block marginrightonly">'.$langs->trans('Employee').': ';
print $form->select_dolusers($searchUser, 'user_id', 1, '', 0);
print '</div>';

print '<div class="inline-block marginrightonly">'.$langs->trans('Status').': ';
$opts = array('all' => $langs->trans('Status'), 'open' => $langs->trans('Open'), 'closed' => $langs->trans('Closed'));
print $form->selectarray('status', $opts, $status ? $status : 'all', 0);
print '</div>';

print '<input class="button" type="submit" value="'.$langs->trans('Search').'">';
print '</form>';

$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.clockout, s.status, s.worked_seconds, s.break_seconds, s.net_seconds, s.ip, s.user_agent, s.note,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= " AND s.clockin >= '".$db->idate($tsFrom)."'";
$sql .= " AND s.clockin <= '".$db->idate($tsTo)."'";
if ($searchUser > 0) $sql .= ' AND s.fk_user = '.((int) $searchUser);
if ($status === 'open') $sql .= ' AND s.status = 0';
if ($status === 'closed') $sql .= ' AND s.status = 1';
$sql .= ' ORDER BY s.clockin DESC';
$sql .= $db->plimit(200, 0);

$resql = $db->query($sql);
if (!$resql) {
	print $db->lasterror();
	print dol_get_fiche_end();
	llxFooter();
	exit;
}

print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Employee').'</th>';
print '<th>'.$langs->trans('ClockInTime').'</th>';
print '<th>'.$langs->trans('ClockOutTime').'</th>';
print '<th>'.$langs->trans('BreakTime').'</th>';
print '<th>'.$langs->trans('Net').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '</tr>';

while ($obj = $db->fetch_object($resql)) {
	$clockin = $db->jdate($obj->clockin);
	$clockout = $obj->clockout ? $db->jdate($obj->clockout) : null;

	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag(trim($obj->firstname.' '.$obj->lastname).' ('.$obj->login.')').'</td>';
	print '<td>'.dol_print_date($clockin, 'dayhour').'</td>';
	print '<td>'.($clockout ? dol_print_date($clockout, 'dayhour') : '').'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->break_seconds).'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->net_seconds).'</td>';
	print '<td>'.(((int) $obj->status) === 0 ? $langs->trans('Open') : $langs->trans('Closed')).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();

