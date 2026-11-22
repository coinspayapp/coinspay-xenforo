<?php
/**
 * Webhook signature. The vector is computed by the CoinsPay server code (signPayload), the same
 * as in the tests of the WooCommerce and OpenCart plugins.
 */

use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	const BODY = '{"id":"0b0e1c1e-6f0c-4c56-9a8e-2d6c1e1f0a01","type":"invoice.paid","created_at":"2026-10-28T08:00:00.000Z","shop_id":"5a1f6b9e-1c2d-4e3f-8a9b-0c1d2e3f4a5b","test":true,"data":{"invoice":{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","status":"paid"}}}';
	const SECRET = 'whsec_plugin_test_secret';
	const TIME = 1761638400;
	const V1 = '34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9';

	public function test_accepts_the_signature_of_coinspay() {
		$header = 't=' . self::TIME . ',v1=' . self::V1;
		$this->assertTrue(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME));
		$this->assertTrue(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME + 300));
		$this->assertTrue(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME - 300));
		$this->assertTrue(CoinsPay_Signature::verify(self::BODY, ' t=' . self::TIME . ' , v1=' . self::V1 . ' ', self::SECRET, self::TIME));
		$this->assertTrue(CoinsPay_Signature::verify(self::BODY, 't=' . self::TIME . ',v1=' . str_repeat('0', 64) . ',v1=' . self::V1, self::SECRET, self::TIME));
	}

	public function test_rejects_stale_foreign_and_changed() {
		$header = 't=' . self::TIME . ',v1=' . self::V1;
		$this->assertFalse(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME + 301));
		$this->assertFalse(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME - 301));
		$this->assertFalse(CoinsPay_Signature::verify(self::BODY, $header, 'whsec_other', self::TIME));
		$this->assertFalse(CoinsPay_Signature::verify(str_replace('"paid"', '"open"', self::BODY), $header, self::SECRET, self::TIME));
		$this->assertFalse(CoinsPay_Signature::verify(json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT), $header, self::SECRET, self::TIME));
	}

	public function test_rejects_malformed_headers() {
		foreach (array('', 'v1=' . self::V1, 't=abc,v1=' . self::V1, 't=' . self::TIME, 't=' . self::TIME . ',v1=XYZ', '=1,t=' . self::TIME . ',v1=' . self::V1, 'garbage', 't=' . self::TIME . ',v1=' . strtoupper(self::V1)) as $header) {
			$this->assertFalse(CoinsPay_Signature::verify(self::BODY, $header, self::SECRET, self::TIME), $header);
		}
		$this->assertFalse(CoinsPay_Signature::verify(self::BODY, 't=' . self::TIME . ',v1=' . self::V1, '', self::TIME));
	}
}
