<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hooks for Clockwork module.
 */
class ActionsClockwork extends CommonHookActions
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * @var mixed[]
	 */
	public $results = array();

	/**
	 * @var string
	 */
	public $resprints;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add quick access icon in the top right menu.
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $form, $langs, $user;
		$langs->load('clockwork@clockwork');

		if (!isModEnabled('clockwork')) return 0;
		if (!$user->hasRight('clockwork', 'clock')) return 0;

		$url = DOL_URL_ROOT.'/custom/clockwork/clockwork/clock.php';
		$text = '<a href="'.$url.'" class="nofocusvisible">';
		$text .= '<span class="fa fa-clock atoplogin valignmiddle"></span>';
		$text .= '</a>';

		$this->resprints = $form->textwithtooltip('', $langs->trans('ClockworkMyTime'), 2, 1, $text, 'login_block_elem', 2);
		return 0;
	}

	/**
	 * Handle user-card actions.
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $db, $langs, $user;

		if (empty($parameters['context']) || strpos((string) $parameters['context'], 'usercard') === false) {
			return 0;
		}
		if ($action !== 'save_clockwork_targets' && $action !== 'test_clockwork_targets') {
			return 0;
		}

		$userId = GETPOSTINT('id');
		if ($userId <= 0) {
			return 0;
		}

		if (!$this->canEditNotificationTargets($userId)) {
			$langs->load('errors');
			setEventMessages($langs->trans('ErrorForbidden'), null, 'errors');
			header('Location: '.DOL_URL_ROOT.'/user/card.php?id='.$userId);
			exit;
		}

		$targets = array(
			'CLOCKWORK_DISCORD_USER_ID' => GETPOST('clockwork_discord_user_id', 'alphanohtml'),
			'CLOCKWORK_DISCORD_USERNAME' => GETPOST('clockwork_discord_username', 'alphanohtml'),
			'CLOCKWORK_SLACK_ID' => GETPOST('clockwork_slack_id', 'alphanohtml'),
		);

		$db->begin();
		$error = 0;
		foreach ($targets as $param => $value) {
			if (!$this->saveUserParam($db, $userId, $param, $value)) {
				$error++;
				break;
			}
		}

		if ($error) {
			$db->rollback();
			setEventMessages($this->error ?: $db->lasterror(), null, 'errors');
		} else {
			$db->commit();
			$langs->load('clockwork@clockwork');
			setEventMessages($langs->trans('ClockworkNotificationTargetsSaved'), null, 'mesgs');
			if ($action === 'test_clockwork_targets') {
				$testRes = $this->sendTestDiscordNotification((int) $userId);
				if (!empty($testRes['ok'])) {
					setEventMessages($langs->trans('ClockworkTestDiscordSent'), null, 'mesgs');
				} else {
					$msg = !empty($testRes['error']) ? (string) $testRes['error'] : $langs->trans('Error');
					setEventMessages($langs->trans('ClockworkTestDiscordFailed', $msg), null, 'errors');
				}
			}
		}

		header('Location: '.DOL_URL_ROOT.'/user/card.php?id='.$userId);
		exit;
	}

	/**
	 * Render Clockwork notification target fields on user card.
	 */
	public function addMoreObjectBlock($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (empty($parameters['context']) || strpos((string) $parameters['context'], 'usercard') === false) {
			return 0;
		}
		if (empty($object->id) || !$this->canViewNotificationTargets((int) $object->id)) {
			return 0;
		}

		$langs->load('clockwork@clockwork');
		$canEdit = $this->canEditNotificationTargets((int) $object->id);

		$discordUserId = $this->getUserParam((int) $object->id, 'CLOCKWORK_DISCORD_USER_ID');
		$discordUsername = $this->getUserParam((int) $object->id, 'CLOCKWORK_DISCORD_USERNAME');
		$slackId = $this->getUserParam((int) $object->id, 'CLOCKWORK_SLACK_ID');

		$html = '<div class="fichecenter">';
		$html .= '<div class="fichehalfleft">';
		$html .= '<div class="underbanner clearboth"></div>';

		if ($canEdit) {
			$html .= '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?id='.(int) $object->id.'">';
			$html .= '<input type="hidden" name="token" value="'.newToken().'">';
			$html .= '<input type="hidden" name="action" value="save_clockwork_targets">';
			$html .= '<input type="hidden" name="id" value="'.(int) $object->id.'">';
			$html .= '<table class="border centpercent tableforfield">';
			$html .= '<tr><td colspan="2"><strong>'.$langs->trans('ClockworkNotificationTargets').'</strong><br>';
			$html .= '<span class="opacitymedium">'.$langs->trans('ClockworkNotificationTargetsHelp').'</span></td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkDiscordUserId').'</td><td><input type="text" class="flat minwidth300" name="clockwork_discord_user_id" value="'.dol_escape_htmltag($discordUserId).'"><div class="opacitymedium">'.$langs->trans('ClockworkDiscordUserIdHelp').'</div></td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkDiscordUsername').'</td><td><input type="text" class="flat minwidth300" name="clockwork_discord_username" value="'.dol_escape_htmltag($discordUsername).'"><div class="opacitymedium">'.$langs->trans('ClockworkDiscordUsernameHelp').'</div></td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkSlackUserId').'</td><td><input type="text" class="flat minwidth300" name="clockwork_slack_id" value="'.dol_escape_htmltag($slackId).'"><div class="opacitymedium">'.$langs->trans('ClockworkSlackUserIdHelp').'</div></td></tr>';
			$html .= '<tr><td></td><td>';
			$html .= '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
			$html .= ' <button type="submit" class="button" formaction="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?id='.(int) $object->id.'&action=test_clockwork_targets">'.dol_escape_htmltag($langs->trans('ClockworkSaveAndSendTestDiscord')).'</button>';
			$html .= '</td></tr>';
			$html .= '</table>';
			$html .= '</form>';
		} else {
			$html .= '<table class="border centpercent tableforfield">';
			$html .= '<tr><td colspan="2"><strong>'.$langs->trans('ClockworkNotificationTargets').'</strong><br>';
			$html .= '<span class="opacitymedium">'.$langs->trans('ClockworkNotificationTargetsHelp').'</span></td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkDiscordUserId').'</td><td>'.($discordUserId !== '' ? dol_escape_htmltag($discordUserId) : '<span class="opacitymedium">'.$langs->trans('None').'</span>').'</td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkDiscordUsername').'</td><td>'.($discordUsername !== '' ? dol_escape_htmltag($discordUsername) : '<span class="opacitymedium">'.$langs->trans('None').'</span>').'</td></tr>';
			$html .= '<tr><td>'.$langs->trans('ClockworkSlackUserId').'</td><td>'.($slackId !== '' ? dol_escape_htmltag($slackId) : '<span class="opacitymedium">'.$langs->trans('None').'</span>').'</td></tr>';
			$html .= '</table>';
		}
		$html .= '</div>';
		$html .= '</div>';

		$this->resprints = $html;
		return 1;
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	private function canViewNotificationTargets($userId)
	{
		global $user;

		return ($user->admin || (int) $user->id === (int) $userId);
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	private function canEditNotificationTargets($userId)
	{
		return $this->canViewNotificationTargets($userId);
	}

	/**
	 * @param int    $userId
	 * @param string $param
	 * @return string
	 */
	private function getUserParam($userId, $param)
	{
		global $conf;

		$sql = 'SELECT value FROM '.MAIN_DB_PREFIX.'user_param';
		$sql .= ' WHERE fk_user = '.((int) $userId);
		$sql .= ' AND entity = '.((int) $conf->entity);
		$sql .= " AND param = '".$this->db->escape($param)."'";
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return trim((string) $obj->value);
		}

		return '';
	}

	/**
	 * @param DoliDB $db
	 * @param int    $userId
	 * @param string $param
	 * @param string $value
	 * @return bool
	 */
	private function saveUserParam($db, $userId, $param, $value)
	{
		global $conf;

		$value = trim((string) $value);
		$sql = 'SELECT fk_user FROM '.MAIN_DB_PREFIX.'user_param';
		$sql .= ' WHERE fk_user = '.((int) $userId);
		$sql .= ' AND entity = '.((int) $conf->entity);
		$sql .= " AND param = '".$db->escape($param)."'";
		$sql .= ' LIMIT 1';

		$resql = $db->query($sql);
		if (!$resql) {
			$this->error = $db->lasterror();
			return false;
		}

		if ($obj = $db->fetch_object($resql)) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'user_param';
			$sql .= " SET value = '".$db->escape($value)."'";
			$sql .= ' WHERE fk_user = '.((int) $userId);
			$sql .= ' AND entity = '.((int) $conf->entity);
			$sql .= " AND param = '".$db->escape($param)."'";
		} else {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'user_param (fk_user, entity, param, value)';
			$sql .= ' VALUES ('.((int) $userId).', '.((int) $conf->entity).", '".$db->escape($param)."', '".$db->escape($value)."')";
		}

		if (!$db->query($sql)) {
			$this->error = $db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 * @param int $userId
	 * @return array<string,mixed>
	 */
	private function sendTestDiscordNotification($userId)
	{
		global $db, $langs;

		require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/lib/clockwork_webhook.lib.php';

		$webhook = clockworkGetWebhookUrl(CLOCKWORK_NOTIFY_TYPE_CLOCKIN, CLOCKWORK_PLATFORM_DISCORD);
		if ($webhook === '') {
			return array('ok' => false, 'error' => $langs->trans('ClockworkTestDiscordNoWebhook'));
		}

		$label = $this->getUserNotificationLabel((int) $userId);
		$embed = array(
			'title' => '🧪 Clockwork Test Notification',
			'color' => 3447003,
			'fields' => array(
				clockworkEmbedField('User', $label, true),
				clockworkEmbedField('Time', dol_print_date(dol_now(), 'dayhourlog'), true),
				clockworkEmbedField('Result', 'Targeted Discord notification path is working.', false),
			),
			'timestamp' => gmdate('c'),
			'footer' => array('text' => 'Clockwork • Test Notification'),
		);

		$payload = array('embeds' => array($embed));
		$payload = clockworkApplyTargetsToPayload($db, $payload, array(
			'user_id' => (int) $userId,
			'label' => $label,
			'login' => $label,
		));

		return clockworkSendDiscordWebhook(CLOCKWORK_NOTIFY_TYPE_CLOCKIN, $payload);
	}

	/**
	 * @param int $userId
	 * @return string
	 */
	private function getUserNotificationLabel($userId)
	{
		$sql = 'SELECT login, firstname, lastname FROM '.MAIN_DB_PREFIX.'user';
		$sql .= ' WHERE rowid = '.((int) $userId);
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$login = trim((string) $obj->login);
			$name = trim(((string) $obj->firstname).' '.((string) $obj->lastname));
			if ($name !== '') {
				return $login !== '' ? $login.' ('.$name.')' : $name;
			}
			if ($login !== '') {
				return $login;
			}
		}

		return 'user#'.((int) $userId);
	}
}
