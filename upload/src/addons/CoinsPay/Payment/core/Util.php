<?php
/**
 * Small helpers. Taken from the core of the CoinsPay plugin for OpenCart (core/Util.php), by way
 * of the WHMCS module.
 */
defined('COINSPAY_XF') or exit;

class CoinsPay_Util {

	/**
	 * Constant-time string comparison.
	 *
	 * @param string $known
	 * @param string $given
	 * @return bool
	 */
	public static function equals($known, $given) {
		return hash_equals((string)$known, (string)$given);
	}

	/**
	 * Random hex string. random_bytes came with PHP 7.0.
	 *
	 * @param int $bytes
	 * @return string
	 */
	public static function randomHex($bytes) {
		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes($bytes)); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.random_bytesFound -- guarded by function_exists
		}
		$strong = false;
		$random = openssl_random_pseudo_bytes($bytes, $strong);
		if ($random === false || !$strong) {
			throw new RuntimeException('No source of random bytes');
		}
		return bin2hex($random);
	}

	/**
	 * Decimal string without trailing zeros: 49.90 → 49.9, 10.00 → 10.
	 *
	 * @param string $amount
	 * @return string
	 */
	public static function normalize($amount) {
		$amount = trim((string)$amount);
		if (strpos($amount, '.') !== false) {
			$amount = rtrim(rtrim($amount, '0'), '.');
		}
		return $amount === '' ? '0' : $amount;
	}

	/**
	 * Amount as the API takes it: at most $scale decimals, no trailing zeros.
	 *
	 * @param float|string $total
	 * @param int          $scale
	 * @return string
	 */
	public static function formatAmount($total, $scale) {
		return self::normalize(number_format((float)$total, max(0, (int)$scale), '.', ''));
	}

	/**
	 * Whether a decimal string is above zero.
	 *
	 * @param string $amount
	 * @return bool
	 */
	public static function positive($amount) {
		return (string)$amount !== '' && (bool)preg_match('/[1-9]/', (string)$amount);
	}

	/**
	 * A scalar field of an array as a string.
	 *
	 * @param array  $data
	 * @param string $field
	 * @return string
	 */
	public static function value($data, $field) {
		return is_array($data) && isset($data[$field]) && is_scalar($data[$field]) ? (string)$data[$field] : '';
	}

	/**
	 * Cuts UTF-8 text to a number of characters, with an ellipsis.
	 *
	 * @param string $text
	 * @param int    $max
	 * @return string
	 */
	public static function truncate($text, $max) {
		$text = (string)$text;
		// A regular expression does without mbstring.
		if (preg_match_all('/./us', $text) <= $max) {
			return $text;
		}
		preg_match('/^.{0,' . max(0, (int)$max - 1) . '}/us', $text, $match);
		return rtrim($match[0]) . "\xE2\x80\xA6";
	}

	/**
	 * JSON for the API and the database.
	 *
	 * @param mixed $data
	 * @return string
	 */
	public static function json($data) {
		return json_encode($data);
	}

	/**
	 * Escapes text for HTML.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function html($text) {
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}
}
