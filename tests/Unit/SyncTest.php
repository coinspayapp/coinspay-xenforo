<?php
/**
 * A payment applies the XenForo purchase exactly once, whatever XenForo does meanwhile.
 */

use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase {

	const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	/** @var FakeHost */
	private $host;

	/** @var MemoryRepo */
	private $repo;

	/** @var CoinsPay_Module */
	private $module;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->repo = new MemoryRepo();
		$this->host->repo = $this->repo;
		$this->module = new CoinsPay_Module($this->host);
		$this->module->setRepo($this->repo);
		$this->repo->add($this->row());
	}

	private function row(array $overrides = array()) {
		return $overrides + array(
			'invoice_id' => self::ID, 'request_key' => 'RK1', 'profile_id' => 3, 'user_id' => 7, 'attempt' => 1,
			'sent_amount' => '10', 'sent_currency' => 'USD', 'cost_amount' => '10', 'cost_currency' => 'USD',
			'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
		);
	}

	private function invoice(array $overrides = array()) {
		return $overrides + array(
			'id' => self::ID, 'number' => 12, 'status' => 'paid', 'test' => false, 'currency' => 'USD', 'amount' => '10',
			'amount_paid' => '10', 'amount_pending' => '0', 'amount_due' => '0', 'amount_overpaid' => null,
			'paid_late' => false, 'paid_manually' => false,
			'payments' => array(array('deposit_id' => 'd1', 'status' => 'confirmed', 'amount' => '10', 'asset' => 'USDT', 'chain' => 'tron', 'tx_hash' => 'abcdef0123456789abcdef')),
		);
	}

	public function test_a_paid_invoice_applies_the_purchase_once() {
		$result = $this->module->sync()->apply($this->invoice());
		$this->assertStringStartsWith('apply', $result);
		$this->assertSame(array(self::ID), array_keys($this->host->payments));
		$this->assertStringContainsString('No. 12 is paid: 10 USD (10 USDT (tron, abcdef01…abcdef))', $this->host->payments[self::ID]);
		$this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['next_poll']);
		$this->assertNull($this->repo->rows[self::ID]['attention']);

		$this->assertSame('no change', $this->module->sync()->apply($this->invoice()));
		// Another state of the same invoice (a manual mark, say) applies nothing more.
		$this->assertSame('none', $this->module->sync()->apply($this->invoice(array('paid_manually' => true))));
		$this->assertCount(1, $this->host->payments);
		$this->assertSame(array(), $this->host->log);
	}

	public function test_a_failure_of_xenforo_is_retried() {
		$this->host->fail = 'throw';
		try {
			$this->module->sync()->apply($this->invoice());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('the upgrade could not be saved', $e->getMessage());
		}
		$this->assertSame(array(), $this->host->payments);
		$this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['fingerprint']);
		// The admin sees it, and it is tried again in 30 minutes, past the end of usual polling.
		$this->assertStringContainsString('XenForo failed while applying the purchase', $this->repo->rows[self::ID]['attention']);
		$this->assertCount(1, $this->host->lines('error'));
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_CLOSED, $this->repo->rows[self::ID]['next_poll'], 2);

		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->invoice()));
		$this->assertCount(1, $this->host->payments);
		$this->assertNull($this->repo->rows[self::ID]['attention']);
	}

	public function test_a_purchase_xenforo_refuses_is_tried_again_and_told_once() {
		$this->host->refuseAlways = true;
		$this->assertSame('hold: The user upgrade is gone.', $this->module->sync()->apply($this->invoice()));
		$this->assertSame('hold: The user upgrade is gone.', $this->module->sync()->apply($this->invoice()));
		$this->assertSame(array(), $this->host->payments);
		$errors = $this->host->lines('error');
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('XenForo did not apply the purchase: The user upgrade is gone. It is tried again every 30 minutes', $errors[0]);
		$this->assertSame($errors[0], $this->repo->rows[self::ID]['attention']);
		$this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['fingerprint']);

		// The cause is gone: applied by itself, and the panel line goes.
		$this->host->refuseAlways = false;
		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->invoice()));
		$this->assertCount(1, $this->host->payments);
		$this->assertNull($this->repo->rows[self::ID]['attention']);
	}

	public function test_a_paid_invoice_xenforo_did_not_apply_is_polled_past_the_usual_end() {
		$this->host->refuseAlways = true;
		$this->repo->update(self::ID, array('poll_until' => time() - 10));
		$this->module->sync()->apply($this->invoice());
		$this->repo->update(self::ID, array('next_poll' => time() - 1));
		$this->assertCount(1, $this->repo->due(10));
		// After 30 days it stops.
		$this->repo->update(self::ID, array('created' => time() - CoinsPay_Sync::RETRY_PAID - 1));
		$this->assertCount(0, $this->repo->due(10));
	}

	public function test_a_claim_taken_over_meanwhile_applies_nothing() {
		$this->repo->stealClaim = true;
		try {
			$this->module->sync()->apply($this->invoice());
			$this->fail('no error');
		} catch (CoinsPay_Busy $e) {
			$this->assertStringContainsString('taken over', $e->getMessage());
		}
		$this->assertSame(array(), $this->host->payments);
		// The claim is the other request's now: not given up by this one.
		$this->assertSame('applying', $this->repo->rows[self::ID]['apply_state']);
		$this->assertSame('someone-else', $this->repo->rows[self::ID]['claim_token']);
	}

	public function test_money_that_comes_after_the_purchase_was_applied() {
		$this->module->sync()->apply($this->invoice());
		$this->assertNull($this->repo->rows[self::ID]['attention']);
		$this->assertStringStartsWith('hold', $this->module->sync()->apply($this->invoice(array('amount_paid' => '13', 'amount_overpaid' => '3'))));
		$this->assertStringContainsString('got more money after its purchase was applied: 3 USD overpaid in all', $this->repo->rows[self::ID]['attention']);
		$this->assertCount(1, $this->host->payments);
		// The same excess read again says nothing new.
		$this->host->log = array();
		$this->module->sync()->apply($this->invoice(array('amount_paid' => '13', 'amount_overpaid' => '3', 'paid_manually' => true)));
		$this->assertSame(array(), $this->host->log);
	}

	public function test_an_applied_purchase_clears_what_waited_for_a_decision() {
		$this->repo->update(self::ID, array('attention' => 'CoinsPay invoice No. 12 expired partially paid: 4 USD of 10 USD.'));
		$this->module->sync()->apply($this->invoice(array('paid_late' => true)));
		$this->assertCount(1, $this->host->payments);
		$this->assertNull($this->repo->rows[self::ID]['attention']);
	}

	public function test_an_invoice_of_the_other_mode_is_told_once() {
		$sync = $this->module->sync();
		$sync->otherMode($this->repo->rows[self::ID]);
		$sync->otherMode($this->repo->rows[self::ID]);
		$this->assertCount(1, $this->host->lines('error'));
		$this->assertStringContainsString('was made with a live key, but the payment profile has a test key now', $this->repo->rows[self::ID]['attention']);
	}

	public function test_a_test_payment_applies_only_a_purchase_of_an_admin() {
		$this->module->sync()->apply($this->invoice(array('test' => true)));
		$this->assertSame(array(), $this->host->payments);
		$this->assertStringContainsString('not started by an admin', $this->host->lines('error')[0]);

		$this->repo->add($this->row(array('invoice_id' => '00000000-0000-4000-8000-000000000002', 'request_key' => 'RK1', 'attempt' => 2, 'test' => 1, 'test_admin' => 1)));
		$this->module->sync()->apply($this->invoice(array('id' => '00000000-0000-4000-8000-000000000002', 'test' => true)));
		$this->assertSame(array('test-00000000-0000-4000-8000-000000000002'), array_keys($this->host->payments));
	}

	public function test_a_payment_claimed_by_another_request_is_not_applied_twice() {
		$this->repo->update(self::ID, array('apply_state' => 'applying', 'claimed' => time()));
		try {
			$this->module->sync()->apply($this->invoice());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('being applied by another request', $e->getMessage());
		}
		$this->assertSame(array(), $this->host->payments);

		// The other request died: its claim is taken over once stale.
		$this->repo->update(self::ID, array('claimed' => time() - CoinsPay_Sync::CLAIM_STALE - 1));
		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->invoice()));
		$this->assertCount(1, $this->host->payments);
	}

	public function test_a_payment_already_in_the_log_is_not_applied_again() {
		$this->host->payments[self::ID] = 'Payment received.';
		$this->assertSame('none', $this->module->sync()->apply($this->invoice()));
		$this->assertCount(1, $this->host->payments);
		$this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
	}

	public function test_a_changed_price_is_left_to_the_admin() {
		$this->host->purchase['cost_amount'] = '12.00';
		$this->module->sync()->apply($this->invoice());
		$this->assertSame(array(), $this->host->payments);
		$this->assertStringContainsString('price of the purchase changed', $this->repo->rows[self::ID]['attention']);
	}

	public function test_money_for_a_gone_purchase_is_reported_once() {
		$this->host->purchase = null;
		$this->assertSame('the purchase request is gone', $this->module->sync()->apply($this->invoice()));
		$this->assertSame('the purchase request is gone', $this->module->sync()->apply($this->invoice()));
		$this->assertCount(1, $this->host->lines('error'));
		$this->assertStringContainsString('holds 10 USD, but its purchase request is gone', $this->host->lines('error')[0]);
		$this->assertSame(array(), $this->host->payments);
	}

	public function test_an_overpayment_applies_and_tells_the_admin() {
		$this->module->sync()->apply($this->invoice(array('amount_paid' => '12', 'amount_overpaid' => '2')));
		$this->assertCount(1, $this->host->payments);
		$this->assertStringContainsString('Overpaid by 2 USD', $this->repo->rows[self::ID]['attention']);
	}

	public function test_partial_and_closed_invoices() {
		$open = array('status' => 'open', 'amount_paid' => '4', 'amount_due' => '6', 'payments' => array());
		$this->assertStringStartsWith('log', $this->module->sync()->apply($this->invoice($open)));
		$this->assertStringContainsString('partially paid: 4 USD received, 6 USD left', $this->host->lines('info')[0]);
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_OPEN, $this->repo->rows[self::ID]['next_poll'], 2);

		$this->assertStringStartsWith('hold', $this->module->sync()->apply($this->invoice(array('status' => 'expired') + $open)));
		$this->assertStringContainsString('expired partially paid: 4 USD of 10 USD', $this->repo->rows[self::ID]['attention']);
		$this->assertSame(array(), $this->host->payments);
		$this->assertEqualsWithDelta(time() + CoinsPay_Sync::POLL_CLOSED, $this->repo->rows[self::ID]['next_poll'], 2);
	}

	public function test_an_unpaid_invoice_that_ran_out() {
		$this->assertStringStartsWith('log', $this->module->sync()->apply($this->invoice(array('status' => 'expired', 'amount_paid' => '0', 'payments' => array()))));
		$this->assertStringContainsString('expired unpaid', $this->host->lines('info')[0]);

		// An older invoice of a purchase the member pays with a newer one: nothing to say.
		$this->repo->add($this->row(array('invoice_id' => '00000000-0000-4000-8000-000000000002', 'attempt' => 2)));
		$this->host->log = array();
		$this->assertSame('none', $this->module->sync()->apply($this->invoice(array('status' => 'cancelled', 'amount_paid' => '0', 'payments' => array()))));
		$this->assertSame(array(), $this->host->log);
	}

	public function test_an_unknown_invoice_is_not_touched() {
		$this->assertSame('not an invoice of this forum', $this->module->sync()->apply($this->invoice(array('id' => '00000000-0000-4000-8000-000000000009'))));
		$this->assertSame(array(), $this->host->log);
	}

	public function test_a_busy_purchase_is_retried_later() {
		$this->repo->lock('RK1');
		$this->expectException(RuntimeException::class);
		$this->module->sync()->apply($this->invoice());
	}
}
