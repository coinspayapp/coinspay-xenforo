<?php
/**
 * Paying a purchase: the CoinsPay invoice made, reused or replaced, and the refusals.
 */

use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase {

	/** @var FakeHost */
	private $host;

	/** @var FakeClient */
	private $client;

	/** @var MemoryRepo */
	private $repo;

	/** @var CoinsPay_Module */
	private $module;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->client = new FakeClient();
		$this->repo = new MemoryRepo();
		$this->host->repo = $this->repo;
		$this->setUpModule();
	}

	/** A module reads the settings once: after a change of them, a new one. */
	private function setUpModule() {
		$this->module = new CoinsPay_Module($this->host);
		$this->module->setRepo($this->repo);
		$this->module->setClient($this->client);
	}

	private function start(array $overrides = array()) {
		return (new CoinsPay_Payment($this->module))->start($overrides + array(
			'request_key' => 'RK1', 'purchase_request_id' => 5, 'user_id' => 7, 'cost_amount' => '10.00', 'cost_currency' => 'USD',
			'recurring' => false, 'title' => 'Account upgrade: VIP &amp; more', 'description' => 'Forum, member Ann',
			'return_url' => 'https://forum.example/account/upgrades', 'back_url' => 'https://forum.example/coinspay/return?key=RK1',
		));
	}

	public function test_a_purchase_gets_a_coinspay_invoice() {
		$this->host->lang = 'ru';
		$result = $this->start();
		$id = '00000000-0000-4000-8000-000000000001';
		$this->assertSame(array('redirect' => 'https://pay.example/pay/' . $id . '?lang=ru'), $result);

		list($body, $key) = $this->client->created[0];
		$this->assertSame(array(
			'currency' => 'USD', 'amount' => '10', 'reference' => '5', 'lifetime_minutes' => 60, 'title' => 'Account upgrade: VIP & more',
			'description' => 'Forum, member Ann', 'return_url' => 'https://forum.example/coinspay/return?key=RK1', 'auto_return' => true,
		), $body);
		$this->assertMatchesRegularExpression('/^xf-[0-9a-f]{8}-RK1-1-[0-9a-f]{12}$/D', $key);

		$row = $this->repo->rows[$id];
		$this->assertSame('RK1', $row['request_key']);
		$this->assertSame(3, $row['profile_id']);
		$this->assertSame('10', $row['cost_amount']);
		$this->assertSame('https://forum.example/account/upgrades', $row['return_url']);
		$this->assertSame(0, $row['test_admin']);
	}

	public function test_the_open_invoice_is_reused() {
		$first = $this->start();
		$this->assertSame($first, $this->start());
		$this->assertCount(1, $this->client->created);
	}

	public function test_a_paid_invoice_sends_the_member_back_to_the_purchase() {
		$this->start();
		$this->client->pay('00000000-0000-4000-8000-000000000001');
		$this->assertSame(array('redirect' => 'https://forum.example/coinspay/return?key=RK1'), $this->start());
		$this->assertCount(1, $this->host->payments);
		$this->assertCount(1, $this->client->created);
	}

	public function test_an_expired_invoice_is_replaced() {
		$this->start();
		$this->client->invoices['00000000-0000-4000-8000-000000000001']['status'] = 'expired';
		$result = $this->start();
		$this->assertSame('https://pay.example/pay/00000000-0000-4000-8000-000000000002?lang=en', $result['redirect']);
		$this->assertSame(2, $this->repo->rows['00000000-0000-4000-8000-000000000002']['attempt']);
		$this->assertStringContainsString('-RK1-2-', $this->client->created[1][1]);
	}

	public function test_amounts_follow_the_decimals_of_the_currency() {
		$this->start(array('cost_amount' => '1500.00', 'cost_currency' => 'jpy'));
		$this->assertSame('JPY', $this->client->created[0][0]['currency']);
		$this->assertSame('1500', $this->client->created[0][0]['amount']);
	}

	public function test_refusals() {
		$this->assertSame(array('error' => 'Payment in cryptocurrency is not available for prices in EUR. Please choose another payment method or contact us.'), $this->start(array('cost_currency' => 'EUR')));
		$this->assertStringContainsString('cannot renew by itself', $this->start(array('recurring' => true))['error']);

		$this->host->settings['webhook_secret'] = '';
		$this->setUpModule();
		$this->assertStringContainsString('not available right now', $this->start()['error']);
		$this->assertSame(array(), $this->client->created);
	}

	public function test_a_test_key_is_for_admins_only() {
		$this->host->settings['api_key'] = 'cpk_test_key';
		$this->client->test = true;
		$this->assertStringContainsString('not available right now', $this->start()['error']);

		$this->host->admin = true;
		$this->assertArrayHasKey('redirect', $this->start());
		$row = $this->repo->rows['00000000-0000-4000-8000-000000000001'];
		$this->assertSame(1, $row['test']);
		$this->assertSame(1, $row['test_admin']);
	}

	public function test_a_busy_purchase() {
		$this->repo->lock('RK1');
		$this->assertStringContainsString('being processed', $this->start()['error']);
	}
}
