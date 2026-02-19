<?php

function clockworkPrepareHead()
{
	global $langs;
	$langs->load('clockwork@clockwork');

	$h = 0;
	$head = array();

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/clock.php';
	$head[$h][1] = $langs->trans('ClockworkMyTime');
	$head[$h][2] = 'clock';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/hr_shifts.php';
	$head[$h][1] = $langs->trans('ClockworkHRShifts');
	$head[$h][2] = 'hr_shifts';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/clockwork/clockwork/hr_totals.php';
	$head[$h][1] = $langs->trans('ClockworkHRTotals');
	$head[$h][2] = 'hr_totals';
	$h++;

	return $head;
}

/**
 * Format seconds as H:MM.
 *
 * @param int $seconds
 * @return string
 */
function clockworkFormatDuration($seconds)
{
	$seconds = max(0, (int) $seconds);
	$hours = (int) floor($seconds / 3600);
	$minutes = (int) floor(($seconds % 3600) / 60);
	return $hours.':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
}

