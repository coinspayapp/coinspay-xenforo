<?php
/**
 * Applies the state of a CoinsPay invoice to its XenForo purchase: lock → fresh purchase request →
 * decision → purchase applied or a log line. A purchase is applied exactly once.
 */
defined('COINSPAY_XF') or exit;

/** Another request holds the purchase now: a moment later it is done. Not an error. */
class CoinsPay_Busy extends RuntimeException {
}

class CoinsPay_Sync {

	/** Open invoices are polled every 5 minutes, closed ones every 30 until the end of polling. */
	const POLL_OPEN = 300;
	const POLL_CLOSED = 1800;

	/** A claim of a payment older than this belongs to a request that died: see Repo::claim(). */
	const CLAIM_STALE = 600;

	/**
	 * A paid invoice XenForo did not apply yet — it refused or failed — is tried again every 30
	 * minutes for this long after it was made, past the end of usual polling.
	 */
	const RETRY_PAID = 2592000;

	/** @var CoinsPay_Module */
	private $module;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * @param array $invoice CoinsPay invoice from the API.
	 * @param bool  $locked  The caller holds the lock of the purchase already: Payment does.
	 * @return string What happened, for the log and the webhook answer.
	 * @throws RuntimeException The purchase is busy (CoinsPay_Busy), or XenForo failed while applying
	 *                          it; the caller retries later.
	 */
	public function apply($invoice, $locked = false) {
		$repo = $this->module->repo();
		$row = $repo->find(CoinsPay_Util::value($invoice, 'id'));
		if (!$row) {
			return 'not an invoice of this forum';
		}
		if ($locked) {
			return $this->applyLocked($row['invoice_id'], $invoice);
		}
		$requestKey = (string)$row['request_key'];
		if (!$repo->lock($requestKey)) {
			throw new CoinsPay_Busy('the purchase is busy');
		}
		try {
			return $this->applyLocked($row['invoice_id'], $invoice);
		} finally {
			$repo->unlock($requestKey);
		}
	}

	private function applyLocked($invoiceId, $invoice) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		// Fresh copies: another request may have changed both while we waited for the lock.
		$row = $repo->find($invoiceId);
		if (!$row) {
			return 'not an invoice of this forum';
		}
		$requestKey = (string)$row['request_key'];
		$before = json_decode((string)$row['snapshot'], true);

		$status = CoinsPay_Util::value($invoice, 'status');
		$repo->update($invoiceId, array('status' => $status, 'snapshot' => CoinsPay_Util::json(self::snapshot($invoice))));
		$fingerprint = CoinsPay_Decision::fingerprint($invoice);
		$purchase = $host->purchase($requestKey);
		if (!$purchase) {
			return $this->gone($row, $invoice, $status, $fingerprint);
		}
		// A payment interrupted halfway is never "no change": it is checked again below.
		if ($row['fingerprint'] === $fingerprint && $row['apply_state'] !== 'applying') {
			$this->settle($invoiceId, $status, $row);
			return 'no change';
		}

		$transactionId = self::transactionId($invoice, $invoiceId);
		$applied = $row['apply_state'] === 'applied' || $host->hasPayment($transactionId);
		if ($applied && $row['apply_state'] !== 'applied') {
			$repo->update($invoiceId, array('apply_state' => 'applied', 'claimed' => null, 'claim_token' => null));
		}
		$current = $repo->current($requestKey);

		$text = $this->module->text();
		$decision = CoinsPay_Decision::decide(
			$invoice,
			array(
				'applied' => $applied,
				'test_admin' => !empty($row['test_admin']),
				// Purchase requests of XenForo keep their price; a changed one means another purchase.
				'cost_changed' => CoinsPay_Util::normalize($purchase['cost_amount']) !== CoinsPay_Util::normalize($row['cost_amount'])
					|| strtoupper((string)$purchase['cost_currency']) !== strtoupper((string)$row['cost_currency']),
				'is_current' => $current && $current['invoice_id'] === $invoiceId,
				'overpaid_before' => CoinsPay_Util::value($before, 'amount_overpaid'),
			),
			$text
		);
		$note = $decision['note'];
		$details = self::logDetails($invoice, $row);

		switch ($decision['action']) {
			case CoinsPay_Decision::APPLY:
				$amount = CoinsPay_Util::value($invoice, 'amount') . ' ' . CoinsPay_Util::value($invoice, 'currency');
				try {
					$refused = $this->grant($row, $transactionId, $note, $details);
				} catch (CoinsPay_Busy $e) {
					throw $e;
				} catch (RuntimeException $e) {
					// XenForo failed: the admin sees it, and it is tried again until it goes through.
					$this->tell($row, $transactionId, $text->t('log_apply_failed', CoinsPay_Util::value($invoice, 'number'), $amount, $e->getMessage()), $details);
					$this->retryLater($invoiceId);
					throw $e;
				}
				if ($refused !== '') {
					// XenForo would not apply it (the upgrade was deleted, the add-on that sells it is
					// off): the admin sees it, and it is tried again — the cause may pass.
					$this->tell($row, $transactionId, $text->t('log_not_applied', CoinsPay_Util::value($invoice, 'number'), $amount, $refused), $details);
					$this->retryLater($invoiceId);
					return 'hold: ' . $refused;
				}
				// Applied: whatever waited for a decision is settled; an excess is left for the admin.
				$repo->update($invoiceId, array('attention' => CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_overpaid')) ? $note : null));
				break;
			case CoinsPay_Decision::HOLD:
				$host->log($requestKey, $transactionId, 'error', $note, $details);
				$repo->update($invoiceId, array('attention' => $note));
				break;
			case CoinsPay_Decision::LOG:
				$host->log($requestKey, $transactionId, 'info', $note, $details);
				break;
		}

		// Only after the action went through: had it failed, the next webhook or poll must decide
		// again, not see "no change" and lose the payment.
		$repo->update($invoiceId, array('fingerprint' => $fingerprint));
		$this->settle($invoiceId, $status, $row);
		$this->module->log(sprintf('purchase %s, invoice %s (%s): %s', $requestKey, $invoiceId, $status, $decision['action']), false, $requestKey);
		return $decision['action'] . ($note !== '' ? ': ' . $note : '');
	}

	/**
	 * Applies the purchase exactly once. The claim carries a token of this request; inside the
	 * database transaction that applies the purchase, the first statement turns this very claim into
	 * "applied" and keeps the row locked, so a request that took a stale claim over can never apply
	 * it as well. A request that dies halfway leaves nothing applied; its claim is taken over after
	 * CLAIM_STALE.
	 *
	 * @return string '' when applied, or why XenForo did not apply it.
	 * @throws RuntimeException XenForo failed; CoinsPay_Busy — another request holds the claim.
	 */
	private function grant($row, $transactionId, $note, $details) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		$invoiceId = $row['invoice_id'];
		$token = CoinsPay_Util::randomHex(16);
		if (!$repo->claim($invoiceId, self::CLAIM_STALE, $token)) {
			throw new CoinsPay_Busy('the purchase ' . $row['request_key'] . ' is being applied by another request');
		}
		// The request that held the claim before may have applied it and died before the mark.
		if ($host->hasPayment($transactionId)) {
			$repo->update($invoiceId, array('apply_state' => 'applied', 'claimed' => null, 'claim_token' => null));
			return '';
		}

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(120); // phpcs:ignore Generic.PHP.NoSilencedErrors -- disabled on some hosts
		}
		try {
			$refused = $host->grant((string)$row['request_key'], $transactionId, $note, $details, function () use ($repo, $invoiceId, $token) {
				return $repo->markApplied($invoiceId, $token);
			});
		} catch (CoinsPay_Busy $e) {
			// The claim went to another request: it is theirs to finish.
			throw $e;
		} catch (Throwable $e) {
			$repo->release($invoiceId, $token);
			throw new RuntimeException('XenForo failed to apply the purchase ' . $row['request_key'] . ': ' . $e->getMessage(), 0, $e);
		}
		if ($refused !== '') {
			$repo->release($invoiceId, $token);
		}
		return $refused;
	}

	/**
	 * An invoice made with a key of the other mode than the profile has now: its payments cannot be
	 * read or applied until that key is back. The admin is told once.
	 *
	 * @param array $row
	 */
	public function otherMode($row) {
		$snapshot = json_decode((string)$row['snapshot'], true);
		$number = CoinsPay_Util::value($snapshot, 'number');
		$note = $this->module->text()->t(!empty($row['test']) ? 'log_other_mode_test' : 'log_other_mode_live', $number !== '' ? $number : $row['invoice_id']);
		$this->tell($row, '', $note, array('coinspay_invoice' => $row['invoice_id']));
	}

	/** An error line and the panel, once per message: a retry that fails the same way stays quiet. */
	private function tell($row, $transactionId, $note, $details) {
		$fresh = $this->module->repo()->find($row['invoice_id']);
		if ($fresh && (string)$fresh['attention'] === $note) {
			return;
		}
		$this->module->host()->log((string)$row['request_key'], $transactionId, 'error', $note, $details);
		$this->module->repo()->update($row['invoice_id'], array('attention' => $note));
	}

	/** The next try in 30 minutes; the fingerprint is not saved, so the next read decides again. */
	private function retryLater($invoiceId) {
		$this->module->repo()->update($invoiceId, array('next_poll' => time() + self::POLL_CLOSED));
	}

	/**
	 * The purchase request is gone. Money on its CoinsPay invoice is not applied anywhere: the
	 * admin is told once per state.
	 */
	private function gone($row, $invoice, $status, $fingerprint) {
		$hasMoney = CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_paid')) || CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_pending'));
		if ($hasMoney && $row['fingerprint'] !== $fingerprint && $row['apply_state'] !== 'applied') {
			$received = CoinsPay_Util::normalize((string)((float)CoinsPay_Util::value($invoice, 'amount_paid') + (float)CoinsPay_Util::value($invoice, 'amount_pending'))) . ' ' . CoinsPay_Util::value($invoice, 'currency');
			$note = $this->module->text()->t('log_purchase_gone', CoinsPay_Util::value($invoice, 'number'), $received);
			$this->module->host()->log((string)$row['request_key'], self::transactionId($invoice, $row['invoice_id']), 'error', $note, self::logDetails($invoice, $row));
			$this->module->repo()->update($row['invoice_id'], array('attention' => $note, 'fingerprint' => $fingerprint));
		}
		$this->settle($row['invoice_id'], $status, $row);
		return 'the purchase request is gone';
	}

	/**
	 * Polling of the invoice after this state: open — every 5 minutes, paid — never, expired or
	 * cancelled — every 30 minutes until the end of polling: a late payment may come.
	 */
	private function settle($invoiceId, $status, $row) {
		if ($status === 'open') {
			$next = time() + self::POLL_OPEN;
		} elseif ($status === 'paid') {
			$next = null;
		} else {
			$next = time() + self::POLL_CLOSED;
		}
		if ($next !== null && $next >= (int)$row['poll_until']) {
			$next = null;
		}
		$this->module->repo()->update($invoiceId, array('next_poll' => $next));
	}

	/** The transaction id of the payment log: the CoinsPay invoice, test- for a test one. */
	public static function transactionId($invoice, $invoiceId) {
		return (!empty($invoice['test']) ? 'test-' : '') . strtolower((string)$invoiceId);
	}

	/** What the add-on keeps of an invoice: for the panel and the next decision. */
	public static function snapshot($invoice) {
		$copy = array();
		foreach (array('id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due', 'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually') as $field) {
			$copy[$field] = isset($invoice[$field]) ? $invoice[$field] : null;
		}
		return $copy;
	}

	/** Details of a line of the payment provider log. */
	public static function logDetails($invoice, $row) {
		return array(
			'coinspay_invoice' => CoinsPay_Util::value($invoice, 'id'),
			'number' => CoinsPay_Util::value($invoice, 'number'),
			'status' => CoinsPay_Util::value($invoice, 'status'),
			'test' => !empty($invoice['test']) ? 'yes' : 'no',
			'amount' => CoinsPay_Util::value($invoice, 'amount') . ' ' . CoinsPay_Util::value($invoice, 'currency'),
			'paid' => CoinsPay_Util::value($invoice, 'amount_paid'),
			'pending' => CoinsPay_Util::value($invoice, 'amount_pending'),
			'overpaid' => CoinsPay_Util::value($invoice, 'amount_overpaid'),
			'cost' => (string)$row['cost_amount'] . ' ' . (string)$row['cost_currency'],
		);
	}
}
