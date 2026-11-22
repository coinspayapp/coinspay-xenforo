<?php
/**
 * Settings of a payment profile, the invoice body and the dictionaries.
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_and_flags() {
		$settings = new CoinsPay_Settings(array());
		$this->assertSame('https://coinspay.app', $settings->apiUrl());
		$this->assertFalse($settings->ready());
		$this->assertSame(60, $settings->lifetime());
		$this->assertTrue($settings->flag('auto_return'));
		$this->assertFalse($settings->flag('stablecoins_only'));

		// Saved by verifyConfig(): 1 and 0, so an unticked default stays off.
		$settings = new CoinsPay_Settings(array('auto_return' => 0, 'stablecoins_only' => 1, 'api_key' => ' cpk_test_x ', 'webhook_secret' => 'whsec_y', 'lifetime' => 5000));
		$this->assertFalse($settings->flag('auto_return'));
		$this->assertTrue($settings->flag('stablecoins_only'));
		$this->assertTrue($settings->isTestMode());
		$this->assertTrue($settings->ready());
		$this->assertSame(1440, $settings->lifetime());
		$this->assertSame(15, CoinsPay_Settings::clampLifetime(3));
	}

	public function test_invoice_body() {
		$settings = new CoinsPay_Settings(array('auto_return' => 0, 'buyer_pays_fee' => 1, 'lifetime' => 30));
		$body = CoinsPay_InvoiceRequest::body(array(
			'reference' => '12', 'title' => " <b>VIP</b>\n  plan ", 'currency' => 'eur', 'amount' => '5',
			'description' => '', 'return_url' => 'https://forum.example/coinspay/return?key=' . str_repeat('k', 600),
		), $settings);
		$this->assertSame(array('currency' => 'EUR', 'amount' => '5', 'reference' => '12', 'lifetime_minutes' => 30, 'title' => 'VIP plan', 'buyer_pays_fee' => true), $body);

		$key = CoinsPay_InvoiceRequest::idempotencyKey('https://forum.example', 'Ab-9_x/?', 1, $body);
		$this->assertSame($key, CoinsPay_InvoiceRequest::idempotencyKey('https://forum.example', 'Ab-9_x/?', 1, $body));
		$this->assertStringContainsString('-Ab-9_x-1-', $key);
		$this->assertNotSame($key, CoinsPay_InvoiceRequest::idempotencyKey('https://staging.example', 'Ab-9_x/?', 1, $body));
	}

	public function test_both_dictionaries_have_the_same_keys() {
		$en = include dirname(__DIR__, 2) . '/upload/src/addons/CoinsPay/Payment/core/lang/en.php';
		$ru = include dirname(__DIR__, 2) . '/upload/src/addons/CoinsPay/Payment/core/lang/ru.php';
		$this->assertSame(array_keys($en), array_keys($ru));
		foreach ($en as $key => $text) {
			$this->assertSame(substr_count($text, '%'), substr_count($ru[$key], '%'), $key);
			// The form puts these texts into HTML as they are.
			if (strpos($key, 'field_') === 0 || strpos($key, 'opt_') === 0) {
				$this->assertDoesNotMatchRegularExpression('/[<>&]/', $text . $ru[$key], $key);
			}
		}
	}
}
