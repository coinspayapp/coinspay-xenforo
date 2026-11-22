<?php

namespace CoinsPay\Payment\Pub\Controller;

use CoinsPay\Payment\Front;
use XF\Pub\Controller\AbstractController;

/**
 * coinspay/return?key=<purchase request key>: the return address of the CoinsPay invoice. The
 * invoice is read back and applied at once, then the member goes to the page of the purchase.
 * purchase/process of XenForo is not used: it refuses a purchase the webhook applied a second
 * earlier as one that can no longer be bought.
 */
class Back extends AbstractController {

	public function actionReturn() {
		$back = Front::back($this->filter('key', 'str'));
		return $this->redirect($back !== '' ? $back : $this->buildLink('index'));
	}
}
