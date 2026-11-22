<?php
/**
 * What a state of a CoinsPay invoice means for a XenForo purchase.
 */

use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase {

	private function decide(array $invoice, array $state = array()) {
		$invoice += array(
			'id' => 'a', 'number' => 7, 'status' => 'paid', 'test' => false, 'currency' => 'USD', 'amount' => '10',
			'amount_paid' => '10', 'amount_pending' => '0', 'amount_due' => '0', 'amount_overpaid' => null, 'payments' => array(),
		);
		$state += array('applied' => false, 'test_admin' => false, 'cost_changed' => false, 'is_current' => true);
		return CoinsPay_Decision::decide($invoice, $state, new CoinsPay_Text('en'));
	}

	public function test_paid() {
		$this->assertSame(CoinsPay_Decision::APPLY, $this->decide(array())['action']);
		$this->assertTrue($this->decide(array())['final']);
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide(array(), array('applied' => true))['action']);
		$note = $this->decide(array('paid_manually' => true, 'paid_late' => true, 'amount_overpaid' => '1.5'))['note'];
		$this->assertStringContainsString('marked as paid manually', $note);
		$this->assertStringContainsString('after the CoinsPay invoice expired', $note);
		$this->assertStringContainsString('Overpaid by 1.5 USD', $note);
	}

	public function test_paid_but_held() {
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide(array('test' => true))['action']);
		$this->assertSame(CoinsPay_Decision::APPLY, $this->decide(array('test' => true), array('test_admin' => true))['action']);
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide(array(), array('cost_changed' => true))['action']);
	}

	public function test_open() {
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide(array('status' => 'open', 'amount_paid' => '0'))['action']);
		$partial = $this->decide(array('status' => 'open', 'amount_paid' => '4', 'amount_due' => '6'));
		$this->assertSame(CoinsPay_Decision::LOG, $partial['action']);
		$this->assertFalse($partial['final']);
		$this->assertSame(CoinsPay_Decision::LOG, $this->decide(array('status' => 'open', 'amount_paid' => '0', 'amount_pending' => '10'))['action']);
	}

	public function test_closed() {
		$this->assertSame(CoinsPay_Decision::LOG, $this->decide(array('status' => 'expired', 'amount_paid' => '0'))['action']);
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide(array('status' => 'expired', 'amount_paid' => '0'), array('is_current' => false))['action']);
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide(array('status' => 'expired', 'amount_paid' => '4'))['action']);
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide(array('status' => 'cancelled', 'amount_paid' => '0', 'amount_pending' => '3'))['action']);
		// Applied once: later states of the invoice change nothing.
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide(array('status' => 'expired', 'amount_paid' => '4'), array('applied' => true))['action']);
	}

	public function test_fingerprint_follows_money_not_noise() {
		$a = array('status' => 'open', 'amount_paid' => '0', 'payments' => array(), 'pay_url' => 'x');
		$this->assertSame(CoinsPay_Decision::fingerprint($a), CoinsPay_Decision::fingerprint(array('pay_url' => 'y') + $a));
		$this->assertNotSame(CoinsPay_Decision::fingerprint($a), CoinsPay_Decision::fingerprint(array('amount_paid' => '1') + $a));
		$this->assertNotSame(CoinsPay_Decision::fingerprint($a), CoinsPay_Decision::fingerprint(array('payments' => array(array('deposit_id' => 'd', 'status' => 'pending'))) + $a));
	}

	public function test_russian() {
		$note = CoinsPay_Decision::decide(array('number' => 7, 'status' => 'open', 'currency' => 'USD', 'amount_paid' => '4', 'amount_due' => '6'), array(), new CoinsPay_Text('ru'))['note'];
		$this->assertSame('Счёт CoinsPay № 7 оплачен частично: получено 4 USD, осталось 6 USD. Участник может доплатить на той же странице оплаты.', $note);
	}
}
