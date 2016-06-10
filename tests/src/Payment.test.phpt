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

		$payment = new Payment("100");

		$payment->addCartItem("Item 1", 10, 30000, "Desc 1");
		$payment->addCartItem("Item 2 with a really long name that needs to be trimmed", 1, 10000);

		// No more than two items can be in cart
		Assert::exception(function() use ($payment) {
			$payment->addCartItem("Third item", 1, 2000);
		}, '\OndraKoupil\Csob\Exception');

		$exported = $payment->checkAndPrepare($config)->signAndExport($config);

		// Total sum is OK
		Assert::same(40000, $exported["totalAmount"]);

		// Two items are there, not the third
		Assert::same(2, count($exported["cart"]));

		// Texts are trimmed
		Assert::same("Item 1", $exported["cart"][0]["name"]);
		Assert::same("Desc 1", $exported["cart"][0]["description"]);
		Assert::same("Item 2 with a really", $exported["cart"][1]["name"]);

		// At least one item must be in the cart
		$payment = new Payment("200");
		Assert::exception(function() use ($payment, $config) {
			$payment->checkAndPrepare($config);
		}, '\OndraKoupil\Csob\Exception');

	}

	function testCartName() {

		$config = require(__DIR__ . "/../dummy-config.php");

		$payment = new Payment("100");

		// on char 20 must be space
		$payment->addCartItem("Item with very long name", 10, 30000, "Desc 1");

		$exported = $payment->checkAndPrepare($config)->signAndExport($config);

		Assert::same("Item with very long", $exported["cart"][0]["name"]);

	}

	function testSignature() {

		$config = require(__DIR__ . "/../dummy-config.php");

		$payment = new Payment("100");

		$payment->currency = "EUR";
		$payment->customerId = "test@example.com";
		$payment->closePayment = false;

		$signatureString = $payment->getSignatureString();
		$expectedSignatureString = "|100||||0|EUR|false||||||test@example.com|";
		Assert::equal($expectedSignatureString, $signatureString);

		$payment->addCartItem("test", 1, 1000);

		$payment->checkAndPrepare($config);

		$signatureString = $payment->getSignatureString();
		$expectedSignatureString = "aaa|100|".date(Client::DATE_FORMAT)."|payment|card|1000|EUR|false|eee|POST|test|1|1000||ddd, 100||test@example.com|CZ";
		Assert::equal($expectedSignatureString, $signatureString);

		$export = $payment->signAndExport($config);

		Assert::truthy($export["signature"]);

		Assert::true(Crypto::verifySignature($expectedSignatureString, $export["signature"], __DIR__ . "/../test-keys/test-key.pub"));

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

		$payment->setRecurrentPayment(true);
		Assert::equal($payment->payOperation, Payment::OPERATION_RECURRENT);

		$payment->setRecurrentPayment(false);
		Assert::equal($payment->payOperation, Payment::OPERATION_PAYMENT);

	}


}

$case = new PaymentTestCase();
$case->run();
