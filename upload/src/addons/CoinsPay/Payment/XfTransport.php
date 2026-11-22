<?php

namespace CoinsPay\Payment;

/**
 * Requests to CoinsPay through the HTTP client of XenForo, so the proxy and certificate settings of
 * the forum apply. Guzzle 6 in XenForo 2.2 and Guzzle 7 in 2.3: only the options both take —
 * headers, body, timeout, connect_timeout, allow_redirects, http_errors.
 */
class XfTransport implements \CoinsPay_Transport {

	public function send($method, $url, array $headers, $body, $timeout, $connectTimeout) {
		$options = array(
			'headers' => $headers,
			'timeout' => (int)$timeout,
			'connect_timeout' => (int)$connectTimeout,
			// A redirect would resend the key to another address.
			'allow_redirects' => false,
			// An error answer of the API is read like any other: its code and message matter.
			'http_errors' => false,
		);
		if ($body !== null) {
			$options['body'] = (string)$body;
		}
		try {
			$response = \XF::app()->http()->client()->request((string)$method, (string)$url, $options);
		} catch (\Exception $e) {
			throw new \CoinsPay_ApiError(0, 'NETWORK', $e->getMessage());
		}
		return array((int)$response->getStatusCode(), (string)$response->getBody());
	}
}
