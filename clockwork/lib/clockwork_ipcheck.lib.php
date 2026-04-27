<?php

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Clockwork IP restriction and network monitoring library.
 */

/**
 * Get the client's real IP address.
 *
 * @return string
 */
function clockworkGetClientIP()
{
	$ipKeys = array(
		'HTTP_CF_CONNECTING_IP',     // Cloudflare
		'HTTP_X_FORWARDED_FOR',      // Proxy/Load balancer
		'HTTP_X_REAL_IP',            // Nginx proxy
		'HTTP_CLIENT_IP',            // Proxy
		'REMOTE_ADDR',               // Standard
	);

	foreach ($ipKeys as $key) {
		if (!empty($_SERVER[$key])) {
			$value = trim((string) $_SERVER[$key]);
			// X-Forwarded-For can contain multiple IPs
			if (strpos($value, ',') !== false) {
				$parts = explode(',', $value);
				$value = trim($parts[0]);
			}
			if (filter_var($value, FILTER_VALIDATE_IP)) {
				return $value;
			}
		}
	}

	// Fallback: try to get from environment or use a default
	// This handles cases where the server is behind a reverse proxy
	// and the real IP is not passed through headers
	return '0.0.0.0';
}

/**
 * Check if an IP is within a CIDR range.
 *
 * @param string $ip IP address to check
 * @param string $cidr CIDR notation (e.g., 10.0.0.0/8 or 192.168.1.0/24)
 * @return bool
 */
function clockworkIPInCIDR($ip, $cidr)
{
	$ip = trim((string) $ip);
	$cidr = trim((string) $cidr);

	if ($cidr === '') return false;

	// Handle single IP (no CIDR notation)
	if (strpos($cidr, '/') === false) {
		return $ip === $cidr;
	}

	$parts = explode('/', $cidr);
	if (count($parts) !== 2) return false;

	$network = $parts[0];
	$mask = (int) $parts[1];

	if ($mask < 0 || $mask > 32) return false;

	$ipLong = ip2long($ip);
	$networkLong = ip2long($network);

	if ($ipLong === false || $networkLong === false) return false;

	$maskLong = -1 << (32 - $mask);
	$maskLong = $maskLong & 0xFFFFFFFF;

	return ($ipLong & $maskLong) === ($networkLong & $maskLong);
}

/**
 * Check if an IP is allowed based on configured whitelist.
 *
 * @param string $ip IP address to check
 * @return bool
 */
function clockworkIsIPAllowed($ip)
{
	$allowedIPs = getDolGlobalString('CLOCKWORK_ALLOWED_IPS', '');
	if (empty($allowedIPs)) {
		return true; // No restriction configured
	}

	$ip = trim((string) $ip);
	$ranges = preg_split('/[\s,;]+/', $allowedIPs);

	if (!is_array($ranges)) return true;

	foreach ($ranges as $range) {
		$range = trim($range);
		if ($range === '') continue;
		if (clockworkIPInCIDR($ip, $range)) {
			return true;
		}
	}

	return false;
}

/**
 * Get IP geolocation info using ip-api.com (free, no key required).
 *
 * @param string $ip IP address
 * @return array{country:string,city:string,region:string,org:string,isp:string,error:string}
 */
function clockworkGetIPLocation($ip)
{
	$ip = trim((string) $ip);
	if (!filter_var($ip, FILTER_VALIDATE_IP)) {
		return array('country' => '', 'city' => '', 'region' => '', 'org' => '', 'isp' => '', 'error' => 'Invalid IP');
	}

	// Skip private IPs
	if (in_array($ip, array('127.0.0.1', '::1', '0.0.0.0'), true)) {
		return array('country' => 'Local', 'city' => 'Localhost', 'region' => '', 'org' => 'Local', 'isp' => 'Local', 'error' => '');
	}

	$url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,org,isp,message';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($httpCode !== 200 || empty($response)) {
		return array('country' => '', 'city' => '', 'region' => '', 'org' => '', 'isp' => '', 'error' => 'Geolocation service unavailable');
	}

	$data = json_decode($response, true);
	if (empty($data) || ($data['status'] ?? '') !== 'success') {
		return array('country' => '', 'city' => '', 'region' => '', 'org' => '', 'isp' => '', 'error' => $data['message'] ?? 'Unknown error');
	}

	return array(
		'country' => $data['country'] ?? '',
		'city' => $data['city'] ?? '',
		'region' => $data['regionName'] ?? '',
		'org' => $data['org'] ?? '',
		'isp' => $data['isp'] ?? '',
		'error' => '',
	);
}

/**
 * Check IP restriction and return error message if blocked.
 *
 * @return array{blocked:bool,message:string,ip:string,location:array}
 */
function clockworkCheckIPRestriction()
{
	$ip = clockworkGetClientIP();
	$allowed = clockworkIsIPAllowed($ip);

	if ($allowed) {
		return array('blocked' => false, 'message' => '', 'ip' => $ip, 'location' => array());
	}

	$location = clockworkGetIPLocation($ip);
	$locationStr = '';
	if (empty($location['error'])) {
		$parts = array_filter(array($location['city'], $location['region'], $location['country']));
		$locationStr = implode(', ', $parts);
		if (!empty($location['org'])) {
			$locationStr .= ' (' . $location['org'] . ')';
		}
	}

	$message = 'Access Denied: You are not connected to the corporate network.' . "\n\n";
	$message .= 'Your IP: ' . $ip . "\n";
	if ($locationStr) {
		$message .= 'Location: ' . $locationStr . "\n";
	}
	$message .= "\n" . 'Please connect to the corporate VPN or office network to access Clockwork.';

	return array(
		'blocked' => true,
		'message' => $message,
		'ip' => $ip,
		'location' => $location,
	);
}

/**
 * Render the blocked access page.
 *
 * @param array $checkResult Result from clockworkCheckIPRestriction()
 * @return void
 */
function clockworkRenderBlockedPage($checkResult)
{
	global $langs;

	$ip = $checkResult['ip'];
	$location = $checkResult['location'];
	$message = $checkResult['message'];

	$locationStr = '';
	if (empty($location['error']) && !empty($location['country'])) {
		$parts = array_filter(array($location['city'], $location['region'], $location['country']));
		$locationStr = implode(', ', $parts);
		if (!empty($location['isp'])) {
			$locationStr .= ' • ' . $location['isp'];
		}
	}

	print '<style>
	.clockwork-blocked {
		max-width: 600px;
		margin: 60px auto;
		padding: 40px;
		background: #fff;
		border: 2px solid #dc3545;
		border-radius: 12px;
		text-align: center;
		box-shadow: 0 4px 20px rgba(220, 53, 69, 0.15);
	}
	.clockwork-blocked .icon { font-size: 64px; margin-bottom: 20px; }
	.clockwork-blocked h2 { color: #dc3545; margin-bottom: 20px; }
	.clockwork-blocked .info-box {
		background: #f8f9fa;
		border: 1px solid #dee2e6;
		border-radius: 8px;
		padding: 20px;
		margin: 20px 0;
		text-align: left;
	}
	.clockwork-blocked .info-row {
		display: flex;
		justify-content: space-between;
		padding: 8px 0;
		border-bottom: 1px solid #eee;
	}
	.clockwork-blocked .info-row:last-child { border-bottom: none; }
	.clockwork-blocked .info-label { font-weight: 600; color: #495057; }
	.clockwork-blocked .info-value { color: #212529; }
	.clockwork-blocked .help-text { color: #6c757d; margin-top: 20px; font-size: 14px; }
	</style>';

	print '<div class="clockwork-blocked">';
	print '<div class="icon">🚫</div>';
	print '<h2>Access Denied</h2>';
	print '<p>You are in the wrong location or not connected to the corporate network.</p>';

	print '<div class="info-box">';
	print '<div class="info-row"><span class="info-label">Your IP Address</span><span class="info-value">' . dol_escape_htmltag($ip) . '</span></div>';
	if ($locationStr) {
		print '<div class="info-row"><span class="info-label">Detected Location</span><span class="info-value">' . dol_escape_htmltag($locationStr) . '</span></div>';
	}
	if (!empty($location['org'])) {
		print '<div class="info-row"><span class="info-label">Network/Org</span><span class="info-value">' . dol_escape_htmltag($location['org']) . '</span></div>';
	}
	print '</div>';

	print '<p class="help-text">Please connect to the corporate VPN or office network to access Clockwork.<br>If you believe this is an error, contact your IT administrator.</p>';
	print '</div>';
}

/**
 * Detect if user's IP has changed during an open shift.
 *
 * @param int $shiftId Shift ID
 * @param string $currentIP Current IP address
 * @return array{changed:bool,old_ip:string,new_ip:string}
 */
function clockworkDetectIPChange($shiftId, $currentIP)
{
	global $db, $conf;

	$sql = 'SELECT ip FROM ' . MAIN_DB_PREFIX . 'clockwork_shift';
	$sql .= ' WHERE entity = ' . ((int) $conf->entity);
	$sql .= ' AND rowid = ' . ((int) $shiftId);
	$sql .= ' LIMIT 1';

	$resql = $db->query($sql);
	if (!$resql) {
		return array('changed' => false, 'old_ip' => '', 'new_ip' => '');
	}

	$obj = $db->fetch_object($resql);
	if (!$obj || empty($obj->ip)) {
		return array('changed' => false, 'old_ip' => '', 'new_ip' => '');
	}

	$oldIP = (string) $obj->ip;
	$currentIP = trim((string) $currentIP);

	if ($oldIP !== $currentIP) {
		return array('changed' => true, 'old_ip' => $oldIP, 'new_ip' => $currentIP);
	}

	return array('changed' => false, 'old_ip' => $oldIP, 'new_ip' => $currentIP);
}

/**
 * Flag a shift for IP change and send notification.
 *
 * @param int $shiftId Shift ID
 * @param string $oldIP Previous IP
 * @param string $newIP New IP
 * @param User $user User object
 * @return bool
 */
function clockworkFlagIPChange($shiftId, $oldIP, $newIP, $user)
{
	global $db, $conf;

	// Update shift to flagged
	$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_shift';
	$sql .= ' SET ip_flagged = 1';
	$sql .= ' WHERE entity = ' . ((int) $conf->entity);
	$sql .= ' AND rowid = ' . ((int) $shiftId);

	$db->query($sql);

	// Get location info for both IPs
	$oldLocation = clockworkGetIPLocation($oldIP);
	$newLocation = clockworkGetIPLocation($newIP);

	$oldLocStr = '';
	if (empty($oldLocation['error'])) {
		$parts = array_filter(array($oldLocation['city'], $oldLocation['region'], $oldLocation['country']));
		$oldLocStr = implode(', ', $parts);
	}

	$newLocStr = '';
	if (empty($newLocation['error'])) {
		$parts = array_filter(array($newLocation['city'], $newLocation['region'], $newLocation['country']));
		$newLocStr = implode(', ', $parts);
	}

	$login = !empty($user->login) ? $user->login : ('user#' . (int) $user->id);

	// Send webhook notification
	require_once DOL_DOCUMENT_ROOT . '/custom/clockwork/lib/clockwork_webhook.lib.php';

	$msg = "⚠️ **Network Change Detected**\n";
	$msg .= "User: **" . $login . "**\n";
	$msg .= "Shift: #" . $shiftId . "\n";
	$msg .= "Previous IP: " . $oldIP . ($oldLocStr ? ' (' . $oldLocStr . ')' : '') . "\n";
	$msg .= "Current IP: " . $newIP . ($newLocStr ? ' (' . $newLocStr . ')' : '') . "\n";
	$msg .= "Time: " . dol_print_date(dol_now(), 'dayhour');

	clockworkNotify(CLOCKWORK_NOTIFY_TYPE_NETWORK_CHANGE, $msg);

	return true;
}