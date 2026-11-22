<?php
/**
 * Texts of the module in English or Russian: lang/en.php and lang/ru.php.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Text {

	/** @var array */
	private $strings;

	/** @var array */
	private $fallback;

	/**
	 * @param string $lang en or ru.
	 */
	public function __construct($lang) {
		$this->fallback = self::load('en');
		$this->strings = $lang === 'en' ? $this->fallback : self::load($lang);
	}

	/**
	 * Text by key with sprintf arguments: t('log_paid', $number, $amount).
	 *
	 * @param string $key
	 * @return string
	 */
	public function t($key) {
		$args = func_get_args();
		array_shift($args);
		if (isset($this->strings[$key])) {
			$text = $this->strings[$key];
		} elseif (isset($this->fallback[$key])) {
			$text = $this->fallback[$key];
		} else {
			$text = $key;
		}
		return $args ? vsprintf($text, $args) : $text;
	}

	private static function load($lang) {
		$file = __DIR__ . '/lang/' . preg_replace('/[^a-z]/', '', (string)$lang) . '.php';
		if (!is_file($file)) {
			return array();
		}
		$strings = include $file;
		return is_array($strings) ? $strings : array();
	}
}
