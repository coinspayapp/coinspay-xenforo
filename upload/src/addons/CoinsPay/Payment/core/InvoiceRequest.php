<?php
/**
 * Body of POST /invoices for a XenForo purchase, and its idempotency key. No XenForo calls, so it
 * runs in unit tests. After the WHMCS module.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_InvoiceRequest {

	const MAX_TITLE = 200;
	const MAX_DESCRIPTION = 1000;
	const MAX_REFERENCE = 190;
	const MAX_RETURN_URL = 500;

	/**
	 * @param array             $purchase {reference, title, currency, amount, description, return_url}.
	 * @param CoinsPay_Settings $settings
	 * @return array
	 */
	public static function body($purchase, CoinsPay_Settings $settings) {
		$body = array(
			'currency' => strtoupper((string)$purchase['currency']),
			'amount' => (string)$purchase['amount'],
			'reference' => CoinsPay_Util::truncate((string)$purchase['reference'], self::MAX_REFERENCE),
			'lifetime_minutes' => $settings->lifetime(),
		);
		// Without a title the payment page names the invoice "Order <reference>" in the member's language.
		$title = self::plain($purchase['title']);
		if ($title !== '') {
			$body['title'] = CoinsPay_Util::truncate($title, self::MAX_TITLE);
		}
		$description = self::plain($purchase['description']);
		if ($description !== '') {
			$body['description'] = CoinsPay_Util::truncate($description, self::MAX_DESCRIPTION);
		}

		// A link longer than the API takes would fail the whole invoice; without it the member still pays.
		$returnUrl = (string)$purchase['return_url'];
		if ($returnUrl !== '' && strlen($returnUrl) <= self::MAX_RETURN_URL) {
			$body['return_url'] = $returnUrl;
			if ($settings->flag('auto_return')) {
				$body['auto_return'] = true;
			}
		}

		foreach (array('stablecoins_only', 'buyer_pays_fee', 'buyer_pays_network') as $flag) {
			if ($settings->flag($flag)) {
				$body[$flag] = true;
			}
		}
		return $body;
	}

	/**
	 * Idempotency key: the same purchase request, attempt and body give the same key, so a repeated
	 * "Purchase" gets the CoinsPay invoice already made. The body is part of the key, so a changed
	 * amount never meets the "same key, other body" error; the site part keeps a staging copy on the
	 * same CoinsPay shop from getting invoices of the live forum.
	 *
	 * @param string $site
	 * @param string $requestKey
	 * @param int    $attempt    CoinsPay invoice number within the purchase request, from 1.
	 * @param array  $body
	 * @return string
	 */
	public static function idempotencyKey($site, $requestKey, $attempt, $body) {
		return sprintf('xf-%s-%s-%d-%s', substr(md5((string)$site), 0, 8), substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$requestKey), 0, 32), (int)$attempt, substr(sha1(CoinsPay_Util::json($body)), 0, 12));
	}

	/** Text without markup: titles of XenForo may hold HTML entities. */
	private static function plain($text) {
		return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8')));
	}
}
