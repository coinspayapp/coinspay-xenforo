<?php

namespace CoinsPay\Payment;

/**
 * The database of XenForo for the core: its adapter takes ? placeholders on 2.2 and 2.3 alike.
 */
class XfDb implements \CoinsPay_Db {

	public function exec($sql, $args) {
		return (int)\XF::db()->query($sql, $args)->rowsAffected();
	}

	public function one($sql, $args) {
		$row = \XF::db()->fetchRow($sql, $args);
		return is_array($row) ? $row : null;
	}

	public function all($sql, $args) {
		$rows = \XF::db()->fetchAll($sql, $args);
		return is_array($rows) ? $rows : array();
	}

	public function begin() {
		\XF::db()->beginTransaction();
	}

	public function commit() {
		\XF::db()->commit();
	}

	public function rollback() {
		\XF::db()->rollback();
	}
}
