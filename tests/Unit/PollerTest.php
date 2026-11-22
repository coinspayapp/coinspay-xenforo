<?php
/**
 * Polling: the cron applies what the webhook did not bring, the return checks one purchase.
 */

use PHPUnit\Framework\TestCase;

final class PollerTest extends TestCase {

	/** @var FakeHost */
	private $host;

	/** @var FakeClient */
	private $client;

	/** @var MemoryRepo */
	private $repo;

	/** @var FakeProfiles */
	private $profiles;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->client = new FakeClient();
		$this->repo = new MemoryRepo();
		$this->profiles = new FakeProfiles($this->repo);
		$module = $this->profiles->add($this->host, $this->client);
		(new CoinsPay_Payment($module))->start(array(
			'request_key' => 'RK1', 'purchase_request_id' => 5, 'user_id' => 7, 'cost_amount' => '10.00', 'cost_currency' => 'USD',
			'recurring' => false, 'title' => 'VIP', 'description' => '', 'return_url' => 'https://forum.example/account/upgrades', 'back_url' => 'https://forum.example/coinspay/return?key=RK1',
		));
	}

	const ID = '00000000-0000-4000-8000-000000000001';

	public function test_the_cron_applies_a_payment_whose_webhook_was_lost() {
		$this->client->pay(self::ID);
		$poller = new CoinsPay_Poller($this->profiles);
		$this->assertSame(0, $poller->run());

		$this->repo->update(self::ID, array('next_poll' => time() - 1));
		$this->assertSame(1, $poller->run());
		$this->assertCount(1, $this->host->payments);
		$this->assertNull($this->repo->rows[self::ID]['next_poll']);
	}

	public function test_an_invoice_of_the_other_mode_or_a_gone_profile_is_postponed() {
		$this->repo->update(self::ID, array('next_poll' => time() - 1, 'test' => 1));
		$reads = $this->client->reads;
		(new CoinsPay_Poller($this->profiles))->run();
		$this->assertSame($reads, $this->client->reads);
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_CLOSED, $this->repo->rows[self::ID]['next_poll'], 2);

		$this->repo->update(self::ID, array('next_poll' => time() - 1, 'test' => 0, 'profile_id' => 9));
		(new CoinsPay_Poller($this->profiles))->run();
		$this->assertSame($reads, $this->client->reads);
	}

	public function test_the_return_checks_its_purchase_at_once() {
		$this->client->pay(self::ID);
		$row = (new CoinsPay_Poller($this->profiles))->checkRequest('RK1');
		$this->assertSame('https://forum.example/account/upgrades', $row['return_url']);
		$this->assertSame('paid', $row['status']);
		$this->assertCount(1, $this->host->payments);

		// Settled: the next return asks the API nothing.
		$reads = $this->client->reads;
		(new CoinsPay_Poller($this->profiles))->checkRequest('RK1');
		$this->assertSame($reads, $this->client->reads);
		$this->assertNull((new CoinsPay_Poller($this->profiles))->checkRequest('RK-unknown'));
	}
}
