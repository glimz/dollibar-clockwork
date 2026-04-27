<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkbreak.class.php';

/**
 * ClockworkShift represents one work session (clock-in -> clock-out) for one user.
 */
class ClockworkShift extends CommonObject
{
	public $element = 'clockwork_shift';
	public $table_element = 'clockwork_shift';
	public $picto = 'calendar';

	public $fk_user;
	public $clockin;
	public $clockout;
	public $status; // 0 open, 1 closed
	public $worked_seconds;
	public $break_seconds;
	public $net_seconds;
	public $note;
	public $ip;
	public $user_agent;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch open shift for a user.
	 *
	 * @param int $userId
	 * @return int<-1,0,1> -1 on error, 0 if none, 1 if found
	 */
	public function fetchOpenByUser($userId)
	{
		global $conf;

		$sql = 'SELECT rowid, fk_user, clockin, clockout, status, worked_seconds, break_seconds, net_seconds, note, ip, user_agent';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_user = '.((int) $userId);
		$sql .= ' AND status = 0';
		$sql .= ' ORDER BY clockin DESC';
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		$this->id = (int) $obj->rowid;
		$this->fk_user = (int) $obj->fk_user;
		$this->clockin = $this->db->jdate($obj->clockin);
		$this->clockout = $obj->clockout ? $this->db->jdate($obj->clockout) : null;
		$this->status = (int) $obj->status;
		$this->worked_seconds = (int) $obj->worked_seconds;
		$this->break_seconds = (int) $obj->break_seconds;
		$this->net_seconds = (int) $obj->net_seconds;
		$this->note = $obj->note;
		$this->ip = $obj->ip;
		$this->user_agent = $obj->user_agent;

		return 1;
	}

	/**
	 * Clock in (create new open shift).
	 *
	 * @param User $user
	 * @param string $note
	 * @return int<-1,1> Shift id (>0) on success, -1 on error
	 */
	public function clockIn($user, $note = '')
	{
		global $conf, $langs;
		$langs->load('clockwork@clockwork');

		$tmp = new self($this->db);
		$exists = $tmp->fetchOpenByUser($user->id);
		if ($exists < 0) {
			$this->error = $tmp->error;
			return -1;
		}
		if ($exists > 0) {
			$this->error = $langs->trans('ClockworkAlreadyClockedIn');
			return -1;
		}

		$now = dol_now();
		$ip = getUserRemoteIP();
		$userAgent = empty($_SERVER['HTTP_USER_AGENT']) ? '' : substr($_SERVER['HTTP_USER_AGENT'], 0, 255);

		$this->db->begin();

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$this->table_element.'(';
		$sql .= 'entity, fk_user, clockin, last_activity_at, status, worked_seconds, break_seconds, net_seconds, note, ip, user_agent, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $conf->entity).',';
		$sql .= ((int) $user->id).',';
		$sql .= "'".$this->db->idate($now)."',";
		$sql .= "'".$this->db->idate($now)."',";
		$sql .= '0,0,0,0,';
		$sql .= "'".$this->db->escape($note)."',";
		$sql .= "'".$this->db->escape($ip)."',";
		$sql .= "'".$this->db->escape($userAgent)."',";
		$sql .= "'".$this->db->idate($now)."')";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->fk_user = (int) $user->id;
		$this->clockin = $now;
		$this->status = 0;
		$this->note = $note;
		$this->ip = $ip;
		$this->user_agent = $userAgent;

		$this->db->commit();

		$this->call_trigger('CLOCKWORK_CLOCKIN', $user);

		return $this->id;
	}

	/**
	 * Clock out (close open shift). If there is an open break, it is ended automatically.
	 *
	 * @param User $user
	 * @param string $note
	 * @return int<-1,1> 1 on success, -1 on error
	 */
	public function clockOut($user, $note = '')
	{
		global $langs;
		$langs->load('clockwork@clockwork');

		$tmp = new self($this->db);
		$open = $tmp->fetchOpenByUser($user->id);
		if ($open < 0) {
			$this->error = $tmp->error;
			return -1;
		}
		if ($open == 0) {
			$this->error = $langs->trans('ClockworkNotClockedIn');
			return -1;
		}

		$now = dol_now();

		$this->db->begin();

		// End open break if any.
		$break = new ClockworkBreak($this->db);
		$hasOpenBreak = $break->fetchOpenByShift($tmp->id);
		if ($hasOpenBreak < 0) {
			$this->error = $break->error;
			$this->db->rollback();
			return -1;
		}
		if ($hasOpenBreak > 0) {
			$resEnd = $break->endBreak($user, $tmp->id, '');
			if ($resEnd < 0) {
				$this->error = $break->error;
				$this->db->rollback();
				return -1;
			}
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET clockout='".$this->db->idate($now)."',";
		$sql .= ' status=1,';
		$sql .= " note='".$this->db->escape($note)."'";
		$sql .= ' WHERE rowid = '.((int) $tmp->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		// Recompute totals.
		$resTotals = $this->recomputeTotals($tmp->id);
		if ($resTotals < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		$this->id = $tmp->id;
		$this->fk_user = (int) $user->id;
		$this->clockout = $now;
		$this->status = 1;
		$this->note = $note;

		$this->call_trigger('CLOCKWORK_CLOCKOUT', $user);

		return 1;
	}

	/**
	 * Recompute stored totals for a shift.
	 *
	 * @param int $shiftId
	 * @return int<-1,1>
	 */
	public function recomputeTotals($shiftId)
	{
		// Load shift timestamps.
		$sql = 'SELECT clockin, clockout';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' WHERE rowid = '.((int) $shiftId);
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			$this->error = 'Shift not found';
			return -1;
		}

		$clockinTs = $this->db->jdate($obj->clockin);
		$clockoutTs = $obj->clockout ? $this->db->jdate($obj->clockout) : null;
		$workedSeconds = 0;
		if ($clockoutTs) {
			$workedSeconds = max(0, (int) ($clockoutTs - $clockinTs));
		}

		// Sum break seconds (only closed breaks).
		$sql2 = 'SELECT SUM(seconds) as s';
		$sql2 .= ' FROM '.MAIN_DB_PREFIX.'clockwork_break';
		$sql2 .= ' WHERE fk_shift = '.((int) $shiftId);
		$sql2 .= ' AND break_end IS NOT NULL';

		$resql2 = $this->db->query($sql2);
		if (!$resql2) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj2 = $this->db->fetch_object($resql2);
		$breakSeconds = empty($obj2->s) ? 0 : (int) $obj2->s;

		$netSeconds = max(0, $workedSeconds - $breakSeconds);

		$sql3 = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql3 .= ' SET worked_seconds = '.((int) $workedSeconds).',';
		$sql3 .= ' break_seconds = '.((int) $breakSeconds).',';
		$sql3 .= ' net_seconds = '.((int) $netSeconds);
		$sql3 .= ' WHERE rowid = '.((int) $shiftId);

		$resql3 = $this->db->query($sql3);
		if (!$resql3) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}
}
