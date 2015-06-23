<?php

/*
 * To perform real transaction test that verifies that your crypto keys and
 * other configuration is working correctly and the client is really able to
 * communicate with payment gate's API, create and return a Config object
 * here. Provide your real Merchant ID and keys generated using bank's keygen
 * at https://iplatebnibrana.csob.cz/keygen/
 *
 * WARNING - use only on TESTING gateway, never on live one.
 *
 * The test creates some dummy payments, send them to the payment gateway and
 * then does some operations with them to verify everything is working correctly.
 *
 * Keep the line commented or do not return anything to skip this test.
 */


// Comment this line to do some real testing
// return null;

return new \OndraKoupil\Csob\Config(
	"M1MIPS0038",
	__DIR__ . "/../sandbox/key.key",
	__DIR__ . "/test-keys/bank.pub",
	"Testing e-shop",
	"http://localhost/github/csob-paymentgate/sandbox/return.html",
	null,
	null
);