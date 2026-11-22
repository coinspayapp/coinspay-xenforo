<?php

namespace CoinsPay\Payment;

use CoinsPay\Payment\Payment\CoinsPay;
use XF\Entity\PaymentProfile;

/**
 * The payment profile form: the fields, drawn by the templater of XenForo so the add-on ships no
 * templates, and a panel with the connection check and the last invoices.
 */
class Admin {

	/** Seconds a connection check is kept: the form is opened often, the API is asked rarely. */
	const CHECK_TTL = 600;

	/** The cron entry runs every 5 minutes: an hour without it means the cron of XenForo is stuck. */
	const CRON_LATE = 3600;

	/** @var CoinsPay */
	private $provider;

	public function __construct(CoinsPay $provider) {
		$this->provider = $provider;
	}

	/**
	 * @param PaymentProfile $profile
	 * @return string HTML of the rows of the form.
	 */
	public function render(PaymentProfile $profile) {
		$options = is_array($profile->options) ? $profile->options : array();
		$profileId = (int)$profile->payment_profile_id;
		$module = Front::module($profileId, $options, $this->provider);
		$settings = $module->settings();
		$text = $module->text();
		$templater = \XF::app()->templater();

		$html = $templater->formRow($this->panel($module, $profileId), array('label' => $text->t('panel_title')));
		$html .= $templater->formTextBoxRow(
			array('name' => 'options[api_key]', 'value' => $settings->apiKey(), 'autocomplete' => 'off', 'dir' => 'ltr'),
			array('label' => $text->t('field_api_key'), 'explain' => $text->t('field_api_key_explain'))
		);
		$html .= $templater->formRow(
			'<code dir="ltr" style="user-select: all; word-break: break-all;">' . \CoinsPay_Util::html(Front::webhookUrl()) . '</code>',
			array('label' => $text->t('field_webhook_url'), 'explain' => $text->t('field_webhook_url_explain'))
		);
		$html .= $templater->formTextBoxRow(
			array('name' => 'options[webhook_secret]', 'value' => $settings->webhookSecret(), 'autocomplete' => 'off', 'dir' => 'ltr'),
			array('label' => $text->t('field_webhook_secret'), 'explain' => $text->t('field_webhook_secret_explain'))
		);
		$html .= $templater->formNumberBoxRow(
			array('name' => 'options[lifetime]', 'value' => $settings->lifetime(), 'min' => \CoinsPay_Settings::LIFETIME_MIN, 'max' => \CoinsPay_Settings::LIFETIME_MAX),
			array('label' => $text->t('field_lifetime'), 'explain' => $text->t('field_lifetime_explain'))
		);
		$choices = array();
		foreach (array_keys(\CoinsPay_Settings::$flags) as $flag) {
			$choice = array('name' => 'options[' . $flag . ']', 'value' => '1', 'selected' => $settings->flag($flag), 'label' => $text->t('opt_' . $flag));
			$hint = $text->t('opt_' . $flag . '_explain');
			if ($hint !== 'opt_' . $flag . '_explain') {
				$choice['hint'] = $hint;
			}
			$choices[] = $choice;
		}
		$html .= $templater->formCheckBoxRow(array(), $choices, array('label' => $text->t('field_options')));
		$html .= $templater->formTextBoxRow(
			array('name' => 'options[api_url]', 'value' => isset($options['api_url']) && is_scalar($options['api_url']) ? (string)$options['api_url'] : '', 'placeholder' => \CoinsPay_Settings::DEFAULT_API_URL, 'dir' => 'ltr'),
			array('label' => $text->t('field_api_url'), 'explain' => $text->t('field_api_url_explain'))
		);
		return $html;
	}

	/**
	 * Checks and tidies the options before XenForo saves them.
	 *
	 * @param array $options
	 * @param array $errors
	 * @return bool
	 */
	public function verify(array &$options, &$errors) {
		$module = Front::module(0, array(), $this->provider);
		$text = $module->text();
		if (!is_array($errors)) {
			$errors = array();
		}

		$clean = array();
		foreach (array('api_key', 'webhook_secret', 'api_url') as $field) {
			$clean[$field] = isset($options[$field]) && is_scalar($options[$field]) ? trim((string)$options[$field]) : '';
		}
		$clean['lifetime'] = \CoinsPay_Settings::clampLifetime(isset($options['lifetime']) ? $options['lifetime'] : 0);
		// A box left unticked is not sent: each flag is saved as 1 or 0, so its default stops counting.
		foreach (array_keys(\CoinsPay_Settings::$flags) as $flag) {
			$clean[$flag] = !empty($options[$flag]) ? 1 : 0;
		}
		$options = $clean;

		if (!preg_match('/^cpk_[A-Za-z0-9_-]+$/D', $clean['api_key'])) {
			$errors[] = $text->t('error_key_format');
		}
		if ($clean['webhook_secret'] === '') {
			$errors[] = $text->t('error_secret_missing');
		}
		if ($clean['api_url'] !== '' && \CoinsPay_ApiClient::base($clean['api_url']) === '') {
			$errors[] = $text->t('error_api_url');
		}
		if ($errors) {
			return false;
		}

		// A key CoinsPay refuses is caught now; an API that is away does not keep the admin from saving.
		$check = Front::module(0, $clean, $this->provider);
		try {
			$check->client()->shop();
		} catch (\CoinsPay_ApiError $e) {
			if (in_array($e->status(), array(401, 403), true)) {
				$errors[] = $text->t('error_key_refused', $e->summary());
				return false;
			}
		}
		return true;
	}

	/** The panel at the top of the form. */
	private function panel(\CoinsPay_Module $module, $profileId) {
		$text = $module->text();
		$settings = $module->settings();
		if ($profileId <= 0 || !$settings->ready()) {
			return '<p>' . \CoinsPay_Util::html($text->t('panel_new')) . '</p>';
		}

		$lines = $this->check($module);
		$seen = $module->repo()->kvGet($module->kvKey('webhook_test'));
		$lines[] = array($seen !== null, $seen !== null
			? $text->t('check_webhook_seen', gmdate('Y-m-d H:i', (int)$seen) . ' UTC')
			: $text->t('check_webhook_unseen'));
		if ($settings->isTestMode()) {
			$lines[] = array(true, $text->t('check_test_first'));
		}
		$lastRun = $module->repo()->kvGet(\CoinsPay_Poller::LAST_RUN);
		if ($lastRun === null) {
			$lines[] = array(false, $text->t('check_cron_never'));
		} elseif ((int)$lastRun < time() - self::CRON_LATE) {
			$lines[] = array(false, $text->t('check_cron_late', gmdate('Y-m-d H:i', (int)$lastRun) . ' UTC'));
		} else {
			$lines[] = array(true, $text->t('check_cron'));
		}

		$html = '<ul class="listPlain">';
		foreach ($lines as $line) {
			$html .= '<li>' . ($line[0] ? '&#10004; ' : '&#10008; ') . \CoinsPay_Util::html($line[1]) . '</li>';
		}
		$html .= '</ul>';

		$repo = $module->repo();
		$attention = $repo->attention($profileId, 10);
		if ($attention) {
			$html .= '<h3 class="block-textHeader">' . \CoinsPay_Util::html($text->t('panel_attention')) . '</h3><ul class="listPlain">';
			foreach ($attention as $row) {
				$html .= '<li><code dir="ltr">' . \CoinsPay_Util::html($row['invoice_id']) . '</code> — ' . \CoinsPay_Util::html($row['attention']) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<h3 class="block-textHeader">' . \CoinsPay_Util::html($text->t('panel_recent')) . '</h3>';
		$rows = $repo->recent($profileId, 10);
		if (!$rows) {
			return $html . '<p>' . \CoinsPay_Util::html($text->t('panel_none')) . '</p>';
		}
		$html .= '<table class="dataList-table"><tr class="dataList-row dataList-row--header">';
		foreach (array('panel_invoice', 'panel_date', 'panel_status', 'panel_amount', 'panel_paid') as $key) {
			$html .= '<th class="dataList-cell">' . \CoinsPay_Util::html($text->t($key)) . '</th>';
		}
		$html .= '</tr>';
		foreach ($rows as $row) {
			$snapshot = json_decode((string)$row['snapshot'], true);
			$status = (string)$row['status'];
			if ($row['apply_state'] === 'applied') {
				$status .= ', ' . $text->t('panel_applied');
			}
			$html .= '<tr class="dataList-row">'
				. '<td class="dataList-cell"><code dir="ltr">' . \CoinsPay_Util::html($row['invoice_id']) . '</code>' . (!empty($row['test']) ? ' (' . \CoinsPay_Util::html($text->t('panel_test')) . ')' : '') . '</td>'
				. '<td class="dataList-cell">' . \CoinsPay_Util::html(gmdate('Y-m-d H:i', (int)$row['created'])) . '</td>'
				. '<td class="dataList-cell">' . \CoinsPay_Util::html($status) . '</td>'
				. '<td class="dataList-cell">' . \CoinsPay_Util::html($row['sent_amount'] . ' ' . $row['sent_currency']) . '</td>'
				. '<td class="dataList-cell">' . \CoinsPay_Util::html(\CoinsPay_Util::value($snapshot, 'amount_paid')) . '</td>'
				. '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * The key and its shop, kept for CHECK_TTL; a new key or address is checked at once.
	 *
	 * @return array Lines {ok, text}.
	 */
	private function check(\CoinsPay_Module $module) {
		$text = $module->text();
		$settings = $module->settings();
		$repo = $module->repo();
		$key = $module->kvKey('check_' . substr(md5($settings->apiUrl() . '|' . $settings->apiKey()), 0, 12));
		$cached = $repo->kvGet($key, self::CHECK_TTL);
		$result = $cached !== null ? json_decode($cached, true) : null;
		if (!is_array($result)) {
			try {
				$shop = $module->client()->shop();
				$result = array(
					'shop' => \CoinsPay_Util::value(isset($shop['shop']) ? $shop['shop'] : array(), 'name'),
					'full' => isset($shop['key']['access']) && $shop['key']['access'] === 'full',
				);
			} catch (\CoinsPay_ApiError $e) {
				$result = array('error' => $e->summary());
			}
			$repo->kvSet($key, \CoinsPay_Util::json($result));
		}

		if (isset($result['error'])) {
			return array(array(false, $text->t('check_api_error', $result['error'])));
		}
		$lines = array(array(true, $text->t('check_shop', $result['shop'], $settings->isTestMode() ? $text->t('check_mode_test') : $text->t('check_mode_live'))));
		if (!empty($result['full'])) {
			$lines[] = array(false, $text->t('check_full_access'));
		}
		return $lines;
	}
}
