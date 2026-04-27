<?php
/**
 * Employee self-service payslips.
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
	$i--;
	$j--;
}
if (!$res && $i > 0 && $j > 0) {
	$res = @include substr($tmp, 0, $i + 1)."/main.inc.php";
}
if (!$res) {
	die('Include of main fails');
}

$langs->loadLangs(array('clockwork@clockwork', 'salaries'));

if (!isModEnabled('clockwork')) accessforbidden();
if (!$user->hasRight('clockwork', 'clock') && !$user->hasRight('clockwork', 'read')) accessforbidden();

$yearMonth = GETPOST('year_month', 'alpha');

$sql = 'SELECT c.rowid as compliance_id, c.`year_month`, c.monthly_salary, c.deduction_amount, c.deduction_pct, c.is_approved, m.fk_salary, m.pdf_file';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_monthly_compliance c';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'clockwork_payslip_map m ON m.fk_compliance = c.rowid';
$sql .= ' WHERE c.entity = '.((int) $conf->entity);
$sql .= ' AND c.fk_user = '.((int) $user->id);
$sql .= ' AND c.is_approved = 1';
if (!empty($yearMonth)) {
	$sql .= " AND c.`year_month` = '".$db->escape($yearMonth)."'";
}
$sql .= ' ORDER BY c.`year_month` DESC';

$resql = $db->query($sql);
$rows = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = array(
			'compliance_id' => (int) $obj->compliance_id,
			'year_month' => (string) $obj->year_month,
			'monthly_salary' => (float) $obj->monthly_salary,
			'deduction_amount' => (float) $obj->deduction_amount,
			'deduction_pct' => (float) $obj->deduction_pct,
			'fk_salary' => (int) $obj->fk_salary,
			'pdf_file' => (string) $obj->pdf_file,
		);
	}
}

llxHeader('', $langs->trans('ClockworkMyPayslips'));
print load_fiche_titre($langs->trans('ClockworkMyPayslips'), '', 'title_setup');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom:16px;">';
print '<label>'.$langs->trans('Month').': </label>';
print '<input type="month" name="year_month" value="'.dol_escape_htmltag($yearMonth).'"> ';
print '<input class="button" type="submit" value="'.$langs->trans('Search').'">';
print '</form>';

print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Month').'</th>';
print '<th class="right">'.$langs->trans('AmountHT').'</th>';
print '<th class="right">'.$langs->trans('ClockworkDeduction').'</th>';
print '<th class="right">'.$langs->trans('ClockworkNetSalary').'</th>';
print '<th class="center">'.$langs->trans('ClockworkPayslip').'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="5" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
} else {
	foreach ($rows as $r) {
		$net = max(0, (float) $r['monthly_salary'] - (float) $r['deduction_amount']);
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag(date('F Y', strtotime($r['year_month'].'-01'))).'</td>';
		print '<td class="right">'.price((float) $r['monthly_salary'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		print '<td class="right">'.price((float) $r['deduction_amount'], 0, $langs, 1, -1, -1, $conf->currency).' ('.number_format((float) $r['deduction_pct'], 1).'%)</td>';
		print '<td class="right">'.price($net, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		if ((int) $r['fk_salary'] > 0) {
			print '<td class="center"><a href="'.DOL_URL_ROOT.'/custom/clockwork/clockwork/payslip_download.php?compliance_id='.(int) $r['compliance_id'].'">'.$langs->trans('ClockworkPayslip').' PDF</a><br><span class="opacitymedium">#'.((int) $r['fk_salary']).'</span></td>';
		} else {
			print '<td class="center"><span class="opacitymedium">'.$langs->trans('ClockworkNotGenerated').'</span></td>';
		}
		print '</tr>';
	}
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
