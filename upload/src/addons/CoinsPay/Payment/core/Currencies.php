<?php
/**
 * Currencies CoinsPay accepts prices in, with their decimals; cached for 12 hours.
 * After the OpenCart plugin (core/Currencies.php), by way of the WHMCS module.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Currencies {

	const TTL = 43200;

	/** @var CoinsPay_Module */
	private $module;

	/** @var array|null per request */
	private $list;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * Decimals of a currency.
	 *
	 * @param string $code
	 * @return int
	 * @throws CoinsPay_ApiError CURRENCY_NOT_SUPPORTED or the list is not available.
	 */
	public function scale($code) {
		$list = $this->load();
		$code = strtoupper((string)$code);
		if (!isset($list[$code])) {
			throw new CoinsPay_ApiError(400, 'CURRENCY_NOT_SUPPORTED', 'CoinsPay does not accept prices in ' . $code);
		}
		return $list[$code];
	}

	/**
	 * @return array Code → decimals.
	 * @throws CoinsPay_ApiError The list is not cached and the API is not available.
	 */
	public function load() {
		if ($this->list !== null) {
			return $this->list;
		}
		$repo = $this->module->repo();
		$settings = $this->module->settings();
		$key = 'currencies_' . substr(md5($settings->apiUrl() . '|' . ($settings->isTestMode() ? 'test' : 'live')), 0, 12);
		$cached = $repo->kvGet($key, self::TTL);
		if ($cached !== null) {
			$list = json_decode($cached, true);
			if (is_array($list)) {
				return $this->list = $list;
			}
		}

		$data = $this->module->client()->currencies();
		$list = array();
		foreach (array('fiat', 'crypto') as $kind) {
			if (empty($data[$kind]) || !is_array($data[$kind])) {
				continue;
			}
			foreach ($data[$kind] as $currency) {
				if (isset($currency['code'], $currency['scale'])) {
					$list[strtoupper((string)$currency['code'])] = (int)$currency['scale'];
				}
			}
		}
		if (!$list) {
			// An answer of another shape: asked again next time instead of refusing every price for 12 hours.
			throw new CoinsPay_ApiError(0, 'BAD_RESPONSE', 'The list of currencies is empty');
		}
		$repo->kvSet($key, CoinsPay_Util::json($list));
		return $this->list = $list;
	}
}
