<?php
/**
 * Clockwork heartbeat endpoint (session-authenticated).
 */

if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');

header('Content-Type: application/json; charset=utf-8');

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
	http_response_code(500);
	echo json_encode(array('ok' => false, 'error' => 'Include of main fails'));
	exit;
}

if (!isModEnabled('clockwork') || !$user->hasRight('clockwork', 'clock')) {
	http_response_code(403);
	echo json_encode(array('ok' => false, 'error' => 'Forbidden'));
	exit;
}

require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkshift.class.php';

$shift = new ClockworkShift($db);
$open = $shift->fetchOpenByUser((int) $user->id);
if ($open <= 0) {
	echo json_encode(array('ok' => true, 'open_shift' => 0));
	exit;
}

$now = dol_now();
$sql = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift';
$sql .= ' SET last_activity_at = \''.$db->idate($now).'\', idle_notified_at = NULL';
$sql .= ', idle_notif_count = 0';
$sql .= ' WHERE rowid = '.((int) $shift->id).' AND status = 0';

$ok = (bool) $db->query($sql);
echo json_encode(array(
	'ok' => $ok,
	'open_shift' => 1,
	'shift_id' => (int) $shift->id,
	'ts' => (int) $now,
));
