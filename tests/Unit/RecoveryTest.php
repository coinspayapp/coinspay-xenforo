<?php
/**
 * What happens when things fail: a stuck invoice in the cron, a key of the other mode, a busy
 * purchase while starting a payment, a list of currencies of another shape.
 */

use PHPUnit\Framework\TestCase;

final class RecoveryTest extends TestCase {

	/** @var FakeHost */
	private $host;

	/** @var FakeClient */
	private $client;

	/** @var MemoryRepo */
	private $repo;

	/** @var FakeProfiles */
	private $profiles;

	/** @var CoinsPay_Module */
	private $module;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->client = new FakeClient();
		$this->repo = new MemoryRepo();
		$this->profiles = new FakeProfiles($this->repo);
		$this->module = $this->profiles->add($this->host, $this->client);
	}

	private function start($requestKey = 'RK1') {
		return (new CoinsPay_Payment($this->module))->start(array(
			'request_key' => $requestKey, 'purchase_request_id' => 5, 'user_id' => 7, 'cost_amount' => '10.00', 'cost_currency' => 'USD',
			'recurring' => false, 'title' => 'VIP', 'description' => 'Forum', 'return_url' => 'https://forum.example/account/upgrades', 'back_url' => 'https://forum.example/coinspay/return?key=' . $requestKey,
		));
	}

	public function test_a_stuck_invoice_does_not_block_the_cron() {
		$this->start('RK1');
		$this->start('RK2');
		list($good, $stuck) = array_keys($this->client->invoices);
		foreach (array($good, $stuck) as $id) {
			$this->client->pay($id);
		}
		// The stuck one is first in the queue and fails every time: the API answers 500 for it.
		$this->repo->update($stuck, array('next_poll' => time() - 200));
		$this->repo->update($good, array('next_poll' => time() - 100));
		$this->client->failRead[$stuck] = new CoinsPay_ApiError(500, 'HTTP_500', 'oops');
		(new CoinsPay_Poller($this->profiles))->run();
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_OPEN, $this->repo->rows[$stuck]['next_poll'], 2);
		$this->assertSame('applied', $this->repo->rows[$good]['apply_state']);
		$this->assertNotNull($this->repo->kvGet(CoinsPay_Poller::LAST_RUN));
	}

	public function test_an_exception_on_one_row_lets_the_others_go_on() {
		$this->start('RK1');
		$this->start('RK2');
		list($good, $broken) = array_keys($this->client->invoices);
		foreach (array($good, $broken) as $id) {
			$this->client->pay($id);
		}
		$this->repo->update($broken, array('next_poll' => time() - 200));
		$this->repo->update($good, array('next_poll' => time() - 100));
		$this->client->failRead[$broken] = new LogicException('database gone away');
		$this->assertSame(1, (new CoinsPay_Poller($this->profiles))->run());
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_CLOSED, $this->repo->rows[$broken]['next_poll'], 2);
		$this->assertSame('applied', $this->repo->rows[$good]['apply_state']);
	}

	public function test_money_on_an_invoice_of_the_other_mode_goes_to_the_panel() {
		$this->start();
		$id = array_keys($this->client->invoices)[0];
		$this->repo->update($id, array('next_poll' => time() - 1, 'test' => 1, 'snapshot' => json_encode(array('number' => 7, 'amount_paid' => '10'))));
		(new CoinsPay_Poller($this->profiles))->run();
		$this->assertStringContainsString('CoinsPay invoice 7 was made with a test key, but the payment profile has a live key now', $this->repo->rows[$id]['attention']);
		$this->assertNotNull($this->repo->rows[$id]['next_poll']);

		// No money seen on it: nothing to say.
		$this->start('RK2');
		$other = array_keys($this->client->invoices)[1];
		$this->repo->update($other, array('next_poll' => time() - 1, 'test' => 1));
		(new CoinsPay_Poller($this->profiles))->run();
		$this->assertNull($this->repo->rows[$other]['attention']);
	}

	public function test_a_busy_purchase_while_starting_is_an_answer_for_the_member() {
		$this->start();
		$id = array_keys($this->client->invoices)[0];
		$this->client->pay($id);
		// Another request is applying it right now.
		$this->repo->update($id, array('apply_state' => 'applying', 'claimed' => time(), 'claim_token' => 'other'));
		$this->assertSame(array('error' => 'Your purchase is being processed. Please try again in a few seconds.'), $this->start());
	}

	public function test_an_empty_list_of_currencies_is_not_kept() {
		$this->client->currencyList = array('fiat' => array(), 'crypto' => array());
		$this->assertStringContainsString('could not be opened', $this->start()['error']);
		$this->assertSame(array(), $this->repo->kv);
		$this->client->currencyList = null;
		$this->assertArrayHasKey('redirect', $this->start('RK2'));
	}
}
