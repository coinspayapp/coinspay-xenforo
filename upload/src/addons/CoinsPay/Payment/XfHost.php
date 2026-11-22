<?php

namespace CoinsPay\Payment;

use CoinsPay\Payment\Payment\CoinsPay;
use XF\Payment\CallbackState;

/**
 * XenForo for the core, for one payment profile. A purchase is applied and logged by the provider
 * methods of XenForo itself, the way its payment callback does.
 */
class XfHost implements \CoinsPay_Host {

	/** Characters of a log message: log_message of xf_payment_provider_log is VARCHAR(255). */
	const MESSAGE_MAX = 250;

	/** @var int */
	private $profileId;

	/** @var array */
	private $options;

	/** @var CoinsPay */
	private $provider;

	/** @var XfDb|null */
	private $db;

	public function __construct($profileId, array $options, CoinsPay $provider) {
		$this->profileId = (int)$profileId;
		$this->options = $options;
		$this->provider = $provider;
	}

	public function db() {
		if ($this->db === null) {
			$this->db = new XfDb();
		}
		return $this->db;
	}

	public function profileId() {
		return $this->profileId;
	}

	public function settings() {
		return $this->options;
	}

	public function boardUrl() {
		return Front::boardUrl();
	}

	public function language() {
		return Front::language();
	}

	public function isAdmin() {
		return (bool)\XF::visitor()->is_admin;
	}

	public function purchase($requestKey) {
		$request = \XF::finder('XF:PurchaseRequest')->where('request_key', (string)$requestKey)->fetchOne();
		if (!$request) {
			return null;
		}
		return array(
			'request_key' => (string)$request->request_key,
			'purchase_request_id' => (int)$request->purchase_request_id,
			'user_id' => (int)$request->user_id,
			'cost_amount' => (string)$request->cost_amount,
			'cost_currency' => (string)$request->cost_currency,
			'payment_profile_id' => (int)$request->payment_profile_id,
		);
	}

	public function hasPayment($transactionId) {
		return \XF::finder('XF:PaymentProviderLog')
			->where('provider_id', Front::PROVIDER_ID)
			->where('transaction_id', (string)$transactionId)
			->where('log_type', 'payment')
			->total() > 0;
	}

	public function grant($requestKey, $transactionId, $note, $details, $guard) {
		$state = new CallbackState();
		$state->requestKey = (string)$requestKey;
		$state->transactionId = (string)$transactionId;
		$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
		$state->logType = '';
		$state->logMessage = '';
		$state->logDetails = array();

		$provider = $this->provider;
		if (!$state->getPurchaseRequest()) {
			return 'The purchase request is gone.';
		}
		// The checks of the payment callback: the purchasable, its profile and the member are there.
		if (!$provider->validatePurchasableHandler($state) || !$provider->validatePaymentProfile($state) || !$provider->validatePurchaser($state)) {
			return self::why($state);
		}

		$db = \XF::db();
		$db->beginTransaction();
		try {
			// First: this request's claim becomes "applied" and the row stays locked until the commit.
			if (!call_user_func($guard)) {
				$db->rollback();
				throw new \CoinsPay_Busy('the claim of the purchase was taken over by another request');
			}
			$provider->completeTransaction($state);
			if ($state->logType === 'error') {
				$db->rollback();
				return self::why($state);
			}
			if ((string)$state->logType === '') {
				$state->logType = 'payment';
				$state->logMessage = 'Payment received.';
			}
			self::message($state, trim((string)$state->logMessage . ' ' . $note), $details);
			$provider->log($state);
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollback();
			throw $e;
		}
		return '';
	}

	public function log($requestKey, $transactionId, $type, $message, $details) {
		$state = new CallbackState();
		$state->requestKey = (string)$requestKey;
		$state->transactionId = (string)$transactionId;
		$state->logType = $type === 'error' ? 'error' : 'info';
		self::message($state, (string)$message, is_array($details) ? $details : array());
		$this->provider->log($state);
	}

	/**
	 * The message of a log line fits the 255 characters of log_message; the whole text stays in
	 * the details of the line.
	 */
	private static function message(CallbackState $state, $message, array $details) {
		if (preg_match_all('/./us', $message) > self::MESSAGE_MAX) {
			$details = array('message' => $message) + $details;
			$message = \CoinsPay_Util::truncate($message, self::MESSAGE_MAX);
		}
		$state->logMessage = $message;
		$state->logDetails = $details;
	}

	public function xfVersion() {
		return (string)\XF::$version;
	}

	public function transport() {
		return new XfTransport();
	}

	/** What XenForo said when it refused, in one line. */
	private static function why(CallbackState $state) {
		$message = trim((string)$state->logMessage);
		return $message !== '' ? $message : 'XenForo refused the purchase.';
	}
}
