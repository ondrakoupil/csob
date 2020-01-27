<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

class PaymentTestCase extends TestCase {

	function testConstruct() {

		$payment = new Payment("123", "Hello žluť!", 100);

		Assert::same("123", $payment->orderNo);
		Assert::same(100, $payment->customerId);
		Assert::same("Hello žluť!", $payment->getMerchantData());

		Assert::exception(function() {
			$longData = str_repeat("1234567890", 200);
			$payment = new Payment(100, $longData);
		}, 'Exception');

	}

	function testCart() {

		$config = require(__DIR__ . "/../dummy-config.php");
		$client = new Client($config);

		$payment = new Payment("100");

		$payment->addCartItem("Item 1", 10, 30000, "Desc 1");
		$payment->addCartItem("Item 2 with a really long name that needs to be trimmed", 1, 10000, "A description of a cart item that is long and needs to be trimmed");

		// No more than two items can be in cart
		Assert::exception(function() use ($payment) {
			$payment->addCartItem("Third item", 1, 2000);
		}, '\OndraKoupil\Csob\Exception');

		$exported = $payment->checkAndPrepare($config)->signAndExport($client);

		// Total sum is OK
		Assert::same(40000, $exported["totalAmount"]);

		// Two items are there, not the third
		Assert::same(2, count($exported["cart"]));

		// Texts are trimmed
		Assert::same("Item 1", $exported["cart"][0]["name"]);
		Assert::same("Desc 1", $exported["cart"][0]["description"]);
		Assert::same("Item 2 with a really", $exported["cart"][1]["name"]);
		Assert::same("A description of a cart item that is lon", $exported["cart"][1]["description"]);

		// At least one item must be in the cart
		$payment = new Payment("200");
		Assert::exception(function() use ($payment, $config) {
			$payment->checkAndPrepare($config);
		}, '\OndraKoupil\Csob\Exception');


		// Price must not be decimal
		$payment = new Payment("100");
		$payment->addCartItem("Decimal item", 2, 1234.56);
		$exported = $payment->checkAndPrepare($config)->signAndExport($client);
		Assert::same(1235, $exported["totalAmount"]);

	}

	function testCartName() {

		$config = require(__DIR__ . "/../dummy-config.php");
		$client = new Client($config);

		$payment = new Payment("100");

		// on char 20 must be space
		$payment->addCartItem("Item with very long name", 10, 30000, "Desc 1");

		$exported = $payment->checkAndPrepare($config)->signAndExport($client);

		Assert::same("Item with very long", $exported["cart"][0]["name"]);

	}

	function testSignature() {

		/** @var Config $config */
		$config = require(__DIR__ . "/../dummy-config.php");
		$client = new Client($config);

		$configOnApi17 = clone $config;
		$configOnApi17->url = GatewayUrl::TEST_1_7;
		$configOnApi17->apiVersion = '1.7';
		$clientOnApi17 = clone $client;
		$clientOnApi17->setConfig($configOnApi17);

		$payment = new Payment("100");

		$payment->currency = "EUR";
		$payment->customerId = "test@example.com";
		$payment->closePayment = false;
		$payment->description = 'DESC';

		// Api 1.8 has no description
		$signatureString = $payment->getSignatureString($client);
		$expectedSignatureString = "|100||||0|EUR|false|||||test@example.com||";
		Assert::equal($expectedSignatureString, $signatureString);

		// Api 1.7 has description
		$signatureString = $payment->getSignatureString($clientOnApi17);
		$expectedSignatureString = "|100||||0|EUR|false||||DESC||test@example.com||";
		Assert::equal($expectedSignatureString, $signatureString);

		$payment->addCartItem("test", 1, 1000);
		$payment->ttlSec = 1000;
		$payment->colorSchemeVersion = 2;
		$payment->language = 'PL';
		$payment->logoVersion = '3';

		$payment->checkAndPrepare($config);

		$signatureString = $payment->getSignatureString($client);
		$expectedSignatureString = "aaa|100|".date(Client::DATE_FORMAT)."|payment|card|1000|EUR|false|eee|POST|test|1|1000|||test@example.com|PL|1000|3|2";
		Assert::equal($expectedSignatureString, $signatureString);

		$signatureStringOnApi17 = $payment->getSignatureString($clientOnApi17);
		$expectedSignatureStringOnApi17 = "aaa|100|".date(Client::DATE_FORMAT)."|payment|card|1000|EUR|false|eee|POST|test|1|1000||DESC||test@example.com|PL|1000|3|2";
		Assert::equal($expectedSignatureStringOnApi17, $signatureStringOnApi17);

		$export18 = $payment->signAndExport($client);
		$export17 = $payment->signAndExport($clientOnApi17);

		Assert::truthy($export17["signature"]);
		Assert::truthy($export18["signature"]);

		Assert::true(Crypto::verifySignature($expectedSignatureStringOnApi17, $export17["signature"], __DIR__ . "/../test-keys/test-key.pub", $clientOnApi17->getConfig()->getHashMethod()));
		Assert::true(Crypto::verifySignature($expectedSignatureString, $export18["signature"], __DIR__ . "/../test-keys/test-key.pub", $client->getConfig()->getHashMethod()));

	}

	function testAuxFieldsInSignature() {

		// Logo and colorScheme must be set or must not be in resulting data and signature.

		$config = require(__DIR__ . "/../dummy-config.php");
		$client = new Client($config);

		$payment = new Payment("100");

		$payment->currency = "EUR";
		$payment->customerId = "test@example.com";
		$payment->closePayment = false;

		// A payment with aux fields set

		$payment->colorSchemeVersion = 123456;
		$payment->logoVersion = 987654;

		$signatureString = $payment->getSignatureString($client);
		$exportedData = $payment->signAndExport($client);

		Assert::contains('123456', $signatureString);
		Assert::contains('987654', $signatureString);
		Assert::same(123456, $exportedData['colorSchemeVersion']);
		Assert::same(987654, $exportedData['logoVersion']);

		// A payment with aux fields not set

		$payment->colorSchemeVersion = null;
		$payment->logoVersion = null;

		$signatureString = $payment->getSignatureString($client);
		$exportedData = $payment->signAndExport($client);

		Assert::notContains('123456', $signatureString);
		Assert::notContains('987654', $signatureString);
		Assert::false(isset($exportedData['colorSchemeVersion']));
		Assert::false(isset($exportedData['logoVersion']));

	}

	function testProps() {

		$payment = new Payment("200");

		// Merchant data

		$str = "Příliš žluťoučký kůň";
		$strEncoded = base64_encode($str);

		$payment->setMerchantData($str);
		Assert::equal($str, $payment->getMerchantData());

		$payment->setMerchantData($strEncoded, true);
		Assert::equal($str, $payment->getMerchantData());

		$longString = str_repeat("1234567890", 200);
		Assert::exception(function() use ($payment, $longString) {
			$payment->setMerchantData($longString);
		}, '\OndraKoupil\Csob\Exception');

		Assert::equal($str, $payment->getMerchantData());

		// Pay ID

		$payment->setPayId("12345");
		Assert::equal("12345", $payment->getPayId());

	}

	function testRecurrent() {
		$payment = new Payment("ABC123");

		Assert::error(function() use ($payment) {
			$payment->setRecurrentPayment(true);
		}, E_USER_DEPRECATED);

	}

	function testOneClick() {
		$payment = new Payment("ABCDE12345");
		$payment->setOneClickPayment(true);

		Assert::same($payment::OPERATION_ONE_CLICK, $payment->payOperation);

	}

	function testInvalidUtfData() {

		$payment = new Payment(1234);
		$invalidString = pack("H*" ,'c32e'); // https://stackoverflow.com/questions/10205722/json-encode-invalid-utf-8-sequence-in-argument
		$payment->addCartItem('Hello ' . $invalidString, 1, 12300);

		$client = new Client(include __DIR__ . '/../dummy-config.php');

		Assert::exception(
			function() use ($client, $payment) {
				$client->paymentInit($payment);
			},
			'OndraKoupil\Csob\Exception',
			null,
			1
		);

	}


}

$case = new PaymentTestCase();
$case->run();
