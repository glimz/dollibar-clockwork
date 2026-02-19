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
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkshift.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkbreak.class.php';

$langs->loadLangs(array('clockwork@clockwork', 'users', 'other', 'main'));

if (!isModEnabled('clockwork')) accessforbidden();
if (!$user->hasRight('clockwork', 'readall')) accessforbidden();

$form = new Form($db);

$dateFrom = GETPOST('date_from', 'alpha');
$dateTo = GETPOST('date_to', 'alpha');
$searchUser = GETPOSTINT('user_id');
$status = GETPOST('status', 'alpha'); // open|closed|all
$shiftId = GETPOSTINT('shift_id');
$action = GETPOST('action', 'aZ09');
$note = (string) GETPOST('note', 'restricthtml');

if (empty($dateFrom) || empty($dateTo)) {
	$dateTo = dol_print_date(dol_now(), '%Y-%m-%d');
	$dateFrom = dol_print_date(dol_now() - 7 * 86400, '%Y-%m-%d');
}

$tsFrom = dol_stringtotime($dateFrom.' 00:00:00');
$tsTo = dol_stringtotime($dateTo.' 23:59:59');

/**
 * Parse a datetime-local input value into a unix timestamp.
 *
 * @param string $value
 * @return int|null
 */
function clockworkParseDatetimeLocal($value)
{
	$value = trim((string) $value);
	if ($value === '') return null;
	$value = str_replace('T', ' ', $value);
	// Normalize to seconds if missing.
	if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
		$value .= ':00';
	}
	$ts = dol_stringtotime($value);
	if (empty($ts)) return null;
	return (int) $ts;
}

// Handle manual edits (HR/Admin manage right).
if ($shiftId > 0 && $action === 'save_entry' && !$user->hasRight('clockwork', 'manage')) {
	accessforbidden();
}

if ($shiftId > 0 && $action === 'save_entry' && $user->hasRight('clockwork', 'manage')) {
	$clockinInput = GETPOST('clockin', 'alpha');
	$clockoutInput = GETPOST('clockout', 'alpha');
	$clockinTs = clockworkParseDatetimeLocal($clockinInput);
	$clockoutTs = clockworkParseDatetimeLocal($clockoutInput);

	if (!$clockinTs) {
		setEventMessages('Clock-in time is required.', null, 'errors');
	} elseif ($clockoutTs && $clockoutTs < $clockinTs) {
		setEventMessages('Clock-out must be after clock-in.', null, 'errors');
	} else {
		$db->begin();
		$hasError = false;

		// Ensure shift exists in current entity.
		$sqlShift = 'SELECT rowid, fk_user, clockin, clockout, status';
		$sqlShift .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift';
		$sqlShift .= ' WHERE entity = '.((int) $conf->entity).' AND rowid = '.((int) $shiftId);
		$sqlShift .= ' LIMIT 1';
		$resShift = $db->query($sqlShift);
		if (!$resShift || !($objShift = $db->fetch_object($resShift))) {
			$db->rollback();
			$hasError = true;
			setEventMessages('Shift not found.', null, 'errors');
		} else {
			// Delete selected breaks.
			$deleteBreakIds = GETPOST('delete_break_ids', 'array');
			if (!empty($deleteBreakIds) && is_array($deleteBreakIds)) {
				$ids = array();
				foreach ($deleteBreakIds as $bid) {
					$bid = (int) $bid;
					if ($bid > 0) $ids[] = $bid;
				}
				if (!empty($ids)) {
					$sqlDel = 'DELETE FROM '.MAIN_DB_PREFIX.'clockwork_break';
					$sqlDel .= ' WHERE entity = '.((int) $conf->entity);
					$sqlDel .= ' AND fk_shift = '.((int) $shiftId);
					$sqlDel .= ' AND rowid IN ('.implode(',', $ids).')';
					$resDel = $db->query($sqlDel);
					if (!$resDel) {
						$db->rollback();
						$hasError = true;
						setEventMessages($db->lasterror(), null, 'errors');
					}
				}
			}

			if (!$hasError) {
				// Update existing breaks.
				$breakStarts = GETPOST('break_start', 'array');
				$breakEnds = GETPOST('break_end', 'array');
				$breakNotes = GETPOST('break_note', 'array');
				if (is_array($breakStarts)) {
					foreach ($breakStarts as $bid => $startVal) {
						$bid = (int) $bid;
						if ($bid <= 0) continue;

						$startTs = clockworkParseDatetimeLocal((string) $startVal);
						$endTs = clockworkParseDatetimeLocal(is_array($breakEnds) && isset($breakEnds[$bid]) ? (string) $breakEnds[$bid] : '');
						$bn = is_array($breakNotes) && isset($breakNotes[$bid]) ? (string) $breakNotes[$bid] : '';

						if (!$startTs) {
							$db->rollback();
							$hasError = true;
							setEventMessages('Break start is required for break #'.$bid.'.', null, 'errors');
							break;
						}
						if ($endTs && $endTs < $startTs) {
							$db->rollback();
							$hasError = true;
							setEventMessages('Break end must be after break start for break #'.$bid.'.', null, 'errors');
							break;
						}

						$seconds = ($endTs ? max(0, (int) ($endTs - $startTs)) : 0);
						$sqlUp = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_break SET';
						$sqlUp .= " break_start='".$db->idate($startTs)."',";
						$sqlUp .= ($endTs ? " break_end='".$db->idate($endTs)."'," : ' break_end=NULL,');
						$sqlUp .= ' seconds = '.((int) $seconds).',';
						$sqlUp .= " note='".$db->escape($bn)."'";
						$sqlUp .= ' WHERE entity = '.((int) $conf->entity);
						$sqlUp .= ' AND fk_shift = '.((int) $shiftId);
						$sqlUp .= ' AND rowid = '.((int) $bid);
						$resUp = $db->query($sqlUp);
						if (!$resUp) {
							$db->rollback();
							$hasError = true;
							setEventMessages($db->lasterror(), null, 'errors');
							break;
						}
					}
				}
			}

			if (!$hasError) {
				// Add new break (optional).
				$addStartTs = clockworkParseDatetimeLocal(GETPOST('add_break_start', 'alpha'));
				$addEndTs = clockworkParseDatetimeLocal(GETPOST('add_break_end', 'alpha'));
				$addNote = (string) GETPOST('add_break_note', 'restricthtml');
				if ($addStartTs) {
					if ($addEndTs && $addEndTs < $addStartTs) {
						$db->rollback();
						$hasError = true;
						setEventMessages('New break end must be after break start.', null, 'errors');
					} else {
						$seconds = ($addEndTs ? max(0, (int) ($addEndTs - $addStartTs)) : 0);
						$now = dol_now();
						$sqlIns = 'INSERT INTO '.MAIN_DB_PREFIX.'clockwork_break(entity, fk_shift, break_start, break_end, seconds, note, datec)';
						$sqlIns .= ' VALUES (';
						$sqlIns .= ((int) $conf->entity).',';
						$sqlIns .= ((int) $shiftId).',';
						$sqlIns .= "'".$db->idate($addStartTs)."',";
						$sqlIns .= ($addEndTs ? "'".$db->idate($addEndTs)."'," : 'NULL,');
						$sqlIns .= ((int) $seconds).',';
						$sqlIns .= "'".$db->escape($addNote)."',";
						$sqlIns .= "'".$db->idate($now)."')";
						$resIns = $db->query($sqlIns);
						if (!$resIns) {
							$db->rollback();
							$hasError = true;
							setEventMessages($db->lasterror(), null, 'errors');
						}
					}
				}
			}

			if (!$hasError) {
				// Ensure at most one open break.
				$sqlOpen = 'SELECT COUNT(*) as c FROM '.MAIN_DB_PREFIX.'clockwork_break';
				$sqlOpen .= ' WHERE entity = '.((int) $conf->entity);
				$sqlOpen .= ' AND fk_shift = '.((int) $shiftId);
				$sqlOpen .= ' AND break_end IS NULL';
				$resOpen = $db->query($sqlOpen);
				$cntOpen = 0;
				if ($resOpen) {
					$o = $db->fetch_object($resOpen);
					$cntOpen = (int) $o->c;
				}
				if ($cntOpen > 1) {
					$db->rollback();
					$hasError = true;
					setEventMessages('Invalid state: more than one open break exists. Please close breaks or delete extras.', null, 'errors');
				}
			}

			if (!$hasError) {
				// Update shift timestamps + note and status.
				$statusVal = ($clockoutTs ? 1 : 0);
				$sqlUpdShift = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift SET';
				$sqlUpdShift .= " clockin='".$db->idate($clockinTs)."',";
				$sqlUpdShift .= ($clockoutTs ? " clockout='".$db->idate($clockoutTs)."'," : ' clockout=NULL,');
				$sqlUpdShift .= ' status = '.((int) $statusVal).',';
				$sqlUpdShift .= " note='".$db->escape($note)."'";
				$sqlUpdShift .= ' WHERE entity = '.((int) $conf->entity);
				$sqlUpdShift .= ' AND rowid = '.((int) $shiftId);
				$resUpdShift = $db->query($sqlUpdShift);
				if (!$resUpdShift) {
					$db->rollback();
					$hasError = true;
					setEventMessages($db->lasterror(), null, 'errors');
				}
			}

			if (!$hasError) {
				// Update break_seconds to sum of closed breaks (keep consistent even if open shift).
				$sqlBreakSum = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift SET break_seconds = (';
				$sqlBreakSum .= 'SELECT COALESCE(SUM(seconds),0) FROM '.MAIN_DB_PREFIX.'clockwork_break';
				$sqlBreakSum .= ' WHERE fk_shift = '.((int) $shiftId).' AND break_end IS NOT NULL';
				$sqlBreakSum .= ')';
				$sqlBreakSum .= ' WHERE rowid = '.((int) $shiftId);
				$resBreakSum = $db->query($sqlBreakSum);
				if (!$resBreakSum) {
					$db->rollback();
					$hasError = true;
					setEventMessages($db->lasterror(), null, 'errors');
				}
			}

			if (!$hasError) {
				// If closed, recompute worked/net totals. If open, set worked/net to 0 (break_seconds kept).
				if ($clockoutTs) {
					$tmpShift = new ClockworkShift($db);
					$resTotals = $tmpShift->recomputeTotals($shiftId);
					if ($resTotals < 0) {
						$db->rollback();
						$hasError = true;
						setEventMessages($tmpShift->error, $tmpShift->errors, 'errors');
					}
				} else {
					$sqlZero = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift SET worked_seconds = 0, net_seconds = 0';
					$sqlZero .= ' WHERE rowid = '.((int) $shiftId);
					$resZero = $db->query($sqlZero);
					if (!$resZero) {
						$db->rollback();
						$hasError = true;
						setEventMessages($db->lasterror(), null, 'errors');
					}
				}
			}

			if (!$hasError) {
				$db->commit();
				setEventMessages('Time entry saved.', null, 'mesgs');
			}
		}
	}

	// PRG redirect.
	$redirect = $_SERVER['PHP_SELF'].'?date_from='.urlencode($dateFrom).'&date_to='.urlencode($dateTo);
	if ($searchUser > 0) $redirect .= '&user_id='.((int) $searchUser);
	if (!empty($status)) $redirect .= '&status='.urlencode($status);
	$redirect .= '&shift_id='.((int) $shiftId).'#clockwork-edit';
	header('Location: '.$redirect);
	exit;
}

llxHeader('', $langs->trans('ClockworkHRShifts'));
print load_fiche_titre($langs->trans('ClockworkShifts'), '', 'calendar');

$head = clockworkPrepareHead();
print dol_get_fiche_head($head, 'hr_shifts', $langs->trans('Clockwork'), -1, 'calendar');

// Edit panel (manage right)
if ($shiftId > 0) {
	$sqlOne = 'SELECT s.rowid, s.fk_user, s.clockin, s.clockout, s.status, s.note, u.login, u.firstname, u.lastname';
	$sqlOne .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
	$sqlOne .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
	$sqlOne .= ' WHERE s.entity = '.((int) $conf->entity).' AND s.rowid = '.((int) $shiftId);
	$sqlOne .= ' LIMIT 1';
	$resOne = $db->query($sqlOne);
	$objOne = $resOne ? $db->fetch_object($resOne) : null;

	print '<a id="clockwork-edit"></a>';
	print '<div class="border" style="padding: 12px; margin-bottom: 14px; border-radius: 6px; background: #fff;">';
	print '<div class="bold">'.$langs->trans('ClockworkMyTime').' — '.$langs->trans('Edit').'</div>';

	if (!$objOne) {
		print '<div class="warning">Shift not found.</div>';
	} else {
		$clockinVal = dol_print_date($db->jdate($objOne->clockin), '%Y-%m-%dT%H:%M');
		$clockoutVal = $objOne->clockout ? dol_print_date($db->jdate($objOne->clockout), '%Y-%m-%dT%H:%M') : '';
		$empLabel = trim($objOne->firstname.' '.$objOne->lastname).' ('.$objOne->login.')';

		if (!$user->hasRight('clockwork', 'manage')) {
			print '<div class="opacitymedium">You have read-only access to this entry.</div>';
		}

		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin-top: 10px;">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="save_entry">';
		print '<input type="hidden" name="shift_id" value="'.((int) $shiftId).'">';
		print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'">';
		print '<input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
		print '<input type="hidden" name="user_id" value="'.((int) $searchUser).'">';
		print '<input type="hidden" name="status" value="'.dol_escape_htmltag($status ? $status : 'all').'">';

		print '<table class="border centpercent">';
		print '<tr><td class="titlefield">'.$langs->trans('Employee').'</td><td>'.dol_escape_htmltag($empLabel).'</td></tr>';
		print '<tr><td>'.$langs->trans('ClockInTime').'</td><td><input type="datetime-local" name="clockin" value="'.dol_escape_htmltag($clockinVal).'" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'></td></tr>';
		print '<tr><td>'.$langs->trans('ClockOutTime').'</td><td><input type="datetime-local" name="clockout" value="'.dol_escape_htmltag($clockoutVal).'" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'> <span class="opacitymedium">(leave empty to keep shift open)</span></td></tr>';
		print '<tr><td>'.$langs->trans('Note').'</td><td><textarea name="note" rows="2" class="quatrevingtpercent" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'>'.dol_escape_htmltag((string) $objOne->note).'</textarea></td></tr>';
		print '</table>';

		// Breaks
		$sqlB = 'SELECT rowid, break_start, break_end, note';
		$sqlB .= ' FROM '.MAIN_DB_PREFIX.'clockwork_break';
		$sqlB .= ' WHERE entity = '.((int) $conf->entity).' AND fk_shift = '.((int) $shiftId);
		$sqlB .= ' ORDER BY break_start ASC';
		$resB = $db->query($sqlB);

		print '<br>';
		print '<div class="bold">'.$langs->trans('Breaks').'</div>';
		print '<div class="div-table-responsive">';
		print '<table class="liste centpercent">';
		print '<tr class="liste_titre">';
		print '<th>'.$langs->trans('Breaks').'</th>';
		print '<th>'.$langs->trans('Start').'</th>';
		print '<th>'.$langs->trans('End').'</th>';
		print '<th>'.$langs->trans('Note').'</th>';
		if ($user->hasRight('clockwork', 'manage')) print '<th class="center">'.$langs->trans('Delete').'</th>';
		print '</tr>';

		$i = 0;
		if ($resB) {
			while ($ob = $db->fetch_object($resB)) {
				$i++;
				$bs = dol_print_date($db->jdate($ob->break_start), '%Y-%m-%dT%H:%M');
				$be = $ob->break_end ? dol_print_date($db->jdate($ob->break_end), '%Y-%m-%dT%H:%M') : '';
				print '<tr class="oddeven">';
				print '<td>#'.((int) $ob->rowid).'</td>';
				print '<td><input type="datetime-local" name="break_start['.((int) $ob->rowid).']" value="'.dol_escape_htmltag($bs).'" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'></td>';
				print '<td><input type="datetime-local" name="break_end['.((int) $ob->rowid).']" value="'.dol_escape_htmltag($be).'" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'></td>';
				print '<td><input type="text" class="quatrevingtpercent" name="break_note['.((int) $ob->rowid).']" value="'.dol_escape_htmltag((string) $ob->note).'" '.(!$user->hasRight('clockwork', 'manage') ? 'disabled' : '').'></td>';
				if ($user->hasRight('clockwork', 'manage')) {
					print '<td class="center"><input type="checkbox" name="delete_break_ids[]" value="'.((int) $ob->rowid).'"></td>';
				}
				print '</tr>';
			}
		}

		// Add break row
		if ($user->hasRight('clockwork', 'manage')) {
			print '<tr class="oddeven">';
			print '<td class="opacitymedium">+ New</td>';
			print '<td><input type="datetime-local" name="add_break_start" value=""></td>';
			print '<td><input type="datetime-local" name="add_break_end" value=""></td>';
			print '<td><input type="text" class="quatrevingtpercent" name="add_break_note" value=""></td>';
			print '<td class="center"></td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';

		print '<div class="tabsAction">';
		if ($user->hasRight('clockwork', 'manage')) {
			print '<button class="butAction" type="submit">'.$langs->trans('Save').'</button>';
		}
		$backUrl = $_SERVER['PHP_SELF'].'?date_from='.urlencode($dateFrom).'&date_to='.urlencode($dateTo);
		if ($searchUser > 0) $backUrl .= '&user_id='.((int) $searchUser);
		if (!empty($status)) $backUrl .= '&status='.urlencode($status);
		print '<a class="butActionCancel" href="'.$backUrl.'">'.$langs->trans('Cancel').'</a>';
		print '</div>';

		print '</form>';
	}
	print '</div>';
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="inline-block marginrightonly">';
print $langs->trans('DateFrom').': <input type="date" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"> ';
print $langs->trans('DateTo').': <input type="date" name="date_to" value="'.dol_escape_htmltag($dateTo).'"> ';
print '</div>';

print '<div class="inline-block marginrightonly">'.$langs->trans('Employee').': ';
print $form->select_dolusers($searchUser, 'user_id', 1, '', 0);
print '</div>';

print '<div class="inline-block marginrightonly">'.$langs->trans('Status').': ';
$opts = array('all' => $langs->trans('Status'), 'open' => $langs->trans('Open'), 'closed' => $langs->trans('Closed'));
print $form->selectarray('status', $opts, $status ? $status : 'all', 0);
print '</div>';

print '<input class="button" type="submit" value="'.$langs->trans('Search').'">';
print '</form>';

$sql = 'SELECT s.rowid, s.fk_user, s.clockin, s.clockout, s.status, s.worked_seconds, s.break_seconds, s.net_seconds, s.ip, s.user_agent, s.note,';
$sql .= ' u.login, u.firstname, u.lastname, u.email';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_shift as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = s.fk_user';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= " AND s.clockin >= '".$db->idate($tsFrom)."'";
$sql .= " AND s.clockin <= '".$db->idate($tsTo)."'";
if ($searchUser > 0) $sql .= ' AND s.fk_user = '.((int) $searchUser);
if ($status === 'open') $sql .= ' AND s.status = 0';
if ($status === 'closed') $sql .= ' AND s.status = 1';
$sql .= ' ORDER BY s.clockin DESC';
$sql .= $db->plimit(200, 0);

$resql = $db->query($sql);
if (!$resql) {
	print $db->lasterror();
	print dol_get_fiche_end();
	llxFooter();
	exit;
}

print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Employee').'</th>';
print '<th>'.$langs->trans('ClockInTime').'</th>';
print '<th>'.$langs->trans('ClockOutTime').'</th>';
print '<th>'.$langs->trans('BreakTime').'</th>';
print '<th>'.$langs->trans('Net').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
if ($user->hasRight('clockwork', 'manage')) print '<th class="center">'.$langs->trans('Edit').'</th>';
print '</tr>';

while ($obj = $db->fetch_object($resql)) {
	$clockin = $db->jdate($obj->clockin);
	$clockout = $obj->clockout ? $db->jdate($obj->clockout) : null;

	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag(trim($obj->firstname.' '.$obj->lastname).' ('.$obj->login.')').'</td>';
	print '<td>'.dol_print_date($clockin, 'dayhour').'</td>';
	print '<td>'.($clockout ? dol_print_date($clockout, 'dayhour') : '').'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->break_seconds).'</td>';
	print '<td>'.clockworkFormatDuration((int) $obj->net_seconds).'</td>';
	print '<td>'.(((int) $obj->status) === 0 ? $langs->trans('Open') : $langs->trans('Closed')).'</td>';
	if ($user->hasRight('clockwork', 'manage')) {
		$editUrl = $_SERVER['PHP_SELF'].'?date_from='.urlencode($dateFrom).'&date_to='.urlencode($dateTo);
		if ($searchUser > 0) $editUrl .= '&user_id='.((int) $searchUser);
		if (!empty($status)) $editUrl .= '&status='.urlencode($status);
		$editUrl .= '&shift_id='.((int) $obj->rowid).'#clockwork-edit';
		print '<td class="center"><a href="'.$editUrl.'">'.img_picto($langs->trans('Edit'), 'edit').'</a></td>';
	}
	print '</tr>';
}

print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
