<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

class CryptoTestCase extends TestCase {

	function testSigning() {

		$string = "Hello World! Příliš žluťoučký kůň?";
		$expectedSignature = "eP8sitwGq5ZG+ArjDHysdevtkOd0OyMjW4tWDPxiZN2VaUS3zyZWSczKsTys20DmlX4KOsFbEbGkwjRzRUivVZEwZDHM+JlKHV9fUCpCO3syqDFMIlr+cAhx+Pj4c4CIQGWJMty/fgpyX7kdeeNp/aIYwLs6HCsulERbzZqbcWAPo+HEBkDo9rVXDnYGUMNYaZti2HA27/m+9pMwrJFx/DZM6yu/1JEPOCASIHdjiudSOOsIEt8NlSBAQUNZHigUcw9GKt9L3eXQeJopHYdny+mwCCLU0HKAJuC+0QvK/O1Zgazodxil/w6JGkd2jFYpOq2yY6Nbgt64tsISI6lrOw==";

		$result = Crypto::signString($string, __DIR__."/../test-keys/test-key.key");

		Assert::equal($expectedSignature, $result);

		Assert::true(Crypto::verifySignature($string, $result, __DIR__."/../test-keys/test-key.pub"));
		Assert::false(Crypto::verifySignature($string, str_replace("a", "z", $result), __DIR__."/../test-keys/test-key.pub"));

		Assert::exception(function() {
			Crypto::signString("whatever", "invalid-file");
		}, '\OndraKoupil\Csob\CryptoException');

		Assert::exception(function() {
			Crypto::verifySignature("whatever", "whatever", "invalid-file");
		}, '\OndraKoupil\Csob\CryptoException');

	}


}

$case = new CryptoTestCase();
$case->run();
