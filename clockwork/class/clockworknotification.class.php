<?php
/**
 * In-app notification storage for Clockwork.
 */
class ClockworkNotification
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param int    $userId
	 * @param string $type
	 * @param string $title
	 * @param string $message
	 * @param string $severity
	 * @param int    $shiftId
	 * @param array  $meta
	 * @return bool
	 */
	public function create($userId, $type, $title, $message = '', $severity = 'info', $shiftId = 0, $meta = array())
	{
		global $conf;

		$json = json_encode($meta);
		if ($json === false) $json = '{}';

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'clockwork_notification';
		$sql .= ' (entity, fk_user, fk_shift, notif_type, severity, title, message, meta_json, is_read, datec)';
		$sql .= ' VALUES ('.((int) $conf->entity);
		$sql .= ', '.((int) $userId);
		$sql .= ', '.((int) $shiftId > 0 ? (int) $shiftId : 'NULL');
		$sql .= ", '".$this->db->escape($type)."'";
		$sql .= ", '".$this->db->escape($severity)."'";
		$sql .= ", '".$this->db->escape($title)."'";
		$sql .= ", '".$this->db->escape($message)."'";
		$sql .= ", '".$this->db->escape($json)."'";
		$sql .= ", 0";
		$sql .= ", '".$this->db->idate(dol_now())."')";

		return (bool) $this->db->query($sql);
	}

	/**
	 * @param int  $userId
	 * @param int  $limit
	 * @param bool $onlyUnread
	 * @return array
	 */
	public function listForUser($userId, $limit = 20, $onlyUnread = false)
	{
		$sql = 'SELECT rowid, fk_shift, notif_type, severity, title, message, meta_json, is_read, date_read, datec';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_notification';
		$sql .= ' WHERE fk_user = '.((int) $userId);
		if ($onlyUnread) {
			$sql .= ' AND is_read = 0';
		}
		$sql .= ' ORDER BY datec DESC';
		$sql .= ' LIMIT '.max(1, (int) $limit);

		$resql = $this->db->query($sql);
		if (!$resql) return array();

		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'shift_id' => (int) $obj->fk_shift,
				'type' => (string) $obj->notif_type,
				'severity' => (string) $obj->severity,
				'title' => (string) $obj->title,
				'message' => (string) $obj->message,
				'meta' => (string) $obj->meta_json,
				'is_read' => (int) $obj->is_read,
				'datec' => $this->db->jdate($obj->datec),
				'datec_label' => dol_print_date($this->db->jdate($obj->datec), 'dayhour'),
			);
		}
		return $out;
	}

	/**
	 * @param int $userId
	 * @return int
	 */
	public function countUnread($userId)
	{
		$sql = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'clockwork_notification';
		$sql .= ' WHERE fk_user = '.((int) $userId).' AND is_read = 0';
		$resql = $this->db->query($sql);
		if (!$resql) return 0;
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->nb : 0;
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	public function markAllRead($userId)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'clockwork_notification';
		$sql .= ' SET is_read = 1, date_read = \''.$this->db->idate(dol_now()).'\'';
		$sql .= ' WHERE fk_user = '.((int) $userId).' AND is_read = 0';
		return (bool) $this->db->query($sql);
	}
}
