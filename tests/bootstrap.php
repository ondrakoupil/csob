<?php

require __DIR__ . "/../vendor/autoload.php";

define("TMP_TEST_DIR", __DIR__ . "/temp");
date_default_timezone_set("Europe/Prague");

\Tester\Environment::setup();

// PHP 7.4 shows weird behavior in Tester::Environment.
// The Tester is oficially not compatible with 7.4, however
// the library dows work on it.
// To be able to run tests correctly without having to migrate to Tester 2.X,
// run "fix-old-tester.php" before running tests.


// To run tests using packed code in dist directory, uncomment this:
// include __DIR__ . "/../dist/csob-client.php";
