<?php

namespace OndraKoupil\Csob;

require '../bootstrap.php';

use OndraKoupil\Csob\Metadata\Customer;
use \Tester\Assert;
use \Tester\TestCase;

class ToolsTestCase extends TestCase {

	function testLinearizeForSigning() {

		$in = array(
			'string' => 'ahoj',
			'emptyString' => '',
			'num' => 123,
			'trueBool' => true,
			'zeroString' => '0',
			'null' => null,
			'falseBool' => false,
			'arr' => array('a', 'b', 'c'),
			'deepArr' => array('EE', array('XX', 'YY', 'ZZ'), 'FF'),
			'num2' => 100
		);

		$out = Tools::linearizeForSigning($in);

		Assert::same(
			'ahoj||123|true|0||false|a|b|c|EE|XX|YY|ZZ|FF|100',
			$out
		);

	}


	function testFilterOutEmptyFields() {
		$in = array(
			'a' => 'a',
			'emptyString' => '',
			'b' => 'b',
			'null' => null,
			'c' => 'c',
			'zero' => 0,
			'e' => 'e',
			'zeroString' => '0',
			'd' => 'd',
		);

		$out = Tools::filterOutEmptyFields($in);

		Assert::equal(
			array(
				'a' => 'a',
				'b' => 'b',
				'c' => 'c',
				'zero' => 0,
				'e' => 'e',
				'zeroString' => '0',
				'd' => 'd',
			),
			$out
		);
	}

}

$case = new ToolsTestCase();
$case->run();
