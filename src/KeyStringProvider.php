<?php

namespace OndraKoupil\Csob;

class KeyStringProvider implements KeyProvider
{
    /** @var string */
    private $key;

    /**
     * @param string $key
     */
    public function __construct($key)
    {
        $this->key = $key;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return "[PROVIDED STRING KEY REDACTED]";
    }
}
