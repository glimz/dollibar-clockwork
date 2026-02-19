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
$includeDaily = GETPOSTINT('include_daily');

if (empty($dateFrom) || empty($dateTo)) {
	$dateTo = dol_print_date(dol_now(), '%Y-%m-%d');
	$dateFrom = dol_print_date(dol_now() - 7 * 86400, '%Y-%m-%d');
}

$tsFrom = dol_stringtotime($dateFrom.' 00:00:00');
$tsTo = dol_stringtotime($dateTo.' 23:59:59');

llxHeader('', $langs->trans('ClockworkHRTotals'));
print load_fiche_titre($langs->trans('ClockworkTotals'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'hr_totals', $langs->trans('Clockwork'), -1, 'calendar');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginrightonly">';
print $langs->trans('DateFrom').': <input type="date" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"> ';
print $langs->trans('DateTo').': <input type="date" name="date_to" value="'.dol_escape_htmltag($dateTo).'"> ';
print '</div>';

print '<div class="inline-block marginrightonly">'.$langs->trans('Employee').': ';
print $form->select_dolusers($searchUser, 'user_id', 1, '', 0);
print '</div>';

print '<div class="inline-block marginrightonly">';
print '<label><input type="checkbox" name="include_daily" value="1"'.($includeDaily ? ' checked' : '').'> '.$langs->trans('IncludeDailyBreakdown').'</label>';
print '</div>';

print '<input class="button" type="submit" value="'.$langs->trans('Search').'">';
print '</form>';

$sql = 'SELECT s.fk_user, SUM(s.net_seconds) as net, SUM(s.break_seconds) as brk, SUM(s.worked_seconds) as worked, COUNT(*) as shifts,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= " AND s.clockin >= '".$db->idate($tsFrom)."'";
$sql .= " AND s.clockin <= '".$db->idate($tsTo)."'";
$sql .= ' AND s.status = 1';
if ($searchUser > 0) $sql .= ' AND s.fk_user = '.((int) $searchUser);
$sql .= ' GROUP BY s.fk_user, u.login, u.firstname, u.lastname, u.email';
$sql .= ' ORDER BY u.login';

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
print '<th>'.$langs->trans('Worked').'</th>';
print '<th>'.$langs->trans('BreakTime').'</th>';
print '<th>'.$langs->trans('Net').'</th>';
print '</tr>';

while ($obj = $db->fetch_object($resql)) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag(trim($obj->firstname.' '.$obj->lastname).' ('.$obj->login.')').'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->worked).'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->brk).'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->net).'</td>';
	print '</tr>';

	if ($includeDaily) {
		$sql2 = 'SELECT DATE(clockin) as d, SUM(net_seconds) as net, SUM(break_seconds) as brk, SUM(worked_seconds) as worked';
		$sql2 .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
		$sql2 .= ' WHERE entity = '.((int) $conf->entity);
		$sql2 .= ' AND fk_user = '.((int) $obj->fk_user);
		$sql2 .= " AND clockin >= '".$db->idate($tsFrom)."'";
		$sql2 .= " AND clockin <= '".$db->idate($tsTo)."'";
		$sql2 .= ' AND status = 1';
		$sql2 .= ' GROUP BY DATE(clockin)';
		$sql2 .= ' ORDER BY d';
		$resql2 = $db->query($sql2);
		if ($resql2) {
			while ($d = $db->fetch_object($resql2)) {
				print '<tr class="oddeven">';
				print '<td style="padding-left: 20px;">'.dol_escape_htmltag($d->d).'</td>';
				print '<td>'.clockworkFormatDuration((int) $d->worked).'</td>';
				print '<td>'.clockworkFormatDuration((int) $d->brk).'</td>';
				print '<td>'.clockworkFormatDuration((int) $d->net).'</td>';
				print '</tr>';
			}
		}
	}
}

print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();

