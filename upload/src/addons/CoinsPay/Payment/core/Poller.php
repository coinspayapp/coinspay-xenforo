<?php
/**
 * Polling of CoinsPay invoices when a webhook did not come: from the cron of XenForo, and for one
 * purchase when the member comes back from the payment page.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Poller {

	const BATCH = 50;
	const BUDGET = 20;

	/** Key-value entry with the time of the last cron run, for the panel. */
	const LAST_RUN = 'cron_last';

	/** @var CoinsPay_Profiles */
	private $profiles;

	public function __construct(CoinsPay_Profiles $profiles) {
		$this->profiles = $profiles;
	}

	/**
	 * Checks the invoices that are due, within a limit of count and time: the cron of XenForo runs
	 * other jobs too. A row that fails is put back in the queue, so it never blocks the others.
	 *
	 * @return int How many were checked.
	 */
	public function run() {
		$repo = $this->profiles->repo();
		$repo->kvSet(self::LAST_RUN, (string)time());
		$started = time();
		$failures = 0;
		$checked = 0;
		foreach ($repo->due(self::BATCH) as $row) {
			if (time() - $started >= self::BUDGET || $failures >= 3) {
				break;
			}
			try {
				$ok = $this->check($row);
			} catch (Exception $e) {
				// A database error, say: this row waits, the next one goes on.
				$this->postpone($row, CoinsPay_Sync::POLL_CLOSED);
				$module = $this->profiles->module((int)$row['profile_id']);
				if ($module) {
					$module->log('polling ' . $row['invoice_id'] . ': ' . $e->getMessage(), true, $row['request_key']);
				}
				$ok = false;
			}
			if ($ok) {
				$checked++;
			} else {
				$failures++;
			}
		}
		return $checked;
	}

	/**
	 * The newest CoinsPay invoice of a purchase request: the member is back from the payment page,
	 * maybe before the webhook.
	 *
	 * @param string $requestKey
	 * @return array|null The row after the check.
	 */
	public function checkRequest($requestKey) {
		$repo = $this->profiles->repo();
		$row = $repo->current($requestKey);
		if (!$row) {
			return null;
		}
		// A paid invoice is done once settled (no next poll); one whose payment failed halfway is not.
		$settled = $row['status'] === 'paid' && $row['next_poll'] === null;
		if (!$settled) {
			$this->check($row);
		}
		return $repo->find($row['invoice_id']);
	}

	/** @return bool False when the API failed. */
	private function check($row) {
		$module = $this->profiles->module((int)$row['profile_id']);
		if (!$module) {
			$this->postpone($row, CoinsPay_Sync::POLL_CLOSED);
			return true;
		}
		// Made with a key of the other mode: the admin switched keys, and this one does not know
		// the invoice. Asked again later, when the key of its mode may be back; money already seen
		// on it goes to the panel.
		if ((bool)$row['test'] !== $module->settings()->isTestMode()) {
			$snapshot = json_decode((string)$row['snapshot'], true);
			if ($row['status'] === 'paid' || CoinsPay_Util::positive(CoinsPay_Util::value($snapshot, 'amount_paid')) || CoinsPay_Util::positive(CoinsPay_Util::value($snapshot, 'amount_pending'))) {
				$module->sync()->otherMode($row);
			}
			$this->postpone($row, CoinsPay_Sync::POLL_CLOSED);
			return true;
		}
		try {
			$module->sync()->apply($module->client()->invoice($row['invoice_id']));
			return true;
		} catch (CoinsPay_ApiError $e) {
			$module->log('polling ' . $row['invoice_id'] . ': ' . $e->summary(), true, $row['request_key']);
			if ($e->isNotFound()) {
				// Unknown to this key: a key of another shop now. Not dropped: the old key may come back.
				$this->postpone($row, CoinsPay_Sync::POLL_CLOSED);
				return true;
			}
			$this->postpone($row, CoinsPay_Sync::POLL_OPEN);
			return false;
		} catch (CoinsPay_Busy $e) {
			// The webhook or the member's return applies it right now.
			$module->log('polling ' . $row['invoice_id'] . ': ' . $e->getMessage(), false, $row['request_key']);
			$this->postpone($row, CoinsPay_Sync::POLL_OPEN);
			return true;
		} catch (RuntimeException $e) {
			// XenForo failed: Sync told the admin and set the next try.
			$module->log('polling ' . $row['invoice_id'] . ': ' . $e->getMessage(), false, $row['request_key']);
			return true;
		}
	}

	/** The next poll in $seconds, while the invoice may still be polled. */
	private function postpone($row, $seconds) {
		$next = time() + (int)$seconds;
		$this->profiles->repo()->update($row['invoice_id'], array('next_poll' => CoinsPay_Repo::pollable($row, $next) ? $next : null));
	}
}
