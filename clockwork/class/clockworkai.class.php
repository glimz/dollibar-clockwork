<?php

/**
 * Optional AI helper for Clockwork.
 * Uses Dolibarr core AI module when enabled and configured.
 */
class ClockworkAI
{
	const DEFAULT_IDLE_PROMPT = 'Write one short actionable sentence for an HR idle shift alert. Mention whether user should clock out or resume activity.';
	const DEFAULT_IDLE_MAX_CHARS = 280;

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
	 * @return bool
	 */
	public function isAvailable()
	{
		if (!isModEnabled('ai')) {
			return false;
		}

		if (!class_exists('Ai')) {
			$aiClassPath = DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php';
			if (is_readable($aiClassPath)) {
				require_once $aiClassPath;
			}
		}

		return class_exists('Ai');
	}

	/**
	 * Generate a concise insight text for idle shift notifications.
	 *
	 * @param array<string,mixed> $context
	 * @return string
	 */
	public function generateIdleInsight(array $context)
	{
		if (!getDolGlobalInt('CLOCKWORK_AI_ENABLE_IDLE_INSIGHTS', 0)) {
			return '';
		}
		if (!$this->isAvailable()) {
			return '';
		}

		$promptTemplate = trim((string) getDolGlobalString('CLOCKWORK_AI_IDLE_PROMPT', self::DEFAULT_IDLE_PROMPT));
		if ($promptTemplate === '') {
			$promptTemplate = self::DEFAULT_IDLE_PROMPT;
		}
		$maxChars = (int) getDolGlobalInt('CLOCKWORK_AI_IDLE_MAX_CHARS', self::DEFAULT_IDLE_MAX_CHARS);
		if ($maxChars < 80) $maxChars = 80;
		if ($maxChars > 1000) $maxChars = 1000;

		$payload = array(
			'user_login' => isset($context['login']) ? (string) $context['login'] : '',
			'user_label' => isset($context['label']) ? (string) $context['label'] : '',
			'shift_id' => isset($context['shift_id']) ? (int) $context['shift_id'] : 0,
			'idle_seconds' => isset($context['idle_seconds']) ? (int) $context['idle_seconds'] : 0,
			'idle_hhmmss' => isset($context['idle_hhmmss']) ? (string) $context['idle_hhmmss'] : '',
			'last_activity' => isset($context['last_activity']) ? (string) $context['last_activity'] : '',
		);

		$instructions = $promptTemplate."\n";
		$instructions .= 'Output plain text only. Maximum '.$maxChars." characters.\n";
		$instructions .= 'Context JSON: '.json_encode($payload);

		try {
			$ai = new Ai($this->db);
			$res = $ai->generateContent($instructions, 'auto', 'textgeneration', '');
			if (is_array($res)) {
				return '';
			}
			$text = trim((string) $res);
			if ($text === '') {
				return '';
			}
			if (dol_strlen($text) > $maxChars) {
				$text = dol_substr($text, 0, $maxChars - 1);
			}
			return $text;
		} catch (Exception $e) {
			dol_syslog('ClockworkAI generateIdleInsight failed: '.$e->getMessage(), LOG_WARNING);
			return '';
		}
	}
}
