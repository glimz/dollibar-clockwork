<?php

/**
 * Shared helpers for Clockwork JSON API endpoints.
 *
 * Auth:
 * - Authorization: Bearer <dolibarr_user_api_key>
 */

if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');
if (!defined('NOLOGIN')) define('NOLOGIN', '1');
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NODEFAULTVALUES')) define('NODEFAULTVALUES', '1');

header('Content-Type: application/json; charset=utf-8');

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = include '../../main.inc.php';
}
if (!$res) {
	http_response_code(500);
	echo json_encode(array('error' => 'Include of main fails'));
	exit;
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * @param int $status
 * @param array<string,mixed> $payload
 * @return never
 */
function clockworkApiReply($status, $payload)
{
	http_response_code((int) $status);
	echo json_encode($payload);
	exit;
}

/**
 * @return string
 */
function clockworkApiGetBearerToken()
{
	$header = '';
	if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $header = $_SERVER['HTTP_AUTHORIZATION'];
	if (!$header && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	if (!$header) return '';

	if (preg_match('/^Bearer\\s+(.+)$/i', trim($header), $m)) return trim($m[1]);
	return '';
}

/**
 * Authenticate using Dolibarr user's api_key and set global $user.
 *
 * @return User
 */
function clockworkApiAuth()
{
	global $db, $conf, $langs, $user;

	$langs->load('clockwork@clockwork');

	// Optional CORS (for controlled use cases).
	if (getDolGlobalInt('CLOCKWORK_API_ALLOW_CORS', 0)) {
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, Authorization');
	}

	// Preflight.
	if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
		http_response_code(204);
		exit;
	}

	$token = clockworkApiGetBearerToken();
	if (empty($token)) {
		clockworkApiReply(401, array('error' => 'Missing bearer token'));
	}

	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user";
	$sql .= " WHERE entity IN (0,".((int) $conf->entity).")";
	$sql .= " AND api_key = '".$db->escape($token)."'";
	$sql .= " AND statut = 1";
	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if (!$resql) {
		clockworkApiReply(500, array('error' => 'DB error', 'details' => $db->lasterror()));
	}
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		clockworkApiReply(401, array('error' => 'Invalid token'));
	}

	$apiUser = new User($db);
	$apiUser->fetch((int) $obj->rowid);
	$apiUser->getrights();

	// Require module and API right.
	if (!isModEnabled('clockwork') || !$apiUser->hasRight('clockwork', 'api')) {
		clockworkApiReply(403, array('error' => 'Forbidden'));
	}

	$user = $apiUser;
	return $apiUser;
}

