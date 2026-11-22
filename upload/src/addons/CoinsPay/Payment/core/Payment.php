<?php
/**
 * Paying a XenForo purchase: makes or reuses a CoinsPay invoice for the purchase request and sends
 * the member to its payment page.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Payment {

	/** A closed invoice is polled one more day for a late payment. */
	const POLL_AFTER_CLOSE = 86400;

	/** @var CoinsPay_Module */
	private $module;

	/** @var string Currency of the CoinsPay invoice being made, for the error message. */
	private $currency = '';

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * @param array $purchase {request_key, purchase_request_id, user_id, cost_amount, cost_currency,
	 *                        recurring, title, description, return_url (of the purchase), back_url
	 *                        (the return route of the add-on for this request)}.
	 * @return array {redirect} or {error}.
	 */
	public function start($purchase) {
		$text = $this->module->text();
		$settings = $this->module->settings();
		$requestKey = (string)$purchase['request_key'];
		if (!$settings->ready()) {
			$this->module->log('the payment profile has no API key or webhook secret', true, $requestKey);
			return array('error' => $text->t('error_unavailable'));
		}
		if (!empty($purchase['recurring'])) {
			return array('error' => $text->t('error_recurring'));
		}
		// A test key moves no money: only an admin may pay with it, and a member gets nothing for free.
		if ($settings->isTestMode() && !$this->module->host()->isAdmin()) {
			return array('error' => $text->t('error_unavailable'));
		}

		$repo = $this->module->repo();
		if (!$repo->lock($requestKey)) {
			return array('error' => $text->t('error_busy'));
		}
		try {
			return $this->startLocked($purchase);
		} catch (CoinsPay_ApiError $e) {
			$this->module->log('purchase ' . $requestKey . ': ' . $e->summary(), true, $requestKey);
			$key = $e->errorCode() === 'CURRENCY_NOT_SUPPORTED' ? 'error_currency' : 'error_api';
			return array('error' => $text->t($key, $this->currency));
		} catch (CoinsPay_Busy $e) {
			return array('error' => $text->t('error_busy'));
		} catch (RuntimeException $e) {
			$this->module->log('purchase ' . $requestKey . ': ' . $e->getMessage(), true, $requestKey);
			return array('error' => $text->t('error_api'));
		} finally {
			$repo->unlock($requestKey);
		}
	}

	private function startLocked($purchase) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		$settings = $this->module->settings();
		$requestKey = (string)$purchase['request_key'];

		$currency = $this->currency = strtoupper((string)$purchase['cost_currency']);
		$amount = CoinsPay_Util::formatAmount($purchase['cost_amount'], $this->module->currencies()->scale($currency));
		$test = $settings->isTestMode();
		$admin = $host->isAdmin();
		$client = $this->module->client();
		$lang = $host->language();

		$current = $repo->current($requestKey);
		// A test invoice of an admin is not handed to a member: its payment would be applied.
		if ($current && $current['status'] === 'open' && $current['sent_amount'] === $amount && $current['sent_currency'] === $currency
			&& (bool)$current['test'] === $test && (!$test || (bool)$current['test_admin'] === $admin)
			&& (int)$current['user_id'] === (int)$purchase['user_id'] && (int)$current['profile_id'] === $host->profileId()) {
			try {
				$invoice = $client->invoice($current['invoice_id']);
				if (CoinsPay_Util::value($invoice, 'status') === 'open') {
					return array('redirect' => self::withLang(CoinsPay_Util::value($invoice, 'pay_url'), $lang));
				}
				// Paid or closed meanwhile: its state goes to XenForo; paid — back to the purchase.
				$this->module->sync()->apply($invoice, true);
				if (CoinsPay_Util::value($invoice, 'status') === 'paid') {
					return array('redirect' => (string)$purchase['back_url']);
				}
			} catch (CoinsPay_ApiError $e) {
				if (!$e->isNotFound()) {
					throw $e;
				}
			}
		}

		$attempt = count($repo->forRequest($requestKey)) + 1;
		$body = CoinsPay_InvoiceRequest::body(
			array(
				'reference' => (string)$purchase['purchase_request_id'],
				'title' => (string)$purchase['title'],
				'currency' => $currency,
				'amount' => $amount,
				'description' => (string)$purchase['description'],
				'return_url' => (string)$purchase['back_url'],
			),
			$settings
		);
		$invoice = $client->createInvoice($body, CoinsPay_InvoiceRequest::idempotencyKey($host->boardUrl(), $requestKey, $attempt, $body));
		if (CoinsPay_Util::value($invoice, 'status') !== 'open' && !$repo->find(CoinsPay_Util::value($invoice, 'id'))) {
			// The key met an invoice made earlier and closed since — the tables were dropped, say. One more attempt.
			$attempt++;
			$invoice = $client->createInvoice($body, CoinsPay_InvoiceRequest::idempotencyKey($host->boardUrl(), $requestKey, $attempt, $body));
		}

		$invoiceUuid = CoinsPay_ApiClient::id(CoinsPay_Util::value($invoice, 'id'));
		if (!$repo->find($invoiceUuid)) {
			$repo->add(array(
				'invoice_id' => $invoiceUuid,
				'request_key' => $requestKey,
				'profile_id' => $host->profileId(),
				'user_id' => (int)$purchase['user_id'],
				'attempt' => $attempt,
				'sent_amount' => $amount,
				'sent_currency' => $currency,
				'cost_amount' => CoinsPay_Util::normalize((string)$purchase['cost_amount']),
				'cost_currency' => $currency,
				'test' => !empty($invoice['test']) ? 1 : 0,
				'test_admin' => $admin ? 1 : 0,
				'status' => CoinsPay_Util::value($invoice, 'status'),
				'pay_url' => CoinsPay_Util::value($invoice, 'pay_url'),
				'return_url' => (string)$purchase['return_url'],
				'snapshot' => CoinsPay_Util::json(CoinsPay_Sync::snapshot($invoice)),
				'next_poll' => time() + CoinsPay_Sync::POLL_OPEN,
				'poll_until' => time() + $settings->lifetime() * 60 + self::POLL_AFTER_CLOSE,
			));
			$this->module->log(sprintf('purchase %s: CoinsPay invoice %s for %s %s', $requestKey, $invoiceUuid, $amount, $currency), false, $requestKey);
		}

		$this->cancelReplaced($requestKey, $invoiceUuid);
		return array('redirect' => self::withLang(CoinsPay_Util::value($invoice, 'pay_url'), $lang));
	}

	/**
	 * Cancels the open CoinsPay invoices of a purchase request that hold no money: replaced by a new
	 * one. CoinsPay cancels an open invoice even with a payment on its way, so each one is read
	 * fresh first; a payment that still lands in the gap is held for the admin.
	 *
	 * @param string $requestKey
	 * @param string $keep       CoinsPay invoice to leave open.
	 */
	private function cancelReplaced($requestKey, $keep) {
		foreach ($this->module->repo()->forRequest($requestKey) as $row) {
			if ($row['invoice_id'] === $keep || $row['status'] !== 'open') {
				continue;
			}
			try {
				$invoice = $this->module->client()->invoice($row['invoice_id']);
				$hasMoney = CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_paid')) || CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_pending'));
				if (CoinsPay_Util::value($invoice, 'status') === 'open' && !$hasMoney) {
					$invoice = $this->module->client()->cancelInvoice($row['invoice_id']);
				}
				$this->module->sync()->apply($invoice, true);
			} catch (CoinsPay_ApiError $e) {
				// Money came meanwhile (INVOICE_NOT_OPEN) or the API is away: its real state comes with polling.
				$this->module->log(sprintf('purchase %s: CoinsPay invoice %s not cancelled: %s', $requestKey, $row['invoice_id'], $e->summary()), !in_array($e->status(), array(404, 409), true), $requestKey);
			} catch (RuntimeException $e) {
				$this->module->log(sprintf('purchase %s: CoinsPay invoice %s: %s', $requestKey, $row['invoice_id'], $e->getMessage()), true, $requestKey);
			}
		}
	}

	/** The payment page in the member's language. */
	public static function withLang($payUrl, $lang) {
		if ($payUrl === '') {
			return '';
		}
		return $payUrl . (strpos($payUrl, '?') === false ? '?' : '&') . 'lang=' . ($lang === 'ru' ? 'ru' : 'en');
	}
}
