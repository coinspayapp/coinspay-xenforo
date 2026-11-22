<?php

namespace CoinsPay\Payment;

use CoinsPay\Payment\Payment\CoinsPay;

/**
 * The CoinsPay payment profiles of the forum, for the webhook and the cron of the core.
 */
class XfProfiles implements \CoinsPay_Profiles {

	/** @var CoinsPay */
	private $provider;

	/** @var array Modules by profile id, per request. */
	private $modules = array();

	public function __construct(CoinsPay $provider) {
		$this->provider = $provider;
	}

	public function module($profileId) {
		$profileId = (int)$profileId;
		if (!array_key_exists($profileId, $this->modules)) {
			$profile = $profileId > 0 ? \XF::em()->find('XF:PaymentProfile', $profileId) : null;
			$this->modules[$profileId] = $profile && $profile->provider_id === Front::PROVIDER_ID
				? Front::module($profileId, $profile->options, $this->provider)
				: null;
		}
		return $this->modules[$profileId];
	}

	public function all() {
		$modules = array();
		$profiles = \XF::finder('XF:PaymentProfile')->where('provider_id', Front::PROVIDER_ID)->fetch();
		foreach ($profiles as $profile) {
			$module = $this->module((int)$profile->payment_profile_id);
			if ($module) {
				$modules[] = $module;
			}
		}
		return $modules;
	}

	public function repo() {
		return Front::repo();
	}
}
