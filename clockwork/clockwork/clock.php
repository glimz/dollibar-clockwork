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

require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkshift.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkbreak.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

$langs->loadLangs(array('clockwork@clockwork'));

if (!isModEnabled('clockwork')) accessforbidden();
if (!$user->hasRight('clockwork', 'clock')) accessforbidden();

$action = GETPOST('action', 'aZ09');
$note = (string) GETPOST('note', 'restricthtml');

$shift = new ClockworkShift($db);
$hasOpenShift = $shift->fetchOpenByUser($user->id);

if ($action === 'clockin') {
	$resClockin = $shift->clockIn($user, $note);
	if ($resClockin > 0) setEventMessages($langs->trans('ClockworkClockedIn'), null, 'mesgs');
	else setEventMessages($shift->error, $shift->errors, 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'clockout') {
	$resClockout = $shift->clockOut($user, $note);
	if ($resClockout > 0) setEventMessages($langs->trans('ClockworkClockedOut'), null, 'mesgs');
	else setEventMessages($shift->error, $shift->errors, 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'break_start') {
	if ($hasOpenShift <= 0) {
		setEventMessages($langs->trans('ClockworkNotClockedIn'), null, 'errors');
	} else {
		$br = new ClockworkBreak($db);
		$resStart = $br->startBreak($user, $shift->id, $note);
		if ($resStart > 0) setEventMessages($langs->trans('ClockworkBreakStarted'), null, 'mesgs');
		else setEventMessages($br->error, $br->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'break_end') {
	if ($hasOpenShift <= 0) {
		setEventMessages($langs->trans('ClockworkNotClockedIn'), null, 'errors');
	} else {
		$br = new ClockworkBreak($db);
		$resEnd = $br->endBreak($user, $shift->id, $note);
		if ($resEnd > 0) setEventMessages($langs->trans('ClockworkBreakEnded'), null, 'mesgs');
		else setEventMessages($br->error, $br->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Reload status for view
$shift = new ClockworkShift($db);
$hasOpenShift = $shift->fetchOpenByUser($user->id);
$break = new ClockworkBreak($db);
$hasOpenBreak = ($hasOpenShift > 0) ? $break->fetchOpenByShift($shift->id) : 0;

// Compute "so far"
$workedSoFar = 0;
$breakSoFar = 0;
$netSoFar = 0;
if ($hasOpenShift > 0) {
	$now = dol_now();
	$workedSoFar = max(0, (int) ($now - $shift->clockin));

	// Sum closed breaks seconds
	$sql = 'SELECT SUM(seconds) as s FROM '.MAIN_DB_PREFIX.'clockwork_break WHERE fk_shift = '.((int) $shift->id).' AND break_end IS NOT NULL';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$breakSoFar = empty($obj->s) ? 0 : (int) $obj->s;
	}
	// Add open break duration
	if ($hasOpenBreak > 0) {
		$breakSoFar += max(0, (int) ($now - $break->break_start));
	}
	$netSoFar = max(0, $workedSoFar - $breakSoFar);
}

llxHeader('', $langs->trans('ClockworkMyTime'));

print load_fiche_titre($langs->trans('ClockworkMyTime'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'clock', $langs->trans('Clockwork'), -1, 'calendar');

print '<table class="border centpercent">';

print '<tr><td class="titlefield">'.$langs->trans('CurrentStatus').'</td><td>';
if ($hasOpenShift > 0) {
	print $langs->trans('ClockedInAt').' '.dol_print_date($shift->clockin, 'dayhour');
} else {
	print $langs->trans('NotClockedIn');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('Breaks').'</td><td>';
if ($hasOpenBreak > 0) {
	print $langs->trans('OnBreak');
} else {
	print $langs->trans('NotOnBreak');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('Worked').'</td><td>'.clockworkFormatDuration($workedSoFar).'</td></tr>';
print '<tr><td>'.$langs->trans('BreakTime').'</td><td>'.clockworkFormatDuration($breakSoFar).'</td></tr>';
print '<tr><td>'.$langs->trans('Net').'</td><td>'.clockworkFormatDuration($netSoFar).'</td></tr>';

print '</table>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<div class="marginbottomonly">'.$langs->trans('Note').':<br>';
print '<textarea class="quatrevingtpercent" name="note" rows="2"></textarea>';
print '</div>';

print '<div class="center">';
if ($hasOpenShift > 0) {
	print '<button class="button" type="submit" name="action" value="clockout">'.$langs->trans('ClockOut').'</button> ';
	if ($hasOpenBreak > 0) {
		print '<button class="button" type="submit" name="action" value="break_end">'.$langs->trans('EndBreak').'</button>';
	} else {
		print '<button class="button" type="submit" name="action" value="break_start">'.$langs->trans('StartBreak').'</button>';
	}
} else {
	print '<button class="button" type="submit" name="action" value="clockin">'.$langs->trans('ClockIn').'</button>';
}
print '</div>';

print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();

