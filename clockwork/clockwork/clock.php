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
$serverNow = dol_now();
$clockinTs = 0;
$openBreakStartTs = 0;
$closedBreakSeconds = 0;
$workedSoFar = 0;
$breakSoFar = 0;
$netSoFar = 0;
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
}

llxHeader('', $langs->trans('ClockworkMyTime'));

print load_fiche_titre($langs->trans('ClockworkMyTime'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'clock', $langs->trans('Clockwork'), -1, 'calendar');

print '<style>
.clockwork-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
.clockwork-statusbox { border: 1px solid #e6e6e6; border-radius: 8px; padding: 14px; background: #fff; }
.clockwork-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
.clockwork-kpi { border: 1px solid #f0f0f0; border-radius: 8px; padding: 10px; background: #fafafa; }
.clockwork-kpi .label { display: block; opacity: .75; font-size: 12px; margin-bottom: 4px; }
.clockwork-kpi .value { font-size: 18px; font-weight: 600; letter-spacing: .5px; }
.clockwork-timer { font-size: 34px; font-weight: 700; letter-spacing: 1px; margin-top: 6px; }
.clockwork-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.clockwork-pill.on { background: #e8fff0; color: #0f5132; border: 1px solid #b7f7cf; }
.clockwork-pill.off { background: #fff3cd; color: #664d03; border: 1px solid #ffe69c; }
.clockwork-pill.break { background: #fff0e6; color: #7a3e00; border: 1px solid #ffd1b3; }
.clockwork-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.clockwork-actions button { cursor: pointer; }
.clockwork-break-btn { background: #f59e0b !important; border-color: #f59e0b !important; color: #111 !important; }
.clockwork-note textarea { width: min(900px, 100%); }
@media (min-width: 900px) { .clockwork-grid { grid-template-columns: 1.2fr .8fr; align-items: start; } }
</style>';

print '<div id="clockwork-live" class="clockwork-grid"';
print ' data-server-now="'.((int) $serverNow).'"';
print ' data-clockin="'.((int) $clockinTs).'"';
print ' data-breakstart="'.((int) $openBreakStartTs).'"';
print ' data-closed-break="'.((int) $closedBreakSeconds).'"';
print '>';

print '<div class="clockwork-statusbox">';
print '<div style="display:flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap;">';
print '<div>';
if ($hasOpenShift > 0) {
	print '<span class="clockwork-pill on">'.$langs->trans('ClockedInAt').' '.dol_print_date($shift->clockin, 'dayhour').'</span>';
	if ($hasOpenBreak > 0) {
		print ' <span class="clockwork-pill break">'.$langs->trans('OnBreak').'</span>';
	}
} else {
	print '<span class="clockwork-pill off">'.$langs->trans('NotClockedIn').'</span>';
}
print '</div>';

print '<div class="clockwork-actions">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
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
print '</div>';

print '<div class="clockwork-timer" id="cw_timer">'.clockworkFormatDuration($netSoFar).'</div>';

print '<div class="clockwork-kpis">';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('Worked').'</span><span class="value" id="cw_worked">'.clockworkFormatDuration($workedSoFar).'</span></div>';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('BreakTime').'</span><span class="value" id="cw_break">'.clockworkFormatDuration($breakSoFar).'</span></div>';
print '<div class="clockwork-kpi"><span class="label">'.$langs->trans('Net').'</span><span class="value" id="cw_net">'.clockworkFormatDuration($netSoFar).'</span></div>';
print '</div>';

print '</div>'; // statusbox

print '<div class="clockwork-statusbox clockwork-note">';
print '<div class="marginbottomonly">'.$langs->trans('Note').'</div>';
print '<textarea name="note_ui" id="clockwork_note" rows="3" placeholder="'.$langs->trans('Note').'..."></textarea>';
print '<div class="opacitymedium" style="margin-top:8px;">This note will be saved with your next Clockwork action.</div>';
print '</div>';

print '</div>'; // clockwork-live

print '<script>
(function () {
  const root = document.getElementById(\"clockwork-live\");
  if (!root) return;

  const clockin = parseInt(root.dataset.clockin || \"0\", 10);
  const breakstart = parseInt(root.dataset.breakstart || \"0\", 10);
  const closedBreak = parseInt(root.dataset.closedBreak || \"0\", 10);
  const serverNow = parseInt(root.dataset.serverNow || \"0\", 10);

  const clientNow = Math.floor(Date.now() / 1000);
  const offset = serverNow ? (serverNow - clientNow) : 0;

  function fmt(sec) {
    sec = Math.max(0, sec|0);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return String(h).padStart(2, \"0\") + \":\" + String(m).padStart(2, \"0\") + \":\" + String(s).padStart(2, \"0\");
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
    setText(\"cw_worked\", fmt(worked));
    setText(\"cw_break\", fmt(breakTotal));
    setText(\"cw_net\", fmt(net));
    setText(\"cw_timer\", fmt(net));
  }

  // Keep note in the same POST form that contains the buttons.
  const noteEl = document.getElementById(\"clockwork_note\");
  const proxyEl = document.getElementById(\"clockwork_note_proxy\");
  if (noteEl && proxyEl) {
    const sync = () => { proxyEl.value = noteEl.value || \"\"; };
    noteEl.addEventListener(\"input\", sync);
    sync();
  }

  tick();
  window.setInterval(tick, 1000);
})();
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
