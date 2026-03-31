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
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_ipcheck.lib.php';

$langs->loadLangs(array('clockwork@clockwork'));

if (!isModEnabled('clockwork')) accessforbidden();
if (!$user->hasRight('clockwork', 'clock')) accessforbidden();

// IP restriction check
$ipCheck = clockworkCheckIPRestriction();
if ($ipCheck['blocked']) {
	llxHeader('', $langs->trans('ClockworkMyTime'));
	clockworkRenderBlockedPage($ipCheck);
	llxFooter();
	$db->close();
	exit;
}

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
$serverNow = dol_now();
$clockinTs = 0;
$openBreakStartTs = 0;
$closedBreakSeconds = 0;
$workedSoFar = 0;
$breakSoFar = 0;
$netSoFar = 0;
$continuousWorkSeconds = 0;
if ($hasOpenShift > 0) {
	$clockinTs = (int) $shift->clockin;
	$workedSoFar = max(0, (int) ($serverNow - $clockinTs));

	// Sum closed breaks seconds
	$sql = 'SELECT SUM(seconds) as s FROM '.MAIN_DB_PREFIX.'clockwork_break WHERE fk_shift = '.((int) $shift->id).' AND break_end IS NOT NULL';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$closedBreakSeconds = empty($obj->s) ? 0 : (int) $obj->s;
	}
	// Add open break duration
	if ($hasOpenBreak > 0) {
		$openBreakStartTs = (int) $break->break_start;
		$breakSoFar = $closedBreakSeconds + max(0, (int) ($serverNow - $openBreakStartTs));
	} else {
		$breakSoFar = $closedBreakSeconds;
	}
	$netSoFar = max(0, $workedSoFar - $breakSoFar);
	
	// Calculate continuous work time (since last break end or clock-in)
	$sqlLastBreak = 'SELECT MAX(break_end) as last_break_end FROM '.MAIN_DB_PREFIX.'clockwork_break';
	$sqlLastBreak .= ' WHERE fk_shift = '.((int) $shift->id).' AND break_end IS NOT NULL';
	$resqlLastBreak = $db->query($sqlLastBreak);
	if ($resqlLastBreak) {
		$objLastBreak = $db->fetch_object($resqlLastBreak);
		$lastBreakEnd = $objLastBreak && $objLastBreak->last_break_end ? $db->jdate($objLastBreak->last_break_end) : $clockinTs;
		$continuousWorkSeconds = max(0, $serverNow - $lastBreakEnd);
	}
}

// Today's stats
$todayStart = dol_mktime(0, 0, 0, (int) dol_print_date($serverNow, '%m'), (int) dol_print_date($serverNow, '%d'), (int) dol_print_date($serverNow, '%Y'));
$todayEnd = $todayStart + 86400;
$sqlToday = 'SELECT COUNT(*) as nb_shifts, COALESCE(SUM(net_seconds), 0) as total_net, COALESCE(SUM(break_seconds), 0) as total_break';
$sqlToday .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
$sqlToday .= ' WHERE fk_user = '.((int) $user->id);
$sqlToday .= ' AND clockin >= '.$db->idate($todayStart);
$sqlToday .= ' AND clockin < '.$db->idate($todayEnd);
$sqlToday .= ' AND status = 1';
$resqlToday = $db->query($sqlToday);
$todayStats = array('nb_shifts' => 0, 'total_net' => 0, 'total_break' => 0);
if ($resqlToday) {
	$objToday = $db->fetch_object($resqlToday);
	$todayStats = array(
		'nb_shifts' => (int) $objToday->nb_shifts,
		'total_net' => (int) $objToday->total_net,
		'total_break' => (int) $objToday->total_break,
	);
}
// Add current session to today's stats
if ($hasOpenShift > 0) {
	$todayStats['nb_shifts']++;
}

// Weekly stats
$weekStart = $serverNow - ((int) dol_print_date($serverNow, '%u') - 1) * 86400;
$weekStart = dol_mktime(0, 0, 0, (int) dol_print_date($weekStart, '%m'), (int) dol_print_date($weekStart, '%d'), (int) dol_print_date($weekStart, '%Y'));
$weekEnd = $weekStart + 7 * 86400;
$sqlWeek = 'SELECT COUNT(*) as nb_shifts, COALESCE(SUM(net_seconds), 0) as total_net';
$sqlWeek .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
$sqlWeek .= ' WHERE fk_user = '.((int) $user->id);
$sqlWeek .= ' AND clockin >= '.$db->idate($weekStart);
$sqlWeek .= ' AND clockin < '.$db->idate($weekEnd);
$sqlWeek .= ' AND status = 1';
$resqlWeek = $db->query($sqlWeek);
$weekStats = array('nb_shifts' => 0, 'total_net' => 0);
if ($resqlWeek) {
	$objWeek = $db->fetch_object($resqlWeek);
	$weekStats = array(
		'nb_shifts' => (int) $objWeek->nb_shifts,
		'total_net' => (int) $objWeek->total_net,
	);
}

// Recent shifts (last 5)
$sqlRecent = 'SELECT clockin, clockout, net_seconds, break_seconds, worked_seconds';
$sqlRecent .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
$sqlRecent .= ' WHERE fk_user = '.((int) $user->id).' AND status = 1';
$sqlRecent .= ' ORDER BY clockin DESC';
$sqlRecent .= ' LIMIT 5';
$resqlRecent = $db->query($sqlRecent);
$recentShifts = array();
if ($resqlRecent) {
	while ($objRecent = $db->fetch_object($resqlRecent)) {
		$recentShifts[] = array(
			'clockin' => $db->jdate($objRecent->clockin),
			'clockout' => $objRecent->clockout ? $db->jdate($objRecent->clockout) : null,
			'net_seconds' => (int) $objRecent->net_seconds,
			'break_seconds' => (int) $objRecent->break_seconds,
			'worked_seconds' => (int) $objRecent->worked_seconds,
		);
	}
}

// Overwork warning
$overworkThreshold = (int) getDolGlobalInt('CLOCKWORK_OVERWORK_THRESHOLD_HOURS', 4) * 3600;
$isOverworking = $continuousWorkSeconds >= $overworkThreshold;

// Current IP
$currentIP = clockworkGetClientIP();

llxHeader('', $langs->trans('ClockworkMyTime'));

print load_fiche_titre($langs->trans('ClockworkMyTime'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'clock', $langs->trans('Clockwork'), -1, 'calendar');

print '<style>
.clockwork-dashboard { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 1024px) { .clockwork-dashboard { grid-template-columns: 1fr 1fr; } .clockwork-dashboard .full-width { grid-column: span 2; } }
.clockwork-card { background: #fff; border: 1px solid #e6e6e6; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.clockwork-card h3 { margin: 0 0 16px 0; font-size: 16px; color: #333; border-bottom: 2px solid #f0f0f0; padding-bottom: 8px; }
.clockwork-status { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.clockwork-status .pill { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
.clockwork-status .pill.on { background: #e8fff0; color: #0f5132; }
.clockwork-status .pill.off { background: #fff3cd; color: #664d03; }
.clockwork-status .pill.break { background: #fff0e6; color: #7a3e00; }
.clockwork-status .pill.warning { background: #ffe6e6; color: #b91c1c; }
.clockwork-timer { font-size: 42px; font-weight: 700; letter-spacing: 1px; color: #1a73e8; text-align: center; margin: 16px 0; }
.clockwork-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.clockwork-kpi { background: #f8f9fa; border-radius: 8px; padding: 12px; text-align: center; }
.clockwork-kpi .label { display: block; font-size: 11px; color: #6c757d; text-transform: uppercase; margin-bottom: 4px; }
.clockwork-kpi .value { font-size: 20px; font-weight: 600; color: #212529; }
.clockwork-progress { height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; margin-top: 8px; }
.clockwork-progress-bar { height: 100%; background: linear-gradient(90deg, #1a73e8, #34a853); transition: width 0.3s ease; }
.clockwork-progress-bar.overwork { background: linear-gradient(90deg, #f59e0b, #dc3545); }
.clockwork-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.clockwork-actions button { cursor: pointer; }
.clockwork-break-btn { background: #f59e0b !important; border-color: #f59e0b !important; color: #111 !important; }
.clockwork-note textarea { width: 100%; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; resize: vertical; }
.clockwork-recent { margin-top: 8px; }
.clockwork-recent table { width: 100%; border-collapse: collapse; }
.clockwork-recent th, .clockwork-recent td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.clockwork-recent th { background: #f8f9fa; font-weight: 600; color: #495057; }
.clockwork-info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f8f9fa; }
.clockwork-info-row:last-child { border-bottom: none; }
.clockwork-info-label { color: #6c757d; font-size: 13px; }
.clockwork-info-value { color: #212529; font-weight: 500; font-size: 13px; }
.clockwork-overwork-alert { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
.clockwork-overwork-alert .icon { font-size: 24px; }
.clockwork-overwork-alert .text { font-size: 13px; color: #856404; }
</style>';

print '<div class="clockwork-dashboard">';

// Main Status Card
print '<div class="clockwork-card">';
print '<h3>'.$langs->trans('CurrentStatus').'</h3>';

// Overwork alert
if ($isOverworking && $hasOpenShift > 0) {
	print '<div class="clockwork-overwork-alert">';
	print '<span class="icon">⚠️</span>';
	print '<span class="text"><strong>'.$langs->trans('OverworkAlert').'</strong><br>';
	print sprintf($langs->trans('OverworkMessage'), clockworkFormatDuration($continuousWorkSeconds));
	print '</span>';
	print '</div>';
}

print '<div class="clockwork-status">';
if ($hasOpenShift > 0) {
	print '<span class="pill on">🟢 '.$langs->trans('ClockedInAt').' '.dol_print_date($shift->clockin, 'dayhour').'</span>';
	if ($hasOpenBreak > 0) {
		print '<span class="pill break">⏸️ '.$langs->trans('OnBreak').'</span>';
	}
} else {
	print '<span class="pill off">⚪ '.$langs->trans('NotClockedIn').'</span>';
}
print '</div>';

print '<div class="clockwork-timer" id="cw_timer">'.clockworkFormatDuration($netSoFar).'</div>';

print '<div class="clockwork-kpis">';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('Worked').'</span><span class="value" id="cw_worked">'.clockworkFormatDuration($workedSoFar).'</span></div>';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('BreakTime').'</span><span class="value" id="cw_break">'.clockworkFormatDuration($breakSoFar).'</span></div>';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('Net').'</span><span class="value" id="cw_net">'.clockworkFormatDuration($netSoFar).'</span></div>';
print '</div>';

// Progress bar (8-hour workday)
$workdayTarget = 8 * 3600;
$progressPercent = min(100, ($netSoFar / $workdayTarget) * 100);
print '<div style="margin-top: 16px;">';
print '<div style="display: flex; justify-content: space-between; font-size: 12px; color: #6c757d;">';
print '<span>'.$langs->trans('DailyProgress').'</span>';
print '<span>'.number_format($progressPercent, 0).'%</span>';
print '</div>';
print '<div class="clockwork-progress">';
print '<div class="clockwork-progress-bar'.($isOverworking ? ' overwork' : '').'" style="width: '.$progressPercent.'%"></div>';
print '</div>';
print '</div>';

// Actions
print '<div class="clockwork-actions">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0; width: 100%;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="note" id="clockwork_note_proxy" value="">';

if ($hasOpenShift > 0) {
	print '<button class="butActionDelete" type="submit" name="action" value="clockout">'.$langs->trans('ClockOut').'</button>';
	if ($hasOpenBreak > 0) {
		print '<button class="butAction clockwork-break-btn" type="submit" name="action" value="break_end">'.$langs->trans('EndBreak').'</button>';
	} else {
		print '<button class="butAction clockwork-break-btn" type="submit" name="action" value="break_start">'.$langs->trans('StartBreak').'</button>';
	}
} else {
	print '<button class="butAction" type="submit" name="action" value="clockin">'.$langs->trans('ClockIn').'</button>';
}
print '</form>';
print '</div>';

print '</div>'; // End main card

// Session Info Card
print '<div class="clockwork-card">';
print '<h3>'.$langs->trans('SessionInfo').'</h3>';
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('YourIPAddress').'</span><span class="clockwork-info-value">'.dol_escape_htmltag($currentIP).'</span></div>';
if ($hasOpenShift > 0) {
	print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('SessionDuration').'</span><span class="clockwork-info-value" id="cw_session_duration">'.clockworkFormatDuration($workedSoFar).'</span></div>';
	print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('TimeSinceLastBreak').'</span><span class="clockwork-info-value" id="cw_since_break">'.clockworkFormatDuration($continuousWorkSeconds).'</span></div>';
}
print '</div>';

// Today's Stats Card
print '<div class="clockwork-card">';
print '<h3>'.$langs->trans('TodayStats').'</h3>';
$todayTotalNet = $todayStats['total_net'] + ($hasOpenShift > 0 ? $netSoFar : 0);
$todayTotalBreak = $todayStats['total_break'] + ($hasOpenShift > 0 ? $breakSoFar : 0);
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('ShiftsCompleted').'</span><span class="clockwork-info-value">'.$todayStats['nb_shifts'].'</span></div>';
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('TotalWorked').'</span><span class="clockwork-info-value">'.clockworkFormatDuration($todayTotalNet).'</span></div>';
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('TotalBreaks').'</span><span class="clockwork-info-value">'.clockworkFormatDuration($todayTotalBreak).'</span></div>';
print '</div>';

// Weekly Stats Card
print '<div class="clockwork-card">';
print '<h3>'.$langs->trans('WeeklyStats').'</h3>';
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('DaysWorked').'</span><span class="clockwork-info-value">'.$weekStats['nb_shifts'].'</span></div>';
print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('TotalHours').'</span><span class="clockwork-info-value">'.clockworkFormatDuration($weekStats['total_net']).'</span></div>';
if ($weekStats['nb_shifts'] > 0) {
	$avgDaily = (int) ($weekStats['total_net'] / $weekStats['nb_shifts']);
	print '<div class="clockwork-info-row"><span class="clockwork-info-label">'.$langs->trans('AverageDaily').'</span><span class="clockwork-info-value">'.clockworkFormatDuration($avgDaily).'</span></div>';
}
print '</div>';

// Recent Shifts Card (full width)
print '<div class="clockwork-card full-width">';
print '<h3>'.$langs->trans('RecentShifts').'</h3>';
if (empty($recentShifts)) {
	print '<p class="opacitymedium">'.$langs->trans('NoRecentShifts').'</p>';
} else {
	print '<div class="clockwork-recent">';
	print '<table>';
	print '<tr><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('ClockInTime').'</th><th>'.$langs->trans('ClockOutTime').'</th><th>'.$langs->trans('Net').'</th></tr>';
	foreach ($recentShifts as $rs) {
		print '<tr>';
		print '<td>'.dol_print_date($rs['clockin'], 'day').'</td>';
		print '<td>'.dol_print_date($rs['clockin'], 'hour').'</td>';
		print '<td>'.($rs['clockout'] ? dol_print_date($rs['clockout'], 'hour') : '-').'</td>';
		print '<td>'.clockworkFormatDuration($rs['net_seconds']).'</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}
print '</div>';

// Note Card
print '<div class="clockwork-card full-width">';
print '<h3>'.$langs->trans('Note').'</h3>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="note" id="clockwork_note_proxy2" value="">';
print '<textarea name="note_ui" id="clockwork_note" rows="3" placeholder="'.$langs->trans('Note').'..." style="width: 100%; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; resize: vertical;"></textarea>';
print '<div class="opacitymedium" style="margin-top:8px;">'.$langs->trans('NoteWillBeSaved').'</div>';
print '</form>';
print '</div>';

print '</div>'; // clockwork-dashboard

print dol_get_fiche_end();

print '<script>
(function () {
  const clockin = '.((int) $clockinTs).';
  const breakstart = '.((int) $openBreakStartTs).';
  const closedBreak = '.((int) $closedBreakSeconds).';
  const serverNow = '.((int) $serverNow).';

  const clientNow = Math.floor(Date.now() / 1000);
  const offset = serverNow ? (serverNow - clientNow) : 0;

  function fmt(sec) {
    sec = Math.max(0, sec|0);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return String(h).padStart(2, "0") + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
  }

  function setText(id, v) {
    const el = document.getElementById(id);
    if (el) el.textContent = v;
  }

  function tick() {
    if (!clockin) return;
    const now = Math.floor(Date.now() / 1000) + offset;
    const worked = Math.max(0, now - clockin);
    const openBreak = breakstart ? Math.max(0, now - breakstart) : 0;
    const breakTotal = Math.max(0, closedBreak + openBreak);
    const net = Math.max(0, worked - breakTotal);
    setText("cw_worked", fmt(worked));
    setText("cw_break", fmt(breakTotal));
    setText("cw_net", fmt(net));
    setText("cw_timer", fmt(net));
    setText("cw_session_duration", fmt(worked));
    setText("cw_since_break", fmt(openBreak > 0 ? openBreak : (now - (clockin + closedBreak))));
  }

  // Keep note in the same POST form that contains the buttons.
  const noteEl = document.getElementById("clockwork_note");
  const proxyEl = document.getElementById("clockwork_note_proxy");
  const proxyEl2 = document.getElementById("clockwork_note_proxy2");
  if (noteEl) {
    const sync = () => { 
      if (proxyEl) proxyEl.value = noteEl.value || ""; 
      if (proxyEl2) proxyEl2.value = noteEl.value || ""; 
    };
    noteEl.addEventListener("input", sync);
    sync();
  }

  tick();
  window.setInterval(tick, 1000);
})();
</script>';

llxFooter();
$db->close();