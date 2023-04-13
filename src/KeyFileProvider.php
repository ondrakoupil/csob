<?php

namespace OndraKoupil\Csob;
class KeyFileProvider implements KeyProvider
{
    /** @var string */
    private $keyFileName;

    /**
     * @param string $keyFileName
     */
    public function __construct($keyFileName)
    {
        $this->keyFileName = $keyFileName;
    }

    /**
     * @return false|string
     */
    public function getKey()
    {
        if (!file_exists($this->keyFileName) or !is_readable($this->keyFileName)) {
            throw new CryptoException("Key file \"$this->keyFileName\" not found or not readable.");
        }

        /** @var string|bool $keyString */
        $keyString = file_get_contents($this->keyFileName);

        if (is_bool($keyString)) {
            throw new CryptoException("Could not read key file $this->keyFileName");
        }

        return $keyString;
    }

    public function __toString()
    {
        return $this->keyFileName;
    }
}
