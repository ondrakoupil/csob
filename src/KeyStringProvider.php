<?php

namespace OndraKoupil\Csob;

class KeyStringProvider implements KeyProvider
{
    private $key;

    public function __construct($key)
    {
        $this->key = $key;
    }

    public function getKey()
    {
        return $this->key;
    }
}
