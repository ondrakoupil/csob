<?php

namespace OndraKoupil\Csob;
class KeyFileProvider implements KeyProvider
{
    private $keyFileName;

    public function __construct($keyFileName)
    {
        $this->keyFileName = $keyFileName;
    }

    public function getKey()
    {
        if (!file_exists($this->keyFileName) or !is_readable($this->keyFileName)) {
            throw new CryptoException("Key file \"$this->keyFileName\" not found or not readable.");
        }

        return file_get_contents($this->keyFileName);
    }
}
