<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use OndraKoupil\Csob\Logging\LoggedObject;
use OndraKoupil\Csob\Logging\Request;
use OndraKoupil\Csob\Logging\Response;
use \Tester\Assert;
use \Tester\TestCase;

use \OndraKoupil\Testing\FilesTestCase;

class LoggingTestCase extends FilesTestCase {

	function testLoggingToFile() {

		$config = require(__DIR__ . "/../dummy-config.php");

		$dir = $this->getTempDir();
		$logFile = $dir."/log.txt";

		$client = new Client($config, $logFile);

		// Log should be created and be empty
		Assert::true(file_exists($logFile));
		Assert::equal(0, filesize($logFile));
		Assert::true(is_writable($logFile));

		// it should contain the message...
		$message = "Hello world!";
		$client->writeToLog($message);
		$logContents = file_get_contents($logFile);
		Assert::contains($message, $logContents);

		// ...and timestamp
		$year = date("Y");
		Assert::contains($year, $logContents);

	}

	function testLoggingWithCallback() {

		$config = require(__DIR__ . "/../dummy-config.php");

		$messages = array();

		$logger = function($message) use (&$messages) {
			$messages[] = $message;
		};

		$client = new Client($config, $logger);

		Assert::equal(0, count($messages));

		$message = "Hello world!";
		$client->writeToLog($message);
		$client->writeToTraceLog($message);

		Assert::equal(1, count($messages));
		Assert::equal($message, $messages[0]);

		$client->setLog(null);

		$client->writeToLog($message);
		Assert::equal(1, count($messages));

		$message2 = "Hello sun!";
		$client->setTraceLog($logger);
		$client->writeToLog($message2);
		$client->writeToTraceLog($message2);

		Assert::equal(2, count($messages));
		Assert::equal($message, $messages[0]);
		Assert::equal($message2, $messages[1]);

	}

	function testLoggingClient() {

		$config = require(__DIR__ . "/../dummy-config.php");

		$dir = $this->getTempDir();
		$logFile = $dir."/log.txt";
		$traceLogFile = $dir."/tracelog.txt";

		$client = new Client($config);
		$client->setLog($logFile);
		$client->setTraceLog($traceLogFile);

		$dummyPayId = "abcde12345abcde";
		$client->getPaymentProcessUrl($dummyPayId);

		$logContents = file_get_contents($logFile);
		$traceLogContents = file_get_contents($traceLogFile);

		Assert::contains($dummyPayId, $logContents);
		Assert::contains($dummyPayId, $traceLogContents);

	}

	function testLoggingRequests() {

		/** @var Config $config */
		$config = require(__DIR__ . "/../dummy-config.php");

		$log = array();

		$logger = function(LoggedObject $obj) use (&$log) {
			$log[] = $obj;
		};

		$client = new Client($config);
		$client->setRequestLog($logger);

		Assert::exception(function() use ($client) {
			$client->testGetConnection();
		}, 'OndraKoupil\Csob\Exception');

		Assert::exception(function() use ($client) {
			$client->testPostConnection();
		}, 'OndraKoupil\Csob\Exception');

		Assert::count(4, $log);

		Assert::type('OndraKoupil\Csob\Logging\Request', $log[0]);
		Assert::type('OndraKoupil\Csob\Logging\Response', $log[1]);
		Assert::type('OndraKoupil\Csob\Logging\Request', $log[2]);
		Assert::type('OndraKoupil\Csob\Logging\Response', $log[3]);

		/** @var Request $req */
		$req = $log[0];
		/** @var Response $resp */
		$resp = $log[1];
		Assert::same(1, $req->requestNumber);
		Assert::same('GET', $req->httpMethod);
		Assert::same('echo', $req->apiMethod);
		Assert::same($config->merchantId, $req->payload['merchantId']);
		Assert::true($req->successfullySent);

		Assert::same(1, $resp->requestNumber);
		Assert::same(400, $resp->httpStatus);
		Assert::falsey($resp->rawResponse);

		/** @var Request $req */
		$req = $log[2];
		/** @var Response $resp */
		$resp = $log[3];
		Assert::same(2, $req->requestNumber);
		Assert::same('POST', $req->httpMethod);
		Assert::same('echo', $req->apiMethod);
		Assert::same($config->merchantId, $req->payload['merchantId']);
		Assert::same('{"merchantId":"aaa"', substr($req->encodedPayload, 0, 19));
		Assert::true($req->successfullySent);

		Assert::same(2, $resp->requestNumber);
		Assert::same(400, $resp->httpStatus);
		Assert::falsey($resp->rawResponse);

	}


}

$case = new LoggingTestCase();
$case->run();
