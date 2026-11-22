<?php

namespace CoinsPay\Payment;

use CoinsPay\Payment\Payment\CoinsPay;

/**
 * The add-on and the core in one place: loads the core, finds the provider and the CoinsPay
 * payment profiles, and makes a core module of a profile.
 *
 * Only what XenForo 2.2 and 2.3 both have: entities by short name ('XF:PaymentProfile'), no
 * classes of 2.3 only.
 */
class Front {

	const PROVIDER_ID = 'coinspay';
	const PROVIDER_CLASS = 'CoinsPay\Payment:CoinsPay';
	const ADDON_ID = 'CoinsPay/Payment';

	/** Loads the core classes once. */
	public static function boot() {
		if (!defined('COINSPAY_XF')) {
			define('COINSPAY_XF', true);
		}
		if (!class_exists('CoinsPay_Module', false)) {
			require __DIR__ . '/core/loader.php';
		}
	}

	/** @return CoinsPay|null The provider handler, when the add-on is installed. */
	public static function provider() {
		$provider = \XF::em()->find('XF:PaymentProvider', self::PROVIDER_ID);
		$handler = $provider ? $provider->handler : null;
		return $handler instanceof CoinsPay ? $handler : null;
	}

	/**
	 * A core module of one payment profile.
	 *
	 * @param int      $profileId 0 for options not saved yet.
	 * @param array    $options
	 * @param CoinsPay $provider
	 * @return \CoinsPay_Module
	 */
	public static function module($profileId, $options, CoinsPay $provider) {
		self::boot();
		return new \CoinsPay_Module(new XfHost((int)$profileId, is_array($options) ? $options : array(), $provider));
	}

	/** @return XfProfiles The CoinsPay payment profiles of the forum. */
	public static function profiles(CoinsPay $provider) {
		self::boot();
		return new XfProfiles($provider);
	}

	/** @return \CoinsPay_Repo The tables, outside of a profile. */
	public static function repo() {
		self::boot();
		return new \CoinsPay_Repo(new XfDb(), self::boardUrl());
	}

	/** @return string Address of the forum without the trailing slash. */
	public static function boardUrl() {
		return rtrim((string)\XF::options()->boardUrl, '/');
	}

	/** @return string en or ru: the language of this request. */
	public static function language() {
		$code = strtolower((string)\XF::language()->getLanguageCode());
		return substr($code, 0, 2) === 'ru' ? 'ru' : 'en';
	}

	/** Address CoinsPay sends webhooks to: the payment callback of XenForo. */
	public static function webhookUrl() {
		return self::boardUrl() . '/payment_callback.php?_xfProvider=' . self::PROVIDER_ID;
	}

	/**
	 * The member is back from the payment page: the invoice is read back at once, so a lost or late
	 * webhook keeps no one waiting.
	 *
	 * @param string $requestKey
	 * @return string Where to send the member: the page of the purchase, or '' when unknown.
	 */
	public static function back($requestKey) {
		$provider = self::provider();
		if ($requestKey === '' || !$provider) {
			return '';
		}
		self::boot();
		$poller = new \CoinsPay_Poller(self::profiles($provider));
		try {
			$row = $poller->checkRequest($requestKey);
		} catch (\Exception $e) {
			\XF::logException($e, false, 'CoinsPay: ');
			$row = self::repo()->current($requestKey);
		}
		return $row ? (string)$row['return_url'] : '';
	}
}
