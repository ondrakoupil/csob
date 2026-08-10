<?php

return new \OndraKoupil\Csob\Config(
    "aaa",
    new \OndraKoupil\Csob\KeyFileProvider(__DIR__ . "/test-keys/test-key.key"),
    new \OndraKoupil\Csob\KeyFileProvider(__DIR__ . "/test-keys/bank.pub"),
    "ddd",
    "eee"
);