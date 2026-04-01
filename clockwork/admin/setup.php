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
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_webhook.lib.php';

$langs->loadLangs(array('admin', 'clockwork@clockwork'));

if (!$user->admin) accessforbidden();

$form = new Form($db);
$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
	$allowCors = GETPOSTINT('CLOCKWORK_API_ALLOW_CORS');
	dolibarr_set_const($db, 'CLOCKWORK_API_ALLOW_CORS', $allowCors, 'yesno', 0, '', $conf->entity);
	$allowQueryToken = GETPOSTINT('CLOCKWORK_API_ALLOW_QUERY_TOKEN');
	dolibarr_set_const($db, 'CLOCKWORK_API_ALLOW_QUERY_TOKEN', $allowQueryToken, 'yesno', 0, '', $conf->entity);

	$webhookDefault = (string) GETPOST('CLOCKWORK_WEBHOOK_DEFAULT', 'nohtml');
	$webhookClockin = (string) GETPOST('CLOCKWORK_WEBHOOK_CLOCKIN', 'nohtml');
	$webhookBreak = (string) GETPOST('CLOCKWORK_WEBHOOK_BREAK', 'nohtml');
	$webhookMissed = (string) GETPOST('CLOCKWORK_WEBHOOK_MISSED_CLOCKIN', 'nohtml');
	$webhookWeekly = (string) GETPOST('CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY', 'nohtml');

	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_DEFAULT', $webhookDefault, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_CLOCKIN', $webhookClockin, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_BREAK', $webhookBreak, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_MISSED_CLOCKIN', $webhookMissed, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY', $webhookWeekly, 'chaine', 0, '', $conf->entity);

	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_CLOCKIN', GETPOSTINT('CLOCKWORK_NOTIFY_CLOCKIN'), 'yesno', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_BREAK', GETPOSTINT('CLOCKWORK_NOTIFY_BREAK'), 'yesno', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_MISSED_CLOCKIN', GETPOSTINT('CLOCKWORK_NOTIFY_MISSED_CLOCKIN'), 'yesno', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_WEEKLY_SUMMARY', GETPOSTINT('CLOCKWORK_NOTIFY_WEEKLY_SUMMARY'), 'yesno', 0, '', $conf->entity);

	$denylist = (string) GETPOST('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_DENYLIST_LOGINS', $denylist, 'chaine', 0, '', $conf->entity);

	$missedTz = (string) GETPOST('CLOCKWORK_MISSED_CLOCKIN_TZ', 'nohtml');
	$missedCutoff = (string) GETPOST('CLOCKWORK_MISSED_CLOCKIN_CUTOFF', 'nohtml');
	$missedGrace = GETPOSTINT('CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES');
	$missedWeekdays = (string) GETPOST('CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS', 'nohtml');
	$respectLeave = GETPOSTINT('CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE');
	$respectHolidays = GETPOSTINT('CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS');
	$holidayCountryCode = (string) GETPOST('CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE', 'nohtml');

	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_TZ', $missedTz, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_CUTOFF', $missedCutoff, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES', $missedGrace, 'integer', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS', $missedWeekdays, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE', $respectLeave, 'yesno', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS', $respectHolidays, 'yesno', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE', $holidayCountryCode, 'chaine', 0, '', $conf->entity);

	$weeklyTz = (string) GETPOST('CLOCKWORK_WEEKLY_SUMMARY_TZ', 'nohtml');
	$weeklyDow = GETPOSTINT('CLOCKWORK_WEEKLY_SUMMARY_DOW');
	$weeklyTime = (string) GETPOST('CLOCKWORK_WEEKLY_SUMMARY_TIME', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEEKLY_SUMMARY_TZ', $weeklyTz, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEEKLY_SUMMARY_DOW', $weeklyDow, 'integer', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_WEEKLY_SUMMARY_TIME', $weeklyTime, 'chaine', 0, '', $conf->entity);

	// IP restriction settings
	$allowedIPs = (string) GETPOST('CLOCKWORK_ALLOWED_IPS', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_ALLOWED_IPS', $allowedIPs, 'chaine', 0, '', $conf->entity);

	// Network change monitoring
	$monitorNetwork = GETPOSTINT('CLOCKWORK_MONITOR_NETWORK_CHANGES');
	dolibarr_set_const($db, 'CLOCKWORK_MONITOR_NETWORK_CHANGES', $monitorNetwork, 'yesno', 0, '', $conf->entity);

	// Overwork settings
	$webhookOverwork = (string) GETPOST('CLOCKWORK_WEBHOOK_OVERWORK', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_OVERWORK', $webhookOverwork, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_OVERWORK', GETPOSTINT('CLOCKWORK_NOTIFY_OVERWORK'), 'yesno', 0, '', $conf->entity);
	$overworkThreshold = GETPOSTINT('CLOCKWORK_OVERWORK_THRESHOLD_HOURS');
	dolibarr_set_const($db, 'CLOCKWORK_OVERWORK_THRESHOLD_HOURS', $overworkThreshold, 'integer', 0, '', $conf->entity);

	// Logout reminder settings
	$webhookLogout = (string) GETPOST('CLOCKWORK_WEBHOOK_LOGOUT_REMINDER', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_LOGOUT_REMINDER', $webhookLogout, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_LOGOUT_REMINDER', GETPOSTINT('CLOCKWORK_NOTIFY_LOGOUT_REMINDER'), 'yesno', 0, '', $conf->entity);
	$logoutCutoff = (string) GETPOST('CLOCKWORK_LOGOUT_REMINDER_CUTOFF', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_LOGOUT_REMINDER_CUTOFF', $logoutCutoff, 'chaine', 0, '', $conf->entity);
	$logoutTz = (string) GETPOST('CLOCKWORK_LOGOUT_REMINDER_TZ', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_LOGOUT_REMINDER_TZ', $logoutTz, 'chaine', 0, '', $conf->entity);

	// Network change settings
	$webhookNetwork = (string) GETPOST('CLOCKWORK_WEBHOOK_NETWORK_CHANGE', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_NETWORK_CHANGE', $webhookNetwork, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_NETWORK_CHANGE', GETPOSTINT('CLOCKWORK_NOTIFY_NETWORK_CHANGE'), 'yesno', 0, '', $conf->entity);

	// Browser notifications settings
	dolibarr_set_const($db, 'CLOCKWORK_ENABLE_BROWSER_NOTIFICATIONS', GETPOSTINT('CLOCKWORK_ENABLE_BROWSER_NOTIFICATIONS'), 'yesno', 0, '', $conf->entity);

	// Maximum shift length settings
	$webhookMaxShift = (string) GETPOST('CLOCKWORK_WEBHOOK_MAX_SHIFT', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_MAX_SHIFT', $webhookMaxShift, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_MAX_SHIFT', GETPOSTINT('CLOCKWORK_NOTIFY_MAX_SHIFT'), 'yesno', 0, '', $conf->entity);
	$maxShiftHours = GETPOSTINT('CLOCKWORK_MAX_SHIFT_HOURS');
	dolibarr_set_const($db, 'CLOCKWORK_MAX_SHIFT_HOURS', $maxShiftHours, 'integer', 0, '', $conf->entity);

	// Escalating break reminders
	dolibarr_set_const($db, 'CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS', GETPOSTINT('CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS'), 'yesno', 0, '', $conf->entity);
	$breakReminderHours = GETPOST('CLOCKWORK_BREAK_REMINDER_HOURS', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_BREAK_REMINDER_HOURS', $breakReminderHours, 'chaine', 0, '', $conf->entity);

	// Weekly overtime detection
	$webhookOvertime = (string) GETPOST('CLOCKWORK_WEBHOOK_OVERTIME', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_OVERTIME', $webhookOvertime, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_OVERTIME', GETPOSTINT('CLOCKWORK_NOTIFY_OVERTIME'), 'yesno', 0, '', $conf->entity);
	$weeklyOvertimeHours = GETPOSTINT('CLOCKWORK_WEEKLY_OVERTIME_HOURS');
	dolibarr_set_const($db, 'CLOCKWORK_WEEKLY_OVERTIME_HOURS', $weeklyOvertimeHours, 'integer', 0, '', $conf->entity);

	// Slack/Teams webhooks
	$slackWebhook = (string) GETPOST('CLOCKWORK_WEBHOOK_SLACK', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_SLACK', $slackWebhook, 'chaine', 0, '', $conf->entity);
	$teamsWebhook = (string) GETPOST('CLOCKWORK_WEBHOOK_TEAMS', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_WEBHOOK_TEAMS', $teamsWebhook, 'chaine', 0, '', $conf->entity);

	// Fatigue management settings
	dolibarr_set_const($db, 'CLOCKWORK_NOTIFY_FATIGUE', GETPOSTINT('CLOCKWORK_NOTIFY_FATIGUE'), 'yesno', 0, '', $conf->entity);
	$minRestHours = GETPOST('CLOCKWORK_MIN_REST_HOURS', 'nohtml');
	dolibarr_set_const($db, 'CLOCKWORK_MIN_REST_HOURS', $minRestHours, 'chaine', 0, '', $conf->entity);

	// Auto-close shifts settings
	dolibarr_set_const($db, 'CLOCKWORK_AUTO_CLOSE_SHIFTS', GETPOSTINT('CLOCKWORK_AUTO_CLOSE_SHIFTS'), 'yesno', 0, '', $conf->entity);
	$autoCloseHours = GETPOSTINT('CLOCKWORK_AUTO_CLOSE_HOURS');
	dolibarr_set_const($db, 'CLOCKWORK_AUTO_CLOSE_HOURS', $autoCloseHours, 'integer', 0, '', $conf->entity);

	// Concurrent session detection
	dolibarr_set_const($db, 'CLOCKWORK_DETECT_CONCURRENT', GETPOSTINT('CLOCKWORK_DETECT_CONCURRENT'), 'yesno', 0, '', $conf->entity);

	// Shift pattern violation detection
	dolibarr_set_const($db, 'CLOCKWORK_DETECT_SHIFT_PATTERN', GETPOSTINT('CLOCKWORK_DETECT_SHIFT_PATTERN'), 'yesno', 0, '', $conf->entity);
	$shiftPatternGrace = GETPOSTINT('CLOCKWORK_SHIFT_PATTERN_GRACE');
	dolibarr_set_const($db, 'CLOCKWORK_SHIFT_PATTERN_GRACE', $shiftPatternGrace, 'integer', 0, '', $conf->entity);

	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'test_webhook') {
	$type = (string) GETPOST('type', 'aZ09');
	if (empty($type)) $type = CLOCKWORK_NOTIFY_TYPE_CLOCKIN;

	// Use rich embed for test webhook
	$fields = array(
		clockworkEmbedField('Type', $type, true),
		clockworkEmbedField('Time', dol_print_date(dol_now(), 'dayhour'), true),
		clockworkEmbedField('Status', 'Test notification', true),
	);

	$res = clockworkNotifyEmbed($type, array(
		'title' => '🔔 Test Notification',
		'description' => 'This is a test webhook from Clockwork setup.',
		'color' => 3447003, // Blue
		'fields' => $fields,
		'footer' => 'Clockwork • Setup Test',
	));

	if (!empty($res['ok'])) {
		setEventMessages('Webhook test sent (rich embed).', null, 'mesgs');
	} else {
		setEventMessages('Webhook test failed: '.(!empty($res['error']) ? $res['error'] : 'unknown error'), null, 'errors');
	}
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

print '<tr class="liste_titre"><td>Discord webhook notifications</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Enable clock-in alerts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_CLOCKIN', getDolGlobalInt('CLOCKWORK_NOTIFY_CLOCKIN', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Enable break alerts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_BREAK', getDolGlobalInt('CLOCKWORK_NOTIFY_BREAK', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Enable missed clock-in alerts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_MISSED_CLOCKIN', getDolGlobalInt('CLOCKWORK_NOTIFY_MISSED_CLOCKIN', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Enable weekly summary</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_WEEKLY_SUMMARY', getDolGlobalInt('CLOCKWORK_NOTIFY_WEEKLY_SUMMARY', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Exclude logins (denylist)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_NOTIFY_DENYLIST_LOGINS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_NOTIFY_DENYLIST_LOGINS', 'admin,user.api')).'">';
print '<br><span class="opacitymedium">Comma/space separated logins to exclude (e.g. admin, user.api).</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Default webhook URL</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_DEFAULT" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_DEFAULT')).'">';
print '<br><span class="opacitymedium">If per-type webhook is empty, Clockwork falls back to this.</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Clock-in webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_CLOCKIN" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_CLOCKIN')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Break webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_BREAK" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_BREAK')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Missed clock-in webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_MISSED_CLOCKIN" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_MISSED_CLOCKIN')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Weekly summary webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>Missed clock-in policy</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Timezone</td>';
print '<td><input type="text" name="CLOCKWORK_MISSED_CLOCKIN_TZ" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_TZ', 'Africa/Lagos')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Cutoff time (HH:MM)</td>';
print '<td><input type="text" name="CLOCKWORK_MISSED_CLOCKIN_CUTOFF" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_CUTOFF', '09:30')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Grace period (minutes)</td>';
print '<td><input type="number" min="0" step="1" name="CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES" value="'.((int) getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_GRACE_MINUTES', 0)).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Weekdays to check (1=Mon..7=Sun)</td>';
print '<td><input type="text" class="minwidth300" name="CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_MISSED_CLOCKIN_WEEKDAYS', '1,2,3,4,5')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Skip if user is on approved leave</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE', getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_RESPECT_LEAVE', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Skip public holidays</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS', getDolGlobalInt('CLOCKWORK_MISSED_CLOCKIN_RESPECT_PUBLIC_HOLIDAYS', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Public holiday country code override</td>';
print '<td><input type="text" name="CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_PUBLIC_HOLIDAY_COUNTRY_CODE')).'">';
print '<br><span class="opacitymedium">Leave empty to use company country (Setup → Company/Organization).</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>Weekly summary schedule</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Timezone</td>';
print '<td><input type="text" name="CLOCKWORK_WEEKLY_SUMMARY_TZ" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEEKLY_SUMMARY_TZ', 'Africa/Lagos')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Day of week (1=Mon..7=Sun)</td>';
print '<td><input type="number" min="1" max="7" step="1" name="CLOCKWORK_WEEKLY_SUMMARY_DOW" value="'.((int) getDolGlobalInt('CLOCKWORK_WEEKLY_SUMMARY_DOW', 1)).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Time (HH:MM)</td>';
print '<td><input type="text" name="CLOCKWORK_WEEKLY_SUMMARY_TIME" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEEKLY_SUMMARY_TIME', '09:35')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>IP Restriction (Access Control)</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Allowed IP ranges</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_ALLOWED_IPS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_ALLOWED_IPS')).'">';
print '<br><span class="opacitymedium">CIDR notation, comma separated (e.g. 10.0.0.0/8, 192.168.1.0/24). Leave empty to allow all IPs.</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>Overwork Detection</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Enable overwork alerts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_OVERWORK', getDolGlobalInt('CLOCKWORK_NOTIFY_OVERWORK', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Overwork threshold (hours)</td>';
print '<td><input type="number" min="1" max="24" step="1" name="CLOCKWORK_OVERWORK_THRESHOLD_HOURS" value="'.((int) getDolGlobalInt('CLOCKWORK_OVERWORK_THRESHOLD_HOURS', 4)).'">';
print '<br><span class="opacitymedium">Alert when user works continuously without a break for this many hours.</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Overwork webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_OVERWORK" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_OVERWORK')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>Logout Reminder</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Enable logout reminders</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_LOGOUT_REMINDER', getDolGlobalInt('CLOCKWORK_NOTIFY_LOGOUT_REMINDER', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Reminder cutoff time (HH:MM)</td>';
print '<td><input type="text" name="CLOCKWORK_LOGOUT_REMINDER_CUTOFF" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_LOGOUT_REMINDER_CUTOFF', '23:00')).'">';
print '<br><span class="opacitymedium">Send reminder to users who haven\'t clocked out after this time.</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Reminder timezone</td>';
print '<td><input type="text" name="CLOCKWORK_LOGOUT_REMINDER_TZ" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_LOGOUT_REMINDER_TZ', 'Africa/Lagos')).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Logout reminder webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_LOGOUT_REMINDER" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_LOGOUT_REMINDER')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>Network Change Monitoring</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>Enable network change alerts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_NETWORK_CHANGE', getDolGlobalInt('CLOCKWORK_NOTIFY_NETWORK_CHANGE', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Monitor network changes during shifts</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_MONITOR_NETWORK_CHANGES', getDolGlobalInt('CLOCKWORK_MONITOR_NETWORK_CHANGES', 1), 1);
print '<br><span class="opacitymedium">Alert when a user\'s IP address changes during an active shift.</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Network change webhook URL (optional override)</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_NETWORK_CHANGE" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_NETWORK_CHANGE')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkBrowserNotifications').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableBrowserNotifications').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_ENABLE_BROWSER_NOTIFICATIONS', getDolGlobalInt('CLOCKWORK_ENABLE_BROWSER_NOTIFICATIONS', 1), 1);
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkEnableBrowserNotificationsHelp').'</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkMaxShiftLength').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableMaxShiftAlerts').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_MAX_SHIFT', getDolGlobalInt('CLOCKWORK_NOTIFY_MAX_SHIFT', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkMaxShiftHours').'</td>';
print '<td><input type="number" min="1" max="24" step="1" name="CLOCKWORK_MAX_SHIFT_HOURS" value="'.((int) getDolGlobalInt('CLOCKWORK_MAX_SHIFT_HOURS', 12)).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkMaxShiftHoursHelp').'</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkWebhookMaxShift').'</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_MAX_SHIFT" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_MAX_SHIFT')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkEscalatingBreakReminders').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableEscalatingBreakReminders').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS', getDolGlobalInt('CLOCKWORK_ENABLE_ESCALATING_BREAK_REMINDERS', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkBreakReminderHours').'</td>';
print '<td><input type="text" name="CLOCKWORK_BREAK_REMINDER_HOURS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_BREAK_REMINDER_HOURS', '2,3,3.5,4')).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkBreakReminderHoursHelp').'</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkWeeklyOvertime').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableWeeklyOvertime').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_OVERTIME', getDolGlobalInt('CLOCKWORK_NOTIFY_OVERTIME', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkWeeklyOvertimeHours').'</td>';
print '<td><input type="number" min="1" max="168" step="1" name="CLOCKWORK_WEEKLY_OVERTIME_HOURS" value="'.((int) getDolGlobalInt('CLOCKWORK_WEEKLY_OVERTIME_HOURS', 48)).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkWeeklyOvertimeHoursHelp').'</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkWebhookOvertime').'</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_OVERTIME" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_OVERTIME')).'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkSlackTeamsWebhooks').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkSlackWebhookURL').'</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_SLACK" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_SLACK')).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkSlackWebhookHelp').'</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkTeamsWebhookURL').'</td>';
print '<td><input class="minwidth300" type="text" name="CLOCKWORK_WEBHOOK_TEAMS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_WEBHOOK_TEAMS')).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkTeamsWebhookHelp').'</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkFatigueManagement').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableFatigueAlerts').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_NOTIFY_FATIGUE', getDolGlobalInt('CLOCKWORK_NOTIFY_FATIGUE', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkMinRestHours').'</td>';
print '<td><input type="text" name="CLOCKWORK_MIN_REST_HOURS" value="'.dol_escape_htmltag(getDolGlobalString('CLOCKWORK_MIN_REST_HOURS', '8')).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkMinRestHoursHelp').'</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkAutoCloseShifts').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableAutoClose').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_AUTO_CLOSE_SHIFTS', getDolGlobalInt('CLOCKWORK_AUTO_CLOSE_SHIFTS', 1), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkAutoCloseHours').'</td>';
print '<td><input type="number" min="1" max="24" step="1" name="CLOCKWORK_AUTO_CLOSE_HOURS" value="'.((int) getDolGlobalInt('CLOCKWORK_AUTO_CLOSE_HOURS', 16)).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkAutoCloseHoursHelp').'</span></td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkConcurrentSessions').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableConcurrentDetection').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_DETECT_CONCURRENT', getDolGlobalInt('CLOCKWORK_DETECT_CONCURRENT', 1), 1).'</td>';
print '</tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('ClockworkShiftPatternViolations').'</td><td></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkEnableShiftPatternDetection').'</td>';
print '<td>'.$form->selectyesno('CLOCKWORK_DETECT_SHIFT_PATTERN', getDolGlobalInt('CLOCKWORK_DETECT_SHIFT_PATTERN', 0), 1).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClockworkShiftPatternGraceMinutes').'</td>';
print '<td><input type="number" min="0" max="120" step="5" name="CLOCKWORK_SHIFT_PATTERN_GRACE" value="'.((int) getDolGlobalInt('CLOCKWORK_SHIFT_PATTERN_GRACE', 15)).'">';
print '<br><span class="opacitymedium">'.$langs->trans('ClockworkShiftPatternGraceHelp').'</span></td>';
print '</tr>';

print '</table>';

print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br>';
print '<div class="center">';
print '<a class="butAction" href="apitest.php">API diagnostics</a>';
print '</div>';

print '<br>';
print '<div class="center">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block; margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_webhook">';
print '<input type="hidden" name="type" value="'.CLOCKWORK_NOTIFY_TYPE_CLOCKIN.'">';
print '<input class="butAction" type="submit" value="Send test webhook">';
print '</form>';
print '</div>';

llxFooter();
$db->close();
