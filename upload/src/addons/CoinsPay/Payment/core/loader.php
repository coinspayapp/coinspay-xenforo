<?php
/**
 * Loads the core classes (CoinsPay_*): plain requires, they have no namespace for the autoloader
 * of XenForo. Run from CoinsPay\Payment\Front::boot(), inside XenForo.
 */
defined('COINSPAY_XF') or exit;

foreach (array(
	'Util', 'ApiError', 'ApiClient', 'Signature', 'Host', 'Profiles', 'Settings', 'Text',
	'InvoiceRequest', 'Repo', 'Currencies', 'Decision', 'Module', 'Sync', 'Payment', 'Webhook', 'Poller',
) as $coinspayClass) {
	require_once __DIR__ . '/' . $coinspayClass . '.php';
}
unset($coinspayClass);
