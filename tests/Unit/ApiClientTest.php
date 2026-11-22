<?php
/**
 * The API client: the address it may send the key to, the request it makes, the errors it reads.
 */

use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase {

	public function test_the_address_of_the_api() {
		$this->assertSame('https://coinspay.app/api/v1', CoinsPay_ApiClient::base('coinspay.app'));
		$this->assertSame('https://pay.example/api/v1', CoinsPay_ApiClient::base('https://pay.example/api/v1/'));
		$this->assertSame('http://host.docker.internal:8080/api/v1', CoinsPay_ApiClient::base('http://host.docker.internal:8080'));
		// The key goes in every request: plain http only to this machine.
		$this->assertSame('', CoinsPay_ApiClient::base('http://pay.example'));
		$this->assertSame('', CoinsPay_ApiClient::base('https://user:pass@pay.example'));
	}

	public function test_a_request() {
		$transport = new FakeTransport();
		$transport->answers[] = array(201, json_encode(array('invoice' => array('id' => 'x', 'status' => 'open'))));
		$client = new CoinsPay_ApiClient('https://pay.example', 'cpk_test_k', 'Agent/1', $transport, 9);
		$invoice = $client->createInvoice(array('currency' => 'USD', 'amount' => '5'), 'xf-key-1');
		$this->assertSame('open', $invoice['status']);
		$request = $transport->requests[0];
		$this->assertSame('POST', $request['method']);
		$this->assertSame('https://pay.example/api/v1/invoices', $request['url']);
		$this->assertSame(array('Idempotency-Key' => 'xf-key-1', 'Authorization' => 'Bearer cpk_test_k', 'Accept' => 'application/json', 'User-Agent' => 'Agent/1', 'Content-Type' => 'application/json'), $request['headers']);
		$this->assertSame('{"currency":"USD","amount":"5"}', $request['body']);
		$this->assertSame(9, $request['timeout']);
		$this->assertSame(9, $request['connectTimeout']);
	}

	public function test_errors() {
		$transport = new FakeTransport();
		$transport->answers[] = array(404, json_encode(array('error' => array('code' => 'NOT_FOUND', 'message' => 'no such invoice'))));
		$transport->answers[] = array(502, '<html>bad gateway</html>');
		$transport->answers[] = new CoinsPay_ApiError(0, 'NETWORK', 'timed out');
		$transport->answers[] = array(301, '');
		$client = new CoinsPay_ApiClient('https://pay.example', 'cpk_k', 'Agent/1', $transport);
		$id = '00000000-0000-4000-8000-000000000001';
		foreach (array(array(404, 'NOT_FOUND'), array(502, 'HTTP_502'), array(0, 'NETWORK'), array(301, 'HTTP_301')) as $expected) {
			try {
				$client->invoice($id);
				$this->fail('no error');
			} catch (CoinsPay_ApiError $e) {
				$this->assertSame($expected, array($e->status(), $e->errorCode()));
			}
		}
		$this->assertNull($transport->requests[0]['body']);
	}

	public function test_nothing_is_sent_without_a_usable_address_or_key() {
		$transport = new FakeTransport();
		foreach (array(array('http://pay.example', 'cpk_k', 'BAD_API_URL'), array('https://pay.example', '', 'NOT_CONFIGURED')) as $case) {
			try {
				(new CoinsPay_ApiClient($case[0], $case[1], 'Agent/1', $transport))->shop();
				$this->fail('no error');
			} catch (CoinsPay_ApiError $e) {
				$this->assertSame($case[2], $e->errorCode());
			}
		}
		$this->assertSame(array(), $transport->requests);
		// Not a UUID: no request at all.
		$this->expectException(CoinsPay_ApiError::class);
		(new CoinsPay_ApiClient('https://pay.example', 'cpk_k', 'Agent/1', $transport))->invoice('../shop');
	}
}
