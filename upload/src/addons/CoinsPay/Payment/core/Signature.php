<?php
/**
 * Webhook signature: x-cpay-signature: t=<unix>,v1=<hex>, HMAC-SHA256 of "<t>.<raw body>" with
 * the whsec_ secret. From the OpenCart plugin (core/Signature.php).
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Signature {

	/** Seconds a signature stays valid either way. */
	const TOLERANCE = 300;

	/**
	 * @param string   $body   Raw request body.
	 * @param string   $header Value of x-cpay-signature.
	 * @param string   $secret whsec_… secret of the shop.
	 * @param int|null $now    Unix time; now by default.
	 * @return bool
	 */
	public static function verify($body, $header, $secret, $now = null) {
		$header = trim((string)$header);
		$secret = (string)$secret;
		if ($header === '' || $secret === '') {
			return false;
		}

		$timestamp = null;
		$signatures = array();
		foreach (explode(',', $header) as $piece) {
			$eq = strpos($piece, '=');
			if ($eq === false || $eq === 0) {
				return false;
			}
			$key = trim(substr($piece, 0, $eq));
			$value = trim(substr($piece, $eq + 1));
			if ($key === 't') {
				$timestamp = $value;
			} elseif ($key === 'v1') {
				$signatures[] = $value;
			}
		}
		// D: "$" must not match before a final line break.
		if ($timestamp === null || !preg_match('/^\d{1,12}$/D', $timestamp) || !$signatures) {
			return false;
		}

		$now = $now === null ? time() : (int)$now;
		if (abs($now - (int)$timestamp) > self::TOLERANCE) {
			return false;
		}
		$expected = hash_hmac('sha256', (int)$timestamp . '.' . $body, $secret);
		foreach ($signatures as $signature) {
			if (preg_match('/^[0-9a-f]{64}$/D', $signature) && CoinsPay_Util::equals($expected, $signature)) {
				return true;
			}
		}
		return false;
	}
}
