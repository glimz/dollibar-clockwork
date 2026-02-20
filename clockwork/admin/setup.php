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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('admin', 'clockwork@clockwork'));

if (!$user->admin) accessforbidden();

$form = new Form($db);
$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
	$allowCors = GETPOSTINT('CLOCKWORK_API_ALLOW_CORS');
	dolibarr_set_const($db, 'CLOCKWORK_API_ALLOW_CORS', $allowCors, 'yesno', 0, '', $conf->entity);
	$allowQueryToken = GETPOSTINT('CLOCKWORK_API_ALLOW_QUERY_TOKEN');
	dolibarr_set_const($db, 'CLOCKWORK_API_ALLOW_QUERY_TOKEN', $allowQueryToken, 'yesno', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('ClockworkSetup'));

print load_fiche_titre($langs->trans('ClockworkSetup'), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Settings').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkApiAllowCors').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_API_ALLOW_CORS', getDolGlobalInt('CLOCKWORK_API_ALLOW_CORS', 0), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Allow API token in query string (api_key)</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_API_ALLOW_QUERY_TOKEN', getDolGlobalInt('CLOCKWORK_API_ALLOW_QUERY_TOKEN', 0), 1);
print '<br><span class="opacitymedium">Not recommended (tokens may end up in logs). Prefer Authorization: Bearer or X-API-Key.</span>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br>';
print '<div class="center">';
print '<a class="butAction" href="apitest.php">API diagnostics</a>';
print '</div>';

llxFooter();
$db->close();
