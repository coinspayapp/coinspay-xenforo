<?php

namespace CoinsPay\Payment;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

/**
 * Installs the tables of the add-on and the payment provider "coinspay" (Setup → Payment
 * profiles → Add payment profile). Uninstalling removes both; payment profiles made with it stay,
 * hidden, until the add-on is installed again.
 */
class Setup extends AbstractSetup {

	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1() {
		Front::repo()->install();
	}

	public function installStep2() {
		$provider = \XF::em()->find('XF:PaymentProvider', Front::PROVIDER_ID);
		if (!$provider) {
			$provider = \XF::em()->create('XF:PaymentProvider');
			$provider->provider_id = Front::PROVIDER_ID;
		}
		$provider->provider_class = Front::PROVIDER_CLASS;
		$provider->addon_id = Front::ADDON_ID;
		$provider->save();
	}

	public function uninstallStep1() {
		$provider = \XF::em()->find('XF:PaymentProvider', Front::PROVIDER_ID);
		if ($provider) {
			$provider->delete();
		}
	}

	public function uninstallStep2() {
		Front::repo()->uninstall();
	}
}
