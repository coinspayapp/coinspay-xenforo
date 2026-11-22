<?php

namespace CoinsPay\Payment\Cron;

use CoinsPay\Payment\Front;

/**
 * Cron entry "CoinsPay: check unpaid invoices", every 5 minutes: invoices whose webhook did not
 * come are read back from CoinsPay and applied.
 */
class Poll {

	public static function run() {
		$provider = Front::provider();
		if (!$provider) {
			return;
		}
		Front::boot();
		(new \CoinsPay_Poller(Front::profiles($provider)))->run();
	}
}
