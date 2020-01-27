<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use \Tester\Assert;
use \Tester\TestCase;

class CryptoTestCase extends TestCase {

	function testSigning() {

		$string = "Hello World! Příliš žluťoučký kůň?";
		$expectedSignatureSha1 = "eP8sitwGq5ZG+ArjDHysdevtkOd0OyMjW4tWDPxiZN2VaUS3zyZWSczKsTys20DmlX4KOsFbEbGkwjRzRUivVZEwZDHM+JlKHV9fUCpCO3syqDFMIlr+cAhx+Pj4c4CIQGWJMty/fgpyX7kdeeNp/aIYwLs6HCsulERbzZqbcWAPo+HEBkDo9rVXDnYGUMNYaZti2HA27/m+9pMwrJFx/DZM6yu/1JEPOCASIHdjiudSOOsIEt8NlSBAQUNZHigUcw9GKt9L3eXQeJopHYdny+mwCCLU0HKAJuC+0QvK/O1Zgazodxil/w6JGkd2jFYpOq2yY6Nbgt64tsISI6lrOw==";
		$expectedSignatureSha256 = "BZ1rLwbkbvcc72L+7SrOb9qDX3RFyGR8ejdcGw5rWHEyyTt+qofeSxNJPtQOXV/HFGkIrwf9S1jIYKY1aEJEhqP7mhdR7ps/FrtsmoAY0dUuUfJmB64R7eQsj+1hDV+ZCbIbG1OEql5Eatx5cjn9ybn4qY+sqFxy7pCRjoz515eVqDjLQlsQxPRx+jbpZUgYIr8hrQfT7iZ3jUUgXxDhACsfxHXew/SdEZeiLQmoFUXEnMaKzZUOZwyYzDQiTcLntshI9fo7jSLRMtu1mqum9G2ba+mnBk8BQYtpmeQtfnUl/3JpTWAjtSLwHx4pLZtC+mEYutyKTOb2ByXSumjW8Q==";

		$resultDefault = Crypto::signString($string, __DIR__."/../test-keys/test-key.key", '', Crypto::DEFAULT_HASH_METHOD);
		$resultSha1 = Crypto::signString($string, __DIR__."/../test-keys/test-key.key", '', Crypto::HASH_SHA1);
		$resultSha256 = Crypto::signString($string, __DIR__."/../test-keys/test-key.key", '', Crypto::HASH_SHA256);

		Assert::same($resultDefault, $resultSha1);

		Assert::equal($expectedSignatureSha1, $resultDefault);
		Assert::equal($expectedSignatureSha1, $resultSha1);
		Assert::equal($expectedSignatureSha256, $resultSha256);

		Assert::true(Crypto::verifySignature($string, $resultDefault, __DIR__."/../test-keys/test-key.pub", Crypto::DEFAULT_HASH_METHOD));
		Assert::false(Crypto::verifySignature($string, str_replace("a", "z", $resultDefault), __DIR__."/../test-keys/test-key.pub", Crypto::DEFAULT_HASH_METHOD));

		Assert::true(Crypto::verifySignature($string, $resultSha1, __DIR__."/../test-keys/test-key.pub", Crypto::HASH_SHA1));
		Assert::false(Crypto::verifySignature($string, str_replace("a", "z", $resultSha1), __DIR__."/../test-keys/test-key.pub", Crypto::HASH_SHA1));

		Assert::true(Crypto::verifySignature($string, $resultSha256, __DIR__."/../test-keys/test-key.pub", Crypto::HASH_SHA256));
		Assert::false(Crypto::verifySignature($string, str_replace("a", "z", $resultSha256), __DIR__."/../test-keys/test-key.pub", Crypto::HASH_SHA256));


		Assert::exception(function() {
			Crypto::signString("whatever", "invalid-file");
		}, '\OndraKoupil\Csob\CryptoException');

		Assert::exception(function() {
			Crypto::verifySignature("whatever", "whatever", "invalid-file");
		}, '\OndraKoupil\Csob\CryptoException');

	}

	function testCreateBasis() {

		$array = array('clato', 'veratta', null, true, 'necktie!');
		$result = Crypto::createSignatureBaseFromArray($array);

		Assert::same('clato|veratta||true|necktie!', $result);

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
			'someBoolean' => true,
			'anotherBoolean' => false,
			'redirect' => array(
				'method' => 'GET',
				'url' => 'https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob'
			)
		);

		$result = Crypto::createSignatureBaseFromArray($array);

		Assert::same(
			'd165e3c4b624fBD|20140425131559|0|OK|true|false|GET|https://platebnibrana.csob.cz/pay/vasobchod.cz/2c72d818-9788-45a1-878a-9db2a706edc5/pt-detect/csob',
			$result
		);

	}

	function testCreateBasisWithOrder() {

		$input = array(
			'lorem' => 'ipsum',
			'dolor' => 'sit',
			'someBoolean' => true,
			'someBoolean2' => false,
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
			array('lorem', 'someBoolean', 'foo.a', 'xxx.a', 'dolor', 'someBoolean2', 'flash', 'foo.c', 'xxx.a.y', '?what', 'xxx.b.x', '?zzz', 'xxx.d.x.c.d.e', 'xxx.b'),
			true
		);

		Assert::same(
			array('ipsum', 'true', 'AAA', 'Z', 'Y', 'X', 'sit', 'false', '', 'CCC', 'Y', 3, '', 1, 2, 3),
			$output
		);


		$data = array(
			'foo' => 'bar',
			'b' => true,
			'g' => false,
			'arr' => array(
				'a' => 'A',
				'b' => 'B',
			),
		);

		$order = array(
			'foo',
			'arr.a',
			'b',
			'somethingRequired',
			'g',
			'?somethingOptional',
			'foo',
		    'arr.x',
	        'foo',
	        'arr'
		 );

		 $result = Crypto::createSignatureBaseWithOrder($data, $order, true);

		 Assert::same(
			 array('bar', 'A', 'true', '', 'false', 'bar', '', 'bar', 'A', 'B'),
			 $result
		 );

		$result = Crypto::createSignatureBaseWithOrder($data, $order, false);

		Assert::same(
			'bar|A|true||false|bar||bar|A|B',
			$result
		);

	}


}

$case = new CryptoTestCase();
$case->run();
