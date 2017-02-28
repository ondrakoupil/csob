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

	function testCreateBasis() {

		$array = array('clato', 'veratta', null, 'necktie!');
		$result = Crypto::createSignatureBaseFromArray($array);

		Assert::same('clato|veratta||necktie!', $result);

		// https://github.com/csob/paymentgateway/wiki/eAPI-v1.7#post-httpsapiplatebnibranacsobczapiv17paymentbutton-

		//"payId":"d165e3c4b624fBD",
		//"dttm":"20140425131559",
		//"resultCode": 0,
		//"resultMessage":"OK",
		//"redirect": {
		//"method":"GET",
		//"url":"https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob"
		//},
		//
		// d165e3c4b624fBD|20140425131559|0|OK|GET|https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob

		$array = array(
			'payId' => 'd165e3c4b624fBD',
			'dttm' => '20140425131559',
			'resultCode' => 0,
			'resultMessage' => 'OK',
			'redirect' => array(
				'method' => 'GET',
				'url' => 'https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob'
			)
		);

		$result = Crypto::createSignatureBaseFromArray($array);

		Assert::same(
			'd165e3c4b624fBD|20140425131559|0|OK|GET|https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob',
			$result
		);

	}

	function testCreateBasisWithOrder() {

		$input = array(
			'lorem' => 'ipsum',
			'dolor' => 'sit',
			'foo' => array(
				'a' => 'AAA',
				'b' => 'BBB',
				'c' => 'CCC',
			),
			'xxx' => array(
				'a' => array(
					'z' => 'Z',
					'y' => 'Y',
					'x' => 'X',
				),
				'b' => array(
					'z' => 1,
					'y' => 2,
					'x' => 3
				)
			)
		);

		$output = Crypto::createSignatureBaseWithOrder(
			$input,
			array('lorem', 'foo.a', 'xxx.a', 'dolor', 'flash', 'foo.c', 'xxx.a.y', '?what', 'xxx.b.x', '?zzz', 'xxx.d.x.c.d.e', 'xxx.b'),
			true
		);

		Assert::same(
			array('ipsum', 'AAA', 'Z', 'Y', 'X', 'sit', '', 'CCC', 'Y', 3, '', 1, 2, 3),
			$output
		);


		$data = array(
			'foo' => 'bar',
			'arr' => array(
				'a' => 'A',
				'b' => 'B',
			),
		);

		$order = array(
			'foo',
			'arr.a',
			'somethingRequired',
			'?somethingOptional',
			'foo',
		    'arr.x',
	        'foo',
	        'arr'
		 );

		 $result = Crypto::createSignatureBaseWithOrder($data, $order, true);

		 Assert::same(
			 array('bar', 'A', '', 'bar', '', 'bar', 'A', 'B'),
			 $result
		 );

		$result = Crypto::createSignatureBaseWithOrder($data, $order, false);

		Assert::same(
			'bar|A||bar||bar|A|B',
			$result
		);

	}


}

$case = new CryptoTestCase();
$case->run();
