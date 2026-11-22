<?php
/**
 * Settings of a CoinsPay payment profile, from the options XenForo keeps for it.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Settings {

	const VERSION = '0.1.0';
	const LIFETIME_MIN = 15;
	const LIFETIME_MAX = 1440;
	const LIFETIME_DEFAULT = 60;
	/** The API address when the admin leaves the field empty. */
	const DEFAULT_API_URL = 'https://coinspay.app';

	/** @var array Defaults of the yes/no settings, used until the admin saves the profile. */
	public static $flags = array(
		'auto_return' => true,
		'stablecoins_only' => false,
		'buyer_pays_fee' => false,
		'buyer_pays_network' => false,
		'debug' => false,
	);

	/** @var array */
	private $values;

	/**
	 * @param array $values Options of the payment profile.
	 */
	public function __construct($values) {
		$this->values = is_array($values) ? $values : array();
	}

	public function apiUrl() {
		$url = rtrim(trim($this->get('api_url')), '/');
		return $url === '' ? self::DEFAULT_API_URL : $url;
	}

	public function apiKey() {
		return trim($this->get('api_key'));
	}

	public function webhookSecret() {
		return trim($this->get('webhook_secret'));
	}

	/** Both the key and the webhook secret are there: the profile can take payments. */
	public function ready() {
		return $this->apiKey() !== '' && $this->webhookSecret() !== '';
	}

	public function isTestMode() {
		return strpos($this->apiKey(), 'cpk_test_') === 0;
	}

	public function lifetime() {
		return self::clampLifetime($this->get('lifetime'));
	}

	public static function clampLifetime($value) {
		$value = (int)$value;
		if ($value <= 0) {
			return self::LIFETIME_DEFAULT;
		}
		return max(self::LIFETIME_MIN, min(self::LIFETIME_MAX, $value));
	}

	/**
	 * A yes/no setting. verifyConfig() saves each one as 1 or 0; a profile saved before a setting
	 * appeared has no value for it and gets the default.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function flag($key) {
		if (!array_key_exists($key, $this->values)) {
			return !empty(self::$flags[$key]);
		}
		return in_array(strtolower(trim((string)$this->values[$key])), array('on', 'yes', '1', 'true'), true);
	}

	/**
	 * @param string             $userAgent
	 * @param CoinsPay_Transport $transport
	 * @param int                $timeout   Seconds.
	 * @return CoinsPay_ApiClient
	 */
	public function client($userAgent, CoinsPay_Transport $transport, $timeout = CoinsPay_ApiClient::TIMEOUT) {
		return new CoinsPay_ApiClient($this->apiUrl(), $this->apiKey(), $userAgent, $transport, $timeout);
	}

	private function get($key) {
		return isset($this->values[$key]) && is_scalar($this->values[$key]) ? (string)$this->values[$key] : '';
	}
}
