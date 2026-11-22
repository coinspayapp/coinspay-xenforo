<?php

namespace CoinsPay\Payment\Payment;

use CoinsPay\Payment\Admin;
use CoinsPay\Payment\Front;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

/**
 * CoinsPay as a payment provider of XenForo: the member pays a purchase — a user upgrade or
 * anything another add-on sells through payment profiles — on the CoinsPay payment page.
 *
 * The webhook (payment_callback.php?_xfProvider=coinspay), the member's return (coinspay/return)
 * and the cron each read the invoice back from CoinsPay and apply it through the core, which
 * applies the purchase exactly once with the provider methods of XenForo (see XfHost::grant()).
 * The callback of XenForo is only the way the webhook comes in.
 *
 * Methods are written without return types, as in XenForo 2.2.
 */
class CoinsPay extends AbstractProvider {

	public function getTitle() {
		return 'CoinsPay';
	}

	public function renderConfig(PaymentProfile $profile) {
		Front::boot();
		return (new Admin($this))->render($profile);
	}

	public function verifyConfig(array &$options, &$errors = array()) {
		Front::boot();
		return (new Admin($this))->verify($options, $errors);
	}

	/** A crypto payment cannot renew by itself: XenForo keeps the profile off recurring purchases. */
	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING) {
		$result = self::ERR_NO_RECURRING;
		return false;
	}

	/** Prices in a currency CoinsPay does not take cannot be paid. When the API is away, the purchase tells. */
	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode) {
		$module = Front::module((int)$paymentProfile->payment_profile_id, $paymentProfile->options, $this);
		if (!$module->settings()->ready()) {
			return true;
		}
		try {
			$list = $module->currencies()->load();
		} catch (\CoinsPay_ApiError $e) {
			return true;
		}
		return isset($list[strtoupper((string)$currencyCode)]);
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase) {
		$profile = $purchase->paymentProfile;
		$module = Front::module((int)$profile->payment_profile_id, $profile->options, $this);
		$requestKey = (string)$purchaseRequest->request_key;
		$result = (new \CoinsPay_Payment($module))->start(array(
			'request_key' => $requestKey,
			'purchase_request_id' => (int)$purchaseRequest->purchase_request_id,
			'user_id' => (int)$purchaseRequest->user_id,
			'cost_amount' => (string)$purchaseRequest->cost_amount,
			'cost_currency' => (string)$purchaseRequest->cost_currency,
			'recurring' => !empty($purchase->recurring),
			'title' => (string)$purchase->title,
			'description' => (string)\XF::options()->boardTitle,
			'return_url' => (string)$purchase->returnUrl,
			'back_url' => $controller->buildLink('canonical:coinspay/return', null, array('key' => $requestKey)),
		));
		if (isset($result['redirect']) && $result['redirect'] !== '') {
			return $controller->redirect($result['redirect'], '');
		}
		return $controller->error(isset($result['error']) ? $result['error'] : '');
	}

	/** purchase/process, for a link that names it: the same as coinspay/return. */
	public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase) {
		Front::back((string)$purchaseRequest->request_key);
		return $controller->redirect($purchase->returnUrl);
	}

	/** @var string Body of the webhook being handled: kept here, not as a property of its own on the state. */
	private $webhookBody = '';

	/** @var string Its x-cpay-signature. */
	private $webhookSignature = '';

	public function setupCallback(Request $request) {
		$this->webhookBody = (string)$request->getInputRaw();
		$this->webhookSignature = (string)$request->getServer('HTTP_X_CPAY_SIGNATURE', '');
		$state = new CallbackState();
		$state->ip = $request->getIp();
		return $state;
	}

	/**
	 * The webhook, handled here in full (see \CoinsPay_Webhook): signature, invoice read back from
	 * CoinsPay, purchase applied and logged. False ends the callback of XenForo with nothing left to
	 * do and the answer in httpCode: 2xx — taken; 401 — a bad signature; 503 — the forum cannot
	 * decide yet, CoinsPay sends it again.
	 */
	public function validateCallback(CallbackState $state) {
		Front::boot();
		$answer = (new \CoinsPay_Webhook(Front::profiles($this)))->handle($this->webhookBody, $this->webhookSignature);
		$state->httpCode = $answer[0];
		$state->logType = '';
		$state->logMessage = 'CoinsPay webhook: ' . $answer[1];
		return false;
	}

	public function getPaymentResult(CallbackState $state) {
		// The core decides: see validateCallback().
	}

	public function prepareLogData(CallbackState $state) {
		if (!isset($state->logDetails) || !is_array($state->logDetails)) {
			$state->logDetails = array();
		}
	}
}
