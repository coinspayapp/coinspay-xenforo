<?php
/**
 * One CoinsPay payment profile in one place: settings, tables, API client and texts for one request.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Module {

	/** @var CoinsPay_Host */
	private $host;

	/** @var CoinsPay_Settings|null */
	private $settings;

	/** @var CoinsPay_Repo|null */
	private $repo;

	/** @var CoinsPay_Currencies|null */
	private $currencies;

	/** @var int Seconds of an API request. */
	private $timeout;

	/** @var CoinsPay_ApiClient|null */
	private $client;

	public function __construct(CoinsPay_Host $host, $timeout = CoinsPay_ApiClient::TIMEOUT) {
		$this->host = $host;
		$this->timeout = (int)$timeout;
	}

	public function host() {
		return $this->host;
	}

	public function settings() {
		if ($this->settings === null) {
			$this->settings = new CoinsPay_Settings($this->host->settings());
		}
		return $this->settings;
	}

	public function repo() {
		if ($this->repo === null) {
			$this->repo = new CoinsPay_Repo($this->host->db(), $this->host->boardUrl());
		}
		return $this->repo;
	}

	public function currencies() {
		if ($this->currencies === null) {
			$this->currencies = new CoinsPay_Currencies($this);
		}
		return $this->currencies;
	}

	public function client() {
		if ($this->client === null) {
			$this->client = $this->settings()->client($this->userAgent(), $this->host->transport(), $this->timeout);
		}
		return $this->client;
	}

	/** For tests: an API client of their own. */
	public function setClient($client) {
		$this->client = $client;
	}

	/** For tests: tables in memory. */
	public function setRepo(CoinsPay_Repo $repo) {
		$this->repo = $repo;
	}

	/** Texts in the language of this request: the visitor's, or the forum's default in webhooks and cron. */
	public function text() {
		return new CoinsPay_Text($this->host->language());
	}

	public function sync() {
		return new CoinsPay_Sync($this);
	}

	public function userAgent() {
		return 'CoinsPay-XenForo/' . CoinsPay_Settings::VERSION . '; XenForo/' . $this->host->xfVersion() . '; PHP/' . PHP_VERSION;
	}

	/** Key of the key-value table for this profile. */
	public function kvKey($name) {
		return 'p' . $this->host->profileId() . '_' . $name;
	}

	/**
	 * A line of the payment provider log: errors always, other steps with "Debug log" on.
	 *
	 * @param string $message
	 * @param bool   $error
	 * @param string $requestKey
	 */
	public function log($message, $error = false, $requestKey = '') {
		if ($error || $this->settings()->flag('debug')) {
			$this->host->log((string)$requestKey, '', $error ? 'error' : 'info', 'CoinsPay: ' . $message, array());
		}
	}
}
