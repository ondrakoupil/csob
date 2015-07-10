<?php

require __DIR__ . "/../vendor/autoload.php";

define("TMP_TEST_DIR", __DIR__ . "/temp");

\Tester\Environment::setup();

// To run tests using packed code in dist directory, uncomment this:
// include __DIR__ . "/../dist/csob-client.php";
