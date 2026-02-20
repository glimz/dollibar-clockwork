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

