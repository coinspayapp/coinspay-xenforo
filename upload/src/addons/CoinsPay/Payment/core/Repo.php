<?php
/**
 * Tables of the add-on: CoinsPay invoices of XenForo purchase requests, and a small key-value
 * store. Plain SQL through the database of XenForo: the same on 2.2 and 2.3.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Repo {

	const INVOICES = 'xf_coinspay_invoice';
	const KV = 'xf_coinspay_kv';

	/** @var CoinsPay_Db */
	private $db;

	/** @var string */
	private $site;

	/**
	 * @param CoinsPay_Db $db
	 * @param string      $site Address of the forum: names the locks apart from other forums on the server.
	 */
	public function __construct(CoinsPay_Db $db, $site) {
		$this->db = $db;
		$this->site = (string)$site;
	}

	/** Creates the tables: from the installer of the add-on. */
	public function install() {
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::INVOICES . '` (
				`invoice_id` CHAR(36) NOT NULL,
				`request_key` VARBINARY(32) NOT NULL,
				`profile_id` INT UNSIGNED NOT NULL,
				`user_id` INT UNSIGNED NOT NULL DEFAULT 0,
				`attempt` INT UNSIGNED NOT NULL,
				`sent_amount` VARCHAR(40) NOT NULL,
				`sent_currency` VARCHAR(10) NOT NULL,
				`cost_amount` VARCHAR(40) NOT NULL,
				`cost_currency` VARCHAR(10) NOT NULL,
				`test` TINYINT(1) NOT NULL DEFAULT 0,
				`test_admin` TINYINT(1) NOT NULL DEFAULT 0,
				`status` VARCHAR(16) NOT NULL DEFAULT \'open\',
				`pay_url` VARCHAR(500) NOT NULL DEFAULT \'\',
				`return_url` TEXT NULL,
				`snapshot` TEXT NULL,
				`fingerprint` CHAR(32) NULL,
				`apply_state` VARCHAR(10) NOT NULL DEFAULT \'none\',
				`claimed` INT UNSIGNED NULL,
				`claim_token` VARCHAR(32) NULL,
				`attention` TEXT NULL,
				`next_poll` INT UNSIGNED NULL,
				`poll_until` INT UNSIGNED NOT NULL DEFAULT 0,
				`created` INT UNSIGNED NOT NULL,
				`updated` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`invoice_id`),
				KEY `request_key` (`request_key`),
				KEY `profile_created` (`profile_id`, `created`),
				KEY `next_poll` (`next_poll`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
			array()
		);
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::KV . '` (
				`k` VARBINARY(64) NOT NULL,
				`v` MEDIUMTEXT NOT NULL,
				`updated` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`k`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
			array()
		);
	}

	/** Drops the tables: from the uninstaller of the add-on. */
	public function uninstall() {
		$this->db->exec('DROP TABLE IF EXISTS `' . self::INVOICES . '`', array());
		$this->db->exec('DROP TABLE IF EXISTS `' . self::KV . '`', array());
	}

	/**
	 * @param array $row Columns of a new CoinsPay invoice.
	 */
	public function add($row) {
		$now = time();
		$row += array('created' => $now, 'updated' => $now, 'apply_state' => 'none');
		$columns = array_keys($row);
		$this->db->exec(
			'INSERT INTO `' . self::INVOICES . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
			array_values($row)
		);
	}

	public function find($invoiceId) {
		return $this->db->one('SELECT * FROM `' . self::INVOICES . '` WHERE invoice_id = ?', array(strtolower((string)$invoiceId)));
	}

	/** CoinsPay invoices of a purchase request, the newest first. */
	public function forRequest($requestKey) {
		return $this->db->all('SELECT * FROM `' . self::INVOICES . '` WHERE request_key = ? ORDER BY attempt DESC', array((string)$requestKey));
	}

	/** The newest CoinsPay invoice of a purchase request: the one the member pays now. */
	public function current($requestKey) {
		$rows = $this->forRequest($requestKey);
		return $rows ? $rows[0] : null;
	}

	public function update($invoiceId, $columns) {
		$columns['updated'] = time();
		$sets = array();
		foreach (array_keys($columns) as $column) {
			$sets[] = '`' . $column . '` = ?';
		}
		$args = array_values($columns);
		$args[] = strtolower((string)$invoiceId);
		$this->db->exec('UPDATE `' . self::INVOICES . '` SET ' . implode(', ', $sets) . ' WHERE invoice_id = ?', $args);
	}

	/**
	 * Invoices of all profiles to poll now: open ones, closed ones a little longer for a late
	 * payment, and paid ones XenForo did not apply yet for up to CoinsPay_Sync::RETRY_PAID.
	 */
	public function due($limit) {
		$now = time();
		return $this->db->all(
			'SELECT * FROM `' . self::INVOICES . '` WHERE next_poll IS NOT NULL AND next_poll <= ?
			AND (poll_until > ? OR (status = \'paid\' AND apply_state <> \'applied\' AND created > ?))
			ORDER BY next_poll LIMIT ' . (int)$limit,
			array($now, $now, $now - CoinsPay_Sync::RETRY_PAID)
		);
	}

	/** Whether an invoice may still be polled at $time: see due(). */
	public static function pollable($row, $time) {
		return $time < (int)$row['poll_until']
			|| ($row['status'] === 'paid' && $row['apply_state'] !== 'applied' && (int)$row['created'] > time() - CoinsPay_Sync::RETRY_PAID);
	}

	/** The last invoices of a profile, for its panel. */
	public function recent($profileId, $limit) {
		return $this->db->all(
			'SELECT * FROM `' . self::INVOICES . '` WHERE profile_id = ? ORDER BY created DESC LIMIT ' . (int)$limit,
			array((int)$profileId)
		);
	}

	/**
	 * Invoices of a profile that need a decision of the admin, the newest first: of the last 30
	 * days, so a case the admin settled outside the forum leaves the list by itself.
	 */
	public function attention($profileId, $limit) {
		return $this->db->all(
			'SELECT * FROM `' . self::INVOICES . '` WHERE profile_id = ? AND attention IS NOT NULL AND updated > ? ORDER BY updated DESC LIMIT ' . (int)$limit,
			array((int)$profileId, time() - 2592000)
		);
	}

	public function kvGet($key, $maxAge = 0) {
		$row = $this->db->one('SELECT v, updated FROM `' . self::KV . '` WHERE k = ?', array((string)$key));
		if (!$row || ($maxAge > 0 && (int)$row['updated'] < time() - $maxAge)) {
			return null;
		}
		return (string)$row['v'];
	}

	public function kvSet($key, $value) {
		$this->db->exec(
			'INSERT INTO `' . self::KV . '` (k, v, updated) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated = VALUES(updated)',
			array((string)$key, (string)$value, time())
		);
	}

	/**
	 * Claims the payment of a CoinsPay invoice for this request: one row update, so of two
	 * requests at once only one gets it, whatever the named lock did. A claim left by a request
	 * that died is taken over after $stale seconds.
	 *
	 * @param string $invoiceId
	 * @param int    $stale
	 * @param string $token     Of this request: only its holder may mark the payment applied.
	 * @return bool
	 */
	public function claim($invoiceId, $stale, $token) {
		$now = time();
		return $this->db->exec(
			'UPDATE `' . self::INVOICES . '` SET apply_state = \'applying\', claimed = ?, claim_token = ?, updated = ?
			WHERE invoice_id = ? AND (apply_state = \'none\' OR (apply_state = \'applying\' AND (claimed IS NULL OR claimed < ?)))',
			array($now, (string)$token, $now, strtolower((string)$invoiceId), $now - (int)$stale)
		) === 1;
	}

	/**
	 * Turns this request's claim into "applied". Inside the transaction that applies the purchase
	 * it locks the row until the commit; false when the claim was taken over meanwhile.
	 */
	public function markApplied($invoiceId, $token) {
		return $this->db->exec(
			'UPDATE `' . self::INVOICES . '` SET apply_state = \'applied\', claimed = NULL, claim_token = NULL, updated = ?
			WHERE invoice_id = ? AND apply_state = \'applying\' AND claim_token = ?',
			array(time(), strtolower((string)$invoiceId), (string)$token)
		) === 1;
	}

	/** Gives this request's claim up, for the next try; a claim taken over by another stays theirs. */
	public function release($invoiceId, $token) {
		$this->db->exec(
			'UPDATE `' . self::INVOICES . '` SET apply_state = \'none\', claimed = NULL, claim_token = NULL, updated = ?
			WHERE invoice_id = ? AND apply_state = \'applying\' AND claim_token = ?',
			array(time(), strtolower((string)$invoiceId), (string)$token)
		);
	}

	/**
	 * Takes the lock of a purchase request. It keeps requests of one purchase in turn, but is not
	 * the guard of the payment: MySQL before 5.7.5 keeps one named lock per connection, and Galera
	 * keeps them per node. claim() is the guard.
	 *
	 * @return bool False when another request held it for 10 seconds.
	 */
	public function lock($requestKey) {
		$row = $this->db->one('SELECT GET_LOCK(?, 10) AS locked', array($this->lockName($requestKey)));
		return $row && (string)$row['locked'] === '1';
	}

	public function unlock($requestKey) {
		$this->db->one('SELECT RELEASE_LOCK(?) AS released', array($this->lockName($requestKey)));
	}

	/** At most 64 characters, as MySQL 5.7 takes. */
	private function lockName($requestKey) {
		return 'cpxf_' . substr(md5($this->site), 0, 10) . '_' . substr(md5((string)$requestKey), 0, 24);
	}
}
