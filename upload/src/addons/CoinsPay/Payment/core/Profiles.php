<?php
/**
 * The CoinsPay payment profiles of the forum. The webhook address and the cron are one for all
 * profiles: the invoice tells which profile it belongs to.
 */
defined('COINSPAY_XF') or exit;

interface CoinsPay_Profiles {

	/**
	 * @param int $profileId
	 * @return CoinsPay_Module|null Null when the profile is gone or is not a CoinsPay one.
	 */
	public function module($profileId);

	/** @return CoinsPay_Module[] Every CoinsPay profile, active or not. */
	public function all();

	/** @return CoinsPay_Repo The tables, shared by all profiles. */
	public function repo();
}
