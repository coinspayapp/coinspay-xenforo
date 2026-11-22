<?php
/**
 * Unit tests of the core: no XenForo, a fake host where one is needed.
 */
define('COINSPAY_XF', true);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/upload/src/addons/CoinsPay/Payment/core/loader.php';
require __DIR__ . '/Fakes.php';
