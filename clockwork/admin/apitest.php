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

$langs->loadLangs(array('admin', 'users', 'clockwork@clockwork'));

if (!$user->admin) accessforbidden();

$form = new Form($db);
$action = GETPOST('action', 'aZ09');

function cw_hash_prefix($value)
{
	if ($value === '') return '';
	return substr(hash('sha256', $value), 0, 10);
}

$resultRow = null;
$resultError = '';
$tokenLen = 0;
$tokenHash = '';

if ($action === 'test') {
	$token = trim((string) GETPOST('token', 'none'));
	$tokenLen = strlen($token);
	$tokenHash = cw_hash_prefix($token);

	if ($token === '') {
		$resultError = 'Token is empty.';
	} else {
		$sql = "SELECT rowid, login, entity, statut, api_key FROM ".MAIN_DB_PREFIX."user";
		$sql .= " WHERE entity IN (0,".((int) $conf->entity).")";
		$sql .= " AND api_key = '".$db->escape($token)."'";
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			$resultError = $db->lasterror();
		} else {
			$resultRow = $db->fetch_object($resql);
		}
	}
}

llxHeader('', 'Clockwork API diagnostics');

print load_fiche_titre('Clockwork API diagnostics', '', 'title_setup');

print '<div class="opacitymedium">Current entity: '.((int) $conf->entity).'</div>';
print '<div class="opacitymedium">Header visibility (this page):</div>';
print '<ul class="opacitymedium" style="margin-top:6px;">';
print '<li>HTTP_AUTHORIZATION: '.(!empty($_SERVER['HTTP_AUTHORIZATION']) ? 'present (len '.strlen($_SERVER['HTTP_AUTHORIZATION']).')' : 'missing').'</li>';
print '<li>HTTP_X_API_KEY: '.(!empty($_SERVER['HTTP_X_API_KEY']) ? 'present (len '.strlen($_SERVER['HTTP_X_API_KEY']).')' : 'missing').'</li>';
print '</ul>';

print '<hr>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Test a token against llx_user.api_key (entity 0 or current entity)</td></tr>';
print '<tr class="oddeven"><td class="titlefield">Token</td><td><input class="quatrevingtpercent" type="password" name="token" value=""></td></tr>';
print '</table>';
print '<div class="center"><input class="button button-save" type="submit" value="Test token"></div>';
print '</form>';

if ($action === 'test') {
	print '<br>';
	if ($resultError) {
		print '<div class="error">'.$resultError.'</div>';
	} else {
		print '<div class="opacitymedium">Provided token: len '.$tokenLen.', sha256 '.$tokenHash.'…</div>';
		if ($resultRow) {
			print '<div class="ok">MATCH: user id '.((int) $resultRow->rowid).' / login '.dol_escape_htmltag($resultRow->login).', entity '.((int) $resultRow->entity).', enabled '.(((int) $resultRow->statut) === 1 ? 'yes' : 'no').'</div>';
		} else {
			print '<div class="warning">NO MATCH. Check: user enabled, api_key saved on the right company/entity, no extra spaces.</div>';
		}
	}
}

print '<hr>';

// List users with api_key set (masked)
$sqlList = "SELECT rowid, login, entity, statut, api_key FROM ".MAIN_DB_PREFIX."user";
$sqlList .= " WHERE entity IN (0,".((int) $conf->entity).")";
$sqlList .= " AND api_key IS NOT NULL AND api_key <> ''";
$sqlList .= " ORDER BY entity ASC, login ASC";
$resList = $db->query($sqlList);

print '<div class="bold">Users with api_key set (masked)</div>';
print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre"><th>Login</th><th class="center">Entity</th><th class="center">Enabled</th><th class="center">Key length</th><th>Key sha256 prefix</th></tr>';
if ($resList) {
	while ($u = $db->fetch_object($resList)) {
		$key = (string) $u->api_key;
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($u->login).'</td>';
		print '<td class="center">'.((int) $u->entity).'</td>';
		print '<td class="center">'.(((int) $u->statut) === 1 ? 'yes' : 'no').'</td>';
		print '<td class="center">'.strlen($key).'</td>';
		print '<td>'.cw_hash_prefix($key).'…</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

llxFooter();
$db->close();

