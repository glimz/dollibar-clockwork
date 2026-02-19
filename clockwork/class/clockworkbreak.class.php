<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * ClockworkBreak represents one break interval inside a shift.
 */
class ClockworkBreak extends CommonObject
{
	public $element = 'clockwork_break';
	public $table_element = 'clockwork_break';
	public $picto = 'pause';

	public $fk_shift;
	public $break_start;
	public $break_end;
	public $seconds;
	public $note;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch open break for a shift (break_end IS NULL).
	 *
	 * @param int $shiftId
	 * @return int<-1,0,1>
	 */
	public function fetchOpenByShift($shiftId)
	{
		global $conf;

		$sql = 'SELECT rowid, fk_shift, break_start, break_end, seconds, note';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_shift = '.((int) $shiftId);
		$sql .= ' AND break_end IS NULL';
		$sql .= ' ORDER BY break_start DESC';
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
		$this->fk_shift = (int) $obj->fk_shift;
		$this->break_start = $this->db->jdate($obj->break_start);
		$this->break_end = $obj->break_end ? $this->db->jdate($obj->break_end) : null;
		$this->seconds = (int) $obj->seconds;
		$this->note = $obj->note;

		return 1;
	}

	/**
	 * Start a break for an open shift.
	 *
	 * @param User $user
	 * @param int $shiftId
	 * @param string $note
	 * @return int<-1,1> Break id (>0) on success, -1 on error
	 */
	public function startBreak($user, $shiftId, $note = '')
	{
		global $conf, $langs;
		$langs->load('clockwork@clockwork');

		$tmp = new self($this->db);
		$exists = $tmp->fetchOpenByShift($shiftId);
		if ($exists < 0) {
			$this->error = $tmp->error;
			return -1;
		}
		if ($exists > 0) {
			$this->error = $langs->trans('ClockworkBreakAlreadyOpen');
			return -1;
		}

		$now = dol_now();

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$this->table_element.'(';
		$sql .= 'entity, fk_shift, break_start, break_end, seconds, note, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $conf->entity).',';
		$sql .= ((int) $shiftId).',';
		$sql .= "'".$this->db->idate($now)."',";
		$sql .= 'NULL,';
		$sql .= '0,';
		$sql .= "'".$this->db->escape($note)."',";
		$sql .= "'".$this->db->idate($now)."')";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->fk_shift = (int) $shiftId;
		$this->break_start = $now;
		$this->break_end = null;
		$this->seconds = 0;
		$this->note = $note;

		$this->call_trigger('CLOCKWORK_BREAK_START', $user);

		return $this->id;
	}

	/**
	 * End the open break for a shift.
	 *
	 * @param User $user
	 * @param int $shiftId
	 * @param string $note
	 * @return int<-1,1>
	 */
	public function endBreak($user, $shiftId, $note = '')
	{
		global $langs;
		$langs->load('clockwork@clockwork');

		$tmp = new self($this->db);
		$open = $tmp->fetchOpenByShift($shiftId);
		if ($open < 0) {
			$this->error = $tmp->error;
			return -1;
		}
		if ($open == 0) {
			$this->error = $langs->trans('ClockworkBreakNotOpen');
			return -1;
		}

		$now = dol_now();
		$seconds = max(0, (int) ($now - $tmp->break_start));

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET break_end='".$this->db->idate($now)."',";
		$sql .= ' seconds = '.((int) $seconds).',';
		$sql .= " note='".$this->db->escape($note)."'";
		$sql .= ' WHERE rowid = '.((int) $tmp->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		// Keep shift.break_seconds up-to-date even while shift is still open.
		// (worked/net are only meaningful after clockout is set)
		$sql2 = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_shift';
		$sql2 .= ' SET break_seconds = (';
		$sql2 .= 'SELECT COALESCE(SUM(seconds),0) FROM '.MAIN_DB_PREFIX.'clockwork_break';
		$sql2 .= ' WHERE fk_shift = '.((int) $shiftId).' AND break_end IS NOT NULL';
		$sql2 .= ')';
		$sql2 .= ' WHERE rowid = '.((int) $shiftId);
		$resql2 = $this->db->query($sql2);
		if (!$resql2) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->id = $tmp->id;
		$this->fk_shift = (int) $shiftId;
		$this->break_end = $now;
		$this->seconds = $seconds;
		$this->note = $note;

		$this->call_trigger('CLOCKWORK_BREAK_END', $user);

		return 1;
	}
}
