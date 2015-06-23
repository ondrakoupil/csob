<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

class ConfigTestCase extends TestCase {

	function testConstruct() {

		$c = new Config("1234", "aaa", "bbb", "ccc", "ddd", "eee", "fff");

		Assert::equal("1234", $c->merchantId);
		Assert::equal("aaa", $c->privateKeyFile);
		Assert::equal("bbb", $c->bankPublicKeyFile);
		Assert::equal("ccc", $c->shopName);
		Assert::equal("ddd", $c->returnUrl);
		Assert::equal("eee", $c->url);
		Assert::equal("fff", $c->privateKeyPassword);

	}


}

$case = new ConfigTestCase();
$case->run();
