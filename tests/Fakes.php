<?php
/**
 * XenForo, the tables and the CoinsPay API in memory, for unit tests.
 */

/**
 * XenForo for one payment profile: one purchase request, payments of the payment provider log in
 * an array, and a grant that can fail or be refused, as asked.
 */
final class FakeHost implements CoinsPay_Host {

	public $profileId = 3;
	public $settings = array('api_url' => 'https://pay.example/api/v1', 'api_key' => 'cpk_live_key', 'webhook_secret' => 'whsec_test');
	public $purchase = array('request_key' => 'RK1', 'purchase_request_id' => 5, 'user_id' => 7, 'cost_amount' => '10.00', 'cost_currency' => 'USD', 'payment_profile_id' => 3);
	/** @var array Transaction id → log message of the payment. */
	public $payments = array();
	/** @var array Lines {type, message} of the payment provider log. */
	public $log = array();
	public $admin = false;
	public $lang = 'en';

	/** @var string|null "throw" — fail inside the transaction; "refuse" — XenForo does not apply it. Once. */
	public $fail = null;

	/** @var bool XenForo refuses every time. */
	public $refuseAlways = false;

	/** @var MemoryRepo|null The tables, rolled back with the transaction of grant() as the database does. */
	public $repo;

	public function db() {
		throw new LogicException('no database in unit tests');
	}

	public function profileId() {
		return $this->profileId;
	}

	public function settings() {
		return $this->settings;
	}

	public function boardUrl() {
		return 'https://forum.example';
	}

	public function language() {
		return $this->lang;
	}

	public function isAdmin() {
		return $this->admin;
	}

	public function purchase($requestKey) {
		return $this->purchase && $this->purchase['request_key'] === $requestKey ? $this->purchase : null;
	}

	public function hasPayment($transactionId) {
		return isset($this->payments[$transactionId]);
	}

	public function grant($requestKey, $transactionId, $note, $details, $guard) {
		$before = $this->repo ? $this->repo->rows : null;
		$rollback = function () use ($before) {
			if ($this->repo) {
				$this->repo->rows = $before;
			}
		};
		if (!call_user_func($guard)) {
			$rollback();
			throw new CoinsPay_Busy('the claim of the purchase was taken over by another request');
		}
		$fail = $this->fail;
		$this->fail = null;
		if ($fail === 'throw') {
			$rollback();
			throw new RuntimeException('the upgrade could not be saved');
		}
		if ($fail === 'refuse' || $this->refuseAlways) {
			$rollback();
			return 'The user upgrade is gone.';
		}
		$this->payments[$transactionId] = 'Payment received. ' . $note;
		return '';
	}

	public function log($requestKey, $transactionId, $type, $message, $details) {
		$this->log[] = array($type, $message);
	}

	public function xfVersion() {
		return '2.3.13';
	}

	public function transport() {
		return new FakeTransport();
	}

	/** Lines of the log of one type. */
	public function lines($type) {
		$lines = array();
		foreach ($this->log as $line) {
			if ($line[0] === $type) {
				$lines[] = $line[1];
			}
		}
		return $lines;
	}
}

/** The tables of the add-on in memory. */
final class MemoryRepo extends CoinsPay_Repo {

	public $rows = array();
	public $kv = array();
	public $locked = array();

	public function __construct() {
	}

	public function install() {
	}

	/** @var bool The claim is taken over by another request before this one marks it applied. */
	public $stealClaim = false;

	public function add($row) {
		$this->rows[$row['invoice_id']] = $row + array(
			'created' => time(), 'updated' => time(), 'apply_state' => 'none', 'claimed' => null, 'claim_token' => null, 'fingerprint' => null,
			'attention' => null, 'snapshot' => null, 'return_url' => '', 'user_id' => 7, 'profile_id' => 3,
		);
	}

	public function find($invoiceId) {
		return isset($this->rows[strtolower($invoiceId)]) ? $this->rows[strtolower($invoiceId)] : null;
	}

	public function forRequest($requestKey) {
		$rows = array();
		foreach ($this->rows as $row) {
			if ((string)$row['request_key'] === (string)$requestKey) {
				$rows[] = $row;
			}
		}
		usort($rows, function ($a, $b) {
			return $b['attempt'] - $a['attempt'];
		});
		return $rows;
	}

	public function update($invoiceId, $columns) {
		$this->rows[strtolower($invoiceId)] = $columns + $this->rows[strtolower($invoiceId)];
	}

	public function due($limit) {
		$now = time();
		$rows = array_values(array_filter($this->rows, function ($row) use ($now) {
			return $row['next_poll'] !== null && $row['next_poll'] <= $now && CoinsPay_Repo::pollable($row, $now);
		}));
		usort($rows, function ($a, $b) {
			return $a['next_poll'] - $b['next_poll'];
		});
		return array_slice($rows, 0, $limit);
	}

	public function recent($profileId, $limit) {
		return array_slice(array_values($this->rows), 0, $limit);
	}

	public function attention($profileId, $limit) {
		return array_values(array_filter($this->rows, function ($row) {
			return $row['attention'] !== null;
		}));
	}

	public function kvGet($key, $maxAge = 0) {
		return isset($this->kv[$key]) ? $this->kv[$key] : null;
	}

	public function kvSet($key, $value) {
		$this->kv[$key] = (string)$value;
	}

	public function claim($invoiceId, $stale, $token) {
		$row = $this->rows[strtolower($invoiceId)];
		if ($row['apply_state'] !== 'none' && !($row['apply_state'] === 'applying' && ($row['claimed'] === null || $row['claimed'] < time() - $stale))) {
			return false;
		}
		$this->update($invoiceId, array('apply_state' => 'applying', 'claimed' => time(), 'claim_token' => $token));
		if ($this->stealClaim) {
			// Another request takes the claim over right after, outside this one's transaction.
			$this->update($invoiceId, array('claim_token' => 'someone-else'));
		}
		return true;
	}

	public function markApplied($invoiceId, $token) {
		$row = $this->rows[strtolower($invoiceId)];
		if ($row['apply_state'] !== 'applying' || $row['claim_token'] !== $token) {
			return false;
		}
		$this->update($invoiceId, array('apply_state' => 'applied', 'claimed' => null, 'claim_token' => null));
		return true;
	}

	public function release($invoiceId, $token) {
		$row = $this->rows[strtolower($invoiceId)];
		if ($row['apply_state'] === 'applying' && $row['claim_token'] === $token) {
			$this->update($invoiceId, array('apply_state' => 'none', 'claimed' => null, 'claim_token' => null));
		}
	}

	public function lock($requestKey) {
		if (!empty($this->locked[$requestKey])) {
			return false;
		}
		$this->locked[$requestKey] = true;
		return true;
	}

	public function unlock($requestKey) {
		unset($this->locked[$requestKey]);
	}
}

/** The CoinsPay API in memory: invoices by id, and the requests that came. */
final class FakeClient extends CoinsPay_ApiClient {

	public $invoices = array();
	public $created = array();
	public $cancelled = array();
	public $reads = 0;
	public $test = false;
	/** @var array Invoice id → an exception its read throws. */
	public $failRead = array();
	/** @var array|null The answer of currencies(), when set. */
	public $currencyList = null;

	public function __construct() {
	}

	public function shop() {
		return array('shop' => array('id' => 's1', 'name' => 'Forum shop'), 'key' => array('test' => $this->test, 'access' => 'invoices'));
	}

	public function currencies() {
		if ($this->currencyList !== null) {
			return $this->currencyList;
		}
		return array('fiat' => array(array('code' => 'USD', 'scale' => 2), array('code' => 'JPY', 'scale' => 0)), 'crypto' => array(array('code' => 'USDT', 'scale' => 6)));
	}

	public function createInvoice($body, $idempotencyKey) {
		$this->created[] = array($body, $idempotencyKey);
		$id = sprintf('00000000-0000-4000-8000-%012d', count($this->created));
		return $this->invoices[$id] = array(
			'id' => $id, 'number' => count($this->created), 'status' => 'open', 'test' => $this->test,
			'currency' => $body['currency'], 'amount' => $body['amount'], 'amount_paid' => '0', 'amount_pending' => '0',
			'amount_due' => $body['amount'], 'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false,
			'pay_url' => 'https://pay.example/pay/' . $id, 'payments' => array(),
		);
	}

	public function invoice($id) {
		$this->reads++;
		if (isset($this->failRead[$id])) {
			throw $this->failRead[$id];
		}
		if (!isset($this->invoices[$id])) {
			throw new CoinsPay_ApiError(404, 'NOT_FOUND', 'Invoice not found');
		}
		return $this->invoices[$id];
	}

	public function cancelInvoice($id) {
		$this->cancelled[] = $id;
		$this->invoices[$id]['status'] = 'cancelled';
		return $this->invoices[$id];
	}

	/** The invoice paid in full. */
	public function pay($id, array $overrides = array()) {
		$this->invoices[$id] = $overrides + array(
			'status' => 'paid', 'amount_paid' => $this->invoices[$id]['amount'], 'amount_due' => '0',
			'payments' => array(array('deposit_id' => 'd1', 'status' => 'confirmed', 'amount' => '10', 'asset' => 'USDT', 'chain' => 'tron', 'tx_hash' => 'abcdef0123456789abcdef')),
		) + $this->invoices[$id];
	}
}

/** The CoinsPay payment profiles: modules over one repo and one client. */
final class FakeProfiles implements CoinsPay_Profiles {

	/** @var CoinsPay_Module[] By profile id. */
	public $modules = array();

	/** @var MemoryRepo */
	public $repo;

	public function __construct(MemoryRepo $repo) {
		$this->repo = $repo;
	}

	/** Adds a profile over the repo and the client. */
	public function add(FakeHost $host, FakeClient $client) {
		$host->repo = $this->repo;
		$module = new CoinsPay_Module($host);
		$module->setRepo($this->repo);
		$module->setClient($client);
		return $this->modules[$host->profileId] = $module;
	}

	public function module($profileId) {
		return isset($this->modules[(int)$profileId]) ? $this->modules[(int)$profileId] : null;
	}

	public function all() {
		return array_values($this->modules);
	}

	public function repo() {
		return $this->repo;
	}
}

/** Header x-cpay-signature for a body, as CoinsPay signs it. */
function coinspay_sign($body, $secret, $time = null) {
	$time = $time === null ? time() : $time;
	return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
}

/** A transport that answers from a list and keeps the requests. */
final class FakeTransport implements CoinsPay_Transport {

	/** @var array Answers {0: status, 1: body}, or a CoinsPay_ApiError to throw. */
	public $answers = array();
	public $requests = array();

	public function send($method, $url, array $headers, $body, $timeout, $connectTimeout) {
		$this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout', 'connectTimeout');
		$answer = array_shift($this->answers);
		if ($answer instanceof CoinsPay_ApiError) {
			throw $answer;
		}
		return $answer;
	}
}
