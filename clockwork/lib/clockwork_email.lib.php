<?php
/**
 * Clockwork email notification functions.
 */

require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';

/**
 * Send an email notification to a user.
 *
 * @param int    $userId     User ID
 * @param string $type       Email type (missed_clockin, monthly_summary, deduction_warning, etc.)
 * @param string $subject    Email subject
 * @param string $body       Email body (HTML)
 * @return array             Result array with 'ok' and optional 'error'
 */
function clockworkSendEmail($userId, $type, $subject, $body)
{
	global $db, $conf, $mysoc;

	// Get user email
	$sql = 'SELECT email, firstname, lastname FROM ' . MAIN_DB_PREFIX . 'user WHERE rowid = ' . ((int) $userId);
	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => 'Failed to fetch user email');
	}
	$obj = $db->fetch_object($resql);
	if (!$obj || empty($obj->email)) {
		return array('ok' => false, 'error' => 'User has no email configured');
	}

	$toEmail = $obj->email;
	$toName = trim($obj->firstname . ' ' . $obj->lastname);

	// Get sender info
	$fromEmail = getDolGlobalString('MAIN_MAIL_EMAILFROM', 'noreply@' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'));
	$fromName = getDolGlobalString('MAIN_MAIL_EMAILFROM_NAME', 'Clockwork');

	// Build HTML email
	$html = '<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<style>
		body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
		.container { max-width: 600px; margin: 0 auto; padding: 20px; }
		.header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
		.content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
		.footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
		.button { display: inline-block; background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 10px 0; }
		.alert { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin: 15px 0; }
		.alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; }
		.alert-success { background: #d4edda; border: 1px solid #c3e6cb; }
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>🕐 Clockwork Notification</h1>
		</div>
		<div class="content">
			' . $body . '
		</div>
		<div class="footer">
			<p>This is an automated message from Clockwork Time Tracking.</p>
			<p>' . dol_escape_htmltag($mysoc->name) . '</p>
		</div>
	</div>
</body>
</html>';

	// Log the email
	$now = dol_now();
	$sqlInsert = 'INSERT INTO ' . MAIN_DB_PREFIX . 'clockwork_email_log';
	$sqlInsert .= ' (entity, fk_user, email_type, subject, body, status, datec)';
	$sqlInsert .= ' VALUES (' . ((int) $conf->entity) . ', ' . ((int) $userId) . ', ';
	$sqlInsert .= "'" . $db->escape($type) . "', ";
	$sqlInsert .= "'" . $db->escape($subject) . "', ";
	$sqlInsert .= "'" . $db->escape($html) . "', ";
	$sqlInsert .= "'pending', '" . $db->idate($now) . "')";

	$db->query($sqlInsert);

	// Send email using Dolibarr's CMailFile
	require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';

	$mail = new CMailFile(
		$subject,           // Subject
		$toEmail,           // To
		$fromEmail,         // From
		$html,              // Body
		array(),            // Array of target CC
		array(),            // Array of target BCC
		array(),            // Array of files to attach
		array(),            // Array of other inline files
		0,                  // Do not send delivery receipt
		0,                  // Message ID
		'',                 // Errors to
		'',                 // Reply to
		'',                 // In reply to
		'',                 // References
		$html,              // HTML body
		0,                  // Priority
		'',                 // Message ID
		'auto'              // Auto format
	);

	if ($mail->sendfile()) {
		// Update log to sent
		$sqlUpdate = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_email_log';
		$sqlUpdate .= ' SET status = "sent", sent_date = "' . $db->idate($now) . '"';
		$sqlUpdate .= ' WHERE fk_user = ' . ((int) $userId);
		$sqlUpdate .= ' AND email_type = "' . $db->escape($type) . '"';
		$sqlUpdate .= ' AND status = "pending"';
		$sqlUpdate .= ' ORDER BY rowid DESC LIMIT 1';
		$db->query($sqlUpdate);

		return array('ok' => true);
	} else {
		// Update log to failed
		$sqlUpdate = 'UPDATE ' . MAIN_DB_PREFIX . 'clockwork_email_log';
		$sqlUpdate .= ' SET status = "failed", error_message = "' . $db->escape($mail->error) . '"';
		$sqlUpdate .= ' WHERE fk_user = ' . ((int) $userId);
		$sqlUpdate .= ' AND email_type = "' . $db->escape($type) . '"';
		$sqlUpdate .= ' AND status = "pending"';
		$sqlUpdate .= ' ORDER BY rowid DESC LIMIT 1';
		$db->query($sqlUpdate);

		return array('ok' => false, 'error' => $mail->error);
	}
}

/**
 * Send missed clock-in email notification.
 *
 * @param string $login    User login
 * @param string $date     Date of missed clock-in
 * @param string $cutoff   Cutoff time
 * @return array           Result
 */
function clockworkEmailMissedClockin($login, $date, $cutoff)
{
	global $db;

	$sql = 'SELECT rowid, email, firstname, lastname FROM ' . MAIN_DB_PREFIX . 'user WHERE login = "' . $db->escape($login) . '"';
	$resql = $db->query($sql);
	if (!$resql) return array('ok' => false);
	$obj = $db->fetch_object($resql);
	if (!$obj) return array('ok' => false);

	$name = trim($obj->firstname . ' ' . $obj->lastname);
	$subject = '[Clockwork] Missed Clock-In Alert - ' . $date;

	$body = '<h2>Missed Clock-In Alert</h2>
	<p>Dear ' . dol_escape_htmltag($name) . ',</p>
	<p>Our records show that you did not clock in on <strong>' . dol_escape_htmltag($date) . '</strong> before the cutoff time of <strong>' . dol_escape_htmltag($cutoff) . '</strong>.</p>
	<div class="alert alert-danger">
		<strong>Action Required:</strong> Please ensure you clock in on time to avoid salary deductions.
	</div>
	<p>If you were on approved leave or there was an error, please contact your manager.</p>
	<p>Best regards,<br>Clockwork Team</p>';

	return clockworkSendEmail((int) $obj->rowid, 'missed_clockin', $subject, $body);
}

/**
 * Send monthly compliance summary email.
 *
 * @param int    $userId       User ID
 * @param string $yearMonth    Year-month (YYYY-MM)
 * @param array  $compliance   Compliance data
 * @return array               Result
 */
function clockworkEmailMonthlySummary($userId, $yearMonth, $compliance)
{
	$name = $compliance['name'] ?? 'Employee';
	$expectedHours = $compliance['expected_hours'] ?? 0;
	$actualHours = $compliance['actual_hours'] ?? 0;
	$status = $compliance['status'] ?? 'red';
	$deductionPct = $compliance['deduction_pct'] ?? 0;

	$statusIcon = $status === 'green' ? '🟢' : ($status === 'yellow' ? '🟡' : '🔴');
	$statusText = $status === 'green' ? 'Compliant' : ($status === 'yellow' ? 'Near Target' : 'Below Target');

	$subject = '[Clockwork] Monthly Hours Summary - ' . date('F Y', strtotime($yearMonth . '-01'));

	$alertClass = $status === 'green' ? 'alert-success' : ($status === 'yellow' ? 'alert' : 'alert alert-danger');

	$body = '<h2>Monthly Hours Summary</h2>
	<p>Dear ' . dol_escape_htmltag($name) . ',</p>
	<p>Here is your time tracking summary for <strong>' . date('F Y', strtotime($yearMonth . '-01')) . '</strong>:</p>
	<div class="' . $alertClass . '">
		<strong>' . $statusIcon . ' Status: ' . $statusText . '</strong><br>
		Expected Hours: ' . number_format($expectedHours, 1) . '<br>
		Actual Hours: ' . number_format($actualHours, 1) . '<br>';

	if ($deductionPct > 0) {
		$body .= '<br><strong>Salary Deduction: ' . number_format($deductionPct, 1) . '%</strong>';
	}

	$body .= '</div>
	<p>Thank you for your hard work!</p>
	<p>Best regards,<br>Clockwork Team</p>';

	return clockworkSendEmail($userId, 'monthly_summary', $subject, $body);
}

/**
 * Send salary deduction warning email.
 *
 * @param int    $userId         User ID
 * @param string $yearMonth      Year-month (YYYY-MM)
 * @param array  $deductionData  Deduction data
 * @return array                 Result
 */
function clockworkEmailDeductionWarning($userId, $yearMonth, $deductionData)
{
	$name = $deductionData['name'] ?? 'Employee';
	$missedDays = $deductionData['missed_days'] ?? 0;
	$deductionPct = $deductionData['deduction_pct'] ?? 0;
	$deductionAmount = $deductionData['deduction_amount'] ?? 0;

	$subject = '[Clockwork] Salary Deduction Warning - ' . date('F Y', strtotime($yearMonth . '-01'));

	$body = '<h2>⚠️ Salary Deduction Warning</h2>
	<p>Dear ' . dol_escape_htmltag($name) . ',</p>
	<p>This is a notification that your attendance for <strong>' . date('F Y', strtotime($yearMonth . '-01')) . '</strong> is below the required threshold.</p>
	<div class="alert alert-danger">
		<strong>Details:</strong><br>
		Missed Days: ' . ((int) $missedDays) . '<br>
		Deduction Percentage: ' . number_format($deductionPct, 1) . '%<br>
		Estimated Deduction Amount: ₦' . number_format($deductionAmount, 2) . '
	</div>
	<p>Please contact your manager or HR if you believe this is incorrect.</p>
	<p>Best regards,<br>Clockwork Team</p>';

	return clockworkSendEmail($userId, 'deduction_warning', $subject, $body);
}

/**
 * Send shift schedule notification email.
 *
 * @param int    $userId   User ID
 * @param string $type     Notification type (new_schedule, schedule_change)
 * @param array  $schedule Schedule data
 * @return array           Result
 */
function clockworkEmailScheduleNotification($userId, $type, $schedule)
{
	$name = $schedule['name'] ?? 'Employee';
	$shifts = $schedule['shifts'] ?? array();

	$subject = '[Clockwork] ' . ($type === 'new_schedule' ? 'New Schedule' : 'Schedule Change');

	$body = '<h2>' . ($type === 'new_schedule' ? '📅 New Schedule' : '🔄 Schedule Change') . '</h2>
	<p>Dear ' . dol_escape_htmltag($name) . ',</p>
	<p>Your upcoming work schedule has been ' . ($type === 'new_schedule' ? 'published' : 'updated') . ':</p>
	<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
		<thead>
			<tr style="background: #2c3e50; color: white;">
				<th style="padding: 10px; text-align: left;">Date</th>
				<th style="padding: 10px; text-align: left;">Start</th>
				<th style="padding: 10px; text-align: left;">End</th>
			</tr>
		</thead>
		<tbody>';

	foreach ($shifts as $shift) {
		$body .= '<tr style="border-bottom: 1px solid #ddd;">
			<td style="padding: 10px;">' . dol_escape_htmltag($shift['date']) . '</td>
			<td style="padding: 10px;">' . dol_escape_htmltag($shift['start']) . '</td>
			<td style="padding: 10px;">' . dol_escape_htmltag($shift['end']) . '</td>
		</tr>';
	}

	$body .= '</tbody></table>
	<p>Please ensure you clock in on time for all scheduled shifts.</p>
	<p>Best regards,<br>Clockwork Team</p>';

	return clockworkSendEmail($userId, 'schedule_notification', $subject, $body);
}