<?php

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Clockwork webhook notification types.
 */
const CLOCKWORK_NOTIFY_TYPE_CLOCKIN = 'clockin';
const CLOCKWORK_NOTIFY_TYPE_BREAK = 'break';
const CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN = 'missed_clockin';
const CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY = 'weekly_summary';
const CLOCKWORK_NOTIFY_TYPE_OVERWORK = 'overwork';
const CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER = 'logout_reminder';
const CLOCKWORK_NOTIFY_TYPE_NETWORK_CHANGE = 'network_change';

/**
 * @param string $login
 * @param string $denyListCsv
 * @return bool
 */
function clockworkIsLoginExcluded($login, $denyListCsv)
{
	$login = trim((string) $login);
	if ($login === '') return false;
	$denyListCsv = trim((string) $denyListCsv);
	if ($denyListCsv === '') return false;

	$items = preg_split('/[\\s,;]+/', $denyListCsv);
	if (!is_array($items)) return false;
	foreach ($items as $item) {
		if ($item !== '' && strcasecmp($item, $login) === 0) return true;
	}
	return false;
}

/**
 * Return webhook URL for notification type with fallback.
 *
 * @param string $type
 * @return string
 */
function clockworkGetWebhookUrl($type)
{
	$type = (string) $type;

	$map = array(
		CLOCKWORK_NOTIFY_TYPE_CLOCKIN => 'CLOCKWORK_WEBHOOK_CLOCKIN',
		CLOCKWORK_NOTIFY_TYPE_BREAK => 'CLOCKWORK_WEBHOOK_BREAK',
		CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN => 'CLOCKWORK_WEBHOOK_MISSED_CLOCKIN',
		CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY => 'CLOCKWORK_WEBHOOK_WEEKLY_SUMMARY',
		CLOCKWORK_NOTIFY_TYPE_OVERWORK => 'CLOCKWORK_WEBHOOK_OVERWORK',
		CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER => 'CLOCKWORK_WEBHOOK_LOGOUT_REMINDER',
		CLOCKWORK_NOTIFY_TYPE_NETWORK_CHANGE => 'CLOCKWORK_WEBHOOK_NETWORK_CHANGE',
	);

	$const = isset($map[$type]) ? $map[$type] : '';
	$url = $const ? getDolGlobalString($const) : '';
	if (empty($url)) $url = getDolGlobalString('CLOCKWORK_WEBHOOK_DEFAULT');
	return trim((string) $url);
}

/**
 * @param string $type
 * @return bool
 */
function clockworkIsNotificationEnabled($type)
{
	$type = (string) $type;
	$map = array(
		CLOCKWORK_NOTIFY_TYPE_CLOCKIN => 'CLOCKWORK_NOTIFY_CLOCKIN',
		CLOCKWORK_NOTIFY_TYPE_BREAK => 'CLOCKWORK_NOTIFY_BREAK',
		CLOCKWORK_NOTIFY_TYPE_MISSED_CLOCKIN => 'CLOCKWORK_NOTIFY_MISSED_CLOCKIN',
		CLOCKWORK_NOTIFY_TYPE_WEEKLY_SUMMARY => 'CLOCKWORK_NOTIFY_WEEKLY_SUMMARY',
		CLOCKWORK_NOTIFY_TYPE_OVERWORK => 'CLOCKWORK_NOTIFY_OVERWORK',
		CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER => 'CLOCKWORK_NOTIFY_LOGOUT_REMINDER',
		CLOCKWORK_NOTIFY_TYPE_NETWORK_CHANGE => 'CLOCKWORK_NOTIFY_NETWORK_CHANGE',
	);
	$const = isset($map[$type]) ? $map[$type] : '';
	if (!$const) return false;
	return (bool) getDolGlobalInt($const, 1);
}

/**
 * Send a Discord webhook payload.
 *
 * @param string $type
 * @param array<string,mixed> $payload
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkSendDiscordWebhook($type, $payload)
{
	$url = clockworkGetWebhookUrl($type);
	if (empty($url)) {
		return array('ok' => false, 'error' => 'Webhook URL not configured');
	}

	$headers = array('Content-Type: application/json');
	$json = json_encode($payload);
	if ($json === false) {
		return array('ok' => false, 'error' => 'Failed to encode JSON');
	}

	$res = getURLContent($url, 'POSTALREADYFORMATED', $json, 1, $headers, array('https', 'http'), 0);
	$http = isset($res['http_code']) ? (int) $res['http_code'] : 0;
	$ok = ($http >= 200 && $http < 300);
	if (!$ok) {
		$err = '';
		if (!empty($res['curl_error_msg'])) $err = (string) $res['curl_error_msg'];
		if (!$err && !empty($res['content'])) $err = (string) $res['content'];
		if (!$err) $err = 'HTTP '.$http;
		return array('ok' => false, 'http_code' => $http, 'error' => $err);
	}

	return array('ok' => true, 'http_code' => $http);
}

/**
 * Convenience wrapper for simple text message.
 *
 * @param string $type
 * @param string $content
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotify($type, $content)
{
	if (!clockworkIsNotificationEnabled($type)) {
		return array('ok' => true);
	}
	$content = trim((string) $content);
	if ($content === '') return array('ok' => true);

	$payload = array('content' => $content);
	return clockworkSendDiscordWebhook($type, $payload);
}

/**
 * Send a Discord webhook with rich embed.
 *
 * @param string $type Notification type
 * @param array{title:string,color:int,fields:array,footer?:string,description?:string} $embedData
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyEmbed($type, $embedData)
{
	if (!clockworkIsNotificationEnabled($type)) {
		return array('ok' => true);
	}

	$embed = array(
		'title' => $embedData['title'] ?? 'Clockwork',
		'color' => $embedData['color'] ?? 3447003, // Default blue
		'fields' => $embedData['fields'] ?? array(),
		'timestamp' => gmdate('c'),
	);

	if (!empty($embedData['description'])) {
		$embed['description'] = $embedData['description'];
	}

	if (!empty($embedData['footer'])) {
		$embed['footer'] = array('text' => $embedData['footer']);
	}

	$payload = array('embeds' => array($embed));
	return clockworkSendDiscordWebhook($type, $payload);
}

/**
 * Build a Discord embed field.
 *
 * @param string $name Field name
 * @param string $value Field value
 * @param bool $inline Whether field is inline
 * @return array{name:string,value:string,inline:bool}
 */
function clockworkEmbedField($name, $value, $inline = true)
{
	return array(
		'name' => (string) $name,
		'value' => (string) $value,
		'inline' => (bool) $inline,
	);
}

/**
 * Send clock-in notification with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $clockinTs Clock-in timestamp
 * @param string $ip IP address
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyClockin($login, $shiftId, $clockinTs, $ip)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Time', dol_print_date($clockinTs, 'dayhour'), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
		clockworkEmbedField('IP', $ip, true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_CLOCKIN, array(
		'title' => '🟢 Clock-In',
		'color' => 3066993, // Green
		'fields' => $fields,
		'footer' => 'Clockwork',
	));
}

/**
 * Send clock-out notification with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $clockoutTs Clock-out timestamp
 * @param int $netSeconds Net worked seconds
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyClockout($login, $shiftId, $clockoutTs, $netSeconds)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Time', dol_print_date($clockoutTs, 'dayhour'), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
		clockworkEmbedField('Net Time', clockworkFormatDuration($netSeconds), true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_CLOCKIN, array(
		'title' => '🔴 Clock-Out',
		'color' => 15158332, // Red
		'fields' => $fields,
		'footer' => 'Clockwork',
	));
}

/**
 * Send break start notification with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $breakStartTs Break start timestamp
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyBreakStart($login, $shiftId, $breakStartTs)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Time', dol_print_date($breakStartTs, 'dayhour'), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_BREAK, array(
		'title' => '⏸️ Break Started',
		'color' => 16776960, // Yellow
		'fields' => $fields,
		'footer' => 'Clockwork',
	));
}

/**
 * Send break end notification with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $breakEndTs Break end timestamp
 * @param int $breakSeconds Break duration in seconds
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyBreakEnd($login, $shiftId, $breakEndTs, $breakSeconds)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Time', dol_print_date($breakEndTs, 'dayhour'), true),
		clockworkEmbedField('Duration', clockworkFormatDuration($breakSeconds), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_BREAK, array(
		'title' => '▶️ Break Ended',
		'color' => 3447003, // Blue
		'fields' => $fields,
		'footer' => 'Clockwork',
	));
}

/**
 * Send overwork alert with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $continuousSeconds Continuous work seconds without break
 * @param string $ip IP address
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyOverwork($login, $shiftId, $continuousSeconds, $ip)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Continuous Work', clockworkFormatDuration($continuousSeconds), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
		clockworkEmbedField('IP', $ip, true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_OVERWORK, array(
		'title' => '⚠️ Overwork Alert',
		'description' => 'User has been working continuously without a break.',
		'color' => 16744448, // Orange
		'fields' => $fields,
		'footer' => 'Clockwork • Please take a break',
	));
}

/**
 * Send logout reminder with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param int $clockinTs Clock-in timestamp
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyLogoutReminder($login, $shiftId, $clockinTs)
{
	$workedSeconds = max(0, dol_now() - $clockinTs);

	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Clocked In', dol_print_date($clockinTs, 'dayhour'), true),
		clockworkEmbedField('Worked So Far', clockworkFormatDuration($workedSeconds), true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_LOGOUT_REMINDER, array(
		'title' => '🔔 Logout Reminder',
		'description' => 'You still have an open shift. Don\'t forget to clock out!',
		'color' => 16750848, // Orange-yellow
		'fields' => $fields,
		'footer' => 'Clockwork',
	));
}

/**
 * Send network change alert with rich embed.
 *
 * @param string $login User login
 * @param int $shiftId Shift ID
 * @param string $oldIP Previous IP
 * @param string $newIP New IP
 * @param string $oldLocation Previous location string
 * @param string $newLocation New location string
 * @return array{ok:bool,http_code?:int,error?:string}
 */
function clockworkNotifyNetworkChange($login, $shiftId, $oldIP, $newIP, $oldLocation, $newLocation)
{
	$fields = array(
		clockworkEmbedField('User', $login, true),
		clockworkEmbedField('Shift', '#' . $shiftId, true),
		clockworkEmbedField('Previous IP', $oldIP . ($oldLocation ? "\n" . $oldLocation : ''), true),
		clockworkEmbedField('Current IP', $newIP . ($newLocation ? "\n" . $newLocation : ''), true),
	);

	return clockworkNotifyEmbed(CLOCKWORK_NOTIFY_TYPE_NETWORK_CHANGE, array(
		'title' => '🚨 Network Change Detected',
		'description' => 'User\'s network connection changed during an active shift.',
		'color' => 15158332, // Red
		'fields' => $fields,
		'footer' => 'Clockwork • Security Alert',
	));
}

