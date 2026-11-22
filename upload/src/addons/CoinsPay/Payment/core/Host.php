<?php
/**
 * What the core needs from XenForo, for one payment profile. CoinsPay\Payment\XfHost is the real
 * one; unit tests use a fake.
 */
defined('COINSPAY_XF') or exit;

interface CoinsPay_Host {

	/** @return CoinsPay_Db The database of the forum. */
	public function db();

	/** @return int Id of the payment profile. */
	public function profileId();

	/** @return array Options of the payment profile, as verifyConfig() saved them. */
	public function settings();

	/** @return string Address of the forum, without the trailing slash (boardUrl). */
	public function boardUrl();

	/** @return string en or ru: the language of this request — the visitor's or the forum's default. */
	public function language();

	/** @return bool The visitor of this request is an admin. */
	public function isAdmin();

	/**
	 * A purchase request of XenForo, or null when it is gone.
	 *
	 * @param string $requestKey
	 * @return array|null {request_key, purchase_request_id, user_id, cost_amount, cost_currency, payment_profile_id}.
	 */
	public function purchase($requestKey);

	/**
	 * Whether the payment provider log has a payment with this transaction id: XenForo applied it.
	 *
	 * @param string $transactionId
	 * @return bool
	 */
	public function hasPayment($transactionId);

	/**
	 * Applies the purchase the way a payment callback does: XenForo applies it to the member and
	 * writes the payment to the payment provider log, all in one database transaction. $guard runs
	 * first in that transaction: when it returns false nothing is applied and CoinsPay_Busy is thrown.
	 *
	 * @param string   $requestKey
	 * @param string   $transactionId
	 * @param string   $note    Added to the log message of XenForo.
	 * @param array    $details Log details.
	 * @param callable $guard   Marks the claim of this request applied; false when it was taken over.
	 * @return string '' when applied; otherwise why XenForo did not apply it (rolled back).
	 */
	public function grant($requestKey, $transactionId, $note, $details, $guard);

	/**
	 * A line of the payment provider log (Logs → Payment provider log).
	 *
	 * @param string $requestKey
	 * @param string $transactionId
	 * @param string $type    info or error.
	 * @param string $message
	 * @param array  $details
	 */
	public function log($requestKey, $transactionId, $type, $message, $details);

	/** @return string Version of XenForo, for the User-Agent. */
	public function xfVersion();

	/** @return CoinsPay_Transport The HTTP client of XenForo. */
	public function transport();
}

/**
 * The database, with placeholders. XenForo talks to MySQL through mysqli, not PDO.
 */
interface CoinsPay_Db {

	/** @return int Rows affected. */
	public function exec($sql, $args);

	/** @return array|null The first row. */
	public function one($sql, $args);

	/** @return array Rows. */
	public function all($sql, $args);

	public function begin();

	public function commit();

	public function rollback();
}
