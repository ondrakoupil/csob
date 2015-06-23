<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

use \OndraKoupil\Tools\Strings;

class RealTestCase extends TestCase {

	/**
	 * @var Config
	 */
	protected $config;

	function setUp() {
		parent::setUp();

		$config = include __DIR__."/../real-config.php";

		if (!$config or !($config instanceof Config) or !$config->merchantId) {
			\Tester\Environment::skip("Real transactions test skipped. "
				."If you want to perform them, edit file real-config.php "
				."and fill in proper values."
			);

			return;
		}

		$this->config = $config;

	}

	function testPing() {

		$client = new Client($this->config);

		$res = $client->testGetConnection();
		Assert::truthy($res);
		Assert::equal(0, $res["resultCode"]);
		Assert::equal("OK", $res["resultMessage"]);

		$res = $client->testPostConnection();
		Assert::truthy($res);
		Assert::equal(0, $res["resultCode"]);
		Assert::equal("OK", $res["resultMessage"]);

	}

	function testCreateStatusReturn() {

		$randomPaymentNumber = rand(10000, 99999);
		$randomCustomerId = "test".$randomPaymentNumber."@example.com";

		$payment = new Payment($randomPaymentNumber, null, $randomCustomerId);

		$payment->closePayment = false;

		$payment->addCartItem("Test item 1", 2, 3000);

		$payment->description = "Payment created during testing";


		$client = new Client($this->config);

		$returnData = $client->paymentInit($payment);

		// We got back payment with PayId
		Assert::truthy($payment->getPayId());
		Assert::equal($returnData["payId"], $payment->getPayId());

		// The payment is ready to be processed. 1 = payment init status
		$status = $client->paymentStatus($payment, true);
		Assert::equal(1, $status);

		$statusArray = $client->paymentStatus($payment->getPayId(), false);
		Assert::equal(1, $statusArray["paymentStatus"]);


		// We can create address to send customer to
		$url = $client->getPaymentProcessUrl($payment);
		Assert::truthy($url);
		Assert::true(Strings::startsWith($url, $this->config->url));
		Assert::true(Strings::contains($url, $payment->getPayId()));

		// Now return the payment.  5 = payment reversed status
		// Unfortunatelly we can't test this automatically since the payment
		// would have to be processed in browser. At least we can call
		// the API method and receive proper error without exceptions.

		$results = $client->paymentReverse($payment, true);
		Assert::null($results);

	}



}

$case = new RealTestCase();
$case->run();
