<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

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


}

$case = new LoggingTestCase();
$case->run();
