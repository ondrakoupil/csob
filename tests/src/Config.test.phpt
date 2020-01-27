<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

class ConfigTestCase extends TestCase {

	function testConstruct() {

		$c = new Config("1234", "aaa", "bbb", "ccc", "ddd", "eee", "fff", "ggg", '1.7', Crypto::HASH_SHA256);

		Assert::equal("1234", $c->merchantId);
		Assert::equal("aaa", $c->privateKeyFile);
		Assert::equal("bbb", $c->bankPublicKeyFile);
		Assert::equal("ccc", $c->shopName);
		Assert::equal("ddd", $c->returnUrl);
		Assert::equal("eee", $c->url);
		Assert::equal("fff", $c->privateKeyPassword);
		Assert::equal("ggg", $c->sslCertificatePath);
		Assert::equal("1.7", $c->apiVersion);
		Assert::equal(Crypto::HASH_SHA256, $c->hashMethod);

	}

	function testDetectApiVersion() {

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::PRODUCTION_1_8);
		Assert::same('1.8', $c->getVersion());

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::PRODUCTION_1_7);
		Assert::same('1.7', $c->getVersion());

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::TEST_1_8);
		Assert::same('1.8', $c->getVersion());

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::TEST_1_7);
		Assert::same('1.7', $c->getVersion());

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::PRODUCTION_LATEST);
		Assert::same('1.8', $c->getVersion());

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::TEST_LATEST);
		Assert::same('1.8', $c->getVersion());

	}

	function testQueryVersion() {

		$c = new Config('111', 'aa', 'bb', 'cc',  'dd', GatewayUrl::PRODUCTION_1_8);
		Assert::true($c->queryApiVersion('1.8'));

		$c->apiVersion = '1.7';
		Assert::false($c->queryApiVersion('1.8'));
		Assert::true($c->queryApiVersion('1.5'));

		$c->apiVersion = '1';
		Assert::true($c->queryApiVersion('1'));
		Assert::false($c->queryApiVersion('1.5'));

	}


}

$case = new ConfigTestCase();
$case->run();
