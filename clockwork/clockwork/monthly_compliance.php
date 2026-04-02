<?php
/**
 * Monthly compliance dashboard page.
 */

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

require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkcompliance.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_email.lib.php';

$langs->loadLangs(array('clockwork@clockwork', 'users'));

if (!$user->hasRight('clockwork', 'readall')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$yearMonth = GETPOST('year_month', 'alpha');
if (empty($yearMonth)) {
	$yearMonth = date('Y-m');
}
$statusFilter = GETPOST('status_filter', 'alpha');

llxHeader('', $langs->trans('ClockworkMonthlyCompliance'), '', '', 0, 0, '', '', '', 'mod-clockwork page-monthly-compliance');

$compliance = new ClockworkCompliance($db);
$canGeneratePayslip = $user->hasRight('clockwork', 'payslipmanage') || ($user->hasRight('clockwork', 'manage') && $user->hasRight('salaries', 'write'));

// Handle actions
if ($action === 'calculate') {
	$results = $compliance->calculateAllUsersCompliance($yearMonth);
	setEventMessages($langs->trans('ClockworkComplianceCalculated', count($results)), null, 'mesgs');
}

if ($action === 'approve' && GETPOSTINT('rowid')) {
	$complianceId = GETPOSTINT('rowid');
	if ($compliance->approveCompliance($complianceId, $user->id)) {
		setEventMessages($langs->trans('ClockworkComplianceApproved'), null, 'mesgs');
	} else {
		setEventMessages($compliance->error, null, 'errors');
	}
}

if ($action === 'generate_payslip' && GETPOSTINT('rowid')) {
	if (!$canGeneratePayslip) {
		accessforbidden();
	}
	$complianceId = GETPOSTINT('rowid');
	$result = $compliance->generatePayslipFromCompliance($complianceId, (int) $user->id);
	if ($result !== false) {
		if (!empty($result['already_exists'])) {
			setEventMessages($langs->trans('ClockworkPayslipAlreadyGenerated', $result['salary_id']), null, 'warnings');
		} else {
			setEventMessages($langs->trans('ClockworkPayslipGenerated', $result['salary_id']), null, 'mesgs');
		}
	} else {
		setEventMessages($compliance->error, null, 'errors');
	}
}

if ($action === 'generate_payslips_bulk') {
	if (!$canGeneratePayslip) {
		accessforbidden();
	}
	$recordsForBulk = $compliance->getMonthlyComplianceReport($yearMonth, $statusFilter);
	$payslipMap = $compliance->getPayslipMapForMonth($yearMonth);
	$created = 0;
	foreach ($recordsForBulk as $record) {
		if (empty($record['is_approved'])) continue;
		if (!empty($payslipMap[(int) $record['rowid']]['salary_id'])) continue;
		$res = $compliance->generatePayslipFromCompliance((int) $record['rowid'], (int) $user->id);
		if ($res !== false && empty($res['already_exists'])) {
			$created++;
		}
	}
	setEventMessages($langs->trans('ClockworkPayslipsBulkGenerated', $created), null, 'mesgs');
}

if ($action === 'send_payslip_email' && GETPOSTINT('rowid')) {
	if (!$canGeneratePayslip) {
		accessforbidden();
	}
	$complianceId = GETPOSTINT('rowid');
	$payslipId = $compliance->getSalaryIdByComplianceId($complianceId);
	if ($payslipId <= 0) {
		setEventMessages($langs->trans('ClockworkPayslipEmailNoPayslip'), null, 'errors');
	} else {
		$record = $compliance->getComplianceById($complianceId);
		if (empty($record)) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} else {
			$resEmail = $compliance->sendPayslipEmail((int) $record['user_id'], (int) $payslipId, (int) $complianceId, (string) $record['year_month'], (float) $record['monthly_salary'], (float) $record['deduction_amount'], max(0, (float) $record['monthly_salary'] - (float) $record['deduction_amount']));
			if (!empty($resEmail['ok'])) {
				setEventMessages($langs->trans('ClockworkPayslipEmailSent'), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('ClockworkPayslipEmailFailed') . ': ' . (!empty($resEmail['error']) ? $resEmail['error'] : 'unknown'), null, 'errors');
			}
		}
	}
}

if ($action === 'send_payslip_emails_bulk') {
	if (!$canGeneratePayslip) {
		accessforbidden();
	}
	$recordsForBulkEmail = $compliance->getMonthlyComplianceReport($yearMonth, $statusFilter);
	$payslipMap = $compliance->getPayslipMapForMonth($yearMonth);
	$sent = 0;
	foreach ($recordsForBulkEmail as $record) {
		$cid = (int) $record['rowid'];
		if (empty($payslipMap[$cid]['salary_id'])) continue;
		$resEmail = $compliance->sendPayslipEmail((int) $record['user_id'], (int) $payslipMap[$cid]['salary_id'], $cid, (string) $record['year_month'], (float) $record['monthly_salary'], (float) $record['deduction_amount'], max(0, (float) $record['monthly_salary'] - (float) $record['deduction_amount']));
		if (!empty($resEmail['ok'])) $sent++;
	}
	setEventMessages($langs->trans('ClockworkPayslipEmailsSent', $sent), null, 'mesgs');
}

if ($action === 'send_emails' && GETPOSTINT('send_emails')) {
	$records = $compliance->getMonthlyComplianceReport($yearMonth, $statusFilter);
	$sent = 0;
	foreach ($records as $record) {
		$res = clockworkEmailMonthlySummary($record['user_id'], $yearMonth, $record);
		if ($res['ok']) $sent++;
	}
	setEventMessages($langs->trans('ClockworkEmailsSent', $sent), null, 'mesgs');
}

// Get compliance records
$records = $compliance->getMonthlyComplianceReport($yearMonth, $statusFilter);
$payslipMap = $compliance->getPayslipMapForMonth($yearMonth);

// Calculate summary stats
$totalUsers = count($records);
$greenCount = 0;
$yellowCount = 0;
$redCount = 0;
$totalDeductions = 0;
foreach ($records as $r) {
	if ($r['status'] === 'green') $greenCount++;
	elseif ($r['status'] === 'yellow') $yellowCount++;
	else $redCount++;
	$totalDeductions += $r['deduction_amount'];
}

// Month navigation
$currentMonth = new DateTime($yearMonth . '-01');
$prevMonth = clone $currentMonth;
$prevMonth->modify('-1 month');
$nextMonth = clone $currentMonth;
$nextMonth->modify('+1 month');

print load_fiche_titre($langs->trans('ClockworkMonthlyCompliance'), '', 'title_setup');

// Month navigation
print '<div class="center" style="margin-bottom: 20px;">';
print '<a href="'.$_SERVER['PHP_SELF'].'?year_month='.$prevMonth->format('Y-m').'" class="butAction">< '.$prevMonth->format('F Y').'</a>';
print ' <strong style="font-size: 1.2em; padding: 0 20px;">'.$currentMonth->format('F Y').'</strong> ';
print '<a href="'.$_SERVER['PHP_SELF'].'?year_month='.$nextMonth->format('Y-m').'" class="butAction">'.$nextMonth->format('F Y').' ></a>';
print '</div>';

// Summary cards
print '<div class="fichecenter">';
print '<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">';

print '<div style="flex: 1; min-width: 150px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; text-align: center;">';
print '<div style="font-size: 2em; font-weight: bold; color: #155724;">🟢 '.$greenCount.'</div>';
print '<div>'.$langs->trans('ClockworkCompliant').'</div>';
print '</div>';

print '<div style="flex: 1; min-width: 150px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px; text-align: center;">';
print '<div style="font-size: 2em; font-weight: bold; color: #856404;">🟡 '.$yellowCount.'</div>';
print '<div>'.$langs->trans('ClockworkNearTarget').'</div>';
print '</div>';

print '<div style="flex: 1; min-width: 150px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; text-align: center;">';
print '<div style="font-size: 2em; font-weight: bold; color: #721c24;">🔴 '.$redCount.'</div>';
print '<div>'.$langs->trans('ClockworkBelowTarget').'</div>';
print '</div>';

print '<div style="flex: 1; min-width: 150px; background: #e2e3e5; border: 1px solid #d6d8db; border-radius: 8px; padding: 15px; text-align: center;">';
print '<div style="font-size: 2em; font-weight: bold; color: #383d41;">'.price($totalDeductions, 0, $langs, 1, -1, -1, $conf->currency).'</div>';
print '<div>'.$langs->trans('ClockworkTotalDeductions').'</div>';
print '</div>';

print '</div>';
print '</div>';

// Action buttons
print '<div class="center" style="margin-bottom: 20px;">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display: inline-block; margin: 0 5px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
print '<input type="hidden" name="action" value="calculate">';
print '<input class="butAction" type="submit" value="'.$langs->trans('ClockworkRecalculate').'">';
print '</form>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display: inline-block; margin: 0 5px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
print '<input type="hidden" name="action" value="send_emails">';
print '<input type="hidden" name="send_emails" value="1">';
print '<input class="butAction" type="submit" value="'.$langs->trans('ClockworkSendEmails').'">';
print '</form>';

if ($canGeneratePayslip) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display: inline-block; margin: 0 5px;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
	print '<input type="hidden" name="action" value="generate_payslips_bulk">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('ClockworkGenerateApprovedPayslips').'">';
	print '</form>';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display: inline-block; margin: 0 5px;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
	print '<input type="hidden" name="action" value="send_payslip_emails_bulk">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('ClockworkSendPayslipEmails').'">';
	print '</form>';
}
print '</div>';

// Filter
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom: 20px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
print $langs->trans('Filter').': ';
print '<select name="status_filter" onchange="this.form.submit()">';
print '<option value="">'.$langs->trans('All').'</option>';
print '<option value="green"'.($statusFilter === 'green' ? ' selected' : '').'>🟢 '.$langs->trans('ClockworkCompliant').'</option>';
print '<option value="yellow"'.($statusFilter === 'yellow' ? ' selected' : '').'>🟡 '.$langs->trans('ClockworkNearTarget').'</option>';
print '<option value="red"'.($statusFilter === 'red' ? ' selected' : '').'>🔴 '.$langs->trans('ClockworkBelowTarget').'</option>';
print '</select>';
print '</form>';

// Compliance table
print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Employee').'</th>';
print '<th class="center">'.$langs->trans('ExpectedHours').'</th>';
print '<th class="center">'.$langs->trans('ActualHours').'</th>';
print '<th class="center">'.$langs->trans('CompliancePct').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '<th class="center">'.$langs->trans('MissedDays').'</th>';
print '<th class="right">'.$langs->trans('Deduction').'</th>';
print '<th class="right">'.$langs->trans('ClockworkNetSalary').'</th>';
print '<th class="center">'.$langs->trans('Approved').'</th>';
print '<th class="center">'.$langs->trans('ClockworkPayslip').'</th>';
print '<th class="center">'.$langs->trans('Actions').'</th>';
print '</tr>';

if (empty($records)) {
	print '<tr><td colspan="11" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
} else {
	foreach ($records as $r) {
		print '<tr class="oddeven">';
		print '<td><strong>'.dol_escape_htmltag($r['name']).'</strong><br><span class="opacitymedium">'.dol_escape_htmltag($r['login']).'</span></td>';
		print '<td class="center">'.number_format($r['expected_hours'], 1).'</td>';
		print '<td class="center">'.number_format($r['actual_hours'], 1).'</td>';
		
		$pctColor = $r['status'] === 'green' ? 'green' : ($r['status'] === 'yellow' ? '#856404' : 'red');
		print '<td class="center" style="color: '.$pctColor.'; font-weight: bold;">'.number_format($r['compliance_pct'], 1).'%</td>';
		
		$statusIcon = $r['status'] === 'green' ? '🟢' : ($r['status'] === 'yellow' ? '🟡' : '🔴');
		$statusText = $r['status'] === 'green' ? $langs->trans('ClockworkCompliant') : ($r['status'] === 'yellow' ? $langs->trans('ClockworkNearTarget') : $langs->trans('ClockworkBelowTarget'));
		print '<td class="center">'.$statusIcon.' '.$statusText.'</td>';
		
		print '<td class="center">'.number_format((float) $r['missed_days'], 2).'</td>';
		print '<td class="right" style="color: '.($r['deduction_amount'] > 0 ? 'red' : 'green').';">'.price((float) $r['deduction_amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		$netSalary = max(0, (float) $r['monthly_salary'] - (float) $r['deduction_amount']);
		print '<td class="right">'.price($netSalary, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		
		$approvedText = $r['is_approved'] ? '✅ '.$langs->trans('Yes') : '❌ '.$langs->trans('No');
		print '<td class="center">'.$approvedText.'</td>';
		$payslipId = !empty($payslipMap[(int) $r['rowid']]['salary_id']) ? (int) $payslipMap[(int) $r['rowid']]['salary_id'] : 0;
		$pdfUrl = DOL_URL_ROOT.'/custom/clockwork/clockwork/payslip_download.php?compliance_id='.(int) $r['rowid'];
		if ($payslipId > 0) {
			print '<td class="center"><a href="'.$pdfUrl.'">'.$langs->trans('ClockworkPayslip').' PDF</a><br><span class="opacitymedium">#'.$payslipId.'</span>';
			if ($canGeneratePayslip) {
				print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;margin-top:4px;">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
				print '<input type="hidden" name="status_filter" value="'.dol_escape_htmltag($statusFilter).'">';
				print '<input type="hidden" name="action" value="send_payslip_email">';
				print '<input type="hidden" name="rowid" value="'.((int) $r['rowid']).'">';
				print '<input class="butActionRefused" type="submit" value="'.$langs->trans('ClockworkSendPayslipEmail').'">';
				print '</form>';
			}
			print '</td>';
		} else {
			print '<td class="center"><span class="opacitymedium">'.$langs->trans('ClockworkNotGenerated').'</span></td>';
		}
		
		print '<td class="center">';
		if (!$r['is_approved']) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?year_month='.$yearMonth.'&action=approve&rowid='.$r['rowid'].'&token='.newToken().'" class="butAction">'.$langs->trans('Approve').'</a>';
		} elseif (!$payslipId && $canGeneratePayslip) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="year_month" value="'.$yearMonth.'">';
			print '<input type="hidden" name="status_filter" value="'.dol_escape_htmltag($statusFilter).'">';
			print '<input type="hidden" name="action" value="generate_payslip">';
			print '<input type="hidden" name="rowid" value="'.((int) $r['rowid']).'">';
			print '<input class="butAction" type="submit" value="'.$langs->trans('ClockworkGeneratePayslip').'">';
			print '</form>';
		}
		print '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
