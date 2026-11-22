<?php
/**
 * Webhook of CoinsPay: finds the payment profile of the invoice, checks the signature with its
 * secret, then reads the invoice from the API instead of trusting the event, and applies it. The
 * event is only a reason to look.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Webhook {

	/** @var CoinsPay_Profiles */
	private $profiles;

	public function __construct(CoinsPay_Profiles $profiles) {
		$this->profiles = $profiles;
	}

	/**
	 * @param string   $body   Raw request body.
	 * @param string   $header x-cpay-signature.
	 * @param int|null $now    For tests.
	 * @return array {0: HTTP status, 1: text}. 2xx — CoinsPay stops sending the event.
	 */
	public function handle($body, $header, $now = null) {
		$event = json_decode((string)$body, true);
		if (!is_array($event) || !isset($event['type']) || !is_string($event['type'])) {
			return array(400, 'not an event');
		}

		// "Send test event" of the dashboard names no invoice: the profile whose secret signed it gets it.
		if ($event['type'] === 'webhook.test') {
			foreach ($this->profiles->all() as $module) {
				$secret = $module->settings()->webhookSecret();
				if ($secret !== '' && CoinsPay_Signature::verify($body, $header, $secret, $now)) {
					$module->repo()->kvSet($module->kvKey('webhook_test'), (string)time());
					return array(200, 'test event received');
				}
			}
			return array(401, 'bad signature');
		}

		$data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
		$invoiceId = '';
		if (isset($data['invoice']) && is_array($data['invoice'])) {
			$invoiceId = CoinsPay_Util::value($data['invoice'], 'id');
		} elseif (isset($data['deposit']) && is_array($data['deposit'])) {
			$invoiceId = CoinsPay_Util::value($data['deposit'], 'invoice_id');
		}
		$row = $invoiceId !== '' ? $this->profiles->repo()->find($invoiceId) : null;
		if (!$row) {
			// Another forum or site on the same CoinsPay shop: nothing to do here.
			return array(200, 'not an invoice of this forum');
		}
		$module = $this->profiles->module((int)$row['profile_id']);
		if (!$module) {
			// The profile was deleted with money maybe on its way: CoinsPay retries until it is back.
			return array(503, 'the payment profile of the invoice is gone');
		}
		$settings = $module->settings();
		if ($settings->webhookSecret() === '' || !CoinsPay_Signature::verify($body, $header, $settings->webhookSecret(), $now)) {
			return array(401, 'bad signature');
		}
		// Ours, but made with a key of the other mode: the admin switched keys. 503 brings the event
		// again until the key of its mode is back, so a real payment is not dropped.
		if ((bool)$row['test'] !== $settings->isTestMode()) {
			$module->sync()->otherMode($row);
			return array(503, 'the invoice is of the other mode than the key now');
		}

		try {
			$invoice = $module->client()->invoice($invoiceId);
			return array(200, $module->sync()->apply($invoice));
		} catch (CoinsPay_ApiError $e) {
			$module->log('webhook: ' . $row['invoice_id'] . ': ' . $e->summary(), true, $row['request_key']);
			if ($e->isNotFound()) {
				// Ours, yet unknown to this key: a key of another shop now. CoinsPay sends it again.
				return array(503, 'the invoice is not found for this key');
			}
			return array(503, 'the CoinsPay API is not reachable');
		} catch (CoinsPay_Busy $e) {
			// The cron or the member's return applies it right now; CoinsPay sends it again anyway.
			$module->log('webhook: ' . $e->getMessage(), false, $row['request_key']);
			return array(503, 'the purchase was not applied yet');
		} catch (RuntimeException $e) {
			// XenForo failed while applying: Sync told the admin; CoinsPay sends it again.
			$module->log('webhook: ' . $e->getMessage(), false, $row['request_key']);
			return array(503, 'the purchase was not applied yet');
		}
	}
}
