<?php
/**
 * The webhook: the profile of the invoice, its signature and mode, unknown invoices, and the
 * answers CoinsPay acts on.
 */

use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {

	/** @var FakeHost */
	private $host;

	/** @var FakeClient */
	private $client;

	/** @var MemoryRepo */
	private $repo;

	/** @var FakeProfiles */
	private $profiles;

	/** @var string */
	private $id;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->client = new FakeClient();
		$this->repo = new MemoryRepo();
		$this->profiles = new FakeProfiles($this->repo);
		$module = $this->profiles->add($this->host, $this->client);

		$result = (new CoinsPay_Payment($module))->start(array(
			'request_key' => 'RK1', 'purchase_request_id' => 5, 'user_id' => 7, 'cost_amount' => '10.00', 'cost_currency' => 'USD',
			'recurring' => false, 'title' => 'VIP', 'description' => '', 'return_url' => 'https://forum.example/account/upgrades', 'back_url' => 'https://forum.example/coinspay/return?key=RK1',
		));
		$this->assertArrayHasKey('redirect', $result);
		$this->id = array_keys($this->client->invoices)[0];
	}

	private function send(array $event, $secret = 'whsec_test') {
		$body = json_encode($event);
		return (new CoinsPay_Webhook($this->profiles))->handle($body, coinspay_sign($body, $secret));
	}

	private function paidEvent() {
		return array('type' => 'invoice.paid', 'data' => array('invoice' => array('id' => $this->id, 'status' => 'paid')));
	}

	public function test_a_paid_invoice_is_read_back_and_applied() {
		$this->client->pay($this->id);
		$answer = $this->send($this->paidEvent());
		$this->assertSame(200, $answer[0]);
		$this->assertStringStartsWith('apply', $answer[1]);
		$this->assertCount(1, $this->host->payments);

		// The event is only a reason to look: an event that says "paid" for an open invoice applies nothing.
		$this->repo->update($this->id, array('apply_state' => 'none', 'fingerprint' => null));
		$this->host->payments = array();
		$this->client->invoices[$this->id]['status'] = 'open';
		$this->client->invoices[$this->id]['amount_paid'] = '0';
		$this->assertSame(200, $this->send($this->paidEvent())[0]);
		$this->assertSame(array(), $this->host->payments);
	}

	public function test_a_bad_signature_or_body_is_refused() {
		$this->client->pay($this->id);
		$this->assertSame(401, $this->send($this->paidEvent(), 'whsec_other')[0]);
		$this->assertSame(400, (new CoinsPay_Webhook($this->profiles))->handle('not json', '')[0]);
		$body = json_encode($this->paidEvent());
		$this->assertSame(401, (new CoinsPay_Webhook($this->profiles))->handle($body, coinspay_sign($body, 'whsec_test', time() - 600))[0]);
		$this->assertSame(array(), $this->host->payments);
	}

	public function test_an_invoice_of_another_forum_is_taken_and_ignored() {
		$answer = $this->send(array('type' => 'invoice.paid', 'data' => array('invoice' => array('id' => '00000000-0000-4000-8000-000000000099'))), 'whsec_unknown');
		$this->assertSame(array(200, 'not an invoice of this forum'), $answer);
	}

	public function test_a_deposit_event_names_its_invoice() {
		$this->client->pay($this->id);
		$answer = $this->send(array('type' => 'deposit.confirmed', 'data' => array('deposit' => array('id' => 'd1', 'invoice_id' => $this->id))));
		$this->assertSame(200, $answer[0]);
		$this->assertCount(1, $this->host->payments);
	}

	public function test_the_test_event_reaches_the_profile_of_its_secret() {
		$other = new FakeHost();
		$other->profileId = 4;
		$other->settings['webhook_secret'] = 'whsec_second';
		$this->profiles->add($other, $this->client);

		$this->assertSame(200, $this->send(array('type' => 'webhook.test', 'data' => array()), 'whsec_second')[0]);
		$this->assertArrayHasKey('p4_webhook_test', $this->repo->kv);
		$this->assertArrayNotHasKey('p3_webhook_test', $this->repo->kv);
		$this->assertSame(401, $this->send(array('type' => 'webhook.test', 'data' => array()), 'whsec_nobody')[0]);
	}

	public function test_a_gone_profile_or_switched_mode_is_retried() {
		$this->client->pay($this->id);
		$this->repo->update($this->id, array('test' => 1));
		$this->assertSame(503, $this->send($this->paidEvent())[0]);

		$this->repo->update($this->id, array('test' => 0, 'profile_id' => 9));
		$this->assertSame(503, $this->send($this->paidEvent())[0]);
		$this->assertSame(array(), $this->host->payments);
	}

	public function test_an_invoice_unknown_to_the_key_or_an_api_away_is_retried() {
		unset($this->client->invoices[$this->id]);
		$this->assertSame(array(503, 'the invoice is not found for this key'), $this->send($this->paidEvent()));
	}

	public function test_a_busy_purchase_is_retried() {
		$this->client->pay($this->id);
		$this->repo->lock('RK1');
		$this->assertSame(array(503, 'the purchase was not applied yet'), $this->send($this->paidEvent()));
	}
}
