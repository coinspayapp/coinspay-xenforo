<?php
/**
 * Client of the CoinsPay merchant API. From the OpenCart plugin (core/ApiClient.php), by way of
 * the WHMCS module. Requests go through a transport: in XenForo the HTTP client of XenForo
 * (CoinsPay\Payment\XfTransport), as its resource standards ask.
 */
defined('COINSPAY_XF') or exit;

/**
 * Sends one HTTP request. No redirects: a redirect would resend the key to another address.
 */
interface CoinsPay_Transport {

	/**
	 * @param string      $method
	 * @param string      $url
	 * @param array       $headers        Name → value.
	 * @param string|null $body
	 * @param int         $timeout        Seconds for the whole request.
	 * @param int         $connectTimeout Seconds to connect.
	 * @return array {0: HTTP status, 1: body}.
	 * @throws CoinsPay_ApiError NETWORK when no answer came.
	 */
	public function send($method, $url, array $headers, $body, $timeout, $connectTimeout);
}

class CoinsPay_ApiClient {

	const TIMEOUT = 15;
	const CONNECT_TIMEOUT = 10;
	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

	/** @var string API address without the trailing slash, e.g. https://coinspay.app/api/v1 */
	private $base;

	/** @var string cpk_… or cpk_test_… */
	private $key;

	/** @var string */
	private $userAgent;

	/** @var int */
	private $timeout;

	/** @var bool An address was given, but not one the key may go to. */
	private $badBase;

	/** @var CoinsPay_Transport */
	private $transport;

	/**
	 * @param string             $base      The API address as the admin typed it: see base().
	 * @param CoinsPay_Transport $transport
	 */
	public function __construct($base, $key, $userAgent, CoinsPay_Transport $transport, $timeout = self::TIMEOUT) {
		$this->base = self::base($base);
		$this->badBase = $this->base === '' && trim((string)$base) !== '';
		$this->key = trim((string)$key);
		$this->userAgent = (string)$userAgent;
		$this->transport = $transport;
		$this->timeout = max(1, (int)$timeout);
	}

	/**
	 * The API base from the address the admin typed: https:// when there is no scheme, /api/v1
	 * when only the dashboard address is given. Plain http only to this machine or a container on
	 * it: the key goes in every request.
	 *
	 * @param string $url
	 * @return string The base without the trailing slash, or '' when the address is not usable.
	 */
	public static function base($url) {
		$url = rtrim(trim((string)$url), '/');
		if ($url === '') {
			return '';
		}
		if (strpos($url, '://') === false) {
			$url = 'https://' . $url;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return '';
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'https' && !($scheme === 'http' && self::isLocal($parts['host']))) {
			return '';
		}
		if (!preg_match('#/api/v1$#iD', $url)) {
			$url .= '/api/v1';
		}
		return $url;
	}

	private static function isLocal($host) {
		$host = strtolower(trim((string)$host, '[]'));
		return $host === 'localhost' || $host === '::1' || $host === 'host.docker.internal'
			|| substr($host, -10) === '.localhost' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $host);
	}

	/**
	 * GET /shop: the shop of the key and the key itself.
	 *
	 * @return array {shop: {id, name, created_at}, key: {test, access}}.
	 */
	public function shop() {
		$data = $this->request('GET', '/shop');
		$this->field($data, 'shop');
		return $data;
	}

	/** GET /currencies: fiat and crypto currencies with their scales. */
	public function currencies() {
		return $this->request('GET', '/currencies');
	}

	/**
	 * POST /invoices.
	 *
	 * @param array  $body
	 * @param string $idempotencyKey The same key and body give the same invoice.
	 * @return array Invoice.
	 */
	public function createInvoice($body, $idempotencyKey) {
		return $this->field($this->request('POST', '/invoices', $body, array('Idempotency-Key' => (string)$idempotencyKey)), 'invoice');
	}

	/** GET /invoices/{id}. */
	public function invoice($id) {
		return $this->field($this->request('GET', '/invoices/' . self::id($id)), 'invoice');
	}

	/** POST /invoices/{id}/cancel: an open invoice can no longer be paid. */
	public function cancelInvoice($id) {
		// An empty JSON object: the API refuses a JSON request without a body.
		return $this->field($this->request('POST', '/invoices/' . self::id($id) . '/cancel', new stdClass()), 'invoice');
	}

	/**
	 * A CoinsPay invoice id in lower case.
	 *
	 * @param string $id
	 * @return string
	 * @throws CoinsPay_ApiError 404 for anything that is not a UUID.
	 */
	public static function id($id) {
		if (!preg_match(self::UUID, (string)$id)) {
			throw new CoinsPay_ApiError(404, 'NOT_FOUND', 'Not an invoice id');
		}
		return strtolower($id);
	}

	private function request($method, $path, $body = null, $headers = array()) {
		if ($this->badBase) {
			throw new CoinsPay_ApiError(0, 'BAD_API_URL', 'The CoinsPay API address must start with https://');
		}
		if ($this->base === '' || $this->key === '') {
			throw new CoinsPay_ApiError(0, 'NOT_CONFIGURED', 'The CoinsPay API address or key is not set');
		}
		$headers['Authorization'] = 'Bearer ' . $this->key;
		$headers['Accept'] = 'application/json';
		$headers['User-Agent'] = $this->userAgent;
		$payload = null;
		if ($body !== null) {
			$headers['Content-Type'] = 'application/json';
			$payload = CoinsPay_Util::json($body);
		}
		list($status, $raw) = $this->transport->send($method, $this->base . $path, $headers, $payload, $this->timeout, min(self::CONNECT_TIMEOUT, $this->timeout));
		$status = (int)$status;

		$data = json_decode((string)$raw, true);
		if ($status >= 200 && $status < 300 && is_array($data)) {
			return $data;
		}
		$fault = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : array();
		throw new CoinsPay_ApiError(
			$status,
			isset($fault['code']) ? (string)$fault['code'] : 'HTTP_' . $status,
			isset($fault['message']) ? (string)$fault['message'] : '',
			isset($fault['details']) && is_array($fault['details']) ? $fault['details'] : array()
		);
	}

	private function field($data, $key) {
		if (!isset($data[$key]) || !is_array($data[$key])) {
			throw new CoinsPay_ApiError(0, 'BAD_RESPONSE', 'The answer has no "' . $key . '"');
		}
		return $data[$key];
	}
}
